<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\DomainIssues\Adapters;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\DomainIssues\IssueDTO;
use WHMCS\Module\Addon\DomainIssues\Types;

/**
 * Netim adapter — Stage 2.
 *
 * Sources, in order of preference:
 *   1. mod_netim_async (table of the Netim registrar module): one row per
 *      asynchronous operation still open — ID_OPE, DOMAINE, CODE_OPE
 *      (domainTransferIn / domainCreate / domainRenew ...), DATE_OPE.
 *      Rows disappear when the module's cron processes the result.
 *   2. tbldomains.additionalnotes written by the module:
 *        "domainTransferIn failed with error : E18-M1807 : Transfer error - ..."
 *        "domainCreate (#13943729) failed with error : E12-M1215 : ... Zonecheck failed"
 *   3. The module's SOAP client (modules/registrars/netim/api/APISoap.php) with
 *      the module credentials (Username / Password / Sandbox):
 *        queryOpe(id)       status + message of an operation
 *        domainInfo(domain) registration status / expiry / max restore date
 *
 * Live field names (validated 2026-10-13):
 *   queryOpe    -> {"ID_OPE":14074059,"STATUS":"Done","MESSAGE":"","TYPE":"domainTransferIn","DATE":"2026-10-07 11:30:34"}
 *   domainInfo  -> {"domain","dateCreate","dateExpiration","dateMinRenew","dateMaxRenew","dateMaxRestore",
 *                   "status":"EXPIRED, HOLD, NOT DELEGATED","domainIsLock","autoRenew","ns","authID",...}
 * Values are still read case-insensitively from several candidates; every raw
 * answer is written to the Module Log (domainissues:netim) with auth codes masked.
 */
final class NetimAdapter extends AbstractAdapter
{
    private const ASYNC_TABLE = 'mod_netim_async';

    /** Operations still "pending" after this many days are reported. */
    private const PENDING_WINDOW_DAYS = 7;

    /** Open async operations older than this are added to the proactive scan. */
    private const ASYNC_SCAN_MIN_AGE_DAYS = 1;

    /** @var array<string, mixed>|null */
    private ?array $config = null;

    private ?object $api = null;

    public function getRegistrarSlug(): string
    {
        return 'netim';
    }

    public function supportedIssueTypes(): array
    {
        return [Types::TYPE_TRANSFER_IN, Types::TYPE_REGISTRATION, Types::TYPE_REDEMPTION, Types::TYPE_SYNC_MISMATCH];
    }

    public function __destruct()
    {
        $this->closeSession();
    }

    /**
     * Domains with an open asynchronous operation at Netim older than one day,
     * whatever their WHMCS status (a stuck transfer may still show as Active).
     *
     * @return string[]
     */
    public function additionalDomainsToScan(): array
    {
        try {
            if (!Capsule::schema()->hasTable(self::ASYNC_TABLE)) {
                return [];
            }

            return Capsule::table(self::ASYNC_TABLE)
                ->where('DATE_OPE', '<=', date('Y-m-d H:i:s', time() - self::ASYNC_SCAN_MIN_AGE_DAYS * 86400))
                ->whereIn('CODE_OPE', ['domainTransferIn', 'domainCreate', 'domainTransferTrade', 'domainInternalTransfer'])
                ->pluck('DOMAINE')
                ->map(static fn ($d): string => strtolower(trim((string) $d)))
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            $this->log('async_table', [], $e->getMessage());

            return [];
        }
    }

