<?php
declare(strict_types=1);

/**
 * Yahoo Finance 連結的獨立驗算（tests/link-math.php）；不是績效鏈結測試。
 *
 * 不碰資料庫、不碰網路：只測 Markets::quoteUrl() 的輸入輸出。
 * 績效鏈結由 tests/performance-chain-math.php 負責；有失敗就 exit 1。
 */
require __DIR__ . '/../app/bootstrap.php';

use App\Markets;

$fail = 0;
$ok = static function (string $name, bool $pass, string $got = '') use (&$fail): void {
    if ($pass) {
        echo "  ok   {$name}\n";

        return;
    }
    $fail++;
    echo '  FAIL ' . $name . ($got === '' ? '' : "（{$got}）") . "\n";
};

echo "-- 基本連結\n";
$ok('美股 AAPL → Yahoo quote 頁',
    Markets::quoteUrl('AAPL') === 'https://finance.yahoo.com/quote/AAPL',
    Markets::quoteUrl('AAPL'));
$ok('台股 2330.TW → Yahoo quote 頁',
    Markets::quoteUrl('2330.TW') === 'https://finance.yahoo.com/quote/2330.TW',
    Markets::quoteUrl('2330.TW'));
$ok('港股 0700.HK → Yahoo quote 頁',
    Markets::quoteUrl('0700.HK') === 'https://finance.yahoo.com/quote/0700.HK',
    Markets::quoteUrl('0700.HK'));
$ok('倫敦 VOD.L → Yahoo quote 頁',
    Markets::quoteUrl('VOD.L') === 'https://finance.yahoo.com/quote/VOD.L',
    Markets::quoteUrl('VOD.L'));
$ok('加密貨幣 BTC-USD → Yahoo quote 頁',
    Markets::quoteUrl('BTC-USD') === 'https://finance.yahoo.com/quote/BTC-USD',
    Markets::quoteUrl('BTC-USD'));
$ok('加密貨幣 XAUT-USD → Yahoo quote 頁',
    Markets::quoteUrl('XAUT-USD') === 'https://finance.yahoo.com/quote/XAUT-USD',
    Markets::quoteUrl('XAUT-USD'));

echo "-- 特殊字元\n";
$ok('代號有空白會被 URL encode',
    Markets::quoteUrl('BRK.B') === 'https://finance.yahoo.com/quote/BRK.B',
    Markets::quoteUrl('BRK.B'));
$ok('代號前後空白會被 trim',
    Markets::quoteUrl('  AAPL  ') === 'https://finance.yahoo.com/quote/AAPL',
    Markets::quoteUrl('  AAPL  '));
$ok('小寫代號保持小寫（Yahoo 接受）',
    Markets::quoteUrl('aapl') === 'https://finance.yahoo.com/quote/aapl',
    Markets::quoteUrl('aapl'));

echo "-- 指數與其他\n";
$ok('指數 ^GSPC → Yahoo quote 頁',
    Markets::quoteUrl('^GSPC') === 'https://finance.yahoo.com/quote/%5EGSPC',
    Markets::quoteUrl('^GSPC'));
$ok('ETF VT → Yahoo quote 頁',
    Markets::quoteUrl('VT') === 'https://finance.yahoo.com/quote/VT',
    Markets::quoteUrl('VT'));
$ok('ETF VOO → Yahoo quote 頁',
    Markets::quoteUrl('VOO') === 'https://finance.yahoo.com/quote/VOO',
    Markets::quoteUrl('VOO'));

$ok('法蘭克福 SAP.F → Yahoo quote 頁',
    Markets::quoteUrl('SAP.F') === 'https://finance.yahoo.com/quote/SAP.F',
    Markets::quoteUrl('SAP.F'));
$ok('多倫多 RY.TO → Yahoo quote 頁',
    Markets::quoteUrl('RY.TO') === 'https://finance.yahoo.com/quote/RY.TO',
    Markets::quoteUrl('RY.TO'));
$ok('吉隆坡 1295.KL → Yahoo quote 頁',
    Markets::quoteUrl('1295.KL') === 'https://finance.yahoo.com/quote/1295.KL',
    Markets::quoteUrl('1295.KL'));
$ok('Yahoo 的 BRK-B 寫法保持不變',
    Markets::quoteUrl('BRK-B') === 'https://finance.yahoo.com/quote/BRK-B',
    Markets::quoteUrl('BRK-B'));

echo $fail === 0 ? "YAHOO ALL PASS\n" : "YAHOO FAILURES: {$fail}\n";
exit($fail === 0 ? 0 : 1);
