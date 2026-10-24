<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\DomainIssues;

/**
 * Enumerations used across the module. Kept as plain constants so they can be
 * reused in Capsule schema definitions, validation and templates.
 */
final class Types
{
    public const TYPE_TRANSFER_IN = 'transfer_in';
    public const TYPE_TRANSFER_OUT = 'transfer_out';
    public const TYPE_RENEWAL = 'renewal';
    public const TYPE_REDEMPTION = 'redemption';
    public const TYPE_REGISTRATION = 'registration';
    public const TYPE_SYNC_MISMATCH = 'sync_mismatch';
    public const TYPE_OTHER = 'other';

    /** @var string[] All issue types supported by the schema (Stage 1 + Stage 2). */
    public const TYPES = [
        self::TYPE_TRANSFER_IN,
        self::TYPE_TRANSFER_OUT,
        self::TYPE_RENEWAL,
        self::TYPE_REDEMPTION,
        self::TYPE_REGISTRATION,
        self::TYPE_SYNC_MISMATCH,
        self::TYPE_OTHER,
    ];

    /** @var string[] Types counted on the "Problem transfers" widget line. */
    public const TRANSFER_TYPES = [
        self::TYPE_TRANSFER_IN,
        self::TYPE_TRANSFER_OUT,
    ];

    public const STATUS_NEW = 'new';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_WAITING_CLIENT = 'waiting_client';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_IN_PROGRESS,
        self::STATUS_WAITING_CLIENT,
        self::STATUS_RESOLVED,
        self::STATUS_CANCELLED,
    ];

    /** @var string[] Statuses that count as "active" / needing attention. */
    public const OPEN_STATUSES = [
        self::STATUS_NEW,
        self::STATUS_IN_PROGRESS,
        self::STATUS_WAITING_CLIENT,
    ];

    public const CLOSED_STATUSES = [
        self::STATUS_RESOLVED,
        self::STATUS_CANCELLED,
    ];

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';

    public const PRIORITIES = [
        self::PRIORITY_LOW,
        self::PRIORITY_NORMAL,
        self::PRIORITY_HIGH,
    ];

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_AUTOMATIC = 'automatic';

    public const SOURCES = [
        self::SOURCE_MANUAL,
        self::SOURCE_AUTOMATIC,
    ];

    /**
     * Suggested subtypes. The column is free text (VARCHAR 64) so adapters and
     * admins may use other values; these are offered as autocomplete hints.
     */
    public const SUBTYPES = [
        'invalid_epp',
        'domain_locked',
        'pending_approval',
        'approval_expired',
        'payment_failed',
        'expired_grace',
        'redemption_period',
        'pending_delete',
        'transfer_cancelled',
        'transfer_completed',
        'not_at_registrar',
        'registered_at_registrar',
        'registrar_mismatch',
        'domain_unavailable',
        'registrar_error',
        'contact_mismatch',
        'nameserver_mismatch',
        'other',
    ];

    /** Subtypes of the "redemption" category that mean the domain is already in redemption / deletion. */
    public const REDEMPTION_SUBTYPES = ['redemption_period', 'pending_delete'];

    /** EPP status => issue_subtype mapping used by registrar adapters (§6.4). */
    public const EPP_STATUS_SUBTYPES = [
        'clientTransferProhibited' => 'domain_locked',
        'serverTransferProhibited' => 'domain_locked',
        'pendingTransfer' => 'pending_approval',
        'redemptionPeriod' => 'redemption_period',
        'pendingDelete' => 'pending_delete',
    ];

    /** Bootstrap label class per status (admin UI). */
    public const STATUS_CSS = [
        self::STATUS_NEW => 'label-info',
        self::STATUS_IN_PROGRESS => 'label-primary',
        self::STATUS_WAITING_CLIENT => 'label-warning',
        self::STATUS_RESOLVED => 'label-success',
        self::STATUS_CANCELLED => 'label-default',
    ];

    public const PRIORITY_CSS = [
        self::PRIORITY_LOW => 'label-default',
        self::PRIORITY_NORMAL => 'label-info',
        self::PRIORITY_HIGH => 'label-danger',
    ];

    public static function isTransferType(string $type): bool
    {
        return in_array($type, self::TRANSFER_TYPES, true);
    }

    public static function isOpenStatus(string $status): bool
    {
        return in_array($status, self::OPEN_STATUSES, true);
    }
}
