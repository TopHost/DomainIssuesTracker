<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\DomainIssues\Cron;

use WHMCS\Module\Addon\DomainIssues\Adapters\AdapterInterface;
use WHMCS\Module\Addon\DomainIssues\Adapters\AdapterRegistry;
use WHMCS\Module\Addon\DomainIssues\Config;
use WHMCS\Module\Addon\DomainIssues\Installer;
use WHMCS\Module\Addon\DomainIssues\IssueRepository;
use WHMCS\Module\Addon\DomainIssues\IssueService;
use WHMCS\Module\Addon\DomainIssues\Lang;
use WHMCS\Module\Addon\DomainIssues\Types;

/**
 * Cron work (§6.3), run from the AfterCronJob hook once per configured interval.
 *
 * Phase 1 — proactive scan: domains whose WHMCS status suggests an in-flight
 *   or problematic operation (Pending Transfer, Pending Registration, Expired,
 *   Grace, Redemption) at registrars with an enabled adapter are inspected and
 *   any detected problem becomes an automatic issue (dedup applies).
 *   Only statuses whose category is enabled in the settings are scanned.
 *
 * Phase 2 — re-check: every open issue with source=automatic is re-inspected:
 *   - still reported          -> details/subtype refreshed;
 *   - gone + type supported   -> auto-resolved with a system comment;
 *   - adapter cannot verify   -> untouched.
 * Manual issues are never closed automatically.
 *
 * Failed operations (transfer, registration, renewal) are only auto-resolved
 * while the WHMCS domain status confirms the operation completed or is still
 * running normally (see OPERATION_CLOSE_STATUSES). If the domain meanwhile went
 * Expired / Grace / Redemption / Cancelled / Transferred Away (or disappeared
 * from WHMCS), the adapter only checks the lifecycle and no longer looks at the
 * operation, so "not reported" does not mean "fixed": the case stays open and
 * gets one system comment explaining why.
 */
final class Recheck
{
    /** WHMCS domain status => issue category that must be enabled to scan it. */
    private const SCAN_STATUSES = [
        'Pending Transfer' => Types::TYPE_TRANSFER_IN,
        'Pending' => Types::TYPE_REGISTRATION,
        'Pending Registration' => Types::TYPE_REGISTRATION,
        'Expired' => Types::TYPE_REDEMPTION,
        'Grace' => Types::TYPE_REDEMPTION,
        'Redemption' => Types::TYPE_REDEMPTION,
    ];

    /**
     * Operation issue type => WHMCS domain statuses in which an adapter that no
     * longer reports the problem can be trusted (operation done, or still in
     * flight and checked by the adapter). Any other status keeps the case open.
     */
    private const OPERATION_CLOSE_STATUSES = [
        Types::TYPE_TRANSFER_IN => ['Active', 'Pending Transfer'],
        Types::TYPE_REGISTRATION => ['Active', 'Pending', 'Pending Registration'],
        Types::TYPE_RENEWAL => ['Active'],
    ];

    /** Run only if the configured interval has elapsed since the last run. */
    public static function runIfDue(): ?array
    {
        $last = Config::raw(Config::KEY_LAST_RECHECK);
        $intervalSeconds = Config::cronIntervalHours() * 3600;
        if ($last !== null && is_numeric($last) && (time() - (int) $last) < $intervalSeconds) {
            return null;
        }

        Config::setInternal(Config::KEY_LAST_RECHECK, (string) time());

        return self::run();
    }

