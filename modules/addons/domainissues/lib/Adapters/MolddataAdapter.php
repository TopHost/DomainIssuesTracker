<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\DomainIssues\Adapters;

use WHMCS\Module\Addon\DomainIssues\IssueDTO;
use WHMCS\Module\Addon\DomainIssues\Types;

/**
 * MoldData (nic.md) adapter — Stage 2.
 *
 * Talks to the nic.md registrar interface exactly like the WHMCS "molddata"
 * registrar module does (an EPP-like XML protocol over a TLS socket with a
 * 4-byte length prefix; account credentials inside the <domain:info> command).
 * Credentials come from the module configuration: EPPUser, EPPPassword,
 * EPPHost, EPPPort (connection) and Login, Password (nic.md account).
 *
 * Only the read-only <info> command is used. Response markers (from the
 * module source):
 *   <result code="1000">            success
 *   <domain:name res="0">           "<domain> not registered"
 *   <domain:name res="1">           in our account (full info follows)
 *   <domain:name res="3">           "<domain> not in account" (res="2" in the module source; live answers use 3)
 *   <domain:exp_date>YYYY-MM-DD..   expiry date
 *
 * nic.md exposes no transfer-status query, so transfer problems are inferred
 * from "is the domain in our account yet?" while WHMCS says Pending Transfer.
 */
final class MolddataAdapter extends AbstractAdapter
{
    private const TIMEOUT = 20;

    /** Estimated renewal grace after expiry at nic.md (adjust if the registry differs). */
    private const GRACE_DAYS = 30;

    /** @var array<string, mixed>|null */
    private ?array $config = null;

    /** @var resource|null persistent nic.md session for the current run */
    private $fp = null;

