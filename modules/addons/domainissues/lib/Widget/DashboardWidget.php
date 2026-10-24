<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\DomainIssues\Widget;

use WHMCS\Module\AbstractWidget;
use WHMCS\Module\Addon\DomainIssues\Config;
use WHMCS\Module\Addon\DomainIssues\Installer;
use WHMCS\Module\Addon\DomainIssues\IssueRepository;
use WHMCS\Module\Addon\DomainIssues\Lang;
use WHMCS\Module\Addon\DomainIssues\Types;

/**
 * Admin dashboard widget (hook AdminHomeWidgets) — compact counters + links.
 *
 * Properties are intentionally left untyped because the parent class declares
 * them without types (PHP forbids narrowing an untyped inherited property).
 */
class DashboardWidget extends AbstractWidget
{
    protected $title = 'Domain Issues';
    protected $description = 'Domains that need manual attention (transfers, renewals, registrations).';
    protected $weight = 60;
    protected $columns = 1;
    protected $cache = false;
    protected $cacheExpiry = 120;
    protected $requiredPermission = '';

    private const MODULE_LINK = 'addonmodules.php?module=domainissues';

    /**
     * @return array<string, mixed>
     */
    public function getData()
    {
        try {
            if (!Installer::tablesExist()) {
                return ['error' => Lang::t('widget.not_installed')];
            }

            $repo = new IssueRepository();
            $threshold = Config::deadlineThresholdDays();

            return [
                'transfers' => $repo->countOpenByTypes(Types::TRANSFER_TYPES),
                'others' => $repo->countOpenExcludingTypes(Types::TRANSFER_TYPES),
                'showOthers' => Config::hasOtherCategoriesEnabled(),
                'urgent' => $repo->countUrgent($threshold),
                'threshold' => $threshold,
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function generateOutput($data)
    {
        $e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if (isset($data['error'])) {
            return '<div class="widget-content-padded text-muted"><i class="fas fa-exclamation-triangle"></i> '
                . $e($data['error']) . '</div>';
        }

        $transfers = (int) $data['transfers'];
        $others = (int) $data['others'];
        $urgent = (int) $data['urgent'];
        $showOthers = !empty($data['showOthers']) || $others > 0;

        $row = static function (string $label, int $count, string $url, string $iconClass) use ($e): string {
            $countClass = $count > 0 ? 'text-danger' : 'text-muted';

            return '<a href="' . $e($url) . '" class="list-group-item di-widget-row" style="display:flex;justify-content:space-between;align-items:center;padding:6px 10px;">'
                . '<span><i class="fas ' . $iconClass . '" style="width:18px;text-align:center;"></i> ' . $e($label) . '</span>'
                . '<span class="badge" style="background:' . ($count > 0 ? '#d9534f' : '#999') . ';">' . $count . '</span>'
                . '</a>';
        };

        $html = '<div class="list-group" style="margin:0;">';
        $html .= $row(
            Lang::t('widget.problem_transfers'),
            $transfers,
            self::MODULE_LINK . '&type=cat:transfers',
            'fa-exchange-alt'
        );
        if ($showOthers) {
            $html .= $row(
                Lang::t('widget.other_issues'),
                $others,
                self::MODULE_LINK . '&type=cat:other&show_expired=1&show_redemption=1',
                'fa-globe'
            );
        }
        if ($urgent > 0) {
            $html .= '<a href="' . $e(self::MODULE_LINK . '&urgent=1&show_expired=1&show_redemption=1') . '" class="list-group-item" style="display:flex;justify-content:space-between;align-items:center;padding:6px 10px;background:#fff5f5;">'
                . '<span class="text-danger"><i class="fas fa-fire" style="width:18px;text-align:center;"></i> '
                . $e(Lang::t('widget.urgent', [':days' => (int) $data['threshold']])) . '</span>'
                . '<span class="label label-danger">' . $urgent . '</span>'
                . '</a>';
        }
        $html .= '</div>';
        $html .= '<div style="padding:6px 10px;text-align:right;">'
            . '<a href="' . $e(self::MODULE_LINK) . '" class="btn btn-default btn-xs">' . $e(Lang::t('widget.open_tracker')) . '</a> '
            . '<a href="' . $e(self::MODULE_LINK . '&action=add') . '" class="btn btn-default btn-xs"><i class="fas fa-plus"></i> ' . $e(Lang::t('ui.add_issue')) . '</a>'
            . '</div>';

        return $html;
    }
}
