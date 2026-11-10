<?php
/**
 * Domain Issues Tracker: registrar balance check from the shell (1.6.0).
 *
 *   php modules/addons/domainissues/cli/balance_check.php            read + show, NO e-mails
 *   php modules/addons/domainissues/cli/balance_check.php --notify   same as the cron (alerts if needed)
 *
 * Shows, per registrar (Enom, Netim): the balance, what falls due in the next
 * hours, every domain counted and where its cost comes from. Run it as the
 * WHMCS file owner (e.g. sudo -u <user> php ...).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 4);
chdir($root);
require $root . '/init.php';
require_once $root . '/includes/registrarfunctions.php';
require_once dirname(__DIR__) . '/autoload.php';

use WHMCS\Module\Addon\DomainIssues\Config;
use WHMCS\Module\Addon\DomainIssues\Cron\BalanceMonitor;

$notify = in_array('--notify', $argv, true);
printf("Registrar balance check (%s), window %d h, minimum %.2f, alerts %s%s\n\n",
    date('Y-m-d H:i'),
    Config::balanceWindowHours(),
    Config::balanceThreshold(),
    Config::balanceMonitorEnabled() ? 'ON' : 'OFF (setting "Registrar balance alerts")',
    $notify ? ', e-mails allowed' : ', no e-mails (add --notify)'
);

$results = BalanceMonitor::run($notify);
if ($results === []) {
    echo "No Enom / Netim registrar module is activated in WHMCS.\n";
    exit(0);
}
$labels = [BalanceMonitor::STATE_OK => 'OK', BalanceMonitor::STATE_LOW => 'LOW (below minimum)', BalanceMonitor::STATE_SHORT => 'NOT ENOUGH for the window', BalanceMonitor::STATE_ERROR => 'ERROR'];
foreach ($results as $slug => $r) {
    echo '== ' . BalanceMonitor::label($slug) . ': ' . ($labels[$r['state']] ?? $r['state']) . "\n";
    if ($r['state'] === BalanceMonitor::STATE_ERROR) {
        echo '   ' . $r['error'] . "\n\n";
        continue;
    }
    printf("   balance %.2f %s, due in the window %.2f %s (%d domains", $r['balance'], $r['currency'], $r['total'], $r['currency'], count($r['items']));
    echo $r['unpriced'] > 0 ? ', ' . $r['unpriced'] . " without price)\n" : ")\n";
    foreach ($r['items'] as $item) {
        printf("   - %-40s %-12s %-14s %d y  %s\n", $item['domain'], $item['kind'], $item['date'], $item['years'],
            $item['cost'] !== null ? sprintf('%8.2f  (%s)', $item['cost'], $item['source']) : '     n/a  (no price)');
    }
    echo "\n";
}
