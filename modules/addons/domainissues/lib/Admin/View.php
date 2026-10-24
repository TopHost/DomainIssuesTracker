<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\DomainIssues\Admin;

use WHMCS\Module\Addon\DomainIssues\Config;
use WHMCS\Module\Addon\DomainIssues\Lang;
use WHMCS\Module\Addon\DomainIssues\Types;

/**
 * Tiny PHP template renderer with escaping/URL helpers for the admin pages.
 * Templates live in ../templates and receive $view (this object) plus the data array.
 */
final class View
{
    private string $templateDir;

    public function __construct(private readonly string $moduleLink)
    {
        $this->templateDir = dirname(__DIR__, 2) . '/templates';
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $file = $this->templateDir . '/' . basename($template) . '.php';
        if (!is_file($file)) {
            return '<div class="alert alert-danger">Missing template: ' . self::e($template) . '</div>';
        }

        $view = $this;
        extract($data, EXTR_SKIP);

        ob_start();
        try {
            include $file;
        } finally {
            $output = ob_get_clean();
        }

        return (string) $output;
    }

    // ---------------------------------------------------------------------
    // Helpers available to templates
    // ---------------------------------------------------------------------

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string, string|int> $replace */
    public function t(string $key, array $replace = []): string
    {
        return self::e(Lang::t($key, $replace));
    }

    public function label(string $group, ?string $value): string
    {
        return self::e(Lang::label($group, $value));
    }

    public function link(): string
    {
        return $this->moduleLink;
    }

    /** Build a module URL with extra query parameters (empty values are dropped). */
    public function url(array $params = []): string
    {
        $params = array_filter($params, static fn ($v) => $v !== null && $v !== '' && $v !== false);
        $query = http_build_query($params);

        return self::e($this->moduleLink . ($query !== '' ? '&' . $query : ''));
    }

    /** Raw (unescaped) URL, for use inside JavaScript strings via json_encode. */
    public function rawUrl(array $params = []): string
    {
        $params = array_filter($params, static fn ($v) => $v !== null && $v !== '' && $v !== false);
        $query = http_build_query($params);

        return $this->moduleLink . ($query !== '' ? '&' . $query : '');
    }

    public function csrf(): string
    {
        return function_exists('generate_token') ? (string) generate_token('WHMCS.admin.default') : '';
    }

    public function statusBadge(?string $status): string
    {
        $css = Types::STATUS_CSS[$status] ?? 'label-default';

        return '<span class="label ' . $css . '">' . $this->label('status', $status) . '</span>';
    }

    public function priorityBadge(?string $priority): string
    {
        $css = Types::PRIORITY_CSS[$priority] ?? 'label-default';

        return '<span class="label ' . $css . '">' . $this->label('priority', $priority) . '</span>';
    }

    public function sourceBadge(?string $source): string
    {
        $css = $source === Types::SOURCE_AUTOMATIC ? 'label-primary' : 'label-default';

        return '<span class="label ' . $css . '">' . $this->label('source', $source) . '</span>';
    }

    /**
     * Deadline cell: date + remaining days, highlighted when within the
     * configured threshold or overdue.
     */
    public function deadlineCell(?string $deadline, ?int $thresholdDays = null): string
    {
        if ($deadline === null || $deadline === '') {
            return '<span class="text-muted">&mdash;</span>';
        }
        $thresholdDays ??= Config::deadlineThresholdDays();
        $days = self::daysUntil($deadline);

        if ($days < 0) {
            $badge = '<span class="label label-danger">' . $this->t('deadline.overdue', [':days' => abs($days)]) . '</span>';
        } elseif ($days === 0) {
            $badge = '<span class="label label-danger">' . $this->t('deadline.today') . '</span>';
        } elseif ($days <= $thresholdDays) {
            $badge = '<span class="label label-danger">' . $this->t('deadline.days_left', [':days' => $days]) . '</span>';
        } else {
            $badge = '<span class="text-muted">' . $this->t('deadline.days_left', [':days' => $days]) . '</span>';
        }

        return self::e($deadline) . ' ' . $badge;
    }

