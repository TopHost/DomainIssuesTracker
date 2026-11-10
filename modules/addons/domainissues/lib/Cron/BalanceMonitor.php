<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\DomainIssues\Cron;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\DomainIssues\Adapters\EnomAdapter;
use WHMCS\Module\Addon\DomainIssues\Adapters\NetimAdapter;
use WHMCS\Module\Addon\DomainIssues\Config;

/**
 * Prepaid balance of the registrar accounts (1.6.0).
 *
 * Why: when the Enom / Netim balance runs out, registrations, transfers and
 * renewals fail at the registrar, usually noticed only when a client complains.
 *
 * Every "balance_check_hours" the cron asks each configured registrar for its
 * balance and estimates what the next "balance_window_hours" (24 h) will cost:
 *
 *   - renewals due: Active / Grace domains of that registrar, auto-renew on,
 *     next due date from today until the end of the window (paying the renewal
 *     invoice sends the renewal to the registrar at once);
 *   - paid orders not yet at the registrar: domains still "Pending" whose
 *     registration / transfer invoice is Paid (WHMCS sends them on the next run).
 *
 * Cost per domain: the "Registrar prices" table of the addon settings
 * (cost per year, registrar currency); without a price there, the client's
 * price converted with the WHMCS currency rates (higher than the real cost,
 * so the estimate errs on the safe side).
 *
 * The admins (System Emails) are e-mailed when
 *   - the balance does not cover the estimate for the window, or
 *   - the balance is below the minimum ("balance_threshold"),
 * at most once per REPEAT_HOURS while the situation lasts, at once when it gets
 * worse (more to pay, less money), and once more when the account is fine again.
 * Two failed checks in a row ("cannot read the balance") give one warning a day.
 */
final class BalanceMonitor
{
    public const TABLE = 'mod_domainissues_balance';

    /** Registrar slug => currency the registrar account is kept in. */
    public const REGISTRARS = ['enom' => 'USD', 'netim' => 'EUR'];

    /** Repeat an unchanged alert after this many hours. */
    public const REPEAT_HOURS = 24;

    /** Domains listed in an alert e-mail. */
    private const LIST_MAX = 40;

    public const STATE_OK = 'ok';
    public const STATE_LOW = 'low';      // below the minimum
    public const STATE_SHORT = 'short';  // does not cover the next window
    public const STATE_ERROR = 'error';

    /** @var array<string, callable(): array{balance: float, currency: string, info: string}>|null test seam */
    public static ?array $providers = null;

    public static function ensureTable(): void
    {
        $schema = Capsule::schema();
        if ($schema->hasTable(self::TABLE)) {
            return;
        }
        $schema->create(self::TABLE, static function ($table): void {
            $table->string('registrar', 32)->primary();
            $table->string('currency', 3)->default('');
            $table->decimal('balance', 12, 2)->nullable();
            $table->decimal('forecast', 12, 2)->nullable();
            $table->integer('forecast_count')->default(0);
            $table->integer('unpriced_count')->default(0);
            $table->string('state', 10)->default(self::STATE_OK);
            $table->text('info')->nullable();
            $table->text('error')->nullable();
            $table->integer('fail_count')->default(0);
            $table->dateTime('checked_at')->nullable();
            $table->string('alert_state', 10)->nullable();
            $table->decimal('alert_balance', 12, 2)->nullable();
            $table->decimal('alert_forecast', 12, 2)->nullable();
            $table->dateTime('alert_at')->nullable();
        });
    }

    /** Cron entry point: runs when enabled and the check interval has passed. */
    public static function runIfDue(): ?array
    {
        if (!Config::balanceMonitorEnabled()) {
            return null;
        }
        self::ensureTable();
        $last = Capsule::table(self::TABLE)->max('checked_at');
        if ($last !== null && strtotime((string) $last) > time() - Config::balanceCheckHours() * 3600 + 60) {
            return null;
        }

        return self::run();
    }

    /**
     * Check every configured registrar.
     *
     * @param bool $notify false = only read and store (command line check)
     * @return array<string, array<string, mixed>> per registrar: state, balance, forecast, items, error
     */
    public static function run(bool $notify = true, ?int $now = null): array
    {
        self::ensureTable();
        $now ??= time();
        $results = [];
        foreach (self::REGISTRARS as $slug => $currency) {
            $provider = self::provider($slug);
            if ($provider === null) {
                continue; // registrar module not configured here
            }
            $results[$slug] = self::checkOne($slug, $currency, $provider, $notify, $now);
        }

        return $results;
    }

