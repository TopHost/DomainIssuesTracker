<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\DomainIssues;

use WHMCS\Database\Capsule;

/**
 * Reads the addon settings stored by WHMCS in tbladdonmodules.
 *
 * Settings are cached per request. Missing rows fall back to DEFAULTS so the
 * module behaves sensibly straight after activation.
 */
final class Config
{
    public const MODULE = 'domainissues';

    /** @var array<string, bool|int> */
    public const DEFAULTS = [
        // Issue categories (Stage 1 enables transfers only)
        'enable_transfer_in' => true,
        'enable_transfer_out' => true,
        'enable_renewal' => false,
        'enable_redemption' => false,
        'enable_registration' => false,
        'enable_sync_mismatch' => false,
        'enable_other' => false,
        // Registrar adapters (Stage 2)
        'adapter_enom' => false,
        'adapter_netim' => false,
        'adapter_molddata' => false,
        // Behaviour
        'cron_interval_hours' => 24,
        'deadline_threshold_days' => 7,
        'items_per_page' => 25,
        'scan_limit' => 200,
        'scan_max_expired_days' => 120,
        'drop_tables_on_deactivate' => false,
        // Daily client reminders while a transfer waits for a valid transfer code (1.5.0)
        'transfer_reminders' => false,
        'reminder_hour' => 10,
        'reminder_max' => 30,
        'reminder_escalate_days' => 14,
        'reminder_escalate_after' => 3,
    ];

    /** Internal (non-UI) settings stored alongside the module config. */
    public const KEY_LAST_RECHECK = '_last_recheck';

    /** @var array<string, string>|null */
    private static ?array $cache = null;

    /** @return array<string, string> */
    public static function all(): array
    {
        if (self::$cache === null) {
            try {
                self::$cache = Capsule::table('tbladdonmodules')
                    ->where('module', self::MODULE)
                    ->pluck('value', 'setting')
                    ->toArray();
            } catch (\Throwable $e) {
                self::$cache = [];
            }
        }

        return self::$cache;
    }

    public static function reset(): void
    {
        self::$cache = null;
    }

    public static function raw(string $key): ?string
    {
        $all = self::all();

        return array_key_exists($key, $all) ? (string) $all[$key] : null;
    }

    public static function bool(string $key): bool
    {
        $value = self::raw($key);
        if ($value === null) {
            return (bool) (self::DEFAULTS[$key] ?? false);
        }

        return $value === 'on' || $value === '1' || $value === 'true';
    }

    public static function int(string $key, int $min = 0): int
    {
        $value = self::raw($key);
        if ($value === null || $value === '' || !is_numeric($value)) {
            $value = self::DEFAULTS[$key] ?? 0;
        }

        return max($min, (int) $value);
    }

    /** @return string[] issue types currently enabled in the addon settings */
    public static function enabledIssueTypes(): array
    {
        return array_values(array_filter(
            Types::TYPES,
            static fn (string $type): bool => self::bool('enable_' . $type)
        ));
    }

    public static function isIssueTypeEnabled(string $type): bool
    {
        return in_array($type, self::enabledIssueTypes(), true);
    }

    /** True when at least one non-transfer category is enabled (Stage 2 widget line). */
    public static function hasOtherCategoriesEnabled(): bool
    {
        foreach (self::enabledIssueTypes() as $type) {
            if (!Types::isTransferType($type)) {
                return true;
            }
        }

        return false;
    }

    public static function deadlineThresholdDays(): int
    {
        return self::int('deadline_threshold_days', 0);
    }

    public static function cronIntervalHours(): int
    {
        return self::int('cron_interval_hours', 1);
    }

    public static function itemsPerPage(): int
    {
        return self::int('items_per_page', 5);
    }

    /** Max domains inspected by the proactive cron scan per run. */
    public static function scanLimit(): int
    {
        return self::int('scan_limit', 10);
    }

    /** Domains expired for longer than this are no longer scanned (0 = no limit). */
    public static function scanMaxExpiredDays(): int
    {
        return self::int('scan_max_expired_days', 0);
    }

    public static function isAdapterEnabled(string $slug): bool
    {
        return self::bool('adapter_' . $slug);
    }

    public static function transferRemindersEnabled(): bool
    {
        return self::bool('transfer_reminders');
    }

    /** Hour of the day (server time, 0-23) from which the daily reminders are sent. */
    public static function reminderHour(): int
    {
        return min(23, self::int('reminder_hour', 0));
    }

    public static function reminderMax(): int
    {
        return self::int('reminder_max', 1);
    }

    public static function reminderEscalateDays(): int
    {
        return self::int('reminder_escalate_days', 0);
    }

    public static function reminderEscalateAfter(): int
    {
        return self::int('reminder_escalate_after', 1);
    }

    public static function dropTablesOnDeactivate(): bool
    {
        return self::bool('drop_tables_on_deactivate');
    }

    /**
     * Persist an internal value (e.g. last cron run) next to the module settings.
     * WHMCS may rewrite these rows when the admin saves the config page; that is
     * harmless for the values we store here.
     */
    public static function setInternal(string $key, string $value): void
    {
        Capsule::table('tbladdonmodules')->updateOrInsert(
            ['module' => self::MODULE, 'setting' => $key],
            ['value' => $value]
        );
        self::reset();
    }
}
