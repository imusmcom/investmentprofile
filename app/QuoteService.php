<?php
declare(strict_types=1);

namespace App;

/**
 * Pulls quotes from Yahoo Finance's key-free chart endpoint and caches them in SQLite.
 *
 * Both the data source and the human-facing deep links use Yahoo Finance (see Markets::quoteUrl()).
 * A manual price entered on the instruments page wins whenever it is dated at least as newly as the
 * cached close.
 *
 * Yahoo blocks whole datacenter address ranges, so the request can be pointed at a passthrough of the
 * operator's own choosing instead (settings key `quote_endpoint`, see worker/yahoo-passthrough.js).
 * Whatever the answer says about *why*, a 429/403 is taken as "stop asking": the whole refresh pass
 * ends there, because a second host, a second instrument and a second click all leave from the same
 * exit and only deepen it.
 */
final class QuoteService
{
    private const HOSTS = ['query1.finance.yahoo.com', 'query2.finance.yahoo.com'];

    /** The one path this class asks for, on Yahoo or on a passthrough that preserves it. */
    private const CHART_PATH = '/v8/finance/chart/';

    /** settings keys */
    private const ENDPOINT = 'quote_endpoint';
    private const TOKEN = 'quote_token';
    private const COOLDOWN = 'quote_cooldown_until';

    /** A status that means the exit is being rate limited, so asking again now only makes it worse. */
    private const THROTTLED = [429, 403];

    /** Limits apply after decompression, not merely to the bytes on the wire. */
    private const MAX_RESPONSE_BYTES = 8 * 1024 * 1024;
    private const MAX_TIMESTAMPS = 5000;

    /** Web requests leave room for rendering and for PHP's own execution-time limit. */
    private const WEB_REFRESH_BUDGET_SECONDS = 240;

    /**
     * 回報 needs ten years of daily bars, while a normal refresh only pulls six months. The deep range is
     * fetched when either the date horizon or daily high/low coverage is incomplete, then remembered so
     * the same thousands of unchanged rows are not downloaded on every pass.
     */
    private const HISTORY_DEEP_MONTHS = 115;

    /**
     * Prefix of the row holding, per instrument, how deep the last backfill got and when it ran.
     * Reaching HISTORY_DEEP_MONTHS is only one way to stop asking: a fund opened in 2020 has no bar
     * for 2017 and never will, so for those the question is whether the last attempt moved the oldest
     * bar at all. The value is "<oldest date reached>|<when it ran>" — the second half because a source
     * that once answered with a truncated payload must not be believed forever.
     */
    private const DEEP_MARK = 'deep_oldest_';

    /** Missing-high/low count and last deep attempt, so sources without OHLC are retried only periodically. */
    private const RANGE_MARK = 'deep_high_low_';

    /** 同一個結論信多久。九十天內不再重複那發最大的要求，久到足以發現來源後來補了資料。 */
    private const DEEP_RETRY_DAYS = 90;

    /** Exact-date follow-up around the oldest missing range, for bars outside a rolling ten-year response. */
    private const RANGE_EDGE_BEFORE_DAYS = 7;
    private const RANGE_EDGE_AFTER_DAYS = 45;

    /** @var array<string,mixed>|null */
    private static ?array $config = null;

    private static function config(): array
    {
        if (self::$config === null) {
            self::$config = require __DIR__ . '/config.php';
        }

        return self::$config;
    }

    /** @return array{ok:bool, error:?string, price:?float, high:?float, low:?float, prev:?float, adj:?float, currency:?string, as_of:?string, name:?string, exchange:?string, history: array<int, array{date:string, close:float, high:?float, low:?float, adj:?float}>} */
    public static function get(
        string $yahooSymbol,
        ?string $range = null,
        ?float $deadline = null,
        ?int $period1 = null,
        ?int $period2 = null
    ): array {
        $config = self::config();
        $result = [
            'ok' => false, 'error' => null, 'price' => null, 'high' => null, 'low' => null,
            'prev' => null, 'adj' => null,
            'currency' => null, 'as_of' => null, 'name' => null, 'exchange' => null, 'history' => [],
        ];

        $symbol = rawurlencode($yahooSymbol);
        if ($symbol === '' || $symbol === '%2A') {
            $result['error'] = '投資物代號不可為空。';

            return $result;
        }

        if (!$config['quote']['enabled'] || Settings::get('quote_source', 'yahoo') === 'off') {
            $result['error'] = '自動報價已在設定中關閉';

            return $result;
        }

        // 暫停時間到之前，一個要求都不發出去：被擋的時候每多打一發，都是把出口再往黑名單推一點。
        $left = self::cooldownLeft();
        if ($left > 0) {
            $result['error'] = self::cooldownMessage($left);

            return $result;
        }

        $range = $range ?? (string) $config['quote']['range'];
        $params = ['interval' => '1d', 'includePrePost' => 'false'];
        if ($period1 !== null || $period2 !== null) {
            if ($period1 === null || $period2 === null || $period1 < 1 || $period2 <= $period1) {
                $result['error'] = '歷史區間無效。';

                return $result;
            }
            // A rolling range=10y can start after old rows already in the database. Exact UTC bounds
            // let the high/low backfill reach those boundary bars without downloading decades of data.
            $params['period1'] = $period1;
            $params['period2'] = $period2;
        } else {
            $params['range'] = $range;
        }
        $lastError = null;

        foreach (self::bases() as $base) {
            $url = $base . self::CHART_PATH . $symbol . '?' . http_build_query($params);

            $reply = self::fetch(
                $url,
                (int) $config['quote']['timeout'],
                (string) $config['quote']['user_agent'],
                $deadline
            );
            if ($reply['body'] === null) {
                $lastError = $reply['error'];
                if (in_array($reply['status'], self::THROTTLED, true)) {
                    // 狀態碼寫在訊息裡：轉接位址的金鑰打錯也是一個 403，只報「暫停中」就看不出來是哪家的問題。
                    $result['error'] = sprintf(
                        '來源拒絕要求（HTTP %d），暫停抓取至 %s，到設定頁可以立刻解除。',
                        $reply['status'],
                        date('H:i', time() + self::throttle($reply['retry_after']))
                    );

                    return $result;
                }
                continue;
            }

            $body = $reply['body'];
            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                $lastError = '回應不是有效的 JSON';
                continue;
            }

            $errorNode = $decoded['chart']['error'] ?? null;
            $description = is_array($errorNode) ? self::text($errorNode['description'] ?? null, 200) : null;
            if ($description !== null && $description !== '') {
                $lastError = $description;
                continue;
            }

            $res = $decoded['chart']['result'][0] ?? null;
            if (!is_array($res)) {
                $lastError = '查不到這個投資物的資料';
                continue;
            }

            $parsed = self::parse($res);
            if (($parsed['ok'] ?? false) === true) {
                return $parsed;
            }
            $lastError = $parsed['error'] ?? '來源沒有回傳有效的報價';
            continue;
        }

        $result['error'] = $lastError ?? '無法連線到報價來源';

