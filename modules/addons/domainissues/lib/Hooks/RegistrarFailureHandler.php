<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\DomainIssues\Hooks;

use WHMCS\Module\Addon\DomainIssues\Config;
use WHMCS\Module\Addon\DomainIssues\Installer;
use WHMCS\Module\Addon\DomainIssues\IssueDTO;
use WHMCS\Module\Addon\DomainIssues\IssueRepository;
use WHMCS\Module\Addon\DomainIssues\IssueService;
use WHMCS\Module\Addon\DomainIssues\Types;

/**
 * Turns WHMCS registrar failure hooks into automatic issues (§6.3):
 *   AfterRegistrarTransferFailed      -> transfer_in
 *   AfterRegistrarRegistrationFailed  -> registration
 *   AfterRegistrarRenewalFailed       -> renewal
 *
 * These hooks receive ['params' => <registrar module params>, 'error' => <string>].
 */
final class RegistrarFailureHandler
{
    /** Keyword heuristics applied to the registrar error text to guess a subtype. */
    private const SUBTYPE_PATTERNS = [
        // "Manual (registrar extern)" module: the operation is done by hand on purpose
        'manual_action' => ['manual action required'],
        'invalid_epp' => [
            'epp', 'transfer code', 'trcode', 'trcod ', 'auth code', 'authcode', 'authorization code', 'authorisation code',
            'authorization key', 'authorisation key', 'auth key', 'authkey', 'auth info', 'authinfo', 'transfer key', 'secret',
            // RoTLD / local wording (Romanian)
            'cod de autorizare', 'codul de autorizare', 'cheie de autorizare', 'cheia de autorizare', 'cod de transfer', 'codul de transfer', 'cheia de transfer', 'cheie de transfer',
        ],
        'domain_locked' => ['lock', 'clienttransferprohibited', 'servertransferprohibited', 'prohibited'],
        'pending_approval' => ['pending approval', 'awaiting approval', 'approval', 'confirmation', 'pendingtransfer', 'foa'],
        'approval_expired' => ['expired approval', 'approval expired', 'timed out', 'time out'],
        'payment_failed' => ['insufficient', 'balance', 'funds', 'payment', 'credit'],
        'redemption_period' => ['redemption', 'rgp'],
        'pending_delete' => ['pendingdelete', 'pending delete'],
    ];

    /**
     * @param array<string, mixed> $vars hook variables
     */
    public static function handle(string $issueType, array $vars): void
    {
        try {
            if (!Config::isIssueTypeEnabled($issueType) || !Installer::tablesExist()) {
                return;
            }

            $params = is_array($vars['params'] ?? null) ? $vars['params'] : [];
            $error = trim((string) ($vars['error'] ?? ''));

            $domainName = strtolower(trim((string) ($params['domainname'] ?? '')));
            if ($domainName === '' && isset($params['sld'], $params['tld'])) {
                $domainName = strtolower(trim($params['sld'] . '.' . ltrim((string) $params['tld'], '.')));
            }

            $domainId = isset($params['domainid']) && ctype_digit((string) $params['domainid']) ? (int) $params['domainid'] : null;
            $clientId = isset($params['userid']) && ctype_digit((string) $params['userid']) ? (int) $params['userid'] : null;
            $registrar = isset($params['registrar']) && $params['registrar'] !== '' ? strtolower((string) $params['registrar']) : null;

            if ($domainName === '' && $domainId !== null) {
                $row = (new IssueRepository())->findDomainById($domainId);
                if ($row !== null) {
                    $domainName = strtolower((string) $row->domain);
                    $clientId ??= (int) $row->userid;
                    $registrar ??= !empty($row->registrar) ? (string) $row->registrar : null;
                }
            }
            if ($domainName === '') {
                return;
            }

            $dto = new IssueDTO(
                domainName: $domainName,
                issueType: $issueType,
                issueSubtype: self::guessSubtype($error),
                registrar: $registrar,
                domainId: $domainId,
                clientId: $clientId,
                details: $error !== '' ? $error : 'Registrar operation failed (no error message returned).',
                deadline: null,
                priority: Types::PRIORITY_HIGH, // a failed registrar operation always needs an admin
            );

            (new IssueService())->reportAutomatic($dto);
        } catch (\Throwable $e) {
            if (function_exists('logModuleCall')) {
                logModuleCall(Config::MODULE, 'hook_' . $issueType . '_failed', $vars, $e->getMessage());
            }
        }
    }

    public static function guessSubtype(string $error): ?string
    {
        $haystack = strtolower($error);
        if ($haystack === '') {
            return null;
        }
        foreach (self::SUBTYPE_PATTERNS as $subtype => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return $subtype;
                }
            }
        }

        return 'registrar_error';
    }
}