    /**
     * @param array<string, mixed> $whmcsDomainRow
     * @return IssueDTO[]
     * @throws \RuntimeException when the API cannot be used (the cron then leaves issues untouched)
     */
    public function checkDomain(array $whmcsDomainRow): array
    {
        $domain = strtolower(trim((string) ($whmcsDomainRow['domain'] ?? '')));
        if ($domain === '') {
            return [];
        }
        $whmcsStatus = (string) ($whmcsDomainRow['status'] ?? '');
        $whmcsExpiry = $this->toDate((string) ($whmcsDomainRow['expirydate'] ?? ''));
        $ace = function_exists('idn_to_ascii') ? ((string) (idn_to_ascii($domain) ?: $domain)) : $domain;
        $notes = (string) ($whmcsDomainRow['additionalnotes'] ?? '');

        $issues = [];

        // --- Pending operations (transfer / registration) ------------------
        $asyncOps = $this->asyncOperations($ace);

        // WHMCS assigns the domain to another registrar, yet Netim has an open
        // operation for it (e.g. a transfer Enom -> Netim in progress).
        $whmcsRegistrar = strtolower((string) ($whmcsDomainRow['registrar'] ?? ''));
        if ($whmcsRegistrar !== '' && $whmcsRegistrar !== $this->getRegistrarSlug()) {
            foreach ($asyncOps as $op) {
                $opId = (int) $op->ID_OPE;
                $status = 'unknown';
                $message = '';
                try {
                    $result = $this->api()->queryOpe($opId);
                    $this->log('queryOpe', ['domain' => $ace, 'id' => $opId], $this->dump($result));
                    $status = (string) ($this->field($result, ['STATUS', 'status', 'Status']) ?? 'unknown');
                    $message = (string) ($this->field($result, ['MESSAGE', 'message', 'Message']) ?? '');
                } catch (\Throwable $e) {
                    $this->log('queryOpe', ['domain' => $ace, 'id' => $opId], $e->getMessage());
                    if ($this->isTransportError($e)) {
                        throw new \RuntimeException('Netim queryOpe failed: ' . $e->getMessage(), 0, $e);
                    }
                }
                $done = preg_match('/done|success|complet/i', $status) === 1;
                $advice = $done
                    ? 'The operation is complete: change the WHMCS registrar to netim now, otherwise renewals will be sent to the wrong registrar.'
                    : 'Once the operation completes, change the WHMCS registrar to netim so renewals go to the right place.';
                $issues[] = new IssueDTO(
                    domainName: $domain,
                    issueType: Types::TYPE_SYNC_MISMATCH,
                    issueSubtype: 'registrar_mismatch',
                    registrar: $this->getRegistrarSlug(),
                    domainId: isset($whmcsDomainRow['id']) ? (int) $whmcsDomainRow['id'] : null,
                    clientId: isset($whmcsDomainRow['userid']) ? (int) $whmcsDomainRow['userid'] : null,
                    details: sprintf(
                        'Netim has operation #%d (%s, %s) with status "%s"%s for this domain, but WHMCS lists the registrar as "%s" (status "%s"). %s',
                        $opId,
                        (string) $op->CODE_OPE,
                        $this->toDate((string) $op->DATE_OPE) ?? 'date n/a',
                        $status,
                        $message !== '' ? ' (' . $message . ')' : '',
                        $whmcsRegistrar,
                        $whmcsStatus,
                        $advice
                    ),
                    deadline: null,
                    priority: Types::PRIORITY_HIGH,
                );
            }

            return $issues;
        }

        if ($whmcsStatus === 'Pending Transfer') {
            $issues[] = $this->checkOperation($whmcsDomainRow, $ace, $notes, $asyncOps, 'domainTransferIn', Types::TYPE_TRANSFER_IN, $whmcsStatus);
        } elseif (in_array($whmcsStatus, ['Pending', 'Pending Registration'], true)) {
            $issues[] = $this->checkOperation($whmcsDomainRow, $ace, $notes, $asyncOps, 'domainCreate', Types::TYPE_REGISTRATION, $whmcsStatus);
        } elseif ($asyncOps !== []) {
            // WHMCS no longer shows it pending, yet Netim still has an open operation.
            foreach ($asyncOps as $op) {
                $code = (string) $op->CODE_OPE;
                $type = $code === 'domainCreate' ? Types::TYPE_REGISTRATION : Types::TYPE_TRANSFER_IN;
                $issues[] = $this->checkOperation($whmcsDomainRow, $ace, $notes, [$op], $code, $type, $whmcsStatus);
            }
        }

        // --- Lifecycle ----------------------------------------------------------
        $lifecycle = in_array($whmcsStatus, ['Expired', 'Grace', 'Redemption'], true)
            || ($whmcsStatus === 'Active' && $whmcsExpiry !== null && $whmcsExpiry <= date('Y-m-d'));
        if ($lifecycle) {
            $issues[] = $this->checkLifecycle($whmcsDomainRow, $whmcsStatus, $whmcsExpiry, $ace);
        }

        return array_values(array_filter($issues));
    }

