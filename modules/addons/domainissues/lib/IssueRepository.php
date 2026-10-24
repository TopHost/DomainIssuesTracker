<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\DomainIssues;

use WHMCS\Database\Capsule;

/**
 * All database access for the module. Uses WHMCS\Database\Capsule exclusively
 * (query builder => prepared statements).
 */
final class IssueRepository
{
    public const TABLE = 'mod_domainissues';
    public const TABLE_COMMENTS = 'mod_domainissues_comments';
    public const TABLE_LOG = 'mod_domainissues_log';

    // ---------------------------------------------------------------------
    // Issues
    // ---------------------------------------------------------------------

    /**
     * Base query with client / domain joins used by list and detail views.
     */
    private function baseQuery(): \Illuminate\Database\Query\Builder
    {
        return Capsule::table(self::TABLE . ' as i')
            ->leftJoin('tblclients as c', 'c.id', '=', 'i.client_id')
            ->leftJoin('tbldomains as d', 'd.id', '=', 'i.domain_id')
            ->select(
                'i.*',
                'c.firstname as client_firstname',
                'c.lastname as client_lastname',
                'c.companyname as client_companyname',
                'd.status as whmcs_domain_status',
                'd.userid as whmcs_domain_userid',
                'd.expirydate as whmcs_domain_expiry'
            );
    }

    public function find(int $id): ?object
    {
        $row = $this->baseQuery()->where('i.id', $id)->first();

        return $row ?: null;
    }

    public function findOpenByDomainAndType(string $domainName, string $issueType): ?object
    {
        $row = Capsule::table(self::TABLE)
            ->where('domain_name', $domainName)
            ->where('issue_type', $issueType)
            ->whereIn('status', Types::OPEN_STATUSES)
            ->orderBy('id', 'desc')
            ->first();

        return $row ?: null;
    }

    /**
     * @param array{status?:string,type?:string,registrar?:string,source?:string,q?:string,urgent?:bool,priority?:string} $filters
     * @return array{items: object[], total: int}
     */
    public function search(array $filters, int $page, int $perPage, int $urgentDays): array
    {
        $query = $this->baseQuery();
        $this->applyFilters($query, $filters, $urgentDays);

        $total = (clone $query)->count();

        $page = max(1, $page);
        $items = $query
            ->orderByRaw('(i.deadline IS NULL) ASC, i.deadline ASC')
            ->orderBy('i.updated_at', 'desc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->all();

        return ['items' => $items, 'total' => (int) $total];
    }

    private function applyFilters(\Illuminate\Database\Query\Builder $query, array $filters, int $urgentDays): void
    {
        $status = $filters['status'] ?? 'open';
        if ($status === 'open' || $status === '') {
            $query->whereIn('i.status', Types::OPEN_STATUSES);
        } elseif ($status === 'closed') {
            $query->whereIn('i.status', Types::CLOSED_STATUSES);
        } elseif ($status !== 'all' && in_array($status, Types::STATUSES, true)) {
            $query->where('i.status', $status);
        }

        $type = $filters['type'] ?? '';
        if ($type === 'cat:transfers') {
            $query->whereIn('i.issue_type', Types::TRANSFER_TYPES);
        } elseif ($type === 'cat:other') {
            $query->whereNotIn('i.issue_type', Types::TRANSFER_TYPES);
        } elseif ($type !== '' && in_array($type, Types::TYPES, true)) {
            $query->where('i.issue_type', $type);
        }

        // Lifecycle noise toggles: hide the "redemption" category unless asked for,
        // except when that category is filtered explicitly.
        if ($type !== Types::TYPE_REDEMPTION) {
            $showExpired = !empty($filters['show_expired']);
            $showRedemption = !empty($filters['show_redemption']);
            if (!$showExpired) {
                // grace-period rows of the redemption category ...
                $query->where(static function ($q) {
                    $q->where('i.issue_type', '!=', Types::TYPE_REDEMPTION)
                        ->orWhereIn('i.issue_subtype', Types::REDEMPTION_SUBTYPES);
                });
                // ... and dead domains WHMCS still lists as expired (not at the registrar any more, low priority)
                if ($type !== Types::TYPE_SYNC_MISMATCH) {
                    $query->where(static function ($q) {
                        $q->where('i.issue_type', '!=', Types::TYPE_SYNC_MISMATCH)
                            ->orWhere('i.issue_subtype', '!=', 'not_at_registrar')
                            ->orWhere('i.priority', Types::PRIORITY_HIGH);
                    });
                }
            }
            if (!$showRedemption) {
                $query->where(static function ($q) {
                    $q->where('i.issue_type', '!=', Types::TYPE_REDEMPTION)
                        ->orWhereNull('i.issue_subtype')
                        ->orWhereNotIn('i.issue_subtype', Types::REDEMPTION_SUBTYPES);
                });
            }
        }

        $registrar = trim((string) ($filters['registrar'] ?? ''));
        if ($registrar === '(none)') {
            $query->where(static function ($q) {
                $q->whereNull('i.registrar')->orWhere('i.registrar', '');
            });
        } elseif ($registrar !== '') {
            $query->where('i.registrar', $registrar);
        }

        $source = $filters['source'] ?? '';
        if ($source !== '' && in_array($source, Types::SOURCES, true)) {
            $query->where('i.source', $source);
        }

        $priority = $filters['priority'] ?? '';
        if ($priority !== '' && in_array($priority, Types::PRIORITIES, true)) {
            $query->where('i.priority', $priority);
        }

        if (!empty($filters['urgent'])) {
            $this->applyUrgentCondition($query, $urgentDays, 'i');
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $query->where(static function ($sub) use ($like, $q) {
                $sub->where('i.domain_name', 'like', $like)
                    ->orWhere('c.firstname', 'like', $like)
                    ->orWhere('c.lastname', 'like', $like)
                    ->orWhere('c.companyname', 'like', $like)
                    ->orWhere('c.email', 'like', $like)
                    ->orWhere('i.details', 'like', $like);
                if (ctype_digit($q)) {
                    $sub->orWhere('i.client_id', (int) $q)->orWhere('i.id', (int) $q);
                }
            });
        }
    }

    private function applyUrgentCondition(\Illuminate\Database\Query\Builder $query, int $days, string $alias): void
    {
        $query->where(static function ($q) use ($days, $alias) {
            $q->where($alias . '.priority', Types::PRIORITY_HIGH)
                ->orWhere(static function ($d) use ($days, $alias) {
                    $d->whereNotNull($alias . '.deadline')
                        ->whereRaw($alias . '.deadline <= DATE_ADD(CURDATE(), INTERVAL ? DAY)', [$days]);
                });
        });
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        return (int) Capsule::table(self::TABLE)->insertGetId($data);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }
        Capsule::table(self::TABLE)->where('id', $id)->update($data);
    }

