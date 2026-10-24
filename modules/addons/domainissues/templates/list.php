<?php
/**
 * Issues list (§5.2).
 *
 * @var \WHMCS\Module\Addon\DomainIssues\Admin\View $view
 * @var object[] $issues
 * @var int $total
 * @var int $page
 * @var int $perPage
 * @var array<string, mixed> $filters
 * @var int $threshold
 * @var string[] $registrars
 * @var string[] $enabledTypes
 * @var array{type:string,text:string}|null $flash
 */

use WHMCS\Module\Addon\DomainIssues\IssueRepository;
use WHMCS\Module\Addon\DomainIssues\Lang;
use WHMCS\Module\Addon\DomainIssues\Types;

$from = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
$to = min($total, $page * $perPage);

$statusOptions = ['open' => Lang::t('status.open')];
foreach (Types::STATUSES as $s) {
    $statusOptions[$s] = Lang::label('status', $s);
}
$statusOptions['closed'] = Lang::t('status.closed');
$statusOptions['all'] = Lang::t('status.all');

$typeOptions = ['' => Lang::t('ui.all_types'), 'cat:transfers' => Lang::t('type.cat:transfers')];
foreach ($enabledTypes as $t) {
    $typeOptions[$t] = Lang::label('type', $t);
}
if (count($enabledTypes) > count(array_intersect($enabledTypes, Types::TRANSFER_TYPES))) {
    $typeOptions['cat:other'] = Lang::t('type.cat:other');
}
// Keep a disabled-but-present type selectable when it arrives via URL.
if ($filters['type'] !== '' && !isset($typeOptions[$filters['type']]) && in_array($filters['type'], Types::TYPES, true)) {
    $typeOptions[$filters['type']] = Lang::label('type', $filters['type']);
}

$registrarOptions = ['' => Lang::t('ui.all_registrars')];
foreach ($registrars as $r) {
    $registrarOptions[$r] = $r;
}
$registrarOptions['(none)'] = Lang::t('ui.no_registrar');

$sourceOptions = ['' => Lang::t('ui.all_sources')];
foreach (Types::SOURCES as $s) {
    $sourceOptions[$s] = Lang::label('source', $s);
}
$priorityOptions = ['' => Lang::t('ui.all_priorities')];
foreach (Types::PRIORITIES as $p) {
    $priorityOptions[$p] = Lang::label('priority', $p);
}

$activeTab = match ((string) $filters['type']) {
    'cat:transfers' => 'transfers',
    'cat:other' => 'other',
    default => 'list',
};
?>
<div class="dit" id="dit-app">
<?= $view->render('_topbar', ['active' => $activeTab]) ?>

<div class="dit-head">
    <h2><?= $view->t('ui.title') ?> <small><?= (int) $total ?></small></h2>
    <a class="dit-btn primary" href="<?= $view->url(['action' => 'add']) ?>"><i class="fas fa-plus"></i> <?= $view->t('ui.add_issue') ?></a>
</div>

<?= $view->flashBar($flash) ?>

<form method="get" action="addonmodules.php" class="dit-filters">
    <input type="hidden" name="module" value="domainissues">
    <label><?= $view->t('col.status') ?>
        <select name="status"><?= $view->options($statusOptions, (string) $filters['status']) ?></select></label>
    <label><?= $view->t('col.type') ?>
        <select name="type"><?= $view->options($typeOptions, (string) $filters['type']) ?></select></label>
    <label><?= $view->t('col.registrar') ?>
        <select name="registrar"><?= $view->options($registrarOptions, (string) $filters['registrar']) ?></select></label>
    <label><?= $view->t('col.source') ?>
        <select name="source"><?= $view->options($sourceOptions, (string) $filters['source']) ?></select></label>
    <label><?= $view->t('col.priority') ?>
        <select name="priority"><?= $view->options($priorityOptions, (string) $filters['priority']) ?></select></label>
    <label><?= $view->t('ui.search') ?>
        <input type="text" name="q" class="wide" value="<?= $view::e($filters['q']) ?>" placeholder="<?= $view->t('ui.search_placeholder') ?>"></label>
    <input type="hidden" name="filtered" value="1">
    <div class="dit-checks">
        <label class="dit-check">
            <input type="checkbox" name="urgent" value="1" <?= !empty($filters['urgent']) ? 'checked' : '' ?>> <?= $view->t('ui.urgent_only') ?>
        </label>
        <label class="dit-check" title="<?= $view->t('ui.show_expired_help') ?>">
            <input type="checkbox" name="show_expired" value="1" <?= !empty($filters['show_expired']) ? 'checked' : '' ?>> <?= $view->t('ui.show_expired') ?>
        </label>
        <label class="dit-check" title="<?= $view->t('ui.show_redemption_help') ?>">
            <input type="checkbox" name="show_redemption" value="1" <?= !empty($filters['show_redemption']) ? 'checked' : '' ?>> <?= $view->t('ui.show_redemption') ?>
        </label>
    </div>
    <button type="submit" class="dit-btn blue"><i class="fas fa-filter"></i> <?= $view->t('ui.filter') ?></button>
    <a href="<?= $view->url(['filtered' => 1]) ?>" class="dit-btn"><?= $view->t('ui.reset') ?></a>
