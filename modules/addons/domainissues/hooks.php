<?php
/**
 * Domain Issues Tracker — hooks.
 *
 *  - AdminHomeWidgets: dashboard widget with counters (Stage 1)
 *  - AfterRegistrar*Failed: automatic issues from failed registrar operations (Stage 2, gated by settings)
 *  - AfterCronJob: periodic re-check of open automatic issues via registrar adapters (Stage 2)
 *
 * WHMCS only loads this file while the addon is active, so deactivating the
 * module removes every hook (§8.5). Every handler is wrapped so that an
 * exception can never break the admin area or the cron.
 */

use WHMCS\Module\Addon\DomainIssues\Cron\Recheck;
use WHMCS\Module\Addon\DomainIssues\Hooks\RegistrarFailureHandler;
use WHMCS\Module\Addon\DomainIssues\Types;
use WHMCS\Module\Addon\DomainIssues\Widget\DashboardWidget;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/autoload.php';

add_hook('AdminHomeWidgets', 1, static function () {
    try {
        return new DashboardWidget();
    } catch (\Throwable $e) {
        return null;
    }
});

add_hook('AfterRegistrarTransferFailed', 1, static function (array $vars): void {
    RegistrarFailureHandler::handle(Types::TYPE_TRANSFER_IN, $vars);
});

add_hook('AfterRegistrarRegistrationFailed', 1, static function (array $vars): void {
    RegistrarFailureHandler::handle(Types::TYPE_REGISTRATION, $vars);
});

add_hook('AfterRegistrarRenewalFailed', 1, static function (array $vars): void {
    RegistrarFailureHandler::handle(Types::TYPE_RENEWAL, $vars);
});

add_hook('AfterCronJob', 1, static function (array $vars): void {
    try {
        Recheck::runIfDue();
    } catch (\Throwable $e) {
        if (function_exists('logModuleCall')) {
            logModuleCall('domainissues', 'cron_recheck_fatal', $vars, $e->getMessage());
        }
    }
});
