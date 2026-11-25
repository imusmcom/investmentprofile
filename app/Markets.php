<?php
declare(strict_types=1);

namespace App;

final class Markets
{
    /**
     * market => [label, default currency]
     */
    public const MARKETS = [
        'US' => ['label' => '美股', 'currency' => 'USD'],
        'TW' => ['label' => '台股', 'currency' => 'TWD'],
        'HK' => ['label' => '港股', 'currency' => 'HKD'],
        'JP' => ['label' => '日股', 'currency' => 'JPY'],
        'SH' => ['label' => '上海', 'currency' => 'CNY'],
        'SZ' => ['label' => '深圳', 'currency' => 'CNY'],
        'SG' => ['label' => '新加坡', 'currency' => 'SGD'],
        'KL' => ['label' => '馬來西亞', 'currency' => 'MYR'],
        'GB' => ['label' => '倫敦', 'currency' => 'GBP'],
        'DE' => ['label' => '德國', 'currency' => 'EUR'],
        'CA' => ['label' => '加拿大', 'currency' => 'CAD'],
        'AU' => ['label' => '澳洲', 'currency' => 'AUD'],
        'XC' => ['label' => '加密貨幣', 'currency' => 'USD'],
        'RAW' => ['label' => '其他／指數（直接用 Yahoo 代號）', 'currency' => 'USD'],
    ];

    public static function label(string $market): string
    {
        return self::MARKETS[$market]['label'] ?? $market;
    }

    public static function currencyFor(string $market): string
    {
        return self::MARKETS[$market]['currency'] ?? 'USD';
    }

    /** Used when a form creates an instrument without asking for a class (the trade-entry path). */
    public static function defaultAssetClass(string $market): string
    {
        return $market === 'XC' ? 'CRYPTO' : 'EQUITY';
    }

    /** The Yahoo suffix this market needs, or '' when the ticker Yahoo already uses is the plain one. */
    public static function yahooSuffix(string $market): string
    {
        return match ($market) {
            'TW' => '.TW',
            'HK' => '.HK',
            'JP' => '.T',
            'SH' => '.SS',
            'SZ' => '.SZ',
            'SG' => '.SI',
            'KL' => '.KL',
            'GB' => '.L',
            'DE' => '.F',
            'CA' => '.TO',
            'AU' => '.AX',
            default => '',
        };
    }

    /** Build the Yahoo Finance symbol for a raw user-entered ticker. */
    public static function toYahoo(string $symbol, string $market): string
    {
        $s = strtoupper(trim($symbol));
        if ($market === 'XC') {
            return self::cryptoSymbol($s);
        }

        // Yahoo expects Hong Kong tickers zero-padded to four digits (700 -> 0700).
        return self::appendSuffix($market === 'HK' ? self::padHk($s) : $s, self::yahooSuffix($market));
    }

    /** BTC -> BTC-USD; an explicit pair (BTC-USDT, ETH-EUR) is left alone. */
    private static function cryptoSymbol(string $symbol): string
    {
        if ($symbol === '') {
            return '';
        }

        return str_contains($symbol, '-') ? $symbol : $symbol . '-USD';
    }

    private static function appendSuffix(string $symbol, string $suffix): string
    {
        if ($symbol === '') {
            return '';
        }

        return str_ends_with($symbol, $suffix) ? $symbol : $symbol . $suffix;
    }

    /** Yahoo expects Hong Kong tickers zero-padded to four digits (700 -> 0700). */
    private static function padHk(string $symbol): string
    {
        return ctype_digit($symbol) ? str_pad($symbol, 4, '0', STR_PAD_LEFT) : $symbol;
    }

    /**
     * Deep link to Yahoo Finance for quote verification.
     *
     * Yahoo is the data source, so the link always works: `https://finance.yahoo.com/quote/{yahoo_symbol}`.
     * No exchange mapping needed — Yahoo's symbol already includes the right suffix (.TW, .HK, -USD, etc.).
     */
    public static function quoteUrl(string $yahooSymbol): string
    {
        return 'https://finance.yahoo.com/quote/' . rawurlencode(trim($yahooSymbol));
    }

    public static function assetClasses(): array
    {
        return [
            'EQUITY'    => '股票',
            'ETF'       => 'ETF／指數股票型基金',
            'INDEX'     => '指數',
            'BOND'      => '債券',
            'CURRENCY'  => '外匯',
            'COMMODITY' => '商品',
            'CRYPTO'    => '加密貨幣',
            'OTHER'     => '其他',
        ];
    }
}