</form>

<div class="dit-infobar dit-small">
    <?= $view->t('ui.showing', [':from' => $from, ':to' => $to, ':total' => $total]) ?>
    &middot; <?= $view->t('ui.urgent_hint', [':days' => $threshold]) ?>
    <?php if ($filters['type'] !== Types::TYPE_REDEMPTION && (empty($filters['show_expired']) || empty($filters['show_redemption']))): ?>
        &middot; <?= $view->t('ui.lifecycle_hidden') ?>
    <?php endif; ?>
</div>

<?php if ($issues === []): ?>
    <div class="dit-empty"><?= $view->t('ui.no_results') ?></div>
<?php else: ?>
<div class="dit-card dit-card-table">
<div class="table-responsive dit-scroll">
<table class="dit-table">
    <thead>
    <tr>
        <th><?= $view->t('col.id') ?></th>
        <th><?= $view->t('col.domain') ?></th>
        <th><?= $view->t('col.client') ?></th>
        <th><?= $view->t('col.type') ?></th>
        <th><?= $view->t('col.subtype') ?></th>
        <th><?= $view->t('col.registrar') ?></th>
        <th><?= $view->t('col.source') ?></th>
        <th><?= $view->t('col.status') ?></th>
        <th><?= $view->t('col.priority') ?></th>
        <th><?= $view->t('col.deadline') ?></th>
        <th><?= $view->t('col.updated') ?></th>
        <th></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($issues as $issue):
        $urgent = $view->isUrgent($issue, $threshold);
        $closed = !Types::isOpenStatus((string) $issue->status);
        $clientName = IssueRepository::formatClientName($issue->client_firstname, $issue->client_lastname, $issue->client_companyname);
        $detailUrl = $view->url(['action' => 'view', 'id' => (int) $issue->id]);
        ?>
        <tr class="<?= trim(($urgent ? 'dit-urgent ' : '') . ($closed ? 'dit-closed' : '')) ?>">
            <td class="dit-id"><a href="<?= $detailUrl ?>">#<?= (int) $issue->id ?></a></td>
            <td>
                <span class="dit-domain"><a href="<?= $detailUrl ?>"><?= $view::e($issue->domain_name) ?></a></span>
                <?= $view->domainLink($issue->domain_id !== null ? (int) $issue->domain_id : null, $issue->client_id !== null ? (int) $issue->client_id : null) ?>
                <?php if (!empty($issue->whmcs_domain_status)): ?>
                    <span class="dit-sub"><?= $view::e($issue->whmcs_domain_status) ?></span>
                <?php endif; ?>
            </td>
            <td class="dit-client"><?= $view->clientLink($issue->client_id !== null ? (int) $issue->client_id : null, $clientName) ?></td>
            <td class="dit-nowrap"><?= $view->label('type', $issue->issue_type) ?></td>
            <td><?= $issue->issue_subtype ? $view->label('subtype', $issue->issue_subtype) : '<span class="dit-dash">&mdash;</span>' ?></td>
            <td><?= $issue->registrar ? '<span class="dit-mono dit-small">' . $view::e($issue->registrar) . '</span>' : '<span class="dit-dash">&mdash;</span>' ?></td>
            <td><?= $view->sourceBadge($issue->source) ?></td>
            <td><?= $view->statusBadge($issue->status) ?></td>
            <td><?= $view->priorityBadge($issue->priority) ?></td>
            <td class="dit-nowrap"><?= $view->deadlineCell($issue->deadline, $threshold) ?></td>
            <td class="dit-small dit-muted dit-nowrap"><?= $view->dateTime($issue->updated_at) ?></td>
            <td class="dit-right">
                <a href="<?= $detailUrl ?>" class="dit-btn sm" title="<?= $view->t('ui.view') ?>"><i class="fas fa-pencil-alt"></i> <?= $view->t('ui.view') ?></a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>
<?php endif; ?>

<div class="dit-pager">
    <span class="dit-muted"><?= $view->t('ui.showing', [':from' => $from, ':to' => $to, ':total' => $total]) ?></span>
    <?= $view->pagination($page, $perPage, $total, $filters) ?>
</div>
</div>
