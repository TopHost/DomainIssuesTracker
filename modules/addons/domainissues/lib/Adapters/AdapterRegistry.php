<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\DomainIssues\Adapters;

use WHMCS\Module\Addon\DomainIssues\Config;

/**
 * Registry of available registrar adapters. To add a new registrar:
 *  1. create lib/Adapters/<Name>Adapter.php implementing AdapterInterface
 *     (extend AbstractAdapter for logging / EPP helpers);
 *  2. add its class to self::ADAPTERS below;
 *  3. add an "adapter_<slug>" yes/no field in domainissues_config() and Config::DEFAULTS.
 */
final class AdapterRegistry
{
    /** @var array<string, class-string<AdapterInterface>> slug => class */
    public const ADAPTERS = [
        'enom' => EnomAdapter::class,
        'netim' => NetimAdapter::class,
        'molddata' => MolddataAdapter::class,
    ];

    /** @var array<string, AdapterInterface> */
    private static array $instances = [];

    /** @return string[] */
    public static function slugs(): array
    {
        return array_keys(self::ADAPTERS);
    }

    public static function has(string $slug): bool
    {
        return isset(self::ADAPTERS[strtolower($slug)]);
    }

    /** Returns the adapter for a registrar slug, or null when unknown / disabled in settings. */
    public static function enabledFor(string $slug): ?AdapterInterface
    {
        $slug = strtolower($slug);
        if (!self::has($slug) || !Config::isAdapterEnabled($slug)) {
            return null;
        }

        return self::$instances[$slug] ??= new (self::ADAPTERS[$slug])();
    }

    /** @return array<string, AdapterInterface> slug => adapter, enabled ones only */
    public static function enabled(): array
    {
        $result = [];
        foreach (self::slugs() as $slug) {
            $adapter = self::enabledFor($slug);
            if ($adapter !== null) {
                $result[$slug] = $adapter;
            }
        }

        return $result;
    }
}