    /**
     * @return array{scanned:int, created_or_refreshed:int, checked:int, resolved:int, kept_open:int, skipped:int, errors:int}
     */
    public static function run(): array
    {
        $stats = ['scanned' => 0, 'created_or_refreshed' => 0, 'checked' => 0, 'resolved' => 0, 'kept_open' => 0, 'skipped' => 0, 'errors' => 0];

        if (!Installer::tablesExist()) {
            return $stats;
        }

        $adapters = AdapterRegistry::enabled();
        if ($adapters === []) {
            return $stats;
        }

        $repo = new IssueRepository();
        $service = new IssueService($repo);

        // Cache of adapter results per "registrar|domain" so phase 2 does not
        // repeat API calls already made in phase 1.
        $results = [];

        self::scan($adapters, $repo, $service, $results, $stats);
        self::recheck($adapters, $repo, $service, $results, $stats);

        if (function_exists('logModuleCall')) {
            logModuleCall(Config::MODULE, 'cron_recheck', ['interval_hours' => Config::cronIntervalHours()], $stats);
        }

        return $stats;
    }

    /**
     * @param array<string, AdapterInterface> $adapters
     * @param array<string, \WHMCS\Module\Addon\DomainIssues\IssueDTO[]> $results
     * @param array<string, int> $stats
     */
    private static function scan(array $adapters, IssueRepository $repo, IssueService $service, array &$results, array &$stats): void
    {
        $statuses = [];
        foreach (self::SCAN_STATUSES as $status => $category) {
            if (Config::isIssueTypeEnabled($category)) {
                $statuses[] = $status;
            }
        }
        if ($statuses === []) {
            return;
        }

        $rows = $repo->listDomainsForScan(array_keys($adapters), $statuses, Config::scanLimit(), Config::scanMaxExpiredDays());

        // Adapter-driven additions (e.g. operations still open in the registrar module's tables).
        foreach ($adapters as $slug => $adapter) {
            try {
                $extra = $adapter->additionalDomainsToScan();
            } catch (\Throwable $e) {
                $extra = [];
            }
            if ($extra !== []) {
                $seen = [];
                foreach ($rows as $r) {
                    $seen[strtolower((string) $r->domain)] = true;
                }
                // No registrar filter: the registrar module may know about a domain WHMCS
                // still assigns to another registrar (transfer between registrars in progress).
                foreach ($repo->findDomainsByNames($extra) as $r) {
                    $key = strtolower((string) $r->domain);
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $r->_adapter = $slug; // force this adapter for the row
                        $rows[] = $r;
                    }
                }
            }
        }