    /**
     * @param callable(): array{balance: float, currency: string, info: string} $provider
     * @return array<string, mixed>
     */
    private static function checkOne(string $slug, string $currency, callable $provider, bool $notify, int $now): array
    {
        $row = Capsule::table(self::TABLE)->where('registrar', $slug)->first();
        $stamp = date('Y-m-d H:i:s', $now);

        try {
            $answer = $provider();
        } catch (\Throwable $e) {
            $fails = ($row !== null ? (int) $row->fail_count : 0) + 1;
            $data = ['state' => self::STATE_ERROR, 'error' => mb_substr($e->getMessage(), 0, 1000), 'fail_count' => $fails, 'checked_at' => $stamp];
            $alertAt = $row !== null && $row->alert_at !== null ? strtotime((string) $row->alert_at) : 0;
            $lastWasError = $row !== null && (string) $row->alert_state === self::STATE_ERROR;
            if ($notify && $fails >= 2 && (!$lastWasError || $alertAt < $now - self::REPEAT_HOURS * 3600)) {
                self::mailError($slug, $e->getMessage(), $row);
                // keep alert_state of a money alert pending, only remember when we warned
                $data['alert_at'] = $stamp;
                if ($row === null || !in_array((string) $row->alert_state, [self::STATE_LOW, self::STATE_SHORT], true)) {
                    $data['alert_state'] = self::STATE_ERROR;
                }
            }
            self::save($slug, $data);

            return ['state' => self::STATE_ERROR, 'error' => $e->getMessage(), 'fail_count' => $fails];
        }

        $currency = $answer['currency'] !== '' ? $answer['currency'] : $currency;
        $balance = round((float) $answer['balance'], 2);
        $forecast = self::forecast($slug, $currency, Config::balanceWindowHours(), $now);
        $minimum = (float) Config::balanceThreshold();

        $short = $forecast['total'] > $balance;
        $low = $balance < $minimum;
        $state = $short ? self::STATE_SHORT : ($low ? self::STATE_LOW : self::STATE_OK);

        $data = [
            'currency' => $currency,
            'balance' => $balance,
            'forecast' => $forecast['total'],
            'forecast_count' => count($forecast['items']),
            'unpriced_count' => $forecast['unpriced'],
            'state' => $state,
            'info' => $answer['info'],
            'error' => null,
            'fail_count' => 0,
            'checked_at' => $stamp,
        ];

        $prevAlert = $row !== null ? (string) ($row->alert_state ?? '') : '';
        $prevAlertAt = $row !== null && $row->alert_at !== null ? strtotime((string) $row->alert_at) : 0;
        $wasBad = in_array($prevAlert, [self::STATE_LOW, self::STATE_SHORT], true);

        if ($notify && $state !== self::STATE_OK) {
            $worse = !$wasBad
                || ($state === self::STATE_SHORT && $prevAlert !== self::STATE_SHORT)
                || ($row->alert_balance !== null && $balance < (float) $row->alert_balance - 0.005)
                || ($row->alert_forecast !== null && $forecast['total'] > (float) $row->alert_forecast + 0.005);
            if ($worse || $prevAlertAt < $now - self::REPEAT_HOURS * 3600) {
                self::mailMoney($slug, $state, $balance, $currency, $minimum, $forecast, $answer['info']);
                $data += ['alert_state' => $state, 'alert_balance' => $balance, 'alert_forecast' => $forecast['total'], 'alert_at' => $stamp];
            }
        } elseif ($notify && $state === self::STATE_OK && ($wasBad || $prevAlert === self::STATE_ERROR)) {
            if ($wasBad) {
                self::mailRecovered($slug, $balance, $currency, $forecast);
            }
            $data += ['alert_state' => self::STATE_OK, 'alert_balance' => null, 'alert_forecast' => null, 'alert_at' => $stamp];
        }
        self::save($slug, $data);

        return ['state' => $state, 'balance' => $balance, 'currency' => $currency, 'minimum' => $minimum] + $forecast;
    }

    // ---------------------------------------------------------------------
    // Forecast
    // ---------------------------------------------------------------------