    public function delete(int $id): void
    {
        // Explicit cleanup in case the FK cascade is unavailable (non-InnoDB).
        Capsule::table(self::TABLE_COMMENTS)->where('issue_id', $id)->delete();
        Capsule::table(self::TABLE_LOG)->where('issue_id', $id)->delete();
        Capsule::table(self::TABLE)->where('id', $id)->delete();
    }

    // ---------------------------------------------------------------------
    // Counters (widget) — indexed COUNT queries only
    // ---------------------------------------------------------------------

    /** @param string[] $types */
    public function countOpenByTypes(array $types): int
    {
        if ($types === []) {
            return 0;
        }

        return (int) Capsule::table(self::TABLE)
            ->whereIn('status', Types::OPEN_STATUSES)
            ->whereIn('issue_type', $types)
            ->count();
    }

    public function countOpenExcludingTypes(array $types): int
    {
        return (int) Capsule::table(self::TABLE)
            ->whereIn('status', Types::OPEN_STATUSES)
            ->whereNotIn('issue_type', $types)
            ->count();
    }

    public function countUrgent(int $days): int
    {
        $query = Capsule::table(self::TABLE . ' as i')->whereIn('i.status', Types::OPEN_STATUSES);
        $this->applyUrgentCondition($query, $days, 'i');

        return (int) $query->count();
    }

    // ---------------------------------------------------------------------
    // Cron support
    // ---------------------------------------------------------------------

