<?php
declare(strict_types=1);

namespace App;

final class Settings
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            $rows = Database::all('SELECT key, value FROM settings');
            $map = [];
            foreach ($rows as $row) {
                $map[$row['key']] = $row['value'];
            }
            self::$cache = $map;
        }

        return self::$cache;
    }

    public static function get(string $key, string $default = ''): string
    {
        return self::all()[$key] ?? $default;
    }

    public static function put(string $key, string $value): void
    {
        Database::run(
            'INSERT INTO settings (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value',
            [$key, $value]
        );
        self::$cache = null;
    }

    /** 有些鍵是「開著才有意義」的旗標，關掉時留一列空值只會讓之後讀設定表的人多猜一次。 */
    public static function forget(string $key): void
    {
        Database::run('DELETE FROM settings WHERE key = ?', [$key]);
        self::$cache = null;
    }

    public static function baseCurrency(): string
    {
        return strtoupper(self::get('base_currency', 'TWD')) ?: 'TWD';
    }
}