    /**
     * What the registrar will charge within the window.
     *
     * @return array{total: float, items: list<array{domain: string, kind: string, date: string, years: int, cost: ?float, source: string}>, unpriced: int}
     */
    public static function forecast(string $registrar, string $currency, int $hours, ?int $now = null): array
    {
        $now ??= time();
        $from = date('Y-m-d', $now);
        $until = date('Y-m-d', $now + max(1, $hours) * 3600);
        $items = [];

        // 1. Renewals that fall due in the window.
        $renewals = Capsule::table('tbldomains')
            ->where('registrar', $registrar)
            ->whereIn('status', ['Active', 'Grace'])
            ->where('donotrenew', 0)
            ->whereBetween('nextduedate', [$from, $until])
            ->get(['id', 'userid', 'domain', 'nextduedate', 'registrationperiod', 'recurringamount']);
        foreach ($renewals as $d) {
            $items[] = self::item($registrar, $currency, $d, 'renewal', (string) $d->nextduedate, (float) $d->recurringamount);
        }

        // 2. Paid registrations / transfers not sent to the registrar yet.
        $paid = Capsule::table('tbldomains as d')
            ->join('tblinvoiceitems as ii', 'ii.relid', '=', 'd.id')
            ->join('tblinvoices as i', 'i.id', '=', 'ii.invoiceid')
            ->where('d.registrar', $registrar)
            ->where('d.status', 'Pending')
            ->whereIn('ii.type', ['DomainRegister', 'DomainTransfer'])
            ->where('i.status', 'Paid')
            ->groupBy('d.id', 'd.userid', 'd.domain', 'd.type', 'd.registrationdate', 'd.registrationperiod', 'd.firstpaymentamount')
            ->get(['d.id', 'd.userid', 'd.domain', 'd.type', 'd.registrationdate', 'd.registrationperiod', 'd.firstpaymentamount']);
        foreach ($paid as $d) {
            $kind = strtolower((string) $d->type) === 'transfer' ? 'transfer' : 'registration';
            $items[] = self::item($registrar, $currency, $d, $kind, 'paid, waiting', (float) $d->firstpaymentamount);
        }

        $total = 0.0;
        $unpriced = 0;
        foreach ($items as $item) {
            if ($item['cost'] === null) {
                $unpriced++;
            } else {
                $total += $item['cost'];
            }
        }
        usort($items, static fn (array $a, array $b): int => [$b['cost'] ?? 0, $a['domain']] <=> [$a['cost'] ?? 0, $b['domain']]);

        return ['total' => round($total, 2), 'items' => $items, 'unpriced' => $unpriced];
    }

    /** @return array{domain: string, kind: string, date: string, years: int, cost: ?float, source: string} */
    private static function item(string $registrar, string $currency, object $d, string $kind, string $date, float $clientAmount): array
    {
        $domain = strtolower((string) $d->domain);
        $years = max(1, (int) ($d->registrationperiod ?? 1));
        $tld = substr($domain, (int) strpos($domain, '.') + 1);
        $price = self::priceTable()[$registrar] ?? [];

        $perYear = null;
        foreach ([$tld, substr($tld, (int) strrpos($tld, '.') + (str_contains($tld, '.') ? 1 : 0)), '*'] as $key) {
            if (isset($price[$key])) {
                $perYear = $price[$key];
                break;
            }
        }
        if ($perYear !== null) {
            return ['domain' => $domain, 'kind' => $kind, 'date' => $date, 'years' => $years, 'cost' => round($perYear * $years, 2), 'source' => 'price table'];
        }
        $converted = $clientAmount > 0 ? self::convert($clientAmount, (int) $d->userid, $currency) : null;

        return ['domain' => $domain, 'kind' => $kind, 'date' => $date, 'years' => $years, 'cost' => $converted !== null ? round($converted, 2) : null, 'source' => $converted !== null ? 'client price' : 'no price'];
    }

    /**
     * "Registrar prices" setting, one line per price, cost per year in the registrar currency:
     *   enom com 10.50
     *   enom * 15          (any other TLD)
     *   netim fr 8
     *
     * @return array<string, array<string, float>> registrar => tld => price
     */
    public static function priceTable(): array
    {
        static $cache = [];
        $raw = (string) (Config::raw('balance_prices') ?? '');
        if (isset($cache[$raw])) {
            return $cache[$raw];
        }
        $table = [];
        foreach (preg_split('/[\r\n;]+/', $raw) ?: [] as $line) {
            if (preg_match('/^\s*([a-z0-9_]+)\s+\.?([a-z0-9.\-]+|\*)\s*[=:]?\s*([0-9]+(?:[.,][0-9]+)?)\s*$/i', $line, $m)) {
                $table[strtolower($m[1])][strtolower($m[2])] = (float) str_replace(',', '.', $m[3]);
            }
        }

        return $cache[$raw] = $table;
    }

