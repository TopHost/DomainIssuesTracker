<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\DomainIssues\Cron;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\DomainIssues\Config;
use WHMCS\Module\Addon\DomainIssues\Installer;
use WHMCS\Module\Addon\DomainIssues\IssueRepository;
use WHMCS\Module\Addon\DomainIssues\Lang;
use WHMCS\Module\Addon\DomainIssues\Types;

/**
 * Daily reminder to the client while an incoming transfer waits for a valid
 * transfer (EPP) code (§ 1.5.0).
 *
 * Why: a client who never sends the code loses the domain when it expires at
 * the old registrar. As long as the case is open, the client is e-mailed once a
 * day; when the domain gets close to its expiry (or after a few reminders
 * without reaction) the admins are e-mailed too, so somebody calls the client.
 *
 * A case qualifies when ALL of these hold:
 *   - issue_type transfer_in, open status, subtype invalid_epp / approval_expired;
 *   - it is linked to a WHMCS domain whose status is still Pending / Pending Transfer;
 *   - fewer than "reminder_max" reminders were sent, the last one not today.
 * Reminders stop by themselves when the case is resolved / cancelled or the
 * domain leaves the pending states (transfer done, order cancelled).
 */
final class TransferCodeReminder
{
    public const TABLE = 'mod_domainissues_reminders';
    public const TEMPLATE_NAME = 'Domain Transfer Code Reminder';

    /** Subtypes meaning "the transfer code is missing, wrong or expired". */
    public const SUBTYPES = ['invalid_epp', 'approval_expired'];

    /** WHMCS domain statuses in which the transfer is still waiting. */
    private const PENDING_STATUSES = ['Pending', 'Pending Transfer'];

    /** Safety cap on e-mails per run. */
    private const MAX_PER_RUN = 50;

    /** Run the daily reminders when enabled and the configured hour has been reached. */
    public static function runIfDue(): ?array
    {
        if (!Config::transferRemindersEnabled() || (int) date('G') < Config::reminderHour()) {
            return null;
        }
        if (!Installer::tablesExist()) {
            return null;
        }
        self::ensureTable();

        return self::run();
    }

    /**
     * @return array{candidates:int, sent:int, escalated:int, skipped:int, errors:int}
     */
    public static function run(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $stats = ['candidates' => 0, 'sent' => 0, 'escalated' => 0, 'skipped' => 0, 'errors' => 0];
        $repo = new IssueRepository();

        $issues = Capsule::table(IssueRepository::TABLE)
            ->where('issue_type', Types::TYPE_TRANSFER_IN)
            ->whereIn('status', Types::OPEN_STATUSES)
            ->whereIn('issue_subtype', self::SUBTYPES)
            ->whereNotNull('domain_id')
            ->orderBy('id')
            ->get();

        foreach ($issues as $issue) {
            if ($stats['sent'] >= self::MAX_PER_RUN) {
                break;
            }
            $stats['candidates']++;
            try {
                $domain = $repo->findDomainById((int) $issue->domain_id);
                if ($domain === null || !in_array((string) $domain->status, self::PENDING_STATUSES, true)) {
                    $stats['skipped']++;
                    continue;
                }

                $state = Capsule::table(self::TABLE)->where('issue_id', (int) $issue->id)->first();
                $sentCount = $state !== null ? (int) $state->sent_count : 0;
                $lastSent = $state !== null ? (string) $state->last_sent_at : '';
                if ($sentCount >= Config::reminderMax() || ($lastSent !== '' && substr($lastSent, 0, 10) >= $today)) {
                    $stats['skipped']++;
                    continue;
                }

                $deadline = self::deadline($issue, $domain);
                $daysLeft = $deadline !== null ? (int) floor((strtotime($deadline) - strtotime($today)) / 86400) : null;

                if (!self::emailClient($issue, $domain, $sentCount + 1, $deadline, $daysLeft)) {
                    $stats['errors']++;
                    continue;
                }
                $stats['sent']++;
                // Stored with the same date the "once a day" check compares against.
                $now = $today . date(' H:i:s');
                if ($state === null) {
                    Capsule::table(self::TABLE)->insert(['issue_id' => (int) $issue->id, 'sent_count' => 1, 'last_sent_at' => $now, 'last_escalated_at' => null]);
                    $repo->addComment((int) $issue->id, null, Lang::t('comment.reminder_started', [':domain' => (string) $issue->domain_name]));
                } else {
                    Capsule::table(self::TABLE)->where('issue_id', (int) $issue->id)->update(['sent_count' => $sentCount + 1, 'last_sent_at' => $now]);
                }
                $repo->addLog((int) $issue->id, null, 'client_reminder', (string) $sentCount, (string) ($sentCount + 1));
                $repo->update((int) $issue->id, ['updated_at' => $now]);

                // Escalate to the staff: close to expiry, or the client keeps ignoring the reminders.
                $escalate = ($daysLeft !== null && $daysLeft <= Config::reminderEscalateDays())
                    || $sentCount + 1 >= Config::reminderEscalateAfter();
                $lastEscalated = $state !== null ? (string) ($state->last_escalated_at ?? '') : '';
                if ($escalate && ($lastEscalated === '' || substr($lastEscalated, 0, 10) < $today)) {
                    self::escalate($repo, $issue, $domain, $sentCount + 1, $deadline, $daysLeft, $lastEscalated === '');
                    Capsule::table(self::TABLE)->where('issue_id', (int) $issue->id)->update(['last_escalated_at' => $now]);
                    $stats['escalated']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                if (function_exists('logModuleCall')) {
                    logModuleCall(Config::MODULE, 'transfer_reminder', ['issue' => $issue->id ?? null], $e->getMessage());
                }
            }
        }

        if (function_exists('logModuleCall') && $stats['candidates'] > 0) {
            logModuleCall(Config::MODULE, 'transfer_reminders', ['date' => $today], $stats);
        }

        return $stats;
    }

    /** Domain expiry the client must beat: the case deadline (registry), else the WHMCS expiry date. */
    private static function deadline(object $issue, object $domain): ?string
    {
        foreach ([(string) ($issue->deadline ?? ''), (string) ($domain->expirydate ?? '')] as $date) {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && $m[1] > '2000') {
                return $m[1] . '-' . $m[2] . '-' . $m[3];
            }
        }

        return null;
    }

