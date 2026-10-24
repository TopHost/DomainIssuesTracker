<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\DomainIssues\Admin;

use WHMCS\Module\Addon\DomainIssues\Config;
use WHMCS\Module\Addon\DomainIssues\Installer;
use WHMCS\Module\Addon\DomainIssues\IssueRepository;
use WHMCS\Module\Addon\DomainIssues\IssueService;
use WHMCS\Module\Addon\DomainIssues\Lang;
use WHMCS\Module\Addon\DomainIssues\Types;

/**
 * Routes admin page requests (addonmodules.php?module=domainissues&action=...).
 *
 * Read actions render templates; write actions require POST + the WHMCS admin
 * CSRF token and redirect afterwards (PRG pattern) with a flash message.
 */
final class AdminController
{
    private const FLASH_KEY = 'domainissues_flash';
    private const CSRF_NAMESPACE = 'WHMCS.admin.default';

    private string $link;
    private IssueRepository $repo;
    private IssueService $service;
    private View $view;
    private int $adminId;

    /** @param array<string, mixed> $vars from WHMCS */
    public function __construct(private readonly array $vars)
    {
        $this->link = (string) ($vars['modulelink'] ?? 'addonmodules.php?module=' . Config::MODULE);
        $this->repo = new IssueRepository();
        $this->service = new IssueService($this->repo);
        $this->view = new View($this->link);
        $this->adminId = (int) ($_SESSION['adminid'] ?? 0);
    }

    public function dispatch(): void
    {
        if (!Installer::tablesExist()) {
            echo '<div class="alert alert-warning">' . View::e(Lang::t('widget.not_installed')) . '</div>';

            return;
        }

        $action = (string) ($_REQUEST['action'] ?? 'list');

        switch ($action) {
            case 'ajax_domains':
                $this->ajaxDomains();
                return;
            case 'ajax_domain_lookup':
                $this->ajaxDomainLookup();
                return;
            case 'add':
                $this->showAdd();
                return;
            case 'view':
            case 'edit':
                $this->showDetail((int) ($_GET['id'] ?? 0));
                return;
            case 'save':
                $this->save();
                return;
            case 'comment':
                $this->comment();
                return;
            case 'delete':
                $this->delete();
                return;
            default:
                $this->showList();
        }
    }

    // ---------------------------------------------------------------------
    // Read actions
    // ---------------------------------------------------------------------

    private function showList(): void
    {
        $filters = $this->filtersFromRequest();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = Config::itemsPerPage();
        $threshold = Config::deadlineThresholdDays();

        $result = $this->repo->search($filters, $page, $perPage, $threshold);

        echo $this->view->render('list', [
            'issues' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => $perPage,
            'filters' => $filters,
            'threshold' => $threshold,
            'registrars' => $this->repo->knownRegistrars(),
            'enabledTypes' => Config::enabledIssueTypes(),
            'flash' => $this->takeFlash(),
        ]);
    }

    private function showAdd(): void
    {
        $old = $this->takeOldInput();

        echo $this->view->render('detail', [
            'issue' => null,
            'input' => $old['input'] ?? $this->defaultInput(),
            'errors' => $old['errors'] ?? [],
            'comments' => [],
            'logs' => [],
            'registrars' => $this->repo->knownRegistrars(),
            'enabledTypes' => Config::enabledIssueTypes(),
            'threshold' => Config::deadlineThresholdDays(),
            'flash' => $this->takeFlash(),
        ]);
    }

    private function showDetail(int $id): void
    {
        $issue = $id > 0 ? $this->repo->find($id) : null;
        if ($issue === null) {
            $this->flash('danger', Lang::t('error.not_found'));
            $this->redirect([]);

            return;
        }

        $old = $this->takeOldInput();
        $input = $old['input'] ?? $this->inputFromIssue($issue);

        // Editing keeps the existing type selectable even if the category was disabled later.
        $types = Config::enabledIssueTypes();
        if (!in_array((string) $issue->issue_type, $types, true)) {
            $types[] = (string) $issue->issue_type;
        }

        echo $this->view->render('detail', [
            'issue' => $issue,
            'input' => $input,
            'errors' => $old['errors'] ?? [],
            'comments' => $this->repo->comments($id),
            'logs' => $this->repo->logs($id),
            'registrars' => $this->repo->knownRegistrars(),
            'enabledTypes' => $types,
            'threshold' => Config::deadlineThresholdDays(),
            'flash' => $this->takeFlash(),
        ]);
    }

