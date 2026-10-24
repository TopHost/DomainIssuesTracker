<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\DomainIssues\Adapters;

use WHMCS\Module\Addon\DomainIssues\IssueDTO;
use WHMCS\Module\Addon\DomainIssues\Types;

/**
 * Enom adapter (Stage 2).
 *
 * Uses the Enom reseller API with the credentials of the WHMCS "enom"
 * registrar module (Username / Password / TestMode):
 *
 *   TP_GetDetailsByDomain  -> transfer orders for the domain:
 *                             <TransferOrder><orderid/><orderdate/><statusid/><statusdesc/></TransferOrder> (0..n)
 *   GetDomainInfo          -> <status><registrationstatus>Registered|Expired|RGP|...</registrationstatus>
 *                             <expiration>, <deletebydate>
 *
 * Detection rules (validated against live responses on 2026-10-13):
 *   transfer_in   : WHMCS status "Pending Transfer"/"Pending" and the latest
 *                   Enom transfer order needs action (invalid auth code, locked,
 *                   cancelled, rejected, awaiting approval for too long), or no
 *                   order exists at all.
 *   sync_mismatch : transfer completed at Enom but WHMCS still pending; or the
 *                   domain is unknown to Enom while WHMCS considers it expired/active.
 *   redemption    : registrationstatus Expired (grace) / RGP (redemption) / pending delete.
 *
 * Transfer-out problems stay manual (the registrar cannot know that a client
 * wants to move away).
 */
final class EnomAdapter extends AbstractAdapter
{
    private const ENDPOINT_LIVE = 'https://reseller.enom.com/interface.asp';
    private const ENDPOINT_TEST = 'https://resellertest.enom.com/interface.asp';
    private const TIMEOUT = 25;

    /** Typical Enom lifecycle after expiry (estimates; TLD specific). */
    private const GRACE_DAYS = 40;      // renewable at normal price
    private const RGP_DAYS = 30;        // redemption, restore fee
    private const PENDING_DELETE_DAYS = 5;

    /** "Awaiting registry approval" is normal for this many days after the order. */
    private const REGISTRY_WINDOW_DAYS = 7;

    /** Status descriptions that mean the transfer finished successfully. */
    private const TP_COMPLETED_KEYWORDS = ['complete', 'completed', 'transferred', 'success'];

    /** @var array<string, mixed>|null */
    private ?array $config = null;

    public function getRegistrarSlug(): string
    {
        return 'enom';
    }

    public function supportedIssueTypes(): array
    {
        return [Types::TYPE_TRANSFER_IN, Types::TYPE_REDEMPTION, Types::TYPE_SYNC_MISMATCH];
    }

    /**
     * @param array<string, mixed> $whmcsDomainRow
     * @return IssueDTO[]
     */
    public function checkDomain(array $whmcsDomainRow): array
    {
        $domain = strtolower(trim((string) ($whmcsDomainRow['domain'] ?? '')));
        if ($domain === '' || !$this->hasCredentials()) {
            return [];
        }
        [$sld, $tld] = $this->splitDomain($domain);
        $issues = [];

        $whmcsStatus = (string) ($whmcsDomainRow['status'] ?? '');
        if ($whmcsStatus === 'Pending Transfer') {
            $transferIssue = $this->checkTransferIn($whmcsDomainRow, $sld, $tld);
            if ($transferIssue !== null) {
                $issues[] = $transferIssue;
            }
        } elseif (in_array($whmcsStatus, ['Pending', 'Pending Registration'], true)) {
            $registrationIssue = $this->checkRegistration($whmcsDomainRow, $sld, $tld, $whmcsStatus);
            if ($registrationIssue !== null) {
                $issues[] = $registrationIssue;
            }
        }

        $lifecycleIssue = $this->checkLifecycle($whmcsDomainRow, $sld, $tld);
        if ($lifecycleIssue !== null) {
            $issues[] = $lifecycleIssue;
        }

        return $issues;
    }

