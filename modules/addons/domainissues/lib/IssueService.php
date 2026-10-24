<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\DomainIssues;

/**
 * Business logic: validation, creation, updates with change history,
 * de-duplication of automatic reports and system-side resolution.
 */
final class IssueService
{
    /** Fields whose changes are written to mod_domainissues_log. */
    private const TRACKED_FIELDS = [
        'status',
        'priority',
        'deadline',
        'issue_type',
        'issue_subtype',
        'registrar',
        'domain_name',
        'client_id',
        'domain_id',
    ];

    private IssueRepository $repo;

    public function __construct(?IssueRepository $repo = null)
    {
        $this->repo = $repo ?? new IssueRepository();
    }

    public function repository(): IssueRepository
    {
        return $this->repo;
    }

    // ---------------------------------------------------------------------
    // Validation / normalisation of admin form input
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $input raw form input
     * @return array<string, string> field => error message (empty when valid)
     */
    public function validate(array $input, bool $isNew): array
    {
        $errors = [];
        $domain = strtolower(trim((string) ($input['domain_name'] ?? '')));

        if ($domain === '') {
            $errors['domain_name'] = Lang::t('error.domain_required');
        } elseif (mb_strlen($domain) > 255 || !preg_match('/^(?=.{1,255}$)(xn--)?[a-z0-9\-\.]+\.[a-z0-9\-]{2,}$/i', $domain)) {
            $errors['domain_name'] = Lang::t('error.domain_invalid');
        }

        $type = (string) ($input['issue_type'] ?? '');
        if (!in_array($type, Types::TYPES, true)) {
            $errors['issue_type'] = Lang::t('error.type_invalid');
        } elseif ($isNew && !Config::isIssueTypeEnabled($type)) {
            // Editing an existing issue of a disabled type is allowed; creating is not.
            $errors['issue_type'] = Lang::t('error.type_disabled');
        }

        $status = (string) ($input['status'] ?? Types::STATUS_NEW);
        if (!in_array($status, Types::STATUSES, true)) {
            $errors['status'] = Lang::t('error.status_invalid');
        }

        $priority = (string) ($input['priority'] ?? Types::PRIORITY_NORMAL);
        if (!in_array($priority, Types::PRIORITIES, true)) {
            $errors['priority'] = Lang::t('error.priority_invalid');
        }

        $deadline = trim((string) ($input['deadline'] ?? ''));
        if ($deadline !== '' && !self::isValidDate($deadline)) {
            $errors['deadline'] = Lang::t('error.deadline_invalid');
        }

        $clientId = trim((string) ($input['client_id'] ?? ''));
        if ($clientId !== '') {
            if (!ctype_digit($clientId)) {
                $errors['client_id'] = Lang::t('error.client_invalid');
            } elseif (!$this->repo->clientExists((int) $clientId)) {
                $errors['client_id'] = Lang::t('error.client_not_found');
            }
        }

        if (mb_strlen((string) ($input['issue_subtype'] ?? '')) > 64) {
            $errors['issue_subtype'] = Lang::t('error.too_long');
        }
        if (mb_strlen((string) ($input['registrar'] ?? '')) > 64) {
            $errors['registrar'] = Lang::t('error.too_long');
        }

        return $errors;
    }

    /**
     * Convert validated form input into a column => value array.
     * Resolves domain_id / client_id / registrar from tbldomains when possible.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function normalize(array $input): array
    {
        $domainName = strtolower(trim((string) ($input['domain_name'] ?? '')));
        $clientId = self::intOrNull($input['client_id'] ?? null);
        $domainId = self::intOrNull($input['domain_id'] ?? null);
        $registrar = self::strOrNull($input['registrar'] ?? null, 64);

        // Resolve the WHMCS domain row: by explicit id first, then by name.
        $domainRow = null;
        if ($domainId !== null) {
            $domainRow = $this->repo->findDomainById($domainId);
            if ($domainRow !== null && strtolower((string) $domainRow->domain) !== $domainName) {
                $domainRow = null; // id no longer matches the typed name
            }
        }
        if ($domainRow === null) {
            $domainRow = $this->repo->findDomainByName($domainName);
        }

        if ($domainRow !== null) {
            $domainId = (int) $domainRow->id;
            $clientId ??= (int) $domainRow->userid;
            if ($registrar === null && !empty($domainRow->registrar)) {
                $registrar = (string) $domainRow->registrar;
            }
        } else {
            $domainId = null;
        }

        $deadline = trim((string) ($input['deadline'] ?? ''));

        return [
            'domain_id' => $domainId,
            'domain_name' => $domainName,
            'client_id' => $clientId,
            'registrar' => $registrar !== null ? strtolower($registrar) : null,
            'issue_type' => (string) $input['issue_type'],
            'issue_subtype' => self::strOrNull($input['issue_subtype'] ?? null, 64),
            'status' => (string) ($input['status'] ?? Types::STATUS_NEW),
            'priority' => (string) ($input['priority'] ?? Types::PRIORITY_NORMAL),
            'deadline' => $deadline !== '' ? $deadline : null,
            'details' => self::strOrNull($input['details'] ?? null),
        ];
    }

    // ---------------------------------------------------------------------
    // Manual workflow (admin UI)
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $input already validated input
     */
    public function createManual(array $input, int $adminId): int
    {
        $data = $this->normalize($input);
        $now = IssueRepository::now();

        $data['source'] = Types::SOURCE_MANUAL;
        $data['created_by'] = $adminId ?: null;
        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        $data['resolved_at'] = $data['status'] === Types::STATUS_RESOLVED ? $now : null;

        $id = $this->repo->insert($data);
        $this->repo->addLog($id, $adminId ?: null, 'created', null, $data['status']);

        self::activity(sprintf(
            'Domain Issues: created %s issue #%d for %s',
            $data['issue_type'],
            $id,
            $data['domain_name']
        ), $data['client_id']);

        return $id;
    }