    // ---------------------------------------------------------------------
    // Write actions (POST + CSRF)
    // ---------------------------------------------------------------------

    private function save(): void
    {
        $this->requirePost();
        $id = (int) ($_POST['id'] ?? 0);
        $isNew = $id === 0;
        $input = $this->inputFromPost();

        $errors = $this->service->validate($input, $isNew);
        if ($errors !== []) {
            $this->keepOldInput($input, $errors);
            $this->flash('danger', Lang::t('flash.validation'));
            $this->redirect($isNew ? ['action' => 'add'] : ['action' => 'view', 'id' => $id]);

            return;
        }

        try {
            if ($isNew) {
                $newId = $this->service->createManual($input, $this->adminId);
                $this->flash('success', Lang::t('flash.created', [':id' => $newId]));
                $this->redirect(['action' => 'view', 'id' => $newId]);

                return;
            }

            $changed = $this->service->updateManual($id, $input, $this->adminId);
            if ($changed === []) {
                $this->flash('info', Lang::t('flash.no_changes'));
            } else {
                $labels = array_map(static fn (string $f): string => Lang::label('field', $f), $changed);
                $this->flash('success', Lang::t('flash.updated', [':id' => $id, ':fields' => implode(', ', $labels)]));
            }
        } catch (\Throwable $e) {
            $this->flash('danger', $e->getMessage());
        }

        $this->redirect(['action' => 'view', 'id' => $id]);
    }

    private function comment(): void
    {
        $this->requirePost();
        $id = (int) ($_POST['id'] ?? 0);

        try {
            $this->service->addComment($id, $this->adminId, (string) ($_POST['comment'] ?? ''));
            $this->flash('success', Lang::t('flash.comment_added'));
        } catch (\Throwable $e) {
            $this->flash('danger', $e->getMessage());
        }

        $this->redirect(['action' => 'view', 'id' => $id]);
    }

    private function delete(): void
    {
        $this->requirePost();
        $id = (int) ($_POST['id'] ?? 0);

        try {
            $this->service->delete($id, $this->adminId);
            $this->flash('success', Lang::t('flash.deleted', [':id' => $id]));
            $this->redirect([]);
        } catch (\Throwable $e) {
            $this->flash('danger', $e->getMessage());
            $this->redirect(['action' => 'view', 'id' => $id]);
        }
    }

    // ---------------------------------------------------------------------
    // AJAX (read-only JSON used by the domain autocomplete)
    // ---------------------------------------------------------------------

    private function ajaxDomains(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $rows = mb_strlen($q) >= 2 ? $this->repo->searchDomains($q, 15) : [];

        $this->json(array_map([$this, 'domainToArray'], $rows));
    }

    private function ajaxDomainLookup(): void
    {
        $name = strtolower(trim((string) ($_GET['domain'] ?? '')));
        $row = $name !== '' ? $this->repo->findDomainByName($name) : null;

        if ($row === null) {
            $this->json(['found' => false]);

            return;
        }

        $client = $this->repo->clientDisplayName((int) $row->userid);
        $this->json(['found' => true] + $this->domainToArray($row, $client));
    }

    /** @return array<string, mixed> */
    private function domainToArray(object $row, ?string $clientName = null): array
    {
        $clientName ??= IssueRepository::formatClientName(
            $row->firstname ?? null,
            $row->lastname ?? null,
            $row->companyname ?? null
        );

        return [
            'id' => (int) $row->id,
            'domain' => (string) $row->domain,
            'client_id' => (int) $row->userid,
            'client_name' => $clientName,
            'registrar' => (string) ($row->registrar ?? ''),
            'status' => (string) ($row->status ?? ''),
            'expirydate' => (string) ($row->expirydate ?? ''),
        ];
    }