    // ---------------------------------------------------------------------
    // Transfer in
    // ---------------------------------------------------------------------

    /** @param array<string, mixed> $row */
    private function checkTransferIn(array $row, string $sld, string $tld): ?IssueDTO
    {
        $whmcsStatus = (string) ($row['status'] ?? '');
        // Only WHMCS "in flight" statuses are relevant. Once WHMCS says Active
        // (or anything else) the transfer is not pending any more, whatever the
        // order history at Enom says — this is what lets the cron auto-resolve.
        if ($whmcsStatus !== 'Pending Transfer') {
            return null;
        }

        $xml = $this->call('TP_GetDetailsByDomain', ['SLD' => $sld, 'TLD' => $tld]); // throws on transport error

        $order = $this->latestTransferOrder($xml);
        if ($order === null) {
            return $this->issue(
                $row,
                Types::TYPE_TRANSFER_IN,
                'registrar_error',
                sprintf(
                    'WHMCS shows "%s" but Enom has no transfer order for this domain (%s). Submit the transfer at Enom or correct the WHMCS status.',
                    $whmcsStatus,
                    $this->errorText($xml)
                ),
                null,
                Types::PRIORITY_HIGH
            );
        }

        $orderId = trim((string) ($order->orderid ?? $order->transferorderdetailid ?? '?'));
        $orderDate = $this->toDate((string) ($order->orderdate ?? '')) ?? 'unknown date';
        $statusId = (int) ($order->statusid ?? 0);
        $statusDesc = trim((string) ($order->statusdesc ?? ''));
        $lower = strtolower($statusDesc);
        $summary = sprintf('Enom transfer order %s of %s: [%d] %s', $orderId, $orderDate, $statusId, $statusDesc !== '' ? $statusDesc : 'unknown status');

        foreach (self::TP_COMPLETED_KEYWORDS as $kw) {
            if (str_contains($lower, $kw)) {
                // Done at the registrar, WHMCS not updated: a sync problem, not a transfer problem.
                return $this->issue(
                    $row,
                    Types::TYPE_SYNC_MISMATCH,
                    'transfer_completed',
                    $summary . '. WHMCS still shows "' . $whmcsStatus . '" — run the registrar sync or set the domain Active.'
                );
            }
        }

        $cancelled = str_contains($lower, 'cancel');

        if (str_contains($lower, 'auth') || str_contains($lower, 'epp') || str_contains($lower, 'password')) {
            $subtype = 'invalid_epp';
        } elseif (str_contains($lower, 'lock') || str_contains($lower, '60 days')) {
            $subtype = 'domain_locked';
        } elseif ($cancelled) {
            $subtype = 'transfer_cancelled';
        } elseif (preg_match('/reject|denied|declined|not responded|no response|expired|failed|timed out/', $lower)) {
            $subtype = 'approval_expired';
        } elseif (preg_match('/await|pending|waiting|approval|verification|confirm|fax/', $lower)) {
            // Normal registry window right after the order: not a problem yet.
            if (str_contains($lower, 'registry')) {
                $age = $this->daysSince((string) ($order->orderdate ?? ''));
                if ($age !== null && $age <= self::REGISTRY_WINDOW_DAYS) {
                    return null;
                }
            }
            $subtype = 'pending_approval';
        } else {
            $subtype = 'other';
        }

        // Every blocked transfer needs our involvement and risks losing the domain -> high.
        $priority = Types::PRIORITY_HIGH;
        $hint = $cancelled ? ' The order is cancelled: resolve the cause and resubmit the transfer at Enom.' : '';

        return $this->issue($row, Types::TYPE_TRANSFER_IN, $subtype, $summary . '.' . $hint, null, $priority);
    }