    // ---------------------------------------------------------------------
    // Operations (transfer in / registration)
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     * @param object[] $asyncOps rows of mod_netim_async for this domain
     */
    private function checkOperation(array $row, string $ace, string $notes, array $asyncOps, string $expectedCode, string $issueType, string $whmcsStatus): ?IssueDTO
    {
        // 1. Open operation known locally -> ask Netim for its status.
        foreach ($asyncOps as $op) {
            if ($expectedCode !== '' && (string) $op->CODE_OPE !== $expectedCode) {
                continue;
            }
            $opId = (int) $op->ID_OPE;
            $opDate = $this->toDate((string) $op->DATE_OPE);
            try {
                $result = $this->api()->queryOpe($opId);
                $this->log('queryOpe', ['domain' => $ace, 'id' => $opId], $this->dump($result));
            } catch (\Throwable $e) {
                $this->log('queryOpe', ['domain' => $ace, 'id' => $opId], $e->getMessage());
                if ($this->isTransportError($e)) {
                    throw new \RuntimeException('Netim queryOpe failed: ' . $e->getMessage(), 0, $e);
                }
                $result = null;
            }

            return $this->issueFromOperation($row, $issueType, $whmcsStatus, $opId, (string) $op->CODE_OPE, $opDate, $result);
        }

        // 2. Failure recorded by the module in the admin notes.
        $failure = $this->failureFromNotes($notes, $expectedCode);
        if ($failure !== null) {
            [$opCode, $code, $message] = $failure;

            return $this->issue(
                $row,
                $issueType,
                $this->subtypeFromMessage($message, $code, $issueType),
                sprintf('Netim %s failed: %s : %s (from the domain admin notes). WHMCS still shows "%s".', $opCode, $code, $message, $whmcsStatus),
                null,
                Types::PRIORITY_HIGH
            );
        }

        // 3. Nothing pending and no failure: is the domain already in our account?
        try {
            $info = $this->api()->domainInfo($ace);
            $this->log('domainInfo', ['domain' => $ace], $this->dump($info));

            return $this->issue(
                $row,
                Types::TYPE_SYNC_MISMATCH,
                $issueType === Types::TYPE_REGISTRATION ? 'registered_at_registrar' : 'transfer_completed',
                sprintf('Domain is in our Netim account (status "%s") but WHMCS still shows "%s" — run Sync or set the domain Active.', (string) $this->field($info, ['status', 'STATUS', 'Status']), $whmcsStatus)
            );
        } catch (\Throwable $e) {
            $this->log('domainInfo', ['domain' => $ace], $e->getMessage());
            if ($this->isTransportError($e)) {
                throw new \RuntimeException('Netim domainInfo failed: ' . $e->getMessage(), 0, $e);
            }

            return $this->issue(
                $row,
                $issueType,
                'registrar_error',
                sprintf('WHMCS shows "%s" but Netim has no pending %s operation for this domain and it is not in our account (%s). Submit or check the request at Netim.', $whmcsStatus, $expectedCode, $e->getMessage()),
                null,
                Types::PRIORITY_HIGH
            );
        }
    }

