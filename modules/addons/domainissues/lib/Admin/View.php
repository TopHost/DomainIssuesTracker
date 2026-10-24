<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\DomainIssues\Admin;

use WHMCS\Module\Addon\DomainIssues\Config;
use WHMCS\Module\Addon\DomainIssues\Lang;
use WHMCS\Module\Addon\DomainIssues\Types;

/**
 * Tiny PHP template renderer with escaping/URL helpers for the admin pages.
 * Templates live in ../templates and receive $view (this object) plus the data array.
 *
 * Presentation follows the design shared by the other TopHost modules
 * (Bank Reconciliation, eFactura): styles in assets/dit.css, prefix dit-.
 */
final class View
{
    /** Pill colour per status / priority (TopHost palette: warm = needs action, cool = information). */
    private const STATUS_COLOURS = [
        Types::STATUS_NEW => 'red',
        Types::STATUS_IN_PROGRESS => 'yellow',
        Types::STATUS_WAITING_CLIENT => 'blue',
        Types::STATUS_RESOLVED => 'green',
        Types::STATUS_CANCELLED => 'gray',
    ];
    private const PRIORITY_COLOURS = [
        Types::PRIORITY_LOW => 'gray',
        Types::PRIORITY_NORMAL => 'neutral',
        Types::PRIORITY_HIGH => 'red',
    ];
    /** Flash type (Bootstrap alert name used by the controller) => message bar class. */
    private const FLASH_BARS = [
        'success' => 'dit-okbar',
        'info' => 'dit-infobar',
        'warning' => 'dit-warnbar',
        'danger' => 'dit-warnbar',
    ];

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

    /**
     * Public URL of the assets folder. Admin pages run from the (renamable) admin
     * directory, so the configured SystemURL is preferred over a relative path.
     */
    public static function assetsUrl(): string
    {
        $base = '';
        try {
            if (class_exists('\\WHMCS\\Config\\Setting')) {
                $base = (string) \WHMCS\Config\Setting::getValue('SystemURL');
            }
        } catch (\Throwable $e) {
        }
        if ($base === '' && !empty($GLOBALS['CONFIG']['SystemURL'])) {
            $base = (string) $GLOBALS['CONFIG']['SystemURL'];
        }
        if ($base !== '' && !empty($_SERVER['HTTPS']) && str_starts_with($base, 'http://')) {
            $base = 'https://' . substr($base, 7); // avoid mixed content when the admin runs over HTTPS
        }

        return ($base !== '' ? rtrim($base, '/') : '..') . '/modules/addons/' . Config::MODULE . '/assets';
    }

    /** Flash message as a TopHost message bar. @param array{type:string,text:string}|null $flash */
    public function flashBar(?array $flash): string
    {
        if (!$flash) {
            return '';
        }
        $class = self::FLASH_BARS[$flash['type']] ?? 'dit-infobar';
        $icon = $class === 'dit-okbar' ? '&#10003; ' : ($class === 'dit-warnbar' ? '&#9888; ' : '');

        return '<div class="' . $class . '">' . $icon . self::e($flash['text']) . '</div>';
    }

    /** Pill badge in one of the palette colours: green, yellow, red, blue, gray, neutral. */
    public function pill(string $escapedLabel, string $colour): string
    {
        return '<span class="dit-badge ' . self::e($colour) . '">' . $escapedLabel . '</span>';
    }

    public function csrf(): string
    {
        return function_exists('generate_token') ? (string) generate_token('WHMCS.admin.default') : '';
    }

    public function statusBadge(?string $status): string
    {
        if ($status === null || $status === '') {
            return '<span class="dit-dash">&mdash;</span>';
        }

        return $this->pill($this->label('status', $status), self::STATUS_COLOURS[$status] ?? 'neutral');
    }

    public function priorityBadge(?string $priority): string
    {
        if ($priority === null || $priority === '') {
            return '<span class="dit-dash">&mdash;</span>';
        }

        return $this->pill($this->label('priority', $priority), self::PRIORITY_COLOURS[$priority] ?? 'neutral');
    }

    public function sourceBadge(?string $source): string
    {
        return $this->pill($this->label('source', $source), $source === Types::SOURCE_AUTOMATIC ? 'blue' : 'neutral');
    }

    /**
     * Deadline cell: date + remaining days, highlighted when within the
     * configured threshold or overdue.
     */
    public function deadlineCell(?string $deadline, ?int $thresholdDays = null): string
    {
        if ($deadline === null || $deadline === '') {
            return '<span class="dit-dash">&mdash;</span>';
        }
        $thresholdDays ??= Config::deadlineThresholdDays();
        $days = self::daysUntil($deadline);

        if ($days < 0) {
            $badge = $this->pill($this->t('deadline.overdue', [':days' => abs($days)]), 'red');
        } elseif ($days === 0) {
            $badge = $this->pill($this->t('deadline.today'), 'red');
        } elseif ($days <= $thresholdDays) {
            $badge = $this->pill($this->t('deadline.days_left', [':days' => $days]), 'red');
        } else {
            $badge = $this->pill($this->t('deadline.days_left', [':days' => $days]), 'gray');
        }

        return '<span class="dit-nowrap">' . self::e($deadline) . '</span> ' . $badge;
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
            return '<span class="dit-dash">&mdash;</span>';
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

        return '<a href="' . self::e($url) . '" target="_blank" class="dit-ext" title="' . $this->t('ui.open_in_whmcs') . '">'
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
            return '<span class="dit-dash">&mdash;</span>';
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

    /** Pager in the TopHost style (page buttons), preserving the given query params. */
    public function pagination(int $page, int $perPage, int $total, array $params): string
    {
        $pages = (int) max(1, ceil($total / max(1, $perPage)));
        if ($pages <= 1) {
            return '';
        }
        $page = min(max(1, $page), $pages);

        $item = function (int $p, string $label, bool $active = false, bool $disabled = false) use ($params): string {
            if ($disabled) {
                return '<span class="dit-btn sm" aria-disabled="true" style="opacity:.45;cursor:default">' . $label . '</span>';
            }
            $href = $active ? '#' : $this->url(array_merge($params, ['page' => $p]));

            return '<a class="dit-btn sm' . ($active ? ' blue' : '') . '" href="' . $href . '">' . $label . '</a>';
        };

        $html = '<div class="dit-pages">';
        $html .= $item(max(1, $page - 1), '&laquo;', false, $page === 1);

        $window = 3;
        $start = max(1, $page - $window);
        $end = min($pages, $page + $window);
        if ($start > 1) {
            $html .= $item(1, '1');
            if ($start > 2) {
                $html .= '<span class="dit-muted">&hellip;</span>';
            }
        }
        for ($p = $start; $p <= $end; $p++) {
            $html .= $item($p, (string) $p, $p === $page);
        }
        if ($end < $pages) {
            if ($end < $pages - 1) {
                $html .= '<span class="dit-muted">&hellip;</span>';
            }
            $html .= $item($pages, (string) $pages);
        }

        $html .= $item(min($pages, $page + 1), '&raquo;', false, $page === $pages);
        $html .= '</div>';

        return $html;
    }
}
