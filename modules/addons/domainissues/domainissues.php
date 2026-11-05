<?php
/**
 * Domain Issues Tracker — WHMCS addon module.
 *
 * Centralised list of domains that need manual attention (blocked transfers,
 * failed renewals / registrations, redemption, WHMCS <-> registrar mismatches).
 *
 * @see README.md for installation and configuration.
 */

use WHMCS\Module\Addon\DomainIssues\Admin\AdminController;
use WHMCS\Module\Addon\DomainIssues\Config;
use WHMCS\Module\Addon\DomainIssues\Cron\TransferCodeReminder;
use WHMCS\Module\Addon\DomainIssues\Installer;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/autoload.php';

/**
 * Module configuration + settings page (§7).
 */
function domainissues_config(): array
{
    $yesNo = static fn (string $description, bool $default = false): array => [
        'FriendlyName' => '',
        'Type' => 'yesno',
        'Description' => $description,
        'Default' => $default ? 'on' : '',
    ];

    return [
        'name' => 'Domain Issues Tracker',
        'description' => 'Tracks domains that need manual attention: blocked transfers (invalid EPP, locked, pending approval), failed renewals / registrations, redemption and WHMCS/registrar mismatches. Dashboard widget + full management page.',
        'version' => '1.5.1',
        'author' => 'IT Concept SRL / TopHost.MD',
        'language' => 'english',
        'fields' => [
            // --- Issue categories -------------------------------------------------
            'enable_transfer_in' => ['FriendlyName' => 'Category: Transfer in'] + $yesNo('Track inbound transfer problems (Stage 1).', true),
            'enable_transfer_out' => ['FriendlyName' => 'Category: Transfer out'] + $yesNo('Track outbound transfer problems (Stage 1).', true),
            'enable_renewal' => ['FriendlyName' => 'Category: Renewal failed'] + $yesNo('Track failed renewals (Stage 2).'),
            'enable_redemption' => ['FriendlyName' => 'Category: Expired / redemption'] + $yesNo('Track expired domains and redemption periods (Stage 2).'),
            'enable_registration' => ['FriendlyName' => 'Category: Registration failed'] + $yesNo('Track failed registrations (Stage 2).'),
            'enable_sync_mismatch' => ['FriendlyName' => 'Category: WHMCS / registrar mismatch'] + $yesNo('Track data mismatches between WHMCS and the registrar (Stage 2).'),
            'enable_other' => ['FriendlyName' => 'Category: Other'] + $yesNo('Allow free-form "Other" issues.'),

            // --- Registrar adapters -----------------------------------------------
            'adapter_enom' => ['FriendlyName' => 'Adapter: Enom'] + $yesNo('Enable automatic collection via the Enom API (Stage 2).'),
            'adapter_netim' => ['FriendlyName' => 'Adapter: Netim'] + $yesNo('Enable automatic collection via the Netim API (uses the netim registrar module credentials and SOAP client).'),
            'adapter_molddata' => ['FriendlyName' => 'Adapter: MoldData (nic.md)'] + $yesNo('Enable automatic collection via the nic.md registry interface (uses the molddata registrar module credentials).'),

            // --- Behaviour --------------------------------------------------------
            'cron_interval_hours' => [
                'FriendlyName' => 'Re-check interval (hours)',
                'Type' => 'text',
                'Size' => '5',
                'Default' => (string) Config::DEFAULTS['cron_interval_hours'],
                'Description' => 'How often the cron re-checks open automatic issues against the registrar adapters. Minimum 1.',
            ],
            'deadline_threshold_days' => [
                'FriendlyName' => 'Deadline highlight threshold (days)',
                'Type' => 'text',
                'Size' => '5',
                'Default' => (string) Config::DEFAULTS['deadline_threshold_days'],
                'Description' => 'Issues whose deadline falls within this many days are highlighted in red and counted as urgent.',
            ],
            'items_per_page' => [
                'FriendlyName' => 'Items per page',
                'Type' => 'text',
                'Size' => '5',
                'Default' => (string) Config::DEFAULTS['items_per_page'],
                'Description' => 'Rows per page on the issues list.',
            ],
            'scan_limit' => [
                'FriendlyName' => 'Cron scan limit (domains per run)',
                'Type' => 'text',
                'Size' => '5',
                'Default' => (string) Config::DEFAULTS['scan_limit'],
                'Description' => 'Maximum number of WHMCS domains (Pending Transfer / Expired / Grace / Redemption) inspected at the registrar per cron run. Each domain costs 1–2 API calls.',
            ],
            'scan_max_expired_days' => [
                'FriendlyName' => 'Ignore domains expired for more than (days)',
                'Type' => 'text',
                'Size' => '5',
                'Default' => (string) Config::DEFAULTS['scan_max_expired_days'],
                'Description' => 'Expired / Grace / Redemption domains whose WHMCS expiry date is older than this are skipped by the scan (they are long gone). 0 = no limit.',
            ],
            // --- Transfer code reminders (1.5.0) ----------------------------------
            'transfer_reminders' => ['FriendlyName' => 'Transfer code reminders'] + $yesNo('E-mail the CLIENT once a day while an incoming transfer is blocked by a missing, wrong or expired transfer (EPP) code (open "Transfer in" case with subtype invalid_epp / approval_expired, domain still Pending / Pending Transfer). Text: Setup > Email Templates > Domain Messages > "' . TransferCodeReminder::TEMPLATE_NAME . '".'),
            'reminder_hour' => [
                'FriendlyName' => 'Reminder hour',
                'Type' => 'text',
                'Size' => '3',
                'Default' => (string) Config::DEFAULTS['reminder_hour'],
                'Description' => 'Reminders are sent once a day by the first cron run after this hour (server time, 0-23).',
            ],
            'reminder_max' => [
                'FriendlyName' => 'Maximum reminders per case',
                'Type' => 'text',
                'Size' => '4',
                'Default' => (string) Config::DEFAULTS['reminder_max'],
                'Description' => 'Stop e-mailing the client after this many reminders.',
            ],
            'reminder_escalate_days' => [
                'FriendlyName' => 'Alert staff when domain expires within (days)',
                'Type' => 'text',
                'Size' => '4',
                'Default' => (string) Config::DEFAULTS['reminder_escalate_days'],
                'Description' => 'From this many days before the domain expiry the admins are e-mailed every day too (priority set to High), so somebody calls the client.',
            ],
            'reminder_escalate_after' => [
                'FriendlyName' => 'Alert staff after (reminders)',
                'Type' => 'text',
                'Size' => '4',
                'Default' => (string) Config::DEFAULTS['reminder_escalate_after'],
                'Description' => 'Also alert the admins when the client has ignored this many reminders, whatever the expiry date.',
            ],
            'drop_tables_on_deactivate' => ['FriendlyName' => 'Drop tables on deactivation'] + $yesNo('When ticked, deactivating the addon permanently deletes all issues, comments and history. Leave unticked to keep data across re-activation.'),
        ],
    ];
}