    /** @param array<string, mixed> $row */
    private function issueFromOperation(array $row, string $issueType, string $whmcsStatus, int $opId, string $opCode, ?string $opDate, ?object $result): ?IssueDTO
    {
        $status = strtolower((string) $this->field($result, ['STATUS', 'status', 'Status']));
        $message = (string) $this->field($result, ['MESSAGE', 'message', 'Message', 'ERROR', 'error']);
        $summary = sprintf('Netim operation #%d (%s, %s) status "%s"%s', $opId, $opCode, $opDate ?? 'date n/a', $status !== '' ? $status : 'unknown', $message !== '' ? ': ' . $message : '');

        if (str_contains($status, 'done') || str_contains($status, 'success') || str_contains($status, 'complet')) {
            return $this->issue(
                $row,
                Types::TYPE_SYNC_MISMATCH,
                $issueType === Types::TYPE_REGISTRATION ? 'registered_at_registrar' : 'transfer_completed',
                $summary . '. WHMCS still shows "' . $whmcsStatus . '" — run the Netim sync or set the domain Active.'
            );
        }
        if (str_contains($status, 'cancel')) {
            return $this->issue($row, $issueType, 'transfer_cancelled', $summary . '. Resolve the cause and resubmit.', null, Types::PRIORITY_HIGH);
        }
        if (str_contains($status, 'fail') || str_contains($status, 'error') || str_contains($status, 'reject')) {
            return $this->issue($row, $issueType, $this->subtypeFromMessage($message, '', $issueType), $summary . '.', null, Types::PRIORITY_HIGH);
        }

        // pending / unknown: a problem only when it has been pending for too long
        $age = $opDate !== null ? $this->daysSince($opDate) : null;
        if ($age !== null && $age <= self::PENDING_WINDOW_DAYS) {
            return null;
        }

        return $this->issue(
            $row,
            $issueType,
            'pending_approval',
            $summary . ($age !== null ? sprintf(' — pending for %d days.', $age) : '.') . ' Check registrant approval / auth code / documents at Netim.',
            null,
            Types::PRIORITY_HIGH
        );
    }

    // ---------------------------------------------------------------------
    // Lifecycle
    // ---------------------------------------------------------------------

    /** @param array<string, mixed> $row */
    private function checkLifecycle(array $row, string $whmcsStatus, ?string $whmcsExpiry, string $ace): ?IssueDTO
    {
        try {
            $info = $this->api()->domainInfo($ace);
            $this->log('domainInfo', ['domain' => $ace], $this->dump($info));
        } catch (\Throwable $e) {
            $this->log('domainInfo', ['domain' => $ace], $e->getMessage());
            if ($this->isTransportError($e)) {
                throw new \RuntimeException('Netim domainInfo failed: ' . $e->getMessage(), 0, $e);
            }

            return $this->issue(
                $row,
                Types::TYPE_SYNC_MISMATCH,
                'not_at_registrar',
                sprintf('Netim reports "%s" while WHMCS status is "%s" (WHMCS expiry %s). The domain was deleted after expiry or transferred away — set it Cancelled / Transferred Away in WHMCS.', $e->getMessage(), $whmcsStatus, $whmcsExpiry ?? 'n/a'),
                null,
                $whmcsStatus === 'Active' ? Types::PRIORITY_HIGH : Types::PRIORITY_LOW
            );
        }

        // Live field names (validated 2026-10-13): domain, dateCreate, dateExpiration, dateMinRenew,
        // dateMaxRenew, dateMaxRestore, status ("EXPIRED, HOLD, NOT DELEGATED"), domainIsLock, autoRenew, ns, authID
        $statusRaw = (string) $this->field($info, ['status', 'STATUS', 'Status', 'domainStatus']);
        $status = strtolower($statusRaw);
        $expiry = $this->toDate((string) $this->field($info, ['dateExpiration', 'expiration', 'dateExpire', 'expire', 'expires', 'DATE_EXPIRATION', 'expirationDate'])) ?? $whmcsExpiry;
        $maxRenew = $this->toDate((string) $this->field($info, ['dateMaxRenew', 'maxRenew', 'DATE_MAX_RENEW']));
        $maxRestore = $this->toDate((string) $this->field($info, ['dateMaxRestore', 'maxRestore', 'DATE_MAX_RESTORE', 'dateRestore']));
        $today = date('Y-m-d');

        if ($status === '' || str_contains($status, 'active') || $status === 'ok') {
            if ($expiry !== null && $expiry <= $today) {
                return $this->issue($row, Types::TYPE_REDEMPTION, 'expired_grace', sprintf('Netim status "%s", expiry %s has passed. Renewable at the normal price until %s.', $statusRaw, $expiry, $maxRenew ?? 'unknown'), $maxRenew ?? $this->plusDays($expiry, 30), Types::PRIORITY_LOW);
            }

            return null;
        }
        if (str_contains($status, 'quarantine') || str_contains($status, 'redemption') || str_contains($status, 'restore')) {
            return $this->issue($row, Types::TYPE_REDEMPTION, 'redemption_period', sprintf('Netim status "%s" (expiry %s). Restorable until %s (restore fee).', $statusRaw, $expiry ?? 'n/a', $maxRestore ?? 'unknown'), $maxRestore ?? ($expiry !== null ? $this->plusDays($expiry, 40) : null), Types::PRIORITY_NORMAL);
        }
        if (str_contains($status, 'delete')) {
            return $this->issue($row, Types::TYPE_REDEMPTION, 'pending_delete', sprintf('Netim status "%s" (expiry %s). Domain is pending deletion.', $statusRaw, $expiry ?? 'n/a'), $maxRestore, Types::PRIORITY_HIGH);
        }
        if (str_contains($status, 'expire')) {
            // Past dateMaxRenew the domain can only be restored (fee) -> treat as redemption.
            if ($maxRenew !== null && $maxRenew < $today) {
                return $this->issue($row, Types::TYPE_REDEMPTION, 'redemption_period', sprintf('Netim status "%s" (expiry %s). Normal renewal ended %s; restorable until %s (restore fee).', $statusRaw, $expiry ?? 'n/a', $maxRenew, $maxRestore ?? 'unknown'), $maxRestore ?? $this->plusDays($maxRenew, 30), Types::PRIORITY_NORMAL);
            }

            return $this->issue($row, Types::TYPE_REDEMPTION, 'expired_grace', sprintf('Netim status "%s" (expiry %s). Renewable at the normal price until %s.', $statusRaw, $expiry ?? 'n/a', $maxRenew ?? 'unknown'), $maxRenew ?? ($expiry !== null ? $this->plusDays($expiry, 30) : null), Types::PRIORITY_LOW);
        }

        return $this->issue($row, Types::TYPE_REDEMPTION, 'other', sprintf('Netim status "%s" (expiry %s) — check at Netim.', $statusRaw, $expiry ?? 'n/a'), $maxRestore, Types::PRIORITY_NORMAL);
    }