    /**
     * Update an existing issue from admin form input, logging every tracked change.
     *
     * @param array<string, mixed> $input already validated input
     * @return string[] names of fields that changed
     */
    public function updateManual(int $id, array $input, int $adminId): array
    {
        $existing = $this->repo->find($id);
        if ($existing === null) {
            throw new \RuntimeException(Lang::t('error.not_found'));
        }

        $data = $this->normalize($input);
        $changed = [];
        $update = [];

        foreach ($data as $field => $value) {
            $old = $existing->{$field} ?? null;
            if (self::same($old, $value)) {
                continue;
            }
            $update[$field] = $value;
            $changed[] = $field;
            if (in_array($field, self::TRACKED_FIELDS, true)) {
                $this->repo->addLog($id, $adminId ?: null, $field, self::scalar($old), self::scalar($value));
            }
        }

        if ($update === []) {
            return [];
        }

        $now = IssueRepository::now();
        $update['updated_at'] = $now;

        if (isset($update['status'])) {
            if ($update['status'] === Types::STATUS_RESOLVED) {
                $update['resolved_at'] = $now;
            } elseif ($existing->status === Types::STATUS_RESOLVED) {
                $update['resolved_at'] = null; // re-opened
            }
            self::activity(sprintf(
                'Domain Issues: issue #%d (%s) status changed %s -> %s',
                $id,
                $existing->domain_name,
                $existing->status,
                $update['status']
            ), $data['client_id']);
        }

        $this->repo->update($id, $update);

        return $changed;
    }

    public function addComment(int $id, int $adminId, string $comment): void
    {
        $comment = trim($comment);
        if ($comment === '') {
            throw new \InvalidArgumentException(Lang::t('error.comment_required'));
        }
        if ($this->repo->find($id) === null) {
            throw new \RuntimeException(Lang::t('error.not_found'));
        }
        $this->repo->addComment($id, $adminId ?: null, $comment);
        $this->repo->update($id, ['updated_at' => IssueRepository::now()]);
    }

    public function delete(int $id, int $adminId): void
    {
        $existing = $this->repo->find($id);
        if ($existing === null) {
            throw new \RuntimeException(Lang::t('error.not_found'));
        }
        $this->repo->delete($id);
        self::activity(sprintf(
            'Domain Issues: issue #%d for %s deleted by admin #%d',
            $id,
            $existing->domain_name,
            $adminId
        ), $existing->client_id !== null ? (int) $existing->client_id : null);
    }

    // ---------------------------------------------------------------------
    // Automatic workflow (hooks, adapters, cron)
    // ---------------------------------------------------------------------

