<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\DomainIssues\Adapters;

use WHMCS\Module\Addon\DomainIssues\Config;
use WHMCS\Module\Addon\DomainIssues\IssueDTO;
use WHMCS\Module\Addon\DomainIssues\Types;

/**
 * Shared plumbing for registrar adapters: module logging, EPP status mapping,
 * access to the registrar's WHMCS configuration.
 */
abstract class AbstractAdapter implements AdapterInterface
{
    /** Default: nothing beyond the status-based scan. */
    public function additionalDomainsToScan(): array
    {
        return [];
    }

    /**
     * Registrar module configuration (API credentials etc.) as stored in WHMCS.
     * Resolved lazily via the WHMCS helper getregistrarconfigoptions().
     *
     * @return array<string, mixed>
     */
    protected function registrarConfig(): array
    {
        if (!function_exists('getregistrarconfigoptions')) {
            return [];
        }
        try {
            $config = getregistrarconfigoptions($this->getRegistrarSlug());

            return is_array($config) ? $config : [];
        } catch (\Throwable $e) {
            $this->log('registrarConfig', ['registrar' => $this->getRegistrarSlug()], $e->getMessage());

            return [];
        }
    }

    /**
     * Write to the WHMCS Module Log (Utilities > Logs > Module Log). Errors are
     * never shown to the admin UI (§8.4).
     *
     * @param mixed $request
     * @param mixed $response
     */
    protected function log(string $action, mixed $request, mixed $response, array $replaceVars = []): void
    {
        if (!function_exists('logModuleCall')) {
            return;
        }
        try {
            logModuleCall(
                Config::MODULE . ':' . $this->getRegistrarSlug(),
                $action,
                $request,
                $response,
                '',
                $replaceVars
            );
        } catch (\Throwable $e) {
            // ignore logging failures
        }
    }

    /**
     * Map a list of EPP statuses to issue subtypes (§6.4).
     *
     * @param string[] $eppStatuses
     * @return string[] unique subtypes
     */
    protected function mapEppStatuses(array $eppStatuses): array
    {
        $subtypes = [];
        foreach ($eppStatuses as $status) {
            $status = trim((string) $status);
            if (isset(Types::EPP_STATUS_SUBTYPES[$status])) {
                $subtypes[] = Types::EPP_STATUS_SUBTYPES[$status];
            }
        }

        return array_values(array_unique($subtypes));
    }

    /**
     * Helper to build a DTO from the WHMCS domain row.
     *
     * @param array<string, mixed> $domainRow
     */
    protected function issue(
        array $domainRow,
        string $issueType,
        ?string $subtype = null,
        ?string $details = null,
        ?string $deadline = null,
        string $priority = Types::PRIORITY_NORMAL
    ): IssueDTO {
        return IssueDTO::fromDomainRow($domainRow, $issueType, $subtype, $details, $deadline, $priority);
    }

    /** Split "example.com" into [sld, tld] the way WHMCS registrar modules expect. */
    protected function splitDomain(string $domain): array
    {
        $domain = strtolower(trim($domain));
        $pos = strpos($domain, '.');
        if ($pos === false) {
            return [$domain, ''];
        }

        return [substr($domain, 0, $pos), substr($domain, $pos + 1)];
    }
}