    // ---------------------------------------------------------------------
    // Local data of the Netim module
    // ---------------------------------------------------------------------

    /** @return object[] open async operations for the domain, newest first */
    private function asyncOperations(string $ace): array
    {
        try {
            if (!Capsule::schema()->hasTable(self::ASYNC_TABLE)) {
                return [];
            }

            return Capsule::table(self::ASYNC_TABLE)
                ->whereRaw('LOWER(DOMAINE) = ?', [strtolower($ace)])
                ->orderBy('DATE_OPE', 'desc')
                ->get()
                ->all();
        } catch (\Throwable $e) {
            $this->log('async_table', ['domain' => $ace], $e->getMessage());

            return [];
        }
    }

    /**
     * Parse the last "<opCode> [(#id)] failed with error : E18-M1807 : message" line.
     *
     * @return array{0:string,1:string,2:string}|null [opCode, errorCode, message]
     */
    private function failureFromNotes(string $notes, string $expectedCode): ?array
    {
        $notes = html_entity_decode($notes, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($notes === '' || !preg_match_all('/(domain[A-Za-z]+|\{\{[^}]+\}\})[^\r\n]*?failed with error\s*:\s*(E\d{1,3}-M\d{3,5})\s*:\s*([^\r\n]+)/i', $notes, $m, PREG_SET_ORDER)) {
            return null;
        }
        foreach (array_reverse($m) as $match) { // newest last in the notes
            $opCode = $match[1];
            if ($expectedCode !== '' && stripos($opCode, 'domain') === 0 && strcasecmp($opCode, $expectedCode) !== 0) {
                continue; // a failure of another operation type
            }
            if (str_starts_with($opCode, '{{')) {
                $opCode = $expectedCode !== '' ? $expectedCode : 'operation';
            }

            return [$opCode, strtoupper($match[2]), trim($match[3])];
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // Account balance (1.6.0)
    // ---------------------------------------------------------------------

    /** Netim API calls that return the reseller account, most likely first. */
    public const BALANCE_METHODS = ['queryResellerAccount', 'resellerAccountInfo', 'queryAccount', 'accountInfo'];

    /** Field names that may carry the prepaid balance. */
    private const BALANCE_FIELDS = ['balance_amount', 'balanceAmount', 'BALANCE_AMOUNT', 'balance', 'BALANCE', 'solde', 'amount'];

    /**
     * Prepaid balance of the Netim reseller account, through the SOAP client of
     * the netim registrar module (queryResellerAccount in the Netim API).
     *
     * @return array{balance: float, currency: string, info: string}
     * @throws \RuntimeException when Netim cannot be asked or gives no balance
     */
    public function accountBalance(): array
    {
        $api = $this->api();
        $errors = [];
        foreach (self::BALANCE_METHODS as $method) {
            if (!method_exists($api, $method) && !method_exists($api, '__call')) {
                continue;
            }
            try {
                $result = $api->{$method}();
            } catch (\Throwable $e) {
                $this->log($method, [], $e->getMessage());
                if ($this->isTransportError($e) && stripos($e->getMessage(), 'function') === false && stripos($e->getMessage(), 'method') === false) {
                    throw new \RuntimeException('Netim ' . $method . ' failed: ' . $e->getMessage(), 0, $e);
                }
                $errors[] = $method . ': ' . $e->getMessage();
                continue;
            }
            $this->log($method, [], $this->dump($result));
            $data = self::flatten($result);
            $balance = null;
            foreach (self::BALANCE_FIELDS as $field) {
                $key = strtolower($field);
                if (isset($data[$key]) && is_numeric(str_replace(',', '.', (string) $data[$key]))) {
                    $balance = (float) str_replace(',', '.', (string) $data[$key]);
                    break;
                }
            }
            if ($balance === null) {
                $errors[] = $method . ': no balance field in ' . implode(', ', array_keys($data));
                continue;
            }
            $currency = strtoupper((string) ($data['currency'] ?? $data['devise'] ?? 'EUR'));
            $low = $data['balance_low_limit'] ?? null;
            $info = is_numeric($low) ? sprintf('Netim low limit set at Netim: %.2f %s', (float) $low, $currency) : '';

            return ['balance' => $balance, 'currency' => preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'EUR', 'info' => $info];
        }

        throw new \RuntimeException('Netim client gives no account balance (' . ($errors !== [] ? implode('; ', $errors) : 'none of ' . implode(', ', self::BALANCE_METHODS) . ' exists in ' . get_class($api)) . ')');
    }

    /**
     * Nested stdClass / array -> one level, lower-case keys (first value wins).
     *
     * @return array<string, scalar>
     */
    private static function flatten(mixed $value, int $depth = 0): array
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (!is_array($value) || $depth > 4) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (is_scalar($v)) {
                $out[strtolower((string) $k)] ??= $v;
            } else {
                $out += self::flatten($v, $depth + 1);
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------------
    // Netim API client (from the registrar module)
    // ---------------------------------------------------------------------

    private function api(): object
    {
        if ($this->api !== null) {
            return $this->api;
        }

        $root = defined('ROOTDIR') ? ROOTDIR : dirname(__DIR__, 5);
        $file = $root . '/modules/registrars/netim/api/APISoap.php';
        if (!class_exists('\\Netim\\APISoap')) {
            if (!is_file($file)) {
                throw new \RuntimeException('Netim registrar module not found (' . $file . ')');
            }
            require_once $file;
        }
        if (!class_exists('\\Netim\\APISoap')) {
            throw new \RuntimeException('Netim API client class could not be loaded');
        }

        $config = $this->config ??= $this->registrarConfig();
        $user = (string) ($this->field($config, ['Username', 'username', 'Login', 'login', 'UserID', 'userid']) ?? '');
        $pass = (string) ($this->field($config, ['Password', 'password']) ?? '');
        $sandboxRaw = strtolower((string) ($this->field($config, ['Sandbox', 'sandbox', 'TestMode', 'testmode', 'OTE', 'ote']) ?? ''));
        $sandbox = in_array($sandboxRaw, ['on', '1', 'yes', 'true'], true);

        if ($user === '' || $pass === '') {
            throw new \RuntimeException('Netim registrar credentials are not configured');
        }

        try {
            $class = '\\Netim\\APISoap';
            $this->api = new $class($user, $pass, $sandbox);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Netim API connection failed: ' . $e->getMessage(), 0, $e);
        }

        return $this->api;
    }

    private function closeSession(): void
    {
        if ($this->api !== null && method_exists($this->api, 'sessionClose')) {
            try {
                $this->api->sessionClose();
            } catch (\Throwable $e) {
                // ignore
            }
        }
        $this->api = null;
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** First matching field of a stdClass/array, case-insensitively. */
    private function field(mixed $obj, array $candidates): mixed
    {
        $data = is_object($obj) ? get_object_vars($obj) : (is_array($obj) ? $obj : []);
        if ($data === []) {
            return null;
        }
        $lower = [];
        foreach ($data as $k => $v) {
            $lower[strtolower((string) $k)] = $v;
        }
        foreach ($candidates as $name) {
            $key = strtolower($name);
            if (array_key_exists($key, $lower) && $lower[$key] !== null && $lower[$key] !== '') {
                return is_scalar($lower[$key]) ? $lower[$key] : null;
            }
        }

        return null;
    }

    private function subtypeFromMessage(string $message, string $code, string $issueType): string
    {
        $m = strtolower($message);
        if ($code === 'E18-M1807' || str_contains($m, 'already netim')) {
            return 'registrar_error'; // already at Netim: internal transfer needed / WHMCS wrong
        }
        if ($issueType === Types::TYPE_TRANSFER_IN) {
            if (preg_match('/auth|epp|password|code/', $m)) {
                return 'invalid_epp';
            }
            if (str_contains($m, 'lock') || str_contains($m, 'prohibited')) {
                return 'domain_locked';
            }
            if (preg_match('/reject|denied|refus|expired|timeout|timed out|not responded/', $m)) {
                return 'approval_expired';
            }
        }
        if (str_contains($m, 'not available') || str_contains($m, 'already registered')) {
            return 'domain_unavailable';
        }
        if (str_contains($m, 'zonecheck') || str_contains($m, 'nameserver') || str_contains($m, 'dns')) {
            return 'nameserver_mismatch';
        }
        if (preg_match('/balance|credit|fund|payment/', $m)) {
            return 'payment_failed';
        }
        if (preg_match('/contact|document|registrant|identity|owner/', $m)) {
            return 'contact_mismatch';
        }

        return 'registrar_error';
    }

    /**
     * Distinguish "cannot talk to Netim" (throw, leave issues untouched) from a
     * genuine API answer such as "domain unknown" (Netim error codes look like E12-M1220).
     */
    private function isTransportError(\Throwable $e): bool
    {
        if (preg_match('/\bE\d{1,3}-M\d{3,5}\b/i', $e->getMessage()) === 1) {
            return false; // a Netim business error: the API is reachable
        }
        if (!is_a($e, 'Netim\\NetimAPIException')) {
            return true; // SoapFault / RuntimeException from the client
        }
        $m = strtolower($e->getMessage());

        return preg_match('/connexion|connection|timeout|timed out|could not connect|http\/|wsdl|session|login|password|authentication|unavailable/', $m) === 1;
    }

    private function dump(mixed $value): string
    {
        $json = json_encode($this->mask($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $json !== false ? $json : print_r($this->mask($value), true);
    }

    /** Never write auth codes / passwords to the Module Log. */
    private function mask(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = preg_match('/auth|pass|secret|pw$/i', (string) $k) ? '***' : $this->mask($v);
        }

        return $out;
    }

    private function daysSince(string $date): ?int
    {
        $d = $this->toDate($date);
        if ($d === null) {
            return null;
        }
        $diff = (new \DateTimeImmutable('today'))->diff(new \DateTimeImmutable($d));

        return -(int) $diff->format('%r%a');
    }

    private function plusDays(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date))->modify('+' . $days . ' days')->format('Y-m-d');
    }

    private function toDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || str_starts_with($value, '0000')) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
