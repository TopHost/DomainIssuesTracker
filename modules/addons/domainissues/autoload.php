<?php
/**
 * Domain Issues Tracker — PSR-4 style autoloader for the addon's lib/ directory.
 *
 * Namespace WHMCS\Module\Addon\DomainIssues\  =>  modules/addons/domainissues/lib/
 *
 * WHMCS itself can autoload addon classes under this namespace, but registering
 * our own loader keeps the module independent of version-specific behaviour
 * (e.g. how the module name is capitalised) and lets hooks.php share the same code.
 */

if (!defined('DOMAINISSUES_AUTOLOADER')) {
    define('DOMAINISSUES_AUTOLOADER', true);

    spl_autoload_register(static function (string $class): void {
        $prefix = 'WHMCS\\Module\\Addon\\DomainIssues\\';
        $prefixLength = strlen($prefix);

        if (strncmp($class, $prefix, $prefixLength) !== 0) {
            return;
        }

        $relative = substr($class, $prefixLength);
        $file = __DIR__ . '/lib/' . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    });
}