    public static function daysUntil(string $date): int
    {
        $today = new \DateTimeImmutable('today');
        $target = \DateTimeImmutable::createFromFormat('!Y-m-d', $date) ?: $today;
        $diff = $today->diff($target);

        return (int) $diff->format('%r%a');
    }

    public function isUrgent(object $issue, ?int $thresholdDays = null): bool
    {
        if (!Types::isOpenStatus((string) $issue->status)) {
            return false;
        }
        if ($issue->priority === Types::PRIORITY_HIGH) {
            return true;
        }
        if (!empty($issue->deadline)) {
            $thresholdDays ??= Config::deadlineThresholdDays();

            return self::daysUntil((string) $issue->deadline) <= $thresholdDays;
        }

        return false;
    }

    public function clientLink(?int $clientId, ?string $name): string
    {
        if ($clientId === null || $clientId === 0) {
            return '<span class="text-muted">&mdash;</span>';
        }
        $text = $name !== null && $name !== '' ? $name : '#' . $clientId;

        return '<a href="clientssummary.php?userid=' . (int) $clientId . '">' . self::e($text) . '</a>';
    }

    public function domainLink(?int $domainId, ?int $clientId): string
    {
        if ($domainId === null || $domainId === 0) {
            return '';
        }
        $url = 'clientsdomains.php?id=' . (int) $domainId . ($clientId ? '&userid=' . (int) $clientId : '');

        return '<a href="' . self::e($url) . '" target="_blank" title="' . $this->t('ui.open_in_whmcs') . '">'
            . '<i class="fas fa-external-link-alt"></i></a>';
    }

    public function adminName(object $row): string
    {
        $name = trim(($row->admin_firstname ?? '') . ' ' . ($row->admin_lastname ?? ''));
        if ($name === '') {
            $name = (string) ($row->admin_username ?? '');
        }
        if ($name === '') {
            $name = ($row->admin_id ?? null) ? '#' . (int) $row->admin_id : Lang::t('ui.system');
        }

        return self::e($name);
    }

    public function dateTime(?string $value): string
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000')) {
            return '<span class="text-muted">&mdash;</span>';
        }

        return self::e(substr($value, 0, 16));
    }

    /** Renders <option> tags. @param array<string|int, string> $options value => label */
    public function options(array $options, ?string $selected): string
    {
        $html = '';
        foreach ($options as $value => $label) {
            $sel = (string) $value === (string) $selected ? ' selected' : '';
            $html .= '<option value="' . self::e($value) . '"' . $sel . '>' . self::e($label) . '</option>';
        }

        return $html;
    }

    /** Bootstrap 3 pagination preserving the given query params. */
    public function pagination(int $page, int $perPage, int $total, array $params): string
    {
        $pages = (int) max(1, ceil($total / max(1, $perPage)));
        if ($pages <= 1) {
            return '';
        }
        $page = min(max(1, $page), $pages);

        $item = function (int $p, string $label, bool $active = false, bool $disabled = false) use ($params): string {
            $cls = $active ? ' class="active"' : ($disabled ? ' class="disabled"' : '');
            $href = $disabled || $active ? '#' : $this->url(array_merge($params, ['page' => $p]));

            return '<li' . $cls . '><a href="' . $href . '">' . $label . '</a></li>';
        };

        $html = '<ul class="pagination pagination-sm">';
        $html .= $item(max(1, $page - 1), '&laquo;', false, $page === 1);

        $window = 3;
        $start = max(1, $page - $window);
        $end = min($pages, $page + $window);
        if ($start > 1) {
            $html .= $item(1, '1');
            if ($start > 2) {
                $html .= '<li class="disabled"><span>&hellip;</span></li>';
            }
        }
        for ($p = $start; $p <= $end; $p++) {
            $html .= $item($p, (string) $p, $p === $page);
        }
        if ($end < $pages) {
            if ($end < $pages - 1) {
                $html .= '<li class="disabled"><span>&hellip;</span></li>';
            }
            $html .= $item($pages, (string) $pages);
        }

        $html .= $item(min($pages, $page + 1), '&raquo;', false, $page === $pages);
        $html .= '</ul>';

        return $html;
    }
}