    /**
     * WHMCS "Pending" / "Pending Registration": is the domain in our Enom account?
     *
     * @param array<string, mixed> $row
     */
    private function checkRegistration(array $row, string $sld, string $tld, string $whmcsStatus): ?IssueDTO
    {
        $xml = $this->call('GetDomainInfo', ['SLD' => $sld, 'TLD' => $tld]);
        if ((int) ($xml->ErrCount ?? 0) > 0) {
            return $this->issue(
                $row,
                Types::TYPE_REGISTRATION,
                'registrar_error',
                sprintf('WHMCS shows "%s" but the domain is not in our Enom account (%s). Check the registration order at Enom.', $whmcsStatus, $this->errorText($xml)),
                null,
                Types::PRIORITY_HIGH
            );
        }
        $regStatus = trim((string) ($xml->GetDomainInfo->status->registrationstatus ?? ''));

        return $this->issue(
            $row,
            Types::TYPE_SYNC_MISMATCH,
            'registered_at_registrar',
            sprintf('Domain is in our Enom account (status "%s") but WHMCS still shows "%s" — set the domain Active / run Sync.', $regStatus, $whmcsStatus)
        );
    }

    /**
     * TP_GetDetailsByDomain returns one <TransferOrder> per order (oldest first).
     * Some API variants use <TransferOrderDetail>. Pick the most recent one.
     */
    private function latestTransferOrder(\SimpleXMLElement $xml): ?\SimpleXMLElement
    {
        $best = null;
        $bestTs = -1;
        foreach (['TransferOrder', 'TransferOrderDetail'] as $tag) {
            if (!isset($xml->{$tag})) {
                continue;
            }
            foreach ($xml->{$tag} as $order) {
                $ts = strtotime((string) ($order->orderdate ?? '')) ?: 0;
                if ($ts >= $bestTs) {
                    $best = $order;
                    $bestTs = $ts;
                }
            }
        }

        return $best;
    }

    // ---------------------------------------------------------------------
    // Expiry / redemption / existence at registrar
    // ---------------------------------------------------------------------

    /** @param array<string, mixed> $row */
    private function checkLifecycle(array $row, string $sld, string $tld): ?IssueDTO
    {
        $whmcsStatus = (string) ($row['status'] ?? '');
        if (!in_array($whmcsStatus, ['Active', 'Expired', 'Grace', 'Redemption'], true)) {
            return null;
        }
        $whmcsExpiry = $this->toDate((string) ($row['expirydate'] ?? ''));
        // Healthy, far-from-expiry domains do not deserve an API call.
        if ($whmcsStatus === 'Active' && $whmcsExpiry !== null && ($this->daysSince($whmcsExpiry) ?? 0) < 0) {
            return null;
        }

        $xml = $this->call('GetDomainInfo', ['SLD' => $sld, 'TLD' => $tld]); // throws on transport error

        if ((int) ($xml->ErrCount ?? 0) > 0) {
            $error = $this->errorText($xml);
            if (stripos($error, 'not found') === false) {
                return null; // unexpected API error: cannot assess
            }
            // Enom does not know the domain any more (deleted or transferred away).
            return $this->issue(
                $row,
                Types::TYPE_SYNC_MISMATCH,
                'not_at_registrar',
                sprintf(
                    'Enom reports "%s" while WHMCS status is "%s" (WHMCS expiry %s). The domain was deleted after expiry or transferred away — set it Cancelled / Transferred Away in WHMCS.',
                    $error,
                    $whmcsStatus,
                    $whmcsExpiry ?? 'n/a'
                ),
                null,
                $whmcsStatus === 'Active' ? Types::PRIORITY_HIGH : Types::PRIORITY_LOW
            );
        }

        $info = $xml->GetDomainInfo ?? null;
        $regStatusRaw = trim((string) ($info->status->registrationstatus ?? ''));
        $regStatus = strtolower($regStatusRaw);
        $expiration = $this->toDate((string) ($info->status->expiration ?? '')) ?? $whmcsExpiry;

        if ($regStatus === '' || $regStatus === 'registered' || $regStatus === 'active') {
            return null;
        }

        if ($regStatus === 'rgp' || str_contains($regStatus, 'redemption')) {
            $subtype = 'redemption_period';
            $deadlineOffset = self::GRACE_DAYS + self::RGP_DAYS;
            $priority = Types::PRIORITY_NORMAL; // recoverable with a restore fee
            $hint = 'Domain is in redemption (restore fee applies). Deadline is the estimated end of the redemption period.';
        } elseif (str_contains($regStatus, 'delete')) {
            $subtype = 'pending_delete';
            $deadlineOffset = self::GRACE_DAYS + self::RGP_DAYS + self::PENDING_DELETE_DAYS;
            $priority = Types::PRIORITY_HIGH;
            $hint = 'Domain is pending delete and can no longer be restored.';
        } elseif (str_contains($regStatus, 'expire')) {
            $subtype = 'expired_grace';
            $deadlineOffset = self::GRACE_DAYS;
            $priority = Types::PRIORITY_LOW; // client can still renew at the normal price
            $hint = 'Domain is in the renewal grace period (normal renewal price). Deadline is the estimated end of the grace period.';
        } else {
            $subtype = 'other';
            $deadlineOffset = 0;
            $priority = Types::PRIORITY_NORMAL;
            $hint = 'Unrecognised registration status — check at Enom.';
        }

        $deadline = $expiration !== null
            ? (new \DateTimeImmutable($expiration))->modify('+' . $deadlineOffset . ' days')->format('Y-m-d')
            : null;

        return $this->issue(
            $row,
            Types::TYPE_REDEMPTION,
            $subtype,
            sprintf('Enom registration status: %s (expiration %s). %s', $regStatusRaw, $expiration ?? 'n/a', $hint),
            $deadline,
            $priority
        );
    }