    private static function emailClient(object $issue, object $domain, int $number, ?string $deadline, ?int $daysLeft): bool
    {
        if (!function_exists('localAPI')) {
            return false;
        }
        $vars = [
            'domain_name' => (string) $issue->domain_name,
            'expiry_date' => $deadline ?? '',
            'days_left' => $daysLeft !== null ? (string) max(0, $daysLeft) : '',
            'reminder_number' => (string) $number,
        ];
        $request = [
            'id' => (int) $domain->id,
            'customvars' => base64_encode(serialize($vars)),
        ];
        if (self::templateExists()) {
            $request['messagename'] = self::TEMPLATE_NAME;
        } else {
            $request['customtype'] = 'domain';
            $request['customsubject'] = self::defaultSubject();
            $request['custommessage'] = self::defaultMessage();
        }

        $result = localAPI('SendEmail', $request);
        $ok = is_array($result) && ($result['result'] ?? '') === 'success';
        if (!$ok && function_exists('logModuleCall')) {
            logModuleCall(Config::MODULE, 'transfer_reminder_email', $request, $result);
        }

        return $ok;
    }

    private static function escalate(IssueRepository $repo, object $issue, object $domain, int $sent, ?string $deadline, ?int $daysLeft, bool $first): void
    {
        $id = (int) $issue->id;
        if ($first) {
            $repo->addComment($id, null, Lang::t('comment.reminder_escalated', [
                ':count' => $sent,
                ':deadline' => $deadline ?? Lang::t('comment.reminder_no_deadline'),
            ]));
        }
        if ((string) $issue->priority !== Types::PRIORITY_HIGH && !$repo->hasManualChange($id, 'priority')) {
            $repo->update($id, ['priority' => Types::PRIORITY_HIGH]);
            $repo->addLog($id, null, 'priority', (string) $issue->priority, Types::PRIORITY_HIGH);
        }

        if (!function_exists('localAPI')) {
            return;
        }
        $e = static fn (string $v): string => str_replace(['{', '}'], ['&#123;', '&#125;'], htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $when = $deadline === null
            ? 'unknown expiry date'
            : ($daysLeft !== null && $daysLeft < 0 ? 'expired on ' . $deadline . ' (' . abs($daysLeft) . ' days ago)' : 'expires on ' . $deadline . ' (in ' . $daysLeft . ' days)');
        $subject = str_replace(['{', '}', "\r", "\n"], '', sprintf('[Domain Issues] Transfer code still missing: %s - %s', (string) $issue->domain_name, $when));
        $body = '<p><strong>The incoming transfer of ' . $e((string) $issue->domain_name) . ' is still waiting for a valid transfer code.</strong></p>'
            . '<p>Domain ' . $e($when) . '. The client has received ' . $sent . ' daily reminder(s) without sending a valid code.</p>'
            . '<p>If the transfer is not completed (or the domain renewed at the current registrar) before it expires, the domain can be lost and registered by someone else. '
            . '<strong>Please contact the client directly (phone).</strong></p>'
            . '<p>Domain Issues case #' . $id . ' &middot; WHMCS domain #' . (int) $domain->id . ' &middot; client #' . (int) $domain->userid . '</p>'
            . '<p>Last registrar error: <code>' . $e(mb_substr((string) ($issue->details ?? ''), 0, 500)) . '</code></p>';

        $result = localAPI('SendAdminEmail', ['customsubject' => $subject, 'custommessage' => $body, 'type' => 'system']);
        if ((!is_array($result) || ($result['result'] ?? '') !== 'success') && function_exists('logModuleCall')) {
            logModuleCall(Config::MODULE, 'transfer_reminder_escalation', $subject, $result);
        }
    }

    private static function templateExists(): bool
    {
        try {
            return Capsule::table('tblemailtemplates')->where('type', 'domain')->where('name', self::TEMPLATE_NAME)->first() !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Create the editable WHMCS e-mail template (Setup > Email Templates > Domain
     * Messages) when it does not exist. Admins can change the text and add
     * translations there. Failure is harmless: the built-in text is used.
     */
    public static function installTemplate(): void
    {
        try {
            if (self::templateExists()) {
                return;
            }
            $row = [
                'type' => 'domain',
                'name' => self::TEMPLATE_NAME,
                'subject' => self::defaultSubject(),
                'message' => self::defaultMessage(),
                'attachments' => '',
                'fromname' => '',
                'fromemail' => '',
                'disabled' => 0,
                'custom' => 1,
                'language' => '',
                'copyto' => '',
                'plaintext' => 0,
            ];
            $schema = Capsule::schema();
            if (method_exists($schema, 'hasColumn')) {
                foreach (['blind_copy_to' => '', 'created_at' => IssueRepository::now(), 'updated_at' => IssueRepository::now()] as $column => $value) {
                    if ($schema->hasColumn('tblemailtemplates', $column)) {
                        $row[$column] = $value;
                    }
                }
            }
            Capsule::table('tblemailtemplates')->insert($row);
        } catch (\Throwable $e) {
            if (function_exists('logModuleCall')) {
                logModuleCall(Config::MODULE, 'install_email_template', self::TEMPLATE_NAME, $e->getMessage());
            }
        }
    }

    public static function ensureTable(): void
    {
        $schema = Capsule::schema();
        if ($schema->hasTable(self::TABLE)) {
            return;
        }
        $schema->create(self::TABLE, static function ($table): void {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->unsignedInteger('issue_id');
            $table->unsignedInteger('sent_count')->default(0);
            $table->dateTime('last_sent_at')->nullable();
            $table->dateTime('last_escalated_at')->nullable();
            $table->index('issue_id', 'idx_dir_issue_id');
        });
    }

    public static function defaultSubject(): string
    {
        return 'Transferul domeniului {$domain_name} nu este finalizat - avem nevoie de codul de transfer';
    }

    public static function defaultMessage(): string
    {
        return '<p>Stimate client {$client_name},</p>'
            . '<p>Transferul domeniului <strong>{$domain_name}</strong> la TopHost nu a putut fi finalizat, deoarece codul de transfer (cheia EPP) '
            . 'lipsește, este incorect sau a expirat.</p>'
            . '{if $expiry_date}<p><strong>Atenție:</strong> domeniul expiră la data de <strong>{$expiry_date}</strong>'
            . '{if $days_left neq \'\'} (peste {$days_left} zile){/if}. Dacă transferul nu este finalizat până atunci și domeniul nu este prelungit '
            . 'la registrarul actual, domeniul poate fi pierdut și înregistrat de o altă persoană.</p>{/if}'
            . '<p>Pentru a finaliza transferul:</p>'
            . '<ol><li>Solicitați codul de transfer de la registrarul actual al domeniului (pentru domeniile .md, nic.md trimite codul pe adresa de e-mail '
            . 'a contactului administrativ al domeniului).</li>'
            . '<li>Transmiteți-ne codul primit răspunzând la acest e-mail sau deschizând un tichet de suport.</li></ol>'
            . '<p>Vă vom trimite acest memento zilnic până la finalizarea transferului (mesajul nr. {$reminder_number}).</p>'
            . '<p>{$signature}</p>';
    }
}