    /**
     * Create an automatic issue, or refresh the existing open one for the same
     * domain + issue_type (de-duplication rule from §4.1).
     *
     * @return int issue id (new or existing)
     */
    public function reportAutomatic(IssueDTO $dto): int
    {
        $domainName = strtolower(trim($dto->domainName));
        if ($domainName === '' || !in_array($dto->issueType, Types::TYPES, true)) {
            throw new \InvalidArgumentException('Invalid issue DTO');
        }

        $now = IssueRepository::now();
        $existing = $this->repo->findOpenByDomainAndType($domainName, $dto->issueType);

        if ($existing !== null) {
            $update = ['updated_at' => $now];
            if ($dto->details !== null && $dto->details !== '') {
                $update['details'] = $dto->details;
            }
            if ($dto->issueSubtype !== null && $dto->issueSubtype !== $existing->issue_subtype) {
                $update['issue_subtype'] = mb_substr($dto->issueSubtype, 0, 64);
                $this->repo->addLog((int) $existing->id, null, 'issue_subtype', $existing->issue_subtype, $update['issue_subtype']);
            }
            if ($dto->deadline !== null && $dto->deadline !== $existing->deadline) {
                $update['deadline'] = $dto->deadline;
                $this->repo->addLog((int) $existing->id, null, 'deadline', $existing->deadline, $dto->deadline);
            }
            $priority = in_array($dto->priority, Types::PRIORITIES, true) ? $dto->priority : Types::PRIORITY_NORMAL;
            if ($priority !== $existing->priority && !$this->repo->hasManualChange((int) $existing->id, 'priority')) {
                $update['priority'] = $priority;
                $this->repo->addLog((int) $existing->id, null, 'priority', (string) $existing->priority, $priority);
            }
            if ($existing->domain_id === null && $dto->domainId !== null) {
                $update['domain_id'] = $dto->domainId;
            }
            if ($existing->client_id === null && $dto->clientId !== null) {
                $update['client_id'] = $dto->clientId;
            }
            if (empty($existing->registrar) && $dto->registrar !== null) {
                $update['registrar'] = strtolower($dto->registrar);
            }
            $this->repo->update((int) $existing->id, $update);

            return (int) $existing->id;
        }

        // Fill domain/client from WHMCS when the caller did not supply them.
        $domainId = $dto->domainId;
        $clientId = $dto->clientId;
        $registrar = $dto->registrar;
        if ($domainId === null || $clientId === null || $registrar === null) {
            $row = $domainId !== null ? $this->repo->findDomainById($domainId) : $this->repo->findDomainByName($domainName);
            if ($row !== null) {
                $domainId ??= (int) $row->id;
                $clientId ??= (int) $row->userid;
                $registrar ??= !empty($row->registrar) ? (string) $row->registrar : null;
            }
        }

        $id = $this->repo->insert([
            'domain_id' => $domainId,
            'domain_name' => $domainName,
            'client_id' => $clientId,
            'registrar' => $registrar !== null ? strtolower($registrar) : null,
            'issue_type' => $dto->issueType,
            'issue_subtype' => $dto->issueSubtype !== null ? mb_substr($dto->issueSubtype, 0, 64) : null,
            'source' => Types::SOURCE_AUTOMATIC,
            'status' => Types::STATUS_NEW,
            'priority' => in_array($dto->priority, Types::PRIORITIES, true) ? $dto->priority : Types::PRIORITY_NORMAL,
            'deadline' => $dto->deadline,
            'details' => $dto->details,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'resolved_at' => null,
        ]);
        $this->repo->addLog($id, null, 'created', null, Types::STATUS_NEW);

        self::activity(sprintf(
            'Domain Issues: automatic %s issue #%d detected for %s%s',
            $dto->issueType,
            $id,
            $domainName,
            $dto->issueSubtype !== null ? ' (' . $dto->issueSubtype . ')' : ''
        ), $clientId);

        return $id;
    }

    /**
     * Resolve an issue on behalf of the system (cron re-check). Only automatic
     * issues are ever closed this way; manual ones are left untouched (§6.3).
     */
    public function resolveBySystem(int $id, string $reason): bool
    {
        $existing = $this->repo->find($id);
        if ($existing === null || $existing->source !== Types::SOURCE_AUTOMATIC || !Types::isOpenStatus((string) $existing->status)) {
            return false;
        }

        $now = IssueRepository::now();
        $this->repo->update($id, [
            'status' => Types::STATUS_RESOLVED,
            'resolved_at' => $now,
            'updated_at' => $now,
        ]);
        $this->repo->addLog($id, null, 'status', (string) $existing->status, Types::STATUS_RESOLVED);
        $this->repo->addComment($id, null, Lang::t('comment.auto_resolved', [':reason' => $reason]));

        self::activity(sprintf(
            'Domain Issues: issue #%d for %s auto-resolved (%s)',
            $id,
            $existing->domain_name,
            $reason
        ), $existing->client_id !== null ? (int) $existing->client_id : null);

        return true;
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    public static function isValidDate(string $value): bool
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $value);

        return $dt !== false && $dt->format('Y-m-d') === $value;
    }

    private static function intOrNull(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value !== '' && ctype_digit($value) ? (int) $value : null;
    }

    private static function strOrNull(mixed $value, ?int $maxLength = null): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return $maxLength !== null ? mb_substr($value, 0, $maxLength) : $value;
    }

    private static function scalar(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return (string) $a === (string) $b;
    }

    private static function activity(string $message, ?int $clientId = null): void
    {
        if (function_exists('logActivity')) {
            try {
                logActivity($message, $clientId ?? 0);
            } catch (\Throwable $e) {
                // never let logging break the workflow
            }
        }
    }
}