    /** Client amount (client currency) -> registrar currency, with the WHMCS rates; null when impossible. */
    private static function convert(float $amount, int $clientId, string $target): ?float
    {
        static $currencies = null;
        if ($currencies === null) {
            $currencies = [];
            foreach (Capsule::table('tblcurrencies')->get(['id', 'code', 'rate']) as $c) {
                $currencies[(int) $c->id] = ['code' => strtoupper((string) $c->code), 'rate' => (float) $c->rate];
            }
        }
        $clientCurrency = (int) (Capsule::table('tblclients')->where('id', $clientId)->value('currency') ?? 0);
        $from = $currencies[$clientCurrency] ?? null;
        $to = null;
        foreach ($currencies as $c) {
            if ($c['code'] === strtoupper($target)) {
                $to = $c;
                break;
            }
        }
        if ($from === null || $to === null || $from['rate'] <= 0) {
            return null;
        }

        // WHMCS rates are relative to the default currency (rate 1).
        return $amount / $from['rate'] * $to['rate'];
    }

    // ---------------------------------------------------------------------
    // Registrars
    // ---------------------------------------------------------------------

    /** @return (callable(): array{balance: float, currency: string, info: string})|null */
    private static function provider(string $slug): ?callable
    {
        if (self::$providers !== null) {
            return self::$providers[$slug] ?? null;
        }
        if (!self::registrarConfigured($slug)) {
            return null;
        }

        return match ($slug) {
            'enom' => static fn (): array => (new EnomAdapter())->accountBalance(),
            'netim' => static function (): array {
                $adapter = new NetimAdapter();
                try {
                    return $adapter->accountBalance();
                } finally {
                    unset($adapter); // closes the Netim session
                }
            },
            default => null,
        };
    }