    // ---------------------------------------------------------------------
    // Enom API client
    // ---------------------------------------------------------------------

    private function hasCredentials(): bool
    {
        $config = $this->config();

        return !empty($config['Username']) && !empty($config['Password']);
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return $this->config ??= $this->registrarConfig();
    }

    /**
     * Perform one API call. Throws on transport / parse failure (logged to the
     * Module Log) so that the cron never treats "no answer" as "no problem".
     *
     * @param array<string, string> $params
     * @throws \RuntimeException
     */
    private function call(string $command, array $params): \SimpleXMLElement
    {
        $config = $this->config();
        $endpoint = !empty($config['TestMode']) && $config['TestMode'] !== '0' ? self::ENDPOINT_TEST : self::ENDPOINT_LIVE;

        $query = array_merge([
            'command' => $command,
            'uid' => (string) $config['Username'],
            'pw' => (string) $config['Password'],
            'responsetype' => 'xml',
        ], $params);

        $url = $endpoint . '?' . http_build_query($query);
        $logRequest = ['command' => $command] + $params;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'WHMCS-DomainIssues/1.3',
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $body === '' || $httpCode >= 400) {
            $this->log($command, $logRequest, 'HTTP ' . $httpCode . ' ' . $error, [(string) $config['Password']]);
            throw new \RuntimeException(sprintf('Enom %s failed: HTTP %d %s', $command, $httpCode, $error));
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string((string) $body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->log($command, $logRequest, (string) $body, [(string) $config['Password']]);
        if (!$xml instanceof \SimpleXMLElement) {
            throw new \RuntimeException('Enom ' . $command . ' returned unparsable XML');
        }

        return $xml;
    }

    private function errorText(\SimpleXMLElement $xml): string
    {
        $messages = [];
        if (isset($xml->errors)) {
            foreach ($xml->errors->children() as $err) {
                $messages[] = trim((string) $err);
            }
        }

        return $messages !== [] ? implode('; ', $messages) : 'no error details';
    }

    /** Days elapsed since the date (negative when in the future); null when unparsable. */
    private function daysSince(string $date): ?int
    {
        $d = $this->toDate($date);
        if ($d === null) {
            return null;
        }
        $diff = (new \DateTimeImmutable('today'))->diff(new \DateTimeImmutable($d));

        return -(int) $diff->format('%r%a');
    }

    /** Normalise Enom ("3/14/2027 12:00:00 AM") or MySQL dates to Y-m-d. */
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