/**
 * Called on activation: creates the module tables.
 */
function domainissues_activate(): array
{
    try {
        Installer::install();
        TransferCodeReminder::ensureTable();
        TransferCodeReminder::installTemplate();

        // Stage 1 categories are on by default; make sure the rows exist even if
        // WHMCS does not persist the yes/no "Default" value on first activation.
        foreach (['enable_transfer_in', 'enable_transfer_out'] as $setting) {
            if (Config::raw($setting) === null || Config::raw($setting) === '') {
                Config::setInternal($setting, 'on');
            }
        }

        return [
            'status' => 'success',
            'description' => 'Domain Issues Tracker activated. Tables mod_domainissues, mod_domainissues_comments and mod_domainissues_log are ready. Configure categories and adapters in the addon settings, then open Addons > Domain Issues Tracker.',
        ];
    } catch (\Throwable $e) {
        return [
            'status' => 'error',
            'description' => 'Unable to create the module tables: ' . $e->getMessage(),
        ];
    }
}

/**
 * Called on deactivation: drops tables only when the admin opted in (§7, §8.5).
 */
function domainissues_deactivate(): array
{
    try {
        Config::reset();
        if (Config::dropTablesOnDeactivate()) {
            Installer::uninstall();

            return [
                'status' => 'success',
                'description' => 'Domain Issues Tracker deactivated and its tables dropped.',
            ];
        }

        return [
            'status' => 'success',
            'description' => 'Domain Issues Tracker deactivated. Data was kept (enable "Drop tables on deactivation" before deactivating to remove it).',
        ];
    } catch (\Throwable $e) {
        return [
            'status' => 'error',
            'description' => 'Deactivation error: ' . $e->getMessage(),
        ];
    }
}

/**
 * Called when the version in domainissues_config() is higher than the stored one.
 *
 * @param array{version: string} $vars
 */
function domainissues_upgrade(array $vars): void
{
    try {
        Installer::upgrade((string) ($vars['version'] ?? '0'));
        TransferCodeReminder::ensureTable();      // 1.5.0
        TransferCodeReminder::installTemplate();  // 1.5.0
    } catch (\Throwable $e) {
        if (function_exists('logModuleCall')) {
            logModuleCall(Config::MODULE, 'upgrade', $vars, $e->getMessage());
        }
    }
}

/**
 * Admin area page output (§5).
 *
 * @param array<string, mixed> $vars
 */
function domainissues_output(array $vars): void
{
    try {
        (new AdminController($vars))->dispatch();
    } catch (\Throwable $e) {
        echo '<div class="alert alert-danger"><strong>Domain Issues Tracker error:</strong> '
            . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</div>';
        if (function_exists('logModuleCall')) {
            logModuleCall(Config::MODULE, 'admin_output', $_REQUEST, $e->getMessage() . "\n" . $e->getTraceAsString());
        }
    }
}