    public function getRegistrarSlug(): string
    {
        return 'molddata';
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    public function supportedIssueTypes(): array
    {
        return [Types::TYPE_TRANSFER_IN, Types::TYPE_REDEMPTION, Types::TYPE_SYNC_MISMATCH];
    }

    /**
     * @param array<string, mixed> $whmcsDomainRow
     * @return IssueDTO[]
     * @throws \RuntimeException when the registry cannot be queried (the cron then leaves issues untouched)
     */
    public function checkDomain(array $whmcsDomainRow): array
    {
        $domain = strtolower(trim((string) ($whmcsDomainRow['domain'] ?? '')));
        if ($domain === '') {
            return [];
        }
        if (!str_ends_with($domain, '.md')) {
            return []; // not a nic.md domain
        }
        $whmcsStatus = (string) ($whmcsDomainRow['status'] ?? '');
        $whmcsExpiry = $this->toDate((string) ($whmcsDomainRow['expirydate'] ?? ''));

        $inFlight = in_array($whmcsStatus, ['Pending Transfer', 'Pending', 'Pending Registration'], true);
        $lifecycle = in_array($whmcsStatus, ['Expired', 'Grace', 'Redemption'], true)
            || ($whmcsStatus === 'Active' && $whmcsExpiry !== null && $whmcsExpiry <= date('Y-m-d'));

        if (!$inFlight && !$lifecycle) {
            return [];
        }

        $info = $this->domainInfo($domain); // throws on connection/login failure

        if ($inFlight) {
            return $this->evaluateInFlight($whmcsDomainRow, $whmcsStatus, $info);
        }

        return $this->evaluateLifecycle($whmcsDomainRow, $whmcsStatus, $whmcsExpiry, $info);
    }

    /**
     * @param array<string, mixed> $row
     * @param array{ok:bool,res:?int,expiry:?string,error:?string} $info
     * @return IssueDTO[]
     */
    private function evaluateInFlight(array $row, string $whmcsStatus, array $info): array
    {
        $type = in_array($whmcsStatus, ['Pending', 'Pending Registration'], true) ? Types::TYPE_REGISTRATION : Types::TYPE_TRANSFER_IN;
        $res = $info['res'];
        $notInAccount = $res === 2 || $res === 3 || str_contains(strtolower($info['nameText'] ?? ''), 'not in account');
        $notRegistered = $res === 0 || str_contains(strtolower($info['nameText'] ?? ''), 'not registered');

        if (!$info['ok']) {
            return [$this->issue($row, $type, 'registrar_error', 'nic.md info command failed: ' . ($info['error'] ?? 'unknown error'), null, Types::PRIORITY_HIGH)];
        }
        if ($notRegistered) {
            return [$this->issue(
                $row,
                $type,
                'registrar_error',
                sprintf('WHMCS shows "%s" but nic.md reports "%s" (not registered). Check the %s request at nic.md.', $whmcsStatus, $info['nameText'] ?? 'not registered', $type === Types::TYPE_REGISTRATION ? 'registration' : 'transfer'),
                null,
                Types::PRIORITY_HIGH
            )];
        }
        if ($notInAccount) {
            // Exists at the registry, still in another account: transfer not completed.
            return [$this->issue(
                $row,
                $type,
                'pending_approval',
                sprintf('WHMCS shows "%s"; nic.md reports the domain exists but is not in our account yet (transfer not completed / awaiting approval or transfer code).', $whmcsStatus),
                null,
                Types::PRIORITY_HIGH
            )];
        }

        // In our account: the operation is done at nic.md, WHMCS was not updated.
        return [$this->issue(
            $row,
            Types::TYPE_SYNC_MISMATCH,
            $type === Types::TYPE_REGISTRATION ? 'registered_at_registrar' : 'transfer_completed',
            sprintf('Domain is in our nic.md account (expiry %s) but WHMCS still shows "%s" — set the domain Active / run Sync.', $info['expiry'] ?? 'n/a', $whmcsStatus)
        )];
    }

    /**
     * @param array<string, mixed> $row
     * @param array{ok:bool,res:?int,expiry:?string,error:?string} $info
     * @return IssueDTO[]
     */
    private function evaluateLifecycle(array $row, string $whmcsStatus, ?string $whmcsExpiry, array $info): array
    {
        if (!$info['ok']) {
            throw new \RuntimeException('nic.md info failed: ' . ($info['error'] ?? 'unknown error'));
        }
        $res = $info['res'];
        $nameText = strtolower($info['nameText'] ?? '');
        $notRegistered = $res === 0 || str_contains($nameText, 'not registered');
        $notInAccount = $res === 2 || $res === 3 || str_contains($nameText, 'not in account');
        if ($notRegistered || $notInAccount) {
            return [$this->issue(
                $row,
                Types::TYPE_SYNC_MISMATCH,
                'not_at_registrar',
                sprintf(
                    'nic.md reports the domain %s while WHMCS status is "%s" (WHMCS expiry %s). Set it Cancelled / Transferred Away in WHMCS.',
                    $notRegistered ? 'is not registered (deleted/released)' : 'is not in our account (moved to another registrar/account)',
                    $whmcsStatus,
                    $whmcsExpiry ?? 'n/a'
                ),
                null,
                $whmcsStatus === 'Active' ? Types::PRIORITY_HIGH : Types::PRIORITY_LOW
            )];
        }

        $expiry = $info['expiry'] ?? $whmcsExpiry;
        if ($expiry === null || $expiry > date('Y-m-d')) {
            return []; // renewed at the registry, nothing to report (WHMCS Sync will catch up)
        }

        $deadline = (new \DateTimeImmutable($expiry))->modify('+' . self::GRACE_DAYS . ' days')->format('Y-m-d');
        $overdue = $deadline < date('Y-m-d');

        return [$this->issue(
            $row,
            Types::TYPE_REDEMPTION,
            $overdue ? 'redemption_period' : 'expired_grace',
            sprintf('nic.md expiry date %s has passed; domain still in our account. Estimated end of renewal grace: %s.', $expiry, $deadline),
            $deadline,
            $overdue ? Types::PRIORITY_NORMAL : Types::PRIORITY_LOW
        )];
    }

    // ---------------------------------------------------------------------
    // nic.md protocol client (mirrors the registrar module, read-only)
    // ---------------------------------------------------------------------

    /**
     * @return array{ok:bool,res:?int,expiry:?string,error:?string,nameText:?string}
     * @throws \RuntimeException on connection / login failure
     */
    private function domainInfo(string $domain): array
    {
        $config = $this->config();
        foreach (['EPPHost', 'EPPPort', 'EPPUser', 'EPPPassword', 'Login', 'Password'] as $key) {
            if (empty($config[$key])) {
                throw new \RuntimeException('MoldData registrar configuration is incomplete (missing ' . $key . ')');
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">'
            . '<command><info><domain:info xmlns:domain="urn:ietf:params:xml:ns:domain-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">'
            . '<domain:account>' . htmlspecialchars((string) $config['Login'], ENT_XML1) . '</domain:account>'
            . '<domain:account_pw>' . htmlspecialchars((string) $config['Password'], ENT_XML1) . '</domain:account_pw>'
            . '<domain:name>' . htmlspecialchars($domain, ENT_XML1) . '</domain:name>'
            . '</domain:info></info><clTRID>di' . date('YmdHis') . '</clTRID></command></epp>';

        $response = '';
        // Two attempts: the registry may drop a long-lived session, in which case
        // the first read comes back empty and we reconnect once.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $fp = $this->session($config);
                $response = $this->send($fp, $xml);
            } catch (\RuntimeException $e) {
                $this->disconnect();
                if ($attempt === 2) {
                    throw $e;
                }
                continue;
            }
            $this->log('domain_info', ['domain' => $domain, 'attempt' => $attempt], $response, [(string) $config['Password'], (string) $config['EPPPassword']]);

            if (str_contains($response, '<result code=')) {
                break;
            }
            $this->disconnect(); // empty / truncated answer: reconnect and retry
        }

        if (!str_contains($response, '<result code=')) {
            throw new \RuntimeException('nic.md returned no result for ' . $domain . ' (' . mb_substr(trim($response), 0, 120) . ')');
        }
        if (!str_contains($response, '<result code="1000">')) {
            $code = preg_match('#<result code="(\d+)"#', $response, $m) ? $m[1] : '?';
            $error = preg_match('#<msg[^>]*>(.+?)</msg>#s', $response, $m) ? trim($m[1]) : 'no message';

            return ['ok' => false, 'res' => null, 'expiry' => null, 'error' => 'result ' . $code . ': ' . $error, 'nameText' => null];
        }

        $res = preg_match('#<domain:name[^>]*\bres="(\d+)"#', $response, $m) ? (int) $m[1] : null;
        $nameText = preg_match('#<domain:name[^>]*>(.*?)</domain:name>#s', $response, $m) ? trim(html_entity_decode($m[1])) : null;
        $expiry = preg_match('#<domain:exp_date>(.+?)</domain:exp_date>#', $response, $m) ? $this->toDate($m[1]) : null;

        return ['ok' => true, 'res' => $res, 'expiry' => $expiry, 'error' => null, 'nameText' => $nameText];
    }

    /**
     * Reuse the open session when possible (one login per cron run instead of one per domain).
     *
     * @param array<string, mixed> $config
     * @return resource
     */
    private function session(array $config)
    {
        if (is_resource($this->fp) && !feof($this->fp)) {
            return $this->fp;
        }
        $this->fp = $this->connect($config);

        return $this->fp;
    }

    private function disconnect(): void
    {
        if (is_resource($this->fp)) {
            $this->logout($this->fp);
        }
        $this->fp = null;
    }

    /**
     * @param array<string, mixed> $config
     * @return resource
     */
    private function connect(array $config)
    {
        $target = 'ssl://' . $config['EPPHost'] . ':' . (int) $config['EPPPort'];
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client($target, $errno, $errstr, self::TIMEOUT);
        if (!$fp) {
            $this->log('connect', $target, 'cannot connect: ' . $errstr);
            throw new \RuntimeException('Cannot connect to nic.md (' . $target . '): ' . $errstr);
        }
        stream_set_timeout($fp, self::TIMEOUT);

        $greeting = $this->readFrame($fp); // server greeting
        if (!str_contains($greeting, '</epp>')) {
            fclose($fp);
            throw new \RuntimeException('No EPP greeting from nic.md');
        }

        $login = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>'
            . '<epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">'
            . '<command><login><clID>' . htmlspecialchars((string) $config['EPPUser'], ENT_XML1) . '</clID>'
            . '<pw>' . htmlspecialchars((string) $config['EPPPassword'], ENT_XML1) . '</pw><options/><svcs/></login><clTRID/></command></epp>';
        $response = $this->send($fp, $login);
        if (!str_contains($response, '<result code="1000">')) {
            fclose($fp);
            $error = preg_match('#<msg>(.+?)</msg>#s', $response, $m) ? trim($m[1]) : 'login refused';
            $this->log('login', ['user' => $config['EPPUser']], $error);
            throw new \RuntimeException('nic.md login failed: ' . $error);
        }

        return $fp;
    }

    /** @param resource $fp */
    private function logout($fp): void
    {
        try {
            $xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>'
                . '<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><command><logout/><clTRID/></command></epp>';
            $this->send($fp, $xml);
        } catch (\Throwable $e) {
            // ignore
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
        }
    }

    /** @param resource $fp */
    private function send($fp, string $command): string
    {
        $header = pack('N', strlen($command) + 4);
        if (@fwrite($fp, $header . $command) === false) {
            throw new \RuntimeException('Write to nic.md failed');
        }
        $raw = $this->readFrame($fp);
        // Drop the 4-byte length prefix (and an optional XML declaration) so the log is clean.
        $pos = strpos($raw, '<epp');
        $decl = strpos($raw, '<?xml');
        if ($decl !== false && ($pos === false || $decl < $pos)) {
            return substr($raw, $decl);
        }

        return $pos !== false ? substr($raw, $pos) : $raw;
    }

    /** Read until the closing </epp> tag, like the registrar module does. @param resource $fp */
    private function readFrame($fp): string
    {
        $buffer = '';
        while (!feof($fp)) {
            $chunk = fread($fp, 8192);
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($fp);
                if (!empty($meta['timed_out'])) {
                    throw new \RuntimeException('nic.md read timeout');
                }
                break;
            }
            $buffer .= $chunk;
            if (str_contains($buffer, '</epp>')) {
                break;
            }
        }

        return $buffer;
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return $this->config ??= $this->registrarConfig();
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
