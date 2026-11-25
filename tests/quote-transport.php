<?php
declare(strict_types=1);

namespace App;

// Loaded only by tests/run.sh; production never defines this transport override.
function curl_exec(\CurlHandle $ch)
{
    $host = strtolower(trim((string) (getenv('PORTFOLIO_QUOTE_STUB_HOST') ?: '')));
    $url = \curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $parts = is_string($url) ? parse_url($url) : false;

    if ($host !== '' && is_array($parts) && strtolower((string) ($parts['host'] ?? '')) === $host) {
        $port = (int) ($parts['port'] ?? 443);
        $target = 'http://' . $host . ':' . $port . (string) ($parts['path'] ?? '');
        if (isset($parts['query'])) {
            $target .= '?' . $parts['query'];
        }
        \curl_setopt($ch, CURLOPT_URL, $target);
        \curl_setopt($ch, CURLOPT_RESOLVE, [$host . ':' . $port . ':127.0.0.1']);
    }

    return \curl_exec($ch);
}