        return $result;
    }

    // ------------------------------------------------------- 來源位址與節流

    /**
     * Where the chart request goes. A configured passthrough is the only target — asking Yahoo again
     * after it refused this one would defeat the point of putting something in front of it.
     *
     * @return array<int,string> URL prefixes, without a trailing slash
     */
    public static function bases(): array
    {
        $endpoint = self::endpoint();
        if ($endpoint !== '') {
            $normalised = self::normaliseEndpoint($endpoint);

            return $normalised['ok'] ? [$normalised['endpoint']] : [];
        }

        $bases = [];
        foreach (self::HOSTS as $host) {
            $resolved = self::resolvePublicHost($host);
            if ($resolved['ok']) {
                $bases[] = 'https://' . $resolved['host'];
            }
        }

        return $bases;
    }

    /** 自行架設的轉接位址；留空就是直接打 Yahoo。 */
    public static function endpoint(): string
    {
        return trim((string) Settings::get(self::ENDPOINT, ''));
    }

    public static function setEndpoint(string $url): void
    {
        $normalised = self::normaliseEndpoint($url);
        if (!$normalised['ok']) {
            throw new UserError($normalised['error'] ?? '轉接位址無效。');
        }

        Settings::put(self::ENDPOINT, $normalised['endpoint']);
    }

    public static function token(): string
    {
        // 換行要去掉：設定值裡塞一個 \r\n 等於能憑空多加一個請求標頭。
        return (string) preg_replace('/[\x00-\x1F\x7F]/', '', (string) Settings::get(self::TOKEN, ''));
    }

    public static function setToken(string $token): void
    {
        Settings::put(self::TOKEN, $token);
    }

    /**
     * Validate and canonicalise a configured quote endpoint. DNS is checked here as well as immediately
     * before connecting: stored settings may predate this validation, and a name can change answers later.
     *
     * @return array{ok:bool, endpoint:string, error:?string}
     */
    public static function normaliseEndpoint(string $url, ?string $token = null): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['ok' => true, 'endpoint' => '', 'error' => null];
        }

        $invalid = static fn (string $error): array => ['ok' => false, 'endpoint' => '', 'error' => $error];
        if (preg_match('/[\p{Cc}\s]/u', $url) !== 0 || str_contains($url, '?') || str_contains($url, '#')) {
            return $invalid('轉接位址不可包含空白、控制字元、查詢參數或片段。');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return $invalid('轉接位址要寫成完整的 http:// 或 https:// 網址。');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return $invalid('轉接位址只支援 HTTP 或 HTTPS。');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return $invalid('轉接位址不可包含帳號或密碼。');
        }
        if (isset($parts['port']) && (!is_int($parts['port']) || $parts['port'] < 1 || $parts['port'] > 65535)) {
            return $invalid('轉接位址的連接埠無效。');
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path !== '' && $path !== '/') {
            return $invalid('轉接位址只接受來源根網址，不可包含報價路徑。');
        }
        if ($scheme === 'http' && ($token ?? self::token()) !== '') {
            return $invalid('已設定報價金鑰時，轉接位址必須使用 HTTPS。');
        }

        $resolved = self::resolvePublicHost((string) $parts['host']);
        if (!$resolved['ok']) {
            return $invalid($resolved['error'] ?? '無法驗證轉接位址的主機。');
        }

        $host = str_contains($resolved['host'], ':') ? '[' . $resolved['host'] . ']' : $resolved['host'];
        $endpoint = $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return ['ok' => true, 'endpoint' => $endpoint, 'error' => null];
    }

    /**
     * Resolve once, validate every address, and return the answers that CURLOPT_RESOLVE will pin for this
     * connection. This deliberately performs no HTTP request and can be exercised without a live quote host.
     *
     * @return array{ok:bool, host:string, addresses:array<int,string>, error:?string}
     */
    private static function resolvePublicHost(string $host): array
    {
        $fail = static fn (string $error): array => [
            'ok' => false, 'host' => '', 'addresses' => [], 'error' => $error,
        ];
        $host = trim($host);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if ($host === '' || strlen($host) > 253 || preg_match('/[\p{Cc}\s]/u', $host) !== 0) {
            return $fail('報價來源的主機名稱無效。');
        }

        $host = rtrim($host, '.');
        if ($host === '') {
            return $fail('報價來源的主機名稱無效。');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $packed = inet_pton($host);
            $canonical = is_string($packed) ? inet_ntop($packed) : $host;
            if (self::isBlockedAddress($host)) {
                return $fail('報價出口指向內部網路或保留位址，不允許。');
            }

            return ['ok' => true, 'host' => $canonical, 'addresses' => [$canonical], 'error' => null];
        }

        // curl/glibc also accept decimal, octal and hexadecimal one-number IPv4 spellings. They are not
        // DNS names, so reject them before dns_get_record() rather than letting their meaning depend on libc.
        if (preg_match('/(?:^|\.)(?:[0-9]+|0x[0-9a-f]+)$/i', $host) === 1) {
            return $fail('報價出口不可使用數字縮寫的網路位址。');
        }
        $lower = strtolower($host);
        if ($lower === 'localhost' || str_ends_with($lower, '.localhost')) {
            return $fail('報價出口指向內部網路位址，不允許。');
        }
        if (preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $host) !== 1) {
            return $fail('報價來源的主機名稱無效。');
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records) || $records === []) {
            return $fail('無法解析報價來源的主機名稱。');
        }

        $addresses = [];
        foreach ($records as $record) {
            $type = strtoupper((string) ($record['type'] ?? ''));
            if ($type === 'A') {
                $answer = $record['ip'] ?? null;
            } elseif ($type === 'AAAA') {
                $answer = $record['ipv6'] ?? null;
            } else {
                continue;
            }
            if (!is_string($answer) || filter_var($answer, FILTER_VALIDATE_IP) === false) {
                return $fail('報價來源回傳了無效的網路位址。');
            }
            if (self::isBlockedAddress($answer)) {
                return $fail('報價出口指向內部網路或保留位址，不允許。');
            }
            $packed = inet_pton($answer);
            $canonical = is_string($packed) ? inet_ntop($packed) : $answer;
            if (!in_array($canonical, $addresses, true)) {
                $addresses[] = $canonical;
            }
        }
        if ($addresses === []) {
            return $fail('報價來源沒有可用的網路位址。');
        }

        return ['ok' => true, 'host' => $lower, 'addresses' => $addresses, 'error' => null];
    }

    /** Fail closed for private, local and special-purpose addresses in either address family. */
    private static function isBlockedAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        $blocked = [
            '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
            '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
            '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
            '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
            '::/128', '::1/128', '::/96', '::ffff:0:0/96', '64:ff9b:1::/48',
            '100::/64', '2001::/32', '2001:2::/48', '2001:10::/28', '2001:20::/28',
            '2001:db8::/32', '2002::/16', '3fff::/20', 'fc00::/7', 'fe80::/10',
            'fec0::/10', 'ff00::/8',
        ];
        foreach ($blocked as $cidr) {
            if (self::inCidr($address, $cidr)) {
                return true;
            }
        }

        // A NAT64 address can smuggle a private IPv4 destination through its embedded last 32 bits.
        if (self::inCidr($address, '64:ff9b::/96')) {
            $packed = (string) inet_pton($address);
            $embedded = inet_ntop(substr($packed, 12, 4));

            return $embedded === false || self::isBlockedAddress($embedded);
        }

        return false;
    }

    private static function inCidr(string $address, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr, 2);
        $packed = @inet_pton($address);
        $prefix = @inet_pton($network);
        if (!is_string($packed) || !is_string($prefix) || strlen($packed) !== strlen($prefix)) {
            return false;
        }

        $bits = (int) $bits;
        $whole = intdiv($bits, 8);
        if (substr($packed, 0, $whole) !== substr($prefix, 0, $whole)) {
            return false;
        }
        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($packed[$whole]) & $mask) === (ord($prefix[$whole]) & $mask);
    }

    /** 還在暫停期間就回剩餘秒數，否則 0（順手把過期的記號清掉）。 */
    public static function cooldownLeft(): int
    {
        $until = (string) Settings::get(self::COOLDOWN, '');
        if ($until === '') {
            return 0;
        }

        $at = strtotime($until);
        $left = $at === false ? 0 : $at - time();
        if ($left <= 0) {
            self::clearCooldown();

            return 0;
        }

        return $left;
    }

    public static function clearCooldown(): void
    {
        Settings::put(self::COOLDOWN, '');
    }

    public static function cooldownMessage(int $left): string
    {
        return sprintf('來源暫停中，約 %d 分鐘後再抓（到設定頁可以立刻解除）。', max(1, (int) ceil($left / 60)));
    }

    /**
     * Starts (or extends) the pause and returns its length. `Retry-After` wins when the source bothered to
     * send one — it is the only number that reflects what the other side actually wants — but it is clamped
     * both ways, because a header can say anything and a personal tracker should not go silent for a day.
     */
    private static function throttle(?string $retryAfter): int
    {
        $config = self::config();
        $seconds = self::minutes('quote_cooldown_minutes', (int) $config['quote']['cooldown_minutes']) * 60;

        // Only the delta-seconds form is honoured; the HTTP-date spelling would need clock-skew handling
        // for a benefit this endpoint never actually uses.
        if ($retryAfter !== null && preg_match('/^\d+$/', trim($retryAfter)) === 1) {
            $seconds = (int) trim($retryAfter);
        }
        $seconds = max(60, min($seconds, 6 * 3600));

        Settings::put(self::COOLDOWN, date('Y-m-d H:i:s', time() + $seconds));

        return $seconds;
    }

    /**
     * A settings row beats config.php: this installation's php.ini belongs to the hosting panel and is
     * shared with other sites, so an environment variable is not a knob the operator can actually turn.
     */
    private static function minutes(string $key, int $default): int
    {
        $raw = Settings::get($key, '');

        return $raw !== '' && ctype_digit($raw) ? (int) $raw : $default;
    }

    /**
     * True when this instrument's price came off the source inside the window, so a second click of
     * 「更新報價」 does not become a second request. Closing prices move once a day; a manual price does
     * not count as an answer from the source, because a person typed it precisely because there was none.
     */
    private static function recentlyFetched(int $instrumentId, int $minutes): bool
    {
        $stamp = Database::scalar(
            "SELECT MAX(fetched_at) FROM prices WHERE instrument_id = ? AND source <> 'manual'",
            [$instrumentId]
        );

        return self::fresh(is_string($stamp) ? $stamp : null, $minutes);
    }

    /**
     * Timestamps are compared in PHP because they were written by PHP: SQLite's datetime('now') is UTC
     * whatever the app's clock says, which is the same trap the write path already avoids.
     */
    private static function fresh(?string $stamp, int $minutes): bool
    {
        if ($minutes <= 0 || $stamp === null || $stamp === '') {
            return false;
        }

        $at = strtotime($stamp);

        return $at !== false && time() - $at < $minutes * 60;
    }

    /** @return array{ok:bool, error:?string, price:?float, high:?float, low:?float, prev:?float, adj:?float, currency:?string, as_of:?string, name:?string, exchange:?string, history: array<int, array{date:string, close:float, high:?float, low:?float, adj:?float}>} */
    private static function parse(array $res): array
    {
        $meta = is_array($res['meta'] ?? null) ? $res['meta'] : [];
        $rawOffset = $meta['gmtoffset'] ?? 0;
        $offset = is_numeric($rawOffset) && is_finite((float) $rawOffset)
            ? max(-12 * 3600, min(14 * 3600, (int) $rawOffset))
            : 0;

        $name = self::text($meta['longName'] ?? null, 120);
        if ($name === null || $name === '') {
            $name = self::text($meta['shortName'] ?? null, 120);
        }
        $exchange = self::text($meta['exchangeName'] ?? null, 30);

        $out = [
            'ok'       => false,
            'error'    => null,
            'price'    => null,
            'high'     => null,
            'low'      => null,
            'prev'     => null,
            'adj'      => null,
            'currency' => self::currency($meta['currency'] ?? null),
            'as_of'    => null,
            'name'     => $name === '' ? null : $name,
            'exchange' => $exchange === null || $exchange === '' ? null : strtoupper($exchange),
            'history'  => [],
        ];

        $timestamps = $res['timestamp'] ?? [];
        if (!is_array($timestamps)) {
            $timestamps = [];
        }
        if (count($timestamps) > self::MAX_TIMESTAMPS) {
            $out['error'] = '來源回傳的歷史資料筆數超出上限';

            return $out;
        }

        $indicators = is_array($res['indicators'] ?? null) ? $res['indicators'] : [];
        $quoteNodes = is_array($indicators['quote'] ?? null) ? $indicators['quote'] : [];
        $quoteNode = is_array($quoteNodes[0] ?? null) ? $quoteNodes[0] : [];
        $closes = is_array($quoteNode['close'] ?? null) ? $quoteNode['close'] : [];
        $highs = is_array($quoteNode['high'] ?? null) ? $quoteNode['high'] : [];
        $lows = is_array($quoteNode['low'] ?? null) ? $quoteNode['low'] : [];
        // adjclose 是除息／除權調整後的價格，也就是「配息有續投」的那條線。它和 close 的位置一一對應，
        // 但來源不一定給（加密貨幣、部分外匯沒有），沒給就留 NULL，不要拿 close 冒充。
        $adjustedNodes = is_array($indicators['adjclose'] ?? null) ? $indicators['adjclose'] : [];
        $adjustedNode = is_array($adjustedNodes[0] ?? null) ? $adjustedNodes[0] : [];
        $adjusted = is_array($adjustedNode['adjclose'] ?? null) ? $adjustedNode['adjclose'] : [];

        foreach ($timestamps as $i => $ts) {
            $timestamp = self::timestamp($ts);
            $close = self::positiveNumber($closes[$i] ?? null);
            if ($timestamp === null || $close === null) {
                continue;
            }
            [$high, $low] = self::dailyRange($highs[$i] ?? null, $lows[$i] ?? null, $close);
            $out['history'][] = [
                'date' => gmdate('Y-m-d', $timestamp + $offset),
                'close' => $close,
                'high' => $high,
                'low' => $low,
                'adj' => self::positiveNumber($adjusted[$i] ?? null),
            ];
        }

        $price = self::positiveNumber($meta['regularMarketPrice'] ?? null);
        if ($price !== null) {
            $out['price'] = $price;
            $marketTime = self::timestamp($meta['regularMarketTime'] ?? null);
            if ($marketTime !== null) {
                $out['as_of'] = gmdate('Y-m-d', $marketTime + $offset);
            }
            $lastHistory = $out['history'][count($out['history']) - 1] ?? null;
            if ($out['as_of'] === null && $lastHistory !== null) {
                $out['as_of'] = $lastHistory['date'];
            }
            if ($lastHistory !== null && $lastHistory['date'] === $out['as_of']) {
                // 這根被 pop 掉的就是今天，當天的 adjclose 與高低價一起帶走，不能讓現價那列缺資料。
                $out['adj'] = $lastHistory['adj'];
                $out['high'] = $lastHistory['high'];
                $out['low'] = $lastHistory['low'];
                array_pop($out['history']);
            }
        } elseif ($out['history'] !== []) {
            $last = $out['history'][count($out['history']) - 1];
            $out['price'] = $last['close'];
            $out['adj'] = $last['adj'];
            $out['high'] = $last['high'];
            $out['low'] = $last['low'];
            $out['as_of'] = $last['date'];
            array_pop($out['history']);
        }

        // Some Yahoo responses omit today's quote arrays but still expose its range in meta. Use that
        // only when the bar itself did not provide a coherent range; never invent high/low from close.
        [$rangeHigh, $rangeLow] = self::dailyRange($out['high'], $out['low'], $out['price']);
        if ($rangeHigh === null) {
            [$rangeHigh, $rangeLow] = self::dailyRange(
                $meta['regularMarketDayHigh'] ?? null,
                $meta['regularMarketDayLow'] ?? null,
                $out['price']
            );
        }
        $out['high'] = $rangeHigh;
        $out['low'] = $rangeLow;

        if ($out['price'] === null) {
            $out['error'] = '來源沒有回傳有效的價格';
        } elseif ($out['as_of'] === null) {
            $out['error'] = '來源沒有回傳有效的價格日期';
        } else {
            $out['prev'] = self::positiveNumber(
                $out['history'][count($out['history']) - 1]['close'] ?? $meta['chartPreviousClose'] ?? null
            );
            $out['ok'] = true;
        }

        return $out;
    }

    /** Scalar text only, with controls removed before trimming and applying the character limit. */
    private static function text(mixed $value, int $max): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $original = (string) $value;
        $text = preg_replace('/\p{Cc}/u', '', $original);
        if ($text === null) {
            $scrubbed = mb_scrub($original, 'UTF-8');
            $text = preg_replace('/\p{Cc}/u', '', $scrubbed);
        }
        if (!is_string($text)) {
            return null;
        }

        return mb_substr(trim($text), 0, max(0, $max), 'UTF-8');
    }

    private static function currency(mixed $value): ?string
    {
        $code = self::text($value, 32);
        if ($code === null) {
            return null;
        }
        $code = strtoupper($code);

        return preg_match('/^[A-Z]{2,5}$/D', $code) === 1 ? $code : null;
    }

    private static function positiveNumber(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return is_finite($number) && $number > 0.0 ? $number : null;
    }

    /** @return array{0:?float, 1:?float} */
    private static function dailyRange(mixed $high, mixed $low, mixed $close): array
    {
        $high = self::positiveNumber($high);
        $low = self::positiveNumber($low);
        $close = self::positiveNumber($close);
        if ($high === null || $low === null || $close === null
            || $high < $low || $high < $close || $low > $close) {
            return [null, null];
        }

        return [$high, $low];
    }

    private static function timestamp(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }
        $timestamp = (float) $value;
        if (!is_finite($timestamp) || $timestamp < 631152000 || $timestamp > time() + 86400) {
            return null;
        }

        return (int) $timestamp;
    }

    private static function validDate(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }
        if (!checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            return null;
        }

        return $value;
    }

    /**
     * Atomically store one current quote and its bounded history. No network, FX or settings work belongs
     * here; the caller can refresh FX after this transaction commits and use the returned currency.
     *
     * @throws UserError
     */
    public static function writeQuote(int $instrumentId, array $quote, ?string $fetchedAt = null): string
    {
        if (($quote['ok'] ?? false) !== true) {
            throw new UserError('來源沒有回傳有效的報價。');
        }
        $price = self::positiveNumber($quote['price'] ?? null);
        $asOf = self::validDate($quote['as_of'] ?? null);
        if ($price === null || $asOf === null) {
            throw new UserError('報價缺少有效的價格或日期。');
        }
        [$currentHigh, $currentLow] = self::dailyRange($quote['high'] ?? null, $quote['low'] ?? null, $price);

        $pdo = Database::pdo();
        if ($pdo->inTransaction()) {
            throw new UserError('寫入報價前不可已有交易。');
        }
        $instrument = Database::one('SELECT currency FROM instruments WHERE id = ?', [$instrumentId]);
        if ($instrument === null) {
            throw new UserError('投資物不存在。');
        }

        $instrumentCurrency = self::currency($instrument['currency'] ?? null);
        $sourceCurrency = self::currency($quote['currency'] ?? null);
        if ($instrumentCurrency === null) {
            throw new UserError('投資物的幣別缺少有效值。');
        }
        if ($sourceCurrency !== null && $sourceCurrency !== $instrumentCurrency) {
            throw new UserError(sprintf(
                '報價來源幣別為 %s，與投資物設定的 %s 不同；為避免混用幣別，已拒絕寫入。請先確認投資物幣別。',
                $sourceCurrency,
                $instrumentCurrency
            ));
        }
        $currency = $instrumentCurrency;
        $history = $quote['history'] ?? [];
        if (!is_array($history)) {
            throw new UserError('報價歷史資料格式無效。');
        }
        if (count($history) > self::MAX_TIMESTAMPS) {
            throw new UserError('報價歷史資料筆數超出上限。');
        }
        if ($fetchedAt !== null
            && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $fetchedAt) !== 1) {
            throw new UserError('報價取得時間格式無效。');
        }
        $now = $fetchedAt ?? now_stamp();
        $incoming = [];
        $incomingDates = [];
        foreach ($history as $bar) {
            if (!is_array($bar)) {
                continue;
            }
            $date = self::validDate($bar['date'] ?? null);
            $close = self::positiveNumber($bar['close'] ?? null);
            if ($date === null || $close === null) {
                continue;
            }
            [$high, $low] = self::dailyRange($bar['high'] ?? null, $bar['low'] ?? null, $close);
            $incoming[] = [
                'date'  => $date,
                'close' => $close,
                'high'  => $high,
                'low'   => $low,
                'adj'   => self::positiveNumber($bar['adj'] ?? null),
            ];
            $incomingDates[$date] = true;
        }

        try {
            $pdo->beginTransaction();
            $current = $pdo->prepare(
                'INSERT INTO prices (instrument_id, as_of, close, high, low, prev_close, adj_close, currency, source, fetched_at)
                 VALUES (:iid, :as_of, :close, :high, :low, :prev, :adj, :currency, \'yahoo\', :now)
                 ON CONFLICT(instrument_id, as_of) DO UPDATE SET
                   close = excluded.close,
                   high = COALESCE(excluded.high, prices.high),
                   low = COALESCE(excluded.low, prices.low),
                   prev_close = excluded.prev_close,
                   adj_close = COALESCE(excluded.adj_close, prices.adj_close),
                   currency = excluded.currency, source = excluded.source, fetched_at = excluded.fetched_at'
            );
            $current->execute([
                'iid' => $instrumentId,
                'as_of' => $asOf,
                'close' => $price,
                'high' => $currentHigh,
                'low'  => $currentLow,
                'prev' => self::positiveNumber($quote['prev'] ?? null),
                'adj' => self::positiveNumber($quote['adj'] ?? null),
                'currency' => $currency,
                'now' => $now,
            ]);

            if ($incoming !== []) {
                // Read only dates present in this payload, in bounded chunks. A deep refetch does not
                // need to materialise every historical bar merely to discover which rows are unchanged.
                $existingBars = [];
                foreach (array_chunk(array_keys($incomingDates), 400) as $dates) {
                    $holders = implode(',', array_fill(0, count($dates), '?'));
                    $existing = $pdo->prepare(
                        "SELECT as_of, close, high, low, adj_close, currency FROM prices
                         WHERE instrument_id = ? AND as_of IN ($holders)"
                    );
                    $existing->execute([$instrumentId, ...$dates]);
                    foreach ($existing as $bar) {
                        $existingBars[(string) $bar['as_of']] = $bar;
                    }
                }

                $bars = $pdo->prepare(
                    'INSERT INTO prices (instrument_id, as_of, close, high, low, adj_close, currency, source, fetched_at)
                     VALUES (:iid, :as_of, :close, :high, :low, :adj, :currency, \'yahoo\', :now)
                     ON CONFLICT(instrument_id, as_of) DO UPDATE SET
                       close = excluded.close,
                       high = COALESCE(excluded.high, prices.high),
                       low = COALESCE(excluded.low, prices.low),
                       adj_close = COALESCE(excluded.adj_close, prices.adj_close),
                       currency = excluded.currency, fetched_at = excluded.fetched_at'
                );
                foreach ($incoming as $bar) {
                    $date = $bar['date'];
                    $close = $bar['close'];
                    $adj = $bar['adj'];
                    [$high, $low] = [$bar['high'], $bar['low']];
                    $prior = $existingBars[$date] ?? null;
                    if ($prior !== null) {
                        $priorAdj = $prior['adj_close'] === null ? null : (float) $prior['adj_close'];
                        $priorHigh = $prior['high'] === null ? null : (float) $prior['high'];
                        $priorLow = $prior['low'] === null ? null : (float) $prior['low'];
                        $effectiveAdj = $adj ?? $priorAdj;
                        $effectiveHigh = $high ?? $priorHigh;
                        $effectiveLow = $low ?? $priorLow;
                        if ((float) $prior['close'] === $close
                            && $effectiveAdj === $priorAdj
                            && $effectiveHigh === $priorHigh
                            && $effectiveLow === $priorLow
                            && (string) $prior['currency'] === $currency) {
                            continue;
                        }
                    }

                    $bars->execute([
                        'iid' => $instrumentId,
                        'as_of' => $date,
                        'close' => $close,
                        'high' => $high,
                        'low' => $low,
                        'adj' => $adj,
                        'currency' => $currency,
                        'now' => $now,
                    ]);
                    // Keep duplicate dates in one payload consistent with the sequential upserts they
                    // replace: a later missing adj_close retains the adjusted close already selected.
                    $existingBars[$date] = [
                        'close' => $close,
                        'high' => $high ?? ($prior['high'] ?? null),
                        'low' => $low ?? ($prior['low'] ?? null),
                        'adj_close' => $adj ?? ($prior['adj_close'] ?? null),
                        'currency' => $currency,
                    ];
                }
            }

            $name = self::text($quote['name'] ?? null, 120);
            if ($name !== null && $name !== '') {
                $pdo->prepare("UPDATE instruments SET name = :name WHERE id = :id AND (name = '' OR name IS NULL)")
                    ->execute(['name' => $name, 'id' => $instrumentId]);
            }
            $exchange = self::text($quote['exchange'] ?? null, 30);
            if ($exchange !== null && $exchange !== '') {
                $pdo->prepare("UPDATE instruments SET exchange = :ex WHERE id = :id AND exchange = ''")
                    ->execute(['ex' => $exchange, 'id' => $instrumentId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (\Throwable) {
                    // Preserve the user-facing failure even if SQLite also rejects the rollback.
                }
            }
            throw new UserError('報價寫入失敗，請稍後再試。');
        }

        return $currency;
    }

    /** @return array{body:?string, error:?string, status:int, retry_after:?string} */
    private static function fetch(string $url, int $timeout, string $userAgent, ?float $deadline = null): array
    {
        $timeout = self::requestTimeout($timeout, $deadline);
        if ($timeout === null) {
            return ['body' => null, 'error' => '本次報價更新已達時間上限。', 'status' => 0, 'retry_after' => null];
        }
        if (!function_exists('curl_init')) {
            // The stream wrapper cannot pin DNS answers, so using it here would reopen the SSRF/rebind hole.
            return ['body' => null, 'error' => '此伺服器未啟用 cURL，無法安全取得報價。', 'status' => 0, 'retry_after' => null];
        }
        if (preg_match('/[\p{Cc}\s]/u', $url) !== 0) {
            return ['body' => null, 'error' => '報價網址格式無效。', 'status' => 0, 'retry_after' => null];
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return ['body' => null, 'error' => '報價網址格式無效。', 'status' => 0, 'retry_after' => null];
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['body' => null, 'error' => '報價來源只支援 HTTP 或 HTTPS。', 'status' => 0, 'retry_after' => null];
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!is_int($port) || $port < 1 || $port > 65535) {
            return ['body' => null, 'error' => '報價來源的連接埠無效。', 'status' => 0, 'retry_after' => null];
        }

        // Validate again at request time and pin these exact answers. A stored endpoint or a DNS rebind
        // between bases() and curl_exec() therefore cannot redirect the connection to a private address.
        $resolved = self::resolvePublicHost((string) $parts['host']);
        if (!$resolved['ok']) {
            return ['body' => null, 'error' => $resolved['error'] ?? '無法驗證報價來源。', 'status' => 0, 'retry_after' => null];
        }

        $headers = ['Accept: application/json', 'User-Agent: ' . (self::text($userAgent, 512) ?? '')];
        $token = self::token();
        $directYahoo = in_array($resolved['host'], self::HOSTS, true);
        if ($token !== '' && !$directYahoo) {
            if ($scheme !== 'https') {
                return ['body' => null, 'error' => '已設定報價金鑰時，只能使用 HTTPS 來源。', 'status' => 0, 'retry_after' => null];
            }
            $headers[] = 'X-Quote-Token: ' . $token;
        }

        $retryAfter = null;
        $body = '';
        $tooLarge = false;
        $ch = curl_init($url);
        if ($ch === false) {
            return ['body' => null, 'error' => '無法建立安全的報價連線。', 'status' => 0, 'retry_after' => null];
        }
        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_MAXFILESIZE    => self::MAX_RESPONSE_BYTES,
            CURLOPT_ENCODING       => 'gzip, deflate',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY          => '',
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => self::text($userAgent, 512) ?? '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retryAfter): int {
                if (preg_match('/^retry-after:\s*(.+)\R$/i', $line, $m) === 1) {
                    $retryAfter = mb_substr(trim($m[1]), 0, 128, 'UTF-8');
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$tooLarge): int {
                if ($tooLarge) {
                    return 0;
                }
                if (strlen($chunk) > self::MAX_RESPONSE_BYTES - strlen($body)) {
                    $tooLarge = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ];
        if (filter_var($resolved['host'], FILTER_VALIDATE_IP) === false) {
            $options[CURLOPT_RESOLVE] = array_map(
                static fn (string $address): string => $resolved['host'] . ':' . $port . ':' . $address,
                $resolved['addresses']
            );
        }
        if (!curl_setopt_array($ch, $options)) {
            curl_close($ch);

            return ['body' => null, 'error' => '無法設定安全的報價連線。', 'status' => 0, 'retry_after' => null];
        }

        $executed = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($tooLarge) {
            return ['body' => null, 'error' => '報價來源回應超過 8 MiB 上限。', 'status' => $status, 'retry_after' => $retryAfter];
        }
        if ($executed === false) {
            return ['body' => null, 'error' => '連線失敗：' . self::describeCurlError($err),
                'status' => $status, 'retry_after' => $retryAfter];
        }
        if ($status >= 400) {
            return ['body' => null, 'error' => "來源回覆 HTTP {$status}",
                'status' => $status, 'retry_after' => $retryAfter];
        }

        return ['body' => $body, 'error' => null, 'status' => $status, 'retry_after' => $retryAfter];
    }

    private static function requestTimeout(int $timeout, ?float $deadline): ?int
    {
        $timeout = max(2, min(30, $timeout));
        if ($deadline === null) {
            return $timeout;
        }
        $remaining = $deadline - microtime(true);
        if ($remaining < 2.0) {
            return null;
        }

        return max(2, min($timeout, (int) floor($remaining)));
    }

    /** Windows PHP ships without a CA bundle, so TLS failures are the most common setup problem. */
    private static function describeCurlError(string $err): string
    {
        $err = self::text($err, 300) ?? '';
        if (stripos($err, 'ssl') !== false || stripos($err, 'certificate') !== false
            || stripos($err, 'trust anchors') !== false || str_starts_with(strtolower($err), 'ca')) {
            return $err . '（PHP 找不到 CA 憑證檔：在 php.ini 設定 curl.cainfo 指向 cacert.pem，'
                . '可由 https://curl.se/ca/cacert.pem 下載）';
        }

        return $err;
    }

    /**
     * Refresh every instrument that belongs to the portfolio (or all instruments when null).
     *
     * @return array{updated:int, failed:int, skipped:int, history:int, history_failed:int, errors: array<int,string>, fx: array{updated:int, skipped:int, failed: array<int,string>}}
     */
    public static function refresh(?int $portfolioId = null): array
    {
        $deadline = self::refreshDeadline();
        $config = self::config();
        $sql = $portfolioId === null
            ? 'SELECT * FROM instruments ORDER BY market, symbol'
            : 'SELECT DISTINCT i.* FROM instruments i
               JOIN transactions t ON t.instrument_id = i.id
               WHERE t.portfolio_id = :pid ORDER BY i.market, i.symbol';

        $instruments = Database::all($sql, $portfolioId === null ? [] : ['pid' => $portfolioId]);

        $updated = 0;
        $failed = 0;
        $skipped = 0;
        $errors = [];
        $historyBars = 0;
        $historyFailed = 0;
        $deepErrors = [];
        $asked = 0;

        $minAge = self::minutes('quote_min_refresh_minutes', (int) $config['quote']['min_refresh_minutes']);
        $gap = max(0, (int) $config['quote']['gap_ms']);
        $total = count($instruments);

        foreach ($instruments as $n => $inst) {
            // 暫停是這一整輪的結論，不是這一檔的：換下一個投資物、換下一個網域，出去的還是同一個出口，
            // 打下去只會把暫停拖得更長。沒試過的全部算略過，一次講完。
            $left = self::cooldownLeft();
            if ($left > 0) {
                $skipped += $total - $n;
                $errors[] = self::cooldownMessage($left);
                break;
            }
            if (!self::hasBudget($deadline)) {
                $remaining = $total - $n;
                $skipped += $remaining;
                $errors[] = sprintf('本次報價更新已達時間上限，尚有 %d 檔投資物未處理。', $remaining);
                break;
            }

            // 暫停要擋的是「同一檔在幾分鐘內被問兩次」，不是「欠的歷史一直補不進來」：十年深抓還沒成功的
            // 投資物一定要放行。少了這一句，新建立的標的會先假裝「資料不足」整整一個排程週期，而且
            // 那一輪的回報裡半個錯誤訊息都不会有（正式區實測過：七檔新 ETF 各只存進 128 根）。
            if (!self::deepOwed((int) $inst['id'], self::oldestBar((int) $inst['id']))
                && self::recentlyFetched((int) $inst['id'], $minAge)) {
                $skipped++;
                continue;
            }

            if ($asked > 0 && $gap > 0) {
                $sleep = $gap * 1000;
                if ($deadline !== null) {
                    $sleep = min($sleep, max(0, (int) (($deadline - microtime(true) - 2.0) * 1000000)));
                }
                if ($sleep > 0) {
                    usleep($sleep);
                }
            }
            $asked++;

            $quote = self::get((string) $inst['yahoo_symbol'], null, $deadline);
            if (!$quote['ok']) {
                $failed++;
                $errors[] = sprintf('%s（%s）：%s', $inst['symbol'], $inst['yahoo_symbol'], $quote['error']);
                continue;
            }

            try {
                $currency = self::writeQuote((int) $inst['id'], $quote);
            } catch (UserError $e) {
                $failed++;
                $errors[] = sprintf('%s（%s）：%s', $inst['symbol'], $inst['yahoo_symbol'], $e->getMessage());
                continue;
            }

            $deep = self::syncHistory((int) $inst['id'], (string) $inst['yahoo_symbol'], $deadline);
            $historyBars += $deep['bars'];
            if ($deep['error'] !== null) {
                $historyFailed++;
                $yahoo = (string) $inst['yahoo_symbol'];
                $symbol = (string) $inst['symbol'];
                $deepErrors[(string) $deep['error']][] = $yahoo !== '' && strcasecmp($yahoo, $symbol) !== 0
                    ? $symbol . '（' . $yahoo . '）'
                    : $symbol;
            }

            $updated++;
        }

        // 同一個原因只講一次：來源整個掛掉時，31 行一模樣的錯誤會把閃訊淹死，
        // 而那條正是這輪最該一眼看到的訊息。
        foreach ($deepErrors as $reason => $who) {
            $errors[] = count($who) === 1
                ? sprintf('%s 的十年歷史沒補上：%s', $who[0], $reason)
                : sprintf('%d 檔的十年歷史沒補上（%s）：%s', count($who), $reason,
                    implode('、', array_slice($who, 0, 8)) . (count($who) > 8 ? '…' : ''));
        }

        $currencies = self::usedCurrencies($portfolioId);
        $fx = self::refreshFx($currencies, $deadline);

        if ($updated > 0 || $fx['updated'] > 0) {
            // Nothing landed, so there is no reason to tell the pages a refresh just happened.
            Settings::put('last_refresh', date('Y-m-d H:i:s'));
        }

        return ['updated' => $updated, 'failed' => $failed, 'skipped' => $skipped, 'history' => $historyBars,
            'history_failed' => $historyFailed,
            'errors' => array_values(array_unique($errors)), 'fx' => $fx];
    }

    /** CLI cron jobs keep their existing unlimited pass; only the web request gets a hard wall-clock budget. */
    private static function refreshDeadline(): ?float
    {
        if (PHP_SAPI === 'cli') {
            return null;
        }

        $budget = self::WEB_REFRESH_BUDGET_SECONDS;
        $maxExecution = (int) ini_get('max_execution_time');
        if ($maxExecution > 0) {
            $reserve = max(10, min(60, (int) ceil($maxExecution * 0.2)));
            $budget = max(1, $maxExecution - $reserve);
        }
        $configured = trim((string) (getenv('QUOTE_REFRESH_BUDGET') ?: ''));
        if ($configured === '') {
            $configured = trim((string) Settings::get('quote_refresh_budget_seconds', ''));
        }
        if ($configured === '') {
            $configured = trim((string) (self::config()['quote']['refresh_budget_seconds'] ?? ''));
        }
        if (ctype_digit($configured) && (int) $configured > 0) {
            $budget = min($budget, (int) $configured);
        }

        return microtime(true) + max(1, $budget);
    }

    private static function hasBudget(?float $deadline): bool
    {
        return $deadline === null || $deadline - microtime(true) >= 2.0;
    }

    /**
     * Top one instrument's daily bars up to roughly ten years, but only when it is not already deep
     * enough. The first refresh after an upgrade therefore costs one extra download per instrument,
     * and every refresh after that costs nothing.
     *
     * "Deep enough" has two halves, and both live in {@see self::deepOwed()} so the pause in refresh()
     * cannot disagree with the question asked here. The obvious half is reaching the horizon 回報 needs.
     * The other is knowing when reaching it is impossible: a fund that opened in 2020 has no bar for
     * 2017 and never will, so asking again every pass buys a few thousand identical rows, a few hundred
     * kilobytes through the passthrough, and a write transaction over rows that did not change. The last
     * attempt's oldest date is kept for that — same date means the source has nothing earlier.
     *
     * @return array{bars:int, error:?string} error is set only when a backfill was owed and the source
     *         did not answer: skipping because the history is already deep enough is not a failure.
     */
    private static function syncHistory(int $iid, string $yahooSymbol, ?float $deadline = null): array
    {
        $config = self::config();
        $oldest = self::oldestBar($iid);
        if (!self::deepOwed($iid, $oldest)) {
            return ['bars' => 0, 'error' => null];
        }
        if (!self::hasBudget($deadline)) {
            return ['bars' => 0, 'error' => '本次已達時間上限，未繼續補齊歷史'];
        }

        $quote = self::get($yahooSymbol, (string) $config['quote']['history_range'], $deadline);
        if (!$quote['ok']) {
            // 打不通與「沒有更早的資料」是兩件事：只有後者才值得記下來，否則一次網路抖動就會讓
            // 那個代號再也沒補過。但抖動本身要回報出去，不然這一檔會一直假裝「資料不足」沒人有感。
            return ['bars' => 0, 'error' => (string) $quote['error']];
        }
        if ($quote['history'] === []) {
            return ['bars' => 0, 'error' => '來源回了零根日線'];
        }

        $bars = $quote['history'];
        try {
            self::writeQuote($iid, $quote);
        } catch (UserError $e) {
            return ['bars' => 0, 'error' => $e->getMessage()];
        }

        $fetchedBars = count($bars);
        $edge = self::syncOldestMissingRange($iid, $yahooSymbol, $deadline);
        $fetchedBars += $edge['bars'];
        if ($edge['error'] !== null) {
            // The deep answer landed, but its rolling start date may have left old rows behind. Do not
            // settle the range marker after a failed exact-date retry; the next pass must ask again.
            Settings::forget(self::RANGE_MARK . $iid);

            return ['bars' => $fetchedBars, 'error' => $edge['error']];
        }

        // 剛寫進去的最早一根，就是這個代號目前到得了的地方；Yahoo 的回覆順序不保證，所以逐根比。
        $reached = (string) $oldest;
        foreach ($bars as $bar) {
            $date = (string) $bar['date'];
            if ($reached === '' || strcmp($date, $reached) < 0) {
                $reached = $date;
            }
        }
        Settings::put(self::DEEP_MARK . $iid, $reached . '|' . date('Y-m-d H:i:s'));
        self::rememberRangeCoverage($iid);

        return ['bars' => $fetchedBars, 'error' => null];
    }

    /**
     * Fill the oldest remaining high/low gap with a small exact-date request. Yahoo's range=10y boundary
     * moves forward every day, so an older database can retain a few pre-boundary rows that no subsequent
     * rolling deep request will ever contain again.
     *
     * @return array{bars:int, error:?string}
     */
    private static function syncOldestMissingRange(
        int $iid,
        string $yahooSymbol,
        ?float $deadline
    ): array {
        $oldestMissing = Database::scalar(
            "SELECT MIN(as_of) FROM prices WHERE instrument_id = ? AND source <> 'manual'
             AND (high IS NULL OR low IS NULL)",
            [$iid]
        );
        if (!is_string($oldestMissing) || $oldestMissing === '') {
            return ['bars' => 0, 'error' => null];
        }
        if (!self::hasBudget($deadline)) {
            return ['bars' => 0, 'error' => '本次已達時間上限，未補齊最早缺漏的高低價'];
        }

        $start = strtotime($oldestMissing . ' 00:00:00 UTC');
        if ($start === false) {
            return ['bars' => 0, 'error' => '最早缺漏日期無效'];
        }
        $period1 = max(1, $start - self::RANGE_EDGE_BEFORE_DAYS * 86400);
        $period2 = $start + (self::RANGE_EDGE_AFTER_DAYS + 1) * 86400;
        $quote = self::get($yahooSymbol, null, $deadline, $period1, $period2);
        if (!$quote['ok']) {
            return ['bars' => 0, 'error' => (string) $quote['error']];
        }
        if ($quote['history'] === []) {
            return ['bars' => 0, 'error' => '最早缺漏區間沒有日線'];
        }

        try {
            self::writeQuote($iid, $quote);
        } catch (UserError $e) {
            return ['bars' => 0, 'error' => $e->getMessage()];
        }

        return ['bars' => count($quote['history']), 'error' => null];
    }

    /** 這一檔現在最早的一根日線；資料庫裡還沒有任何非人工日線時 null。 */
    private static function oldestBar(int $iid): ?string
    {
        $oldest = Database::scalar('SELECT MIN(as_of) FROM prices WHERE instrument_id = ?', [$iid]);

        return is_string($oldest) && $oldest !== '' ? $oldest : null;
    }

    /**
     * 這一檔還欠一次十年深抓嗎？refresh() 用它決定「剛剛抓過」那道暫停能不能放行，syncHistory() 用
     * 同一個判斷決定要不要發問——兩邊一定要講同一句話，否則欠歷史的投資物會被暫停一路推後，
     * 而畫面上完全看不出來。
     */
    private static function deepOwed(int $iid, ?string $oldest): bool
    {
        if ($oldest === null) {
            return true;
        }

        $deepEnough = date('Y-m-d', strtotime('-' . self::HISTORY_DEEP_MONTHS . ' months'));
        $dateOwed = strcmp($oldest, $deepEnough) > 0 && !self::historySettled($iid, $oldest);

        return $dateOwed || self::rangeOwed($iid);
    }

    private static function missingRangeCount(int $iid): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM prices WHERE instrument_id = ? AND source <> 'manual'
             AND (high IS NULL OR low IS NULL)",
            [$iid],
            0
        );
    }

    /** Existing ten-year databases still need one deep pass to fill the newly stored daily ranges. */
    private static function rangeOwed(int $iid): bool
    {
        $missing = self::missingRangeCount($iid);
        if ($missing === 0) {
            return false;
        }

        $mark = Settings::get(self::RANGE_MARK . $iid);
        [$count, $stamp] = array_pad(explode('|', $mark, 2), 2, '');
        if ($count === (string) $missing) {
            $at = $stamp === '' ? false : strtotime($stamp);

            return $at === false || $at <= time() - self::DEEP_RETRY_DAYS * 86400;
        }

        return true;
    }

    private static function rememberRangeCoverage(int $iid): void
    {
        Settings::put(
            self::RANGE_MARK . $iid,
            self::missingRangeCount($iid) . '|' . date('Y-m-d H:i:s')
        );
    }

    /**
     * 「這個代號的歷史就到那兒了」這句話還作數嗎？只在他講的是同一根最早日線、而且還在有效期內的時候。
     * 章是 PHP 蓋的（跟 prices.fetched_at 同一顆鐘），所以這裡用 strtotime 比，不進 SQLite 的 datetime()。
     */
    private static function historySettled(int $iid, string $oldest): bool
    {
        $mark = Settings::get(self::DEEP_MARK . $iid);
        if ($mark === '') {
            return false;
        }

        [$reached, $stamp] = array_pad(explode('|', $mark, 2), 2, '');
        if ($reached !== $oldest) {
            return false;
        }
        $at = $stamp === '' ? false : strtotime($stamp);

        // 讀不懂的標記一律當「還得再問」：多發一次要求，換的是不會有個代號悄悄少了幾十年。
        return $at !== false && $at > time() - self::DEEP_RETRY_DAYS * 86400;
    }

    /** 投資物被移除時，它那份「歷史到底了」的記號跟著走：id 會被新的投資物重用。 */
    public static function forgetHistoryMark(int $iid): void
    {
        Settings::forget(self::DEEP_MARK . $iid);
        Settings::forget(self::RANGE_MARK . $iid);
    }

    /** @return array<int,string> */
    public static function usedCurrencies(?int $portfolioId = null): array
    {
        $sql = $portfolioId === null
            ? 'SELECT DISTINCT currency FROM instruments'
            : 'SELECT DISTINCT i.currency FROM instruments i
               JOIN transactions t ON t.instrument_id = i.id WHERE t.portfolio_id = :pid';
        $rows = Database::all($sql, $portfolioId === null ? [] : ['pid' => $portfolioId]);

        $list = array_values(array_unique(array_map(
            static fn (array $r): string => strtoupper((string) $r['currency']),
            $rows
        )));
        $list[] = Settings::baseCurrency();

        return array_values(array_unique(array_filter($list)));
    }

    /**
     * @param array<int,string> $currencies
     * @return array{updated:int, skipped:int, failed: array<int,string>}
     */
    public static function refreshFx(array $currencies, ?float $deadline = null, bool $force = false): array
    {
        $config = self::config();
        $minAge = self::minutes('quote_min_refresh_minutes', (int) $config['quote']['min_refresh_minutes']);
        $updated = 0;
        $skipped = 0;
        $failed = [];
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'INSERT INTO fx_rates (currency, units_per_usd, as_of, source, manual, fetched_at)
             VALUES (:c, :rate, :as_of, \'yahoo\', 0, :now)
             ON CONFLICT(currency) DO UPDATE SET
               units_per_usd = excluded.units_per_usd, as_of = excluded.as_of,
               source = \'yahoo\', manual = 0, fetched_at = excluded.fetched_at
             WHERE fx_rates.manual = 0'
        );

        foreach ($currencies as $code) {
            $code = self::currency($code);
            if ($code === null || $code === 'USD') {
                continue;
            }

            // 鎖成手動的幣別不該每輪都占用一次要求：抓回來的數值本來就不會蓋上去。
            $row = Database::one('SELECT fetched_at, manual FROM fx_rates WHERE currency = ?', [$code]);
            if (is_array($row)
                && !$force
                && ((int) $row['manual'] === 1 || self::fresh((string) ($row['fetched_at'] ?? ''), $minAge))) {
                $skipped++;
                continue;
            }
            if (!self::hasBudget($deadline)) {
                $failed[] = $code;
                continue;
            }

            $quote = self::get($code . '=X', '5d', $deadline);
            $price = self::positiveNumber($quote['price'] ?? null);
            $asOf = self::validDate($quote['as_of'] ?? null);
            if (!$quote['ok'] || $price === null || $asOf === null
                || strtoupper((string) ($quote['currency'] ?? '')) !== $code) {
                $failed[] = $code;
                continue;
            }

            $stmt->execute([
                'c' => $code,
                'rate' => $price,
                'as_of' => $asOf,
                // Same clock as everywhere else: SQLite would otherwise write this one in UTC.
                'now' => now_stamp(),
            ]);
            $updated++;
        }

        return ['updated' => $updated, 'skipped' => $skipped, 'failed' => $failed];
    }
}
