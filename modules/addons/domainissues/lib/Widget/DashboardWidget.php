<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\DomainIssues\Widget;

use WHMCS\Module\AbstractWidget;
use WHMCS\Module\Addon\DomainIssues\Config;
use WHMCS\Module\Addon\DomainIssues\Cron\BalanceMonitor;
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
                'balances' => Config::balanceMonitorEnabled() ? BalanceMonitor::stored() : [],
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Same compact counter format as the other TopHost widgets (Bank Reconciliation,
     * eFactura): large numbers with uppercase labels, then shortcut buttons.
     * Data and links are unchanged; only the presentation differs.
     *
     * @param array<string, mixed> $data
     */
    public function generateOutput($data)
    {
        $e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if (isset($data['error'])) {
            return '<div class="widget-content-padded" style="font-size:13px;color:#E26D5C">&#9888; '
                . $e($data['error']) . '</div>';
        }

        $transfers = (int) $data['transfers'];
        $others = (int) $data['others'];
        $urgent = (int) $data['urgent'];
        $showOthers = !empty($data['showOthers']) || $others > 0;

        // Big number (coloured only when non-zero) above an uppercase label.
        $stat = static function (string $label, int $count, string $url, string $colour, string $title = '') use ($e): string {
            return '<a href="' . $e($url) . '" style="text-decoration:none;color:inherit"' . ($title !== '' ? ' title="' . $e($title) . '"' : '') . '>'
                . '<div style="font-size:26px;font-weight:700;color:' . ($count > 0 ? $colour : '#03045E') . '">' . $count . '</div>'
                . '<div style="font-size:11px;text-transform:uppercase;color:#6B7482">' . $e($label) . '</div></a>';
        };

        $html = '<div class="widget-content-padded" style="font-size:13px">';
        $html .= '<div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:10px">';
        $html .= $stat(Lang::t('widget.problem_transfers'), $transfers, self::MODULE_LINK . '&type=cat:transfers', '#E26D5C');
        if ($showOthers) {
            $html .= $stat(Lang::t('widget.other_issues'), $others, self::MODULE_LINK . '&type=cat:other&show_expired=1&show_redemption=1', '#C98A1A');
        }
        $html .= $stat(
            Lang::t('widget.urgent_short'),
            $urgent,
            self::MODULE_LINK . '&urgent=1&show_expired=1&show_redemption=1',
            '#E26D5C',
            Lang::t('widget.urgent', [':days' => (int) $data['threshold']])
        );
        $html .= '</div>';
        $html .= $this->balanceLine((array) ($data['balances'] ?? []), $e);
        $html .= '<div>'
            . '<a href="' . $e(self::MODULE_LINK) . '" class="btn btn-default btn-sm">' . $e(Lang::t('widget.open_tracker')) . '</a> '
            . '<a href="' . $e(self::MODULE_LINK . '&action=add') . '" class="btn btn-default btn-sm"><i class="fas fa-plus"></i> ' . $e(Lang::t('ui.add_issue')) . '</a>'
            . '</div></div>';

        return $html;
    }

    /**
     * One small block per registrar account, same style as the counters above:
     * balance (coloured by state), registrar name and what the next hours need.
     * Values stored by the cron; no API call from the dashboard.
     *
     * @param list<object> $rows
     */
    private function balanceLine(array $rows, callable $e): string
    {
        if ($rows === []) {
            return '';
        }
        $hours = Config::balanceWindowHours();
        $money = static fn ($v): string => number_format((float) $v, 2, '.', ' ');
        $blocks = '';
        foreach ($rows as $row) {
            $name = BalanceMonitor::label((string) $row->registrar);
            $when = $row->checked_at !== null ? date('d.m H:i', strtotime((string) $row->checked_at)) : '';
            $error = (string) $row->state === BalanceMonitor::STATE_ERROR || $row->balance === null;
            [$colour, $border, $background] = match (true) {
                $error => ['#C98A1A', '#F0D9A8', '#FFF9EC'],
                (string) $row->state === BalanceMonitor::STATE_SHORT => ['#E26D5C', '#F3C4BD', '#FDF1EF'],
                (string) $row->state === BalanceMonitor::STATE_LOW => ['#C98A1A', '#F0D9A8', '#FFF9EC'],
                default => ['#2E8540', '#CFE5D3', '#F3FAF4'],
            };
            if ($error) {
                $amount = '?';
                $sub = Lang::t('widget.balance_unknown');
                $title = (string) $row->error;
            } else {
                $amount = $money($row->balance) . ' <span style="font-size:13px;font-weight:600">' . $e((string) $row->currency) . '</span>';
                $sub = Lang::t('widget.balance_due', [':hours' => $hours, ':amount' => $money($row->forecast) . ' ' . $row->currency]);
                $title = Lang::t('widget.balance_title', [
                    ':hours' => $hours,
                    ':forecast' => $money($row->forecast) . ' ' . $row->currency,
                    ':count' => (int) $row->forecast_count,
                    ':when' => $when,
                ]);
            }
            $blocks .= '<div title="' . $e($title) . '" style="flex:1 1 140px;min-width:0;border:1px solid ' . $border . ';background:' . $background . ';border-radius:6px;padding:8px 12px">'
                . '<div style="font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:#6B7482;font-weight:600">' . $e($name) . '</div>'
                . '<div style="font-size:22px;font-weight:700;line-height:1.25;color:' . $colour . ';white-space:nowrap">' . $amount . '</div>'
                . '<div style="font-size:11px;color:#6B7482;white-space:nowrap">' . $e($sub) . '</div>'
                . '</div>';
        }

        return '<div style="font-size:11px;text-transform:uppercase;color:#6B7482;margin:2px 0 6px">' . $e(rtrim(Lang::t('widget.balance'), ':')) . '</div>'
            . '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px">' . $blocks . '</div>';
    }
}