    private function json(mixed $payload): void
    {
        // WHMCS buffers addon output; discard it so the response is pure JSON.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** Enforces POST + WHMCS admin CSRF token for write actions. */
    private function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->flash('danger', Lang::t('error.invalid_request'));
            $this->redirect([]);
        }
        if (function_exists('check_token')) {
            check_token(self::CSRF_NAMESPACE);
        }
    }

    /** @return array<string, mixed> */
    private function filtersFromRequest(): array
    {
        $type = (string) ($_GET['type'] ?? '');
        if ($type === '' && isset($_GET['category'])) {
            $type = 'cat:' . (string) $_GET['category']; // legacy / widget convenience
        }

        // Lifecycle toggles: explicit on form submit (persisted in the session),
        // explicit when a link passes them, otherwise the remembered choice.
        $sessionKey = self::FLASH_KEY . '_toggles';
        if (isset($_GET['filtered'])) {
            $toggles = ['show_expired' => !empty($_GET['show_expired']), 'show_redemption' => !empty($_GET['show_redemption'])];
            $_SESSION[$sessionKey] = $toggles;
        } elseif (isset($_GET['show_expired']) || isset($_GET['show_redemption'])) {
            $toggles = ['show_expired' => !empty($_GET['show_expired']), 'show_redemption' => !empty($_GET['show_redemption'])];
        } else {
            $saved = $_SESSION[$sessionKey] ?? [];
            $toggles = ['show_expired' => !empty($saved['show_expired']), 'show_redemption' => !empty($saved['show_redemption'])];
        }

        return [
            'filtered' => 1,
            'status' => (string) ($_GET['status'] ?? 'open'),
            'type' => $type,
            'show_expired' => $toggles['show_expired'] ? 1 : '',
            'show_redemption' => $toggles['show_redemption'] ? 1 : '',
            'registrar' => (string) ($_GET['registrar'] ?? ''),
            'source' => (string) ($_GET['source'] ?? ''),
            'priority' => (string) ($_GET['priority'] ?? ''),
            'q' => trim((string) ($_GET['q'] ?? '')),
            'urgent' => !empty($_GET['urgent']) ? 1 : '',
        ];
    }

    /** @return array<string, mixed> */
    private function inputFromPost(): array
    {
        $keys = ['domain_name', 'domain_id', 'client_id', 'registrar', 'issue_type', 'issue_subtype', 'status', 'priority', 'deadline', 'details'];
        $input = [];
        foreach ($keys as $key) {
            $value = $_POST[$key] ?? '';
            $input[$key] = is_string($value) ? trim($value) : '';
        }

        return $input;
    }

    /** @return array<string, mixed> */
    private function inputFromIssue(object $issue): array
    {
        return [
            'domain_name' => (string) $issue->domain_name,
            'domain_id' => $issue->domain_id !== null ? (string) $issue->domain_id : '',
            'client_id' => $issue->client_id !== null ? (string) $issue->client_id : '',
            'registrar' => (string) ($issue->registrar ?? ''),
            'issue_type' => (string) $issue->issue_type,
            'issue_subtype' => (string) ($issue->issue_subtype ?? ''),
            'status' => (string) $issue->status,
            'priority' => (string) $issue->priority,
            'deadline' => (string) ($issue->deadline ?? ''),
            'details' => (string) ($issue->details ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    private function defaultInput(): array
    {
        $types = Config::enabledIssueTypes();

        return [
            'domain_name' => (string) ($_GET['domain'] ?? ''),
            'domain_id' => '',
            'client_id' => '',
            'registrar' => '',
            'issue_type' => $types[0] ?? Types::TYPE_TRANSFER_IN,
            'issue_subtype' => '',
            'status' => Types::STATUS_NEW,
            'priority' => Types::PRIORITY_NORMAL,
            'deadline' => '',
            'details' => '',
        ];
    }

    private function flash(string $type, string $text): void
    {
        $_SESSION[self::FLASH_KEY] = ['type' => $type, 'text' => $text];
    }

    /** @return array{type:string,text:string}|null */
    private function takeFlash(): ?array
    {
        $flash = $_SESSION[self::FLASH_KEY] ?? null;
        unset($_SESSION[self::FLASH_KEY]);

        return is_array($flash) ? $flash : null;
    }

    /** @param array<string, mixed> $input @param array<string, string> $errors */
    private function keepOldInput(array $input, array $errors): void
    {
        $_SESSION[self::FLASH_KEY . '_input'] = ['input' => $input, 'errors' => $errors];
    }

    /** @return array{input:array<string,mixed>,errors:array<string,string>}|null */
    private function takeOldInput(): ?array
    {
        $old = $_SESSION[self::FLASH_KEY . '_input'] ?? null;
        unset($_SESSION[self::FLASH_KEY . '_input']);

        return is_array($old) ? $old : null;
    }

    /**
     * Redirect back into the module. Falls back to a client-side redirect when
     * headers were already sent by the WHMCS admin layout.
     */
    private function redirect(array $params): void
    {
        $url = $this->view->rawUrl($params);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Location: ' . $url);
        } else {
            $safe = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            echo '<meta http-equiv="refresh" content="0;url=' . $safe . '">'
                . '<script>window.location.href=' . json_encode($url) . ';</script>'
                . '<a href="' . $safe . '">Continue</a>';
        }
        exit;
    }
}
