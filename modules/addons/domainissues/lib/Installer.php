<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\DomainIssues;

use Illuminate\Database\Schema\Blueprint;
use WHMCS\Database\Capsule;

/**
 * Creates / upgrades / drops the module tables (§4).
 *
 * The schema is designed for both stages from the start: issue_type already
 * covers every category, so Stage 2 needs no schema change.
 */
final class Installer
{
    public const SCHEMA_VERSION = 1;

    public static function install(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable(IssueRepository::TABLE)) {
            $schema->create(IssueRepository::TABLE, static function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->unsignedInteger('domain_id')->nullable();
                $table->string('domain_name', 255);
                $table->unsignedInteger('client_id')->nullable();
                $table->string('registrar', 64)->nullable();
                $table->enum('issue_type', Types::TYPES);
                $table->string('issue_subtype', 64)->nullable();
                $table->enum('source', Types::SOURCES)->default(Types::SOURCE_MANUAL);
                $table->enum('status', Types::STATUSES)->default(Types::STATUS_NEW);
                $table->enum('priority', Types::PRIORITIES)->default(Types::PRIORITY_NORMAL);
                $table->date('deadline')->nullable();
                $table->text('details')->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
                $table->dateTime('resolved_at')->nullable();

                $table->index('domain_name', 'idx_di_domain_name');
                $table->index('status', 'idx_di_status');
                $table->index('issue_type', 'idx_di_issue_type');
                $table->index(['status', 'issue_type'], 'idx_di_status_type');
                $table->index('domain_id', 'idx_di_domain_id');
                $table->index('client_id', 'idx_di_client_id');
                $table->index('deadline', 'idx_di_deadline');
            });
        }

        if (!$schema->hasTable(IssueRepository::TABLE_COMMENTS)) {
            $schema->create(IssueRepository::TABLE_COMMENTS, static function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->unsignedInteger('issue_id');
                $table->unsignedInteger('admin_id')->nullable();
                $table->text('comment');
                $table->dateTime('created_at');

                $table->index('issue_id', 'idx_dic_issue_id');
                $table->foreign('issue_id', 'fk_dic_issue')
                    ->references('id')->on(IssueRepository::TABLE)
                    ->onDelete('cascade');
            });
        }

        if (!$schema->hasTable(IssueRepository::TABLE_LOG)) {
            $schema->create(IssueRepository::TABLE_LOG, static function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->unsignedInteger('issue_id');
                $table->unsignedInteger('admin_id')->nullable();
                $table->string('field', 32);
                $table->string('old_value', 64)->nullable();
                $table->string('new_value', 64)->nullable();
                $table->dateTime('created_at');

                $table->index('issue_id', 'idx_dil_issue_id');
                $table->foreign('issue_id', 'fk_dil_issue')
                    ->references('id')->on(IssueRepository::TABLE)
                    ->onDelete('cascade');
            });
        }
    }

    /**
     * Apply incremental schema changes between module versions. Called from
     * domainissues_upgrade(). Currently a no-op placeholder for future versions.
     */
    public static function upgrade(string $fromVersion): void
    {
        // Example for a future release:
        // if (version_compare($fromVersion, '1.1.0', '<')) { ... }
        self::install(); // ensures tables exist if they were dropped manually
    }

    public static function uninstall(): void
    {
        $schema = Capsule::schema();
        // Drop children first so FK constraints never block the drop.
        foreach ([\WHMCS\Module\Addon\DomainIssues\Cron\TransferCodeReminder::TABLE, IssueRepository::TABLE_COMMENTS, IssueRepository::TABLE_LOG, IssueRepository::TABLE] as $table) {
            if ($schema->hasTable($table)) {
                $schema->drop($table);
            }
        }
    }

    public static function tablesExist(): bool
    {
        $schema = Capsule::schema();

        return $schema->hasTable(IssueRepository::TABLE)
            && $schema->hasTable(IssueRepository::TABLE_COMMENTS)
            && $schema->hasTable(IssueRepository::TABLE_LOG);
    }
}