    /** @return object[] open issues with source=automatic, oldest first */
    public function listOpenAutomatic(int $limit = 500): array
    {
        return Capsule::table(self::TABLE)
            ->where('source', Types::SOURCE_AUTOMATIC)
            ->whereIn('status', Types::OPEN_STATUSES)
            ->orderBy('updated_at', 'asc')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * Domains whose WHMCS status suggests an in-flight or problematic state,
     * for proactive scanning by the cron (Stage 2).
     *
     * @param string[] $registrars
     * @param string[] $statuses
     * @return object[]
     */
    public function listDomainsForScan(array $registrars, array $statuses, int $limit = 200, int $maxExpiredDays = 0): array
    {
        if ($registrars === [] || $statuses === []) {
            return [];
        }

        $query = Capsule::table('tbldomains')
            ->whereIn('registrar', $registrars)
            ->whereIn('status', $statuses);

        if ($maxExpiredDays > 0) {
            // Pending statuses are always scanned; expired-like statuses only while recent.
            $query->where(static function ($q) use ($maxExpiredDays) {
                $q->whereNotIn('status', ['Expired', 'Grace', 'Redemption'])
                    ->orWhereRaw('expirydate >= DATE_SUB(CURDATE(), INTERVAL ? DAY)', [$maxExpiredDays]);
            });
        }

        // In-flight transfers first, then the most recently expired domains.
        return $query
            ->orderByRaw("FIELD(status, 'Pending Transfer', 'Pending', 'Pending Registration', 'Redemption', 'Grace', 'Expired')")
            ->orderBy('expirydate', 'desc')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * tbldomains rows for the given names at the given registrars (adapter-driven scan).
     *
     * @param string[] $names
     * @param string[] $registrars
     * @return object[]
     */
    public function findDomainsByNames(array $names, array $registrars = []): array
    {
        if ($names === []) {
            return [];
        }

        $query = Capsule::table('tbldomains');
        if ($registrars !== []) {
            $query->whereIn('registrar', $registrars);
        }

        return $query
            ->whereIn('domain', array_values(array_unique($names)))
            ->whereNotIn('status', ['Cancelled', 'Fraud', 'Transferred Away'])
            ->orderBy('id', 'desc')
            ->get()
            ->all();
    }

    // ---------------------------------------------------------------------
    // Comments
    // ---------------------------------------------------------------------

    /** @return object[] */
    public function comments(int $issueId): array
    {
        return Capsule::table(self::TABLE_COMMENTS . ' as cm')
            ->leftJoin('tbladmins as a', 'a.id', '=', 'cm.admin_id')
            ->select('cm.*', 'a.username as admin_username', 'a.firstname as admin_firstname', 'a.lastname as admin_lastname')
            ->where('cm.issue_id', $issueId)
            ->orderBy('cm.created_at', 'asc')
            ->orderBy('cm.id', 'asc')
            ->get()
            ->all();
    }

    public function addComment(int $issueId, ?int $adminId, string $comment): int
    {
        return (int) Capsule::table(self::TABLE_COMMENTS)->insertGetId([
            'issue_id' => $issueId,
            'admin_id' => $adminId,
            'comment' => $comment,
            'created_at' => self::now(),
        ]);
    }

    // ---------------------------------------------------------------------
    // Change log
    // ---------------------------------------------------------------------

    /** @return object[] */
    public function logs(int $issueId): array
    {
        return Capsule::table(self::TABLE_LOG . ' as l')
            ->leftJoin('tbladmins as a', 'a.id', '=', 'l.admin_id')
            ->select('l.*', 'a.username as admin_username', 'a.firstname as admin_firstname', 'a.lastname as admin_lastname')
            ->where('l.issue_id', $issueId)
            ->orderBy('l.created_at', 'desc')
            ->orderBy('l.id', 'desc')
            ->get()
            ->all();
    }

    /** True when an admin (not the system) ever changed this field on the issue. */
    public function hasManualChange(int $issueId, string $field): bool
    {
        return Capsule::table(self::TABLE_LOG)
            ->where('issue_id', $issueId)
            ->where('field', $field)
            ->whereNotNull('admin_id')
            ->exists();
    }

    public function addLog(int $issueId, ?int $adminId, string $field, ?string $old, ?string $new): void
    {
        Capsule::table(self::TABLE_LOG)->insert([
            'issue_id' => $issueId,
            'admin_id' => $adminId,
            'field' => mb_substr($field, 0, 32),
            'old_value' => $old === null ? null : mb_substr($old, 0, 64),
            'new_value' => $new === null ? null : mb_substr($new, 0, 64),
            'created_at' => self::now(),
        ]);
    }

    // ---------------------------------------------------------------------
    // WHMCS lookups (domains, clients, registrars)
    // ---------------------------------------------------------------------

    /** @return string[] distinct registrar slugs (configured registrars + ones already used in issues) */
    public function knownRegistrars(): array
    {
        $fromRegistrars = Capsule::table('tblregistrars')
            ->distinct()
            ->pluck('registrar')
            ->all();
        $fromIssues = Capsule::table(self::TABLE)
            ->whereNotNull('registrar')
            ->where('registrar', '!=', '')
            ->distinct()
            ->pluck('registrar')
            ->all();

        $all = array_unique(array_filter(array_map('strval', array_merge($fromRegistrars, $fromIssues))));
        sort($all, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($all);
    }

    /** @return object[] matching tbldomains rows with client name, for autocomplete */
    public function searchDomains(string $q, int $limit = 15): array
    {
        $like = '%' . addcslashes($q, '%_\\') . '%';

        return Capsule::table('tbldomains as d')
            ->leftJoin('tblclients as c', 'c.id', '=', 'd.userid')
            ->select('d.id', 'd.domain', 'd.userid', 'd.registrar', 'd.status', 'd.expirydate',
                'c.firstname', 'c.lastname', 'c.companyname')
            ->where('d.domain', 'like', $like)
            ->orderBy('d.domain')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function findDomainByName(string $name): ?object
    {
        $row = Capsule::table('tbldomains')
            ->where('domain', $name)
            ->orderBy('id', 'desc')
            ->first();

        return $row ?: null;
    }

    public function findDomainById(int $id): ?object
    {
        $row = Capsule::table('tbldomains')->where('id', $id)->first();

        return $row ?: null;
    }

    public function clientExists(int $id): bool
    {
        return Capsule::table('tblclients')->where('id', $id)->exists();
    }

    public function clientDisplayName(int $id): ?string
    {
        $row = Capsule::table('tblclients')
            ->select('firstname', 'lastname', 'companyname')
            ->where('id', $id)
            ->first();

        return $row ? self::formatClientName($row->firstname, $row->lastname, $row->companyname) : null;
    }

    public static function formatClientName(?string $first, ?string $last, ?string $company): string
    {
        $name = trim(($first ?? '') . ' ' . ($last ?? ''));
        if ($company !== null && $company !== '') {
            $name = $name !== '' ? $name . ' (' . $company . ')' : $company;
        }

        return $name;
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