    /** True when the registrar module is activated in WHMCS with saved settings. */
    private static function registrarConfigured(string $slug): bool
    {
        try {
            return Capsule::table('tblregistrars')->where('registrar', $slug)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @param array<string, mixed> $data */
    private static function save(string $slug, array $data): void
    {
        Capsule::table(self::TABLE)->updateOrInsert(['registrar' => $slug], $data);
    }

    /** @return list<object> stored state, for the widget / admin page */
    public static function stored(): array
    {
        try {
            if (!Capsule::schema()->hasTable(self::TABLE)) {
                return [];
            }

            return Capsule::table(self::TABLE)->orderBy('registrar')->get()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ---------------------------------------------------------------------
    // E-mails
    // ---------------------------------------------------------------------

    public static function label(string $slug): string
    {
        return ['enom' => 'Enom', 'netim' => 'Netim'][$slug] ?? ucfirst($slug);
    }

    /** @param array{total: float, items: list<array<string, mixed>>, unpriced: int} $forecast */
    private static function mailMoney(string $slug, string $state, float $balance, string $currency, float $minimum, array $forecast, string $info): void
    {
        $name = self::label($slug);
        $hours = Config::balanceWindowHours();
        $money = static fn (float $v): string => number_format($v, 2, '.', ' ') . ' ' . $currency;
        $missing = max(0.0, $forecast['total'] - $balance);

        if ($state === self::STATE_SHORT) {
            $subject = sprintf('[Domain Issues] %s balance %s does not cover the next %d h (%s needed) - top up now', $name, $money($balance), $hours, $money($forecast['total']));
            $lead = sprintf('<p><strong>The %s balance (%s) does not cover what is due in the next %d hours: about %s. Missing: %s.</strong></p>', $name, $money($balance), $hours, $money($forecast['total']), $money($missing));
        } else {
            $subject = sprintf('[Domain Issues] %s balance low: %s (minimum %s)', $name, $money($balance), $money($minimum));
            $lead = sprintf('<p><strong>The %s balance is %s, below the minimum of %s.</strong> The next %d hours need about %s.</p>', $name, $money($balance), $money($minimum), $hours, $money($forecast['total']));
        }
        $body = $lead
            . '<p>Without money on the account, registrations, transfers and renewals sent to ' . self::e($name) . ' fail. Please top up the account.</p>'
            . ($info !== '' ? '<p>' . self::e($info) . '</p>' : '')
            . self::itemsTable($forecast, $currency, $hours);
        self::send($subject, $body);
        self::activity(sprintf('Domain Issues: %s balance alert (%s), balance %s, next %d h %s', $name, $state, $money($balance), $hours, $money($forecast['total'])));
    }

    /** @param array{total: float, items: list<array<string, mixed>>, unpriced: int} $forecast */
    private static function mailRecovered(string $slug, float $balance, string $currency, array $forecast): void
    {
        $name = self::label($slug);
        $money = static fn (float $v): string => number_format($v, 2, '.', ' ') . ' ' . $currency;
        self::send(
            sprintf('[Domain Issues] %s balance OK again: %s', $name, $money($balance)),
            sprintf('<p>The %s balance is %s and covers the next %d hours (about %s). No action needed.</p>', self::e($name), $money($balance), Config::balanceWindowHours(), $money($forecast['total']))
        );
        self::activity(sprintf('Domain Issues: %s balance OK again (%s)', $name, $money($balance)));
    }

    private static function mailError(string $slug, string $error, ?object $row): void
    {
        $name = self::label($slug);
        $last = $row !== null && $row->balance !== null
            ? sprintf('<p>Last known balance: %s %s (%s).</p>', number_format((float) $row->balance, 2, '.', ' '), self::e((string) $row->currency), self::e((string) $row->checked_at))
            : '';
        self::send(
            sprintf('[Domain Issues] %s balance cannot be checked', $name),
            '<p>The ' . self::e($name) . ' balance could not be read twice in a row, so low-balance alerts for ' . self::e($name) . ' are not working right now.</p>'
            . '<p>Error: <code>' . self::e(mb_substr($error, 0, 500)) . '</code></p>' . $last
            . '<p>Details: Utilities &gt; Logs &gt; Module Log (module domainissues:' . self::e($slug) . ').</p>'
        );
    }

    /** @param array{total: float, items: list<array<string, mixed>>, unpriced: int} $forecast */
    private static function itemsTable(array $forecast, string $currency, int $hours): string
    {
        if ($forecast['items'] === []) {
            return '<p>Nothing is due in the next ' . $hours . ' hours.</p>';
        }
        $kinds = ['renewal' => 'renewal', 'registration' => 'registration (paid)', 'transfer' => 'transfer (paid)'];
        $html = '<p>Due in the next ' . $hours . ' hours (' . count($forecast['items']) . '):</p>'
            . '<table cellpadding="4" style="border-collapse:collapse;font-size:13px"><tr style="background:#f0f3f5"><th align="left">Domain</th><th align="left">Operation</th><th align="left">Due</th><th align="right">Years</th><th align="right">Cost (' . self::e($currency) . ')</th></tr>';
        foreach (array_slice($forecast['items'], 0, self::LIST_MAX) as $item) {
            $html .= '<tr><td>' . self::e($item['domain']) . '</td><td>' . self::e($kinds[$item['kind']] ?? $item['kind']) . '</td><td>' . self::e($item['date']) . '</td><td align="right">' . (int) $item['years'] . '</td><td align="right">'
                . ($item['cost'] !== null ? number_format((float) $item['cost'], 2, '.', ' ') . ($item['source'] === 'client price' ? ' *' : '') : 'n/a') . '</td></tr>';
        }
        $html .= '</table>';
        if (count($forecast['items']) > self::LIST_MAX) {
            $html .= '<p>... and ' . (count($forecast['items']) - self::LIST_MAX) . ' more.</p>';
        }
        $notes = [];
        foreach ($forecast['items'] as $item) {
            if ($item['source'] === 'client price') {
                $notes[] = '* estimated from the client price (above the real cost); add the registrar price under Addons &gt; Domain Issues Tracker settings &gt; Registrar prices for an exact figure.';
                break;
            }
        }
        if ($forecast['unpriced'] > 0) {
            $notes[] = $forecast['unpriced'] . ' domain(s) without any price are not included in the total.';
        }

        return $html . ($notes !== [] ? '<p style="color:#6b7482;font-size:12px">' . implode('<br>', $notes) . '</p>' : '');
    }

    private static function send(string $subject, string $body): void
    {
        if (!function_exists('localAPI')) {
            return;
        }
        $subject = str_replace(['{', '}', "\r", "\n"], '', $subject);
        $body = str_replace(['{', '}'], ['&#123;', '&#125;'], $body);
        $result = localAPI('SendAdminEmail', ['customsubject' => $subject, 'custommessage' => $body, 'type' => 'system']);
        if ((!is_array($result) || ($result['result'] ?? '') !== 'success') && function_exists('logModuleCall')) {
            logModuleCall(Config::MODULE, 'balance_alert', $subject, $result);
        }
    }

    private static function activity(string $text): void
    {
        if (function_exists('logActivity')) {
            logActivity($text);
        }
    }

    private static function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