        foreach ($rows as $domainRow) {
            $registrar = strtolower((string) ($domainRow->_adapter ?? $domainRow->registrar));
            $adapter = $adapters[$registrar] ?? null;
            if ($adapter === null) {
                continue;
            }
            $key = $registrar . '|' . strtolower((string) $domainRow->domain);

            $detected = self::inspect($adapter, $domainRow, $registrar, $stats);
            $stats['scanned']++;
            if ($detected === null) {
                continue;
            }
            $results[$key] = $detected;

            foreach ($detected as $dto) {
                if (!Config::isIssueTypeEnabled($dto->issueType)) {
                    continue;
                }
                try {
                    $service->reportAutomatic($dto);
                    $stats['created_or_refreshed']++;
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    self::logError($registrar, $dto->domainName, $e);
                }
            }
        }
    }

    /**
     * @param array<string, AdapterInterface> $adapters
     * @param array<string, \WHMCS\Module\Addon\DomainIssues\IssueDTO[]> $results
     * @param array<string, int> $stats
     */
    private static function recheck(array $adapters, IssueRepository $repo, IssueService $service, array &$results, array &$stats): void
    {
        $byDomain = [];
        foreach ($repo->listOpenAutomatic() as $issue) {
            $registrar = strtolower((string) ($issue->registrar ?? ''));
            if ($registrar === '' || !isset($adapters[$registrar])) {
                $stats['skipped']++;
                continue;
            }
            $byDomain[$registrar . '|' . strtolower((string) $issue->domain_name)][] = $issue;
        }

        foreach ($byDomain as $key => $issues) {
            [$registrar] = explode('|', $key, 2);
            $adapter = $adapters[$registrar];
            $first = $issues[0];

            if (!array_key_exists($key, $results)) {
                $domainRow = null;
                if (!empty($first->domain_id)) {
                    $domainRow = $repo->findDomainById((int) $first->domain_id);
                }
                $domainRow ??= $repo->findDomainByName((string) $first->domain_name);
                if ($domainRow === null) {
                    $stats['skipped'] += count($issues);
                    continue;
                }

                $detected = self::inspect($adapter, $domainRow, $registrar, $stats);
                if ($detected === null) {
                    continue; // API error: never resolve on missing data
                }
                $results[$key] = $detected;

                foreach ($detected as $dto) {
                    if (!Config::isIssueTypeEnabled($dto->issueType)) {
                        continue;
                    }
                    try {
                        $service->reportAutomatic($dto); // refresh existing (dedup) or create
                        $stats['created_or_refreshed']++;
                    } catch (\Throwable $e) {
                        $stats['errors']++;
                        self::logError($registrar, $dto->domainName, $e);
                    }
                }
            }
            $stats['checked']++;

            $detectedTypes = [];
            foreach ($results[$key] as $dto) {
                $detectedTypes[$dto->issueType] = true;
            }
            $supported = $adapter->supportedIssueTypes();

            foreach ($issues as $issue) {
                $type = (string) $issue->issue_type;
                if (isset($detectedTypes[$type]) || !in_array($type, $supported, true)) {
                    continue;
                }
                if (isset(self::OPERATION_CLOSE_STATUSES[$type])) {
                    $whmcsStatus = self::currentWhmcsStatus($repo, $issue);
                    if (!in_array($whmcsStatus, self::OPERATION_CLOSE_STATUSES[$type], true)) {
                        self::noteKeptOpen($repo, $issue, $whmcsStatus);
                        $stats['kept_open']++;
                        continue;
                    }
                }
                if ($service->resolveBySystem((int) $issue->id, Lang::t('comment.recheck_reason', [':registrar' => $registrar]))) {
                    $stats['resolved']++;
                }
            }
        }
    }

    /** Current WHMCS status of the issue's domain ('' when the domain is no longer in WHMCS). */
    private static function currentWhmcsStatus(IssueRepository $repo, object $issue): string
    {
        $row = null;
        if (!empty($issue->domain_id)) {
            $row = $repo->findDomainById((int) $issue->domain_id);
        }
        $row ??= $repo->findDomainByName(strtolower((string) $issue->domain_name));

        return $row !== null ? (string) $row->status : '';
    }

    /**
     * One system comment per WHMCS status explaining why the case was not closed,
     * so the admin sees it in the history without a new comment on every cron run.
     */
    private static function noteKeptOpen(IssueRepository $repo, object $issue, string $whmcsStatus): void
    {
        $text = Lang::t('comment.kept_open', [
            ':status' => $whmcsStatus !== '' ? $whmcsStatus : Lang::t('comment.not_in_whmcs'),
            ':type' => Lang::label('type', (string) $issue->issue_type),
        ]);
        foreach ($repo->comments((int) $issue->id) as $comment) {
            if ((string) $comment->comment === $text) {
                return;
            }
        }
        $repo->addComment((int) $issue->id, null, $text);
        $repo->update((int) $issue->id, ['updated_at' => IssueRepository::now()]);
    }

    /**
     * Call the adapter safely. Returns null on error (logged).
     *
     * @param array<string, int> $stats
     * @return \WHMCS\Module\Addon\DomainIssues\IssueDTO[]|null
     */
    private static function inspect(AdapterInterface $adapter, object $domainRow, string $registrar, array &$stats): ?array
    {
        try {
            return $adapter->checkDomain(get_object_vars($domainRow));
        } catch (\Throwable $e) {
            $stats['errors']++;
            self::logError($registrar, (string) $domainRow->domain, $e);

            return null;
        }
    }

    private static function logError(string $registrar, string $domain, \Throwable $e): void
    {
        if (function_exists('logModuleCall')) {
            logModuleCall(
                Config::MODULE . ':' . $registrar,
                'cron_error',
                ['domain' => $domain],
                $e->getMessage() . "\n" . $e->getTraceAsString()
            );
        }
    }
}
