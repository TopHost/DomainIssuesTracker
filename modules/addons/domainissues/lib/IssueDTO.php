<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\DomainIssues;

/**
 * Immutable description of a detected problem, produced by registrar adapters
 * and hook handlers and consumed by IssueService::reportAutomatic().
 */
final class IssueDTO
{
    public function __construct(
        public readonly string $domainName,
        public readonly string $issueType,
        public readonly ?string $issueSubtype = null,
        public readonly ?string $registrar = null,
        public readonly ?int $domainId = null,
        public readonly ?int $clientId = null,
        public readonly ?string $details = null,
        /** Y-m-d or null */
        public readonly ?string $deadline = null,
        public readonly string $priority = Types::PRIORITY_NORMAL,
    ) {
    }

    /**
     * Convenience factory from a tbldomains row (object or array).
     *
     * @param object|array<string, mixed> $domainRow
     */
    public static function fromDomainRow(
        object|array $domainRow,
        string $issueType,
        ?string $issueSubtype = null,
        ?string $details = null,
        ?string $deadline = null,
        string $priority = Types::PRIORITY_NORMAL
    ): self {
        $row = is_object($domainRow) ? get_object_vars($domainRow) : $domainRow;

        return new self(
            domainName: strtolower(trim((string) ($row['domain'] ?? ''))),
            issueType: $issueType,
            issueSubtype: $issueSubtype,
            registrar: isset($row['registrar']) && $row['registrar'] !== '' ? (string) $row['registrar'] : null,
            domainId: isset($row['id']) ? (int) $row['id'] : null,
            clientId: isset($row['userid']) ? (int) $row['userid'] : null,
            details: $details,
            deadline: $deadline,
            priority: $priority,
        );
    }
}
