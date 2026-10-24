<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\DomainIssues\Adapters;

use WHMCS\Module\Addon\DomainIssues\IssueDTO;

/**
 * Registrar adapter contract (§6.1).
 *
 * Adapters are only ever invoked from the cron re-check — never from admin
 * page requests (§8.1) — so they may call the registrar API freely.
 */
interface AdapterInterface
{
    /** Registrar slug exactly as stored in tbldomains.registrar (e.g. "enom"). */
    public function getRegistrarSlug(): string;

    /**
     * Issue types this adapter can positively verify. The cron only auto-resolves
     * an open issue when its type is listed here AND checkDomain() no longer
     * reports it; types not listed are left for manual handling.
     *
     * @return string[] subset of Types::TYPES
     */
    public function supportedIssueTypes(): array;

    /**
     * Extra domain names the proactive scan should inspect regardless of their
     * WHMCS status (e.g. operations still open in the registrar module's own
     * tables). Return [] when not applicable.
     *
     * @return string[]
     */
    public function additionalDomainsToScan(): array;

    /**
     * Inspect one domain at the registrar and return every problem detected
     * (possibly none).
     *
     * @param array<string, mixed> $whmcsDomainRow row from tbldomains, as an array
     * @return IssueDTO[]
     */
    public function checkDomain(array $whmcsDomainRow): array;
}
