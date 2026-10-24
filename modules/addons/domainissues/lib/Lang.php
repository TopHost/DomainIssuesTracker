<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\DomainIssues;

/**
 * Minimal translation helper. The module UI is English-only (per spec), but
 * all strings live in lang/english.php so they can be adjusted in one place.
 */
final class Lang
{
    /** @var array<string, string>|null */
    private static ?array $strings = null;

    /** @return array<string, string> */
    public static function all(): array
    {
        if (self::$strings === null) {
            $_ADDONLANG = [];
            $file = dirname(__DIR__) . '/lang/english.php';
            if (is_file($file)) {
                include $file;
            }
            self::$strings = $_ADDONLANG;
        }

        return self::$strings;
    }

    /**
     * @param array<string, string|int> $replace  e.g. [':count' => 3]
     */
    public static function t(string $key, array $replace = []): string
    {
        $text = self::all()[$key] ?? $key;
        if ($replace !== []) {
            $text = strtr($text, array_map('strval', $replace));
        }

        return $text;
    }

    /** Human label for an enum value, e.g. label('status', 'in_progress') => "In progress". */
    public static function label(string $group, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $key = $group . '.' . $value;
        $all = self::all();

        return $all[$key] ?? ucfirst(str_replace('_', ' ', $value));
    }
}
