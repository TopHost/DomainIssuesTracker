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
?>
<style>
    .di-toolbar { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:12px; }
    .di-toolbar h2 { margin:0; }
    .di-filters { background:#f7f7f7; border:1px solid #e3e3e3; border-radius:4px; padding:10px; margin-bottom:12px; display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
    .di-filters .form-control { width:auto; display:inline-block; }
    .di-filters input[name="q"] { min-width:220px; }
    .di-table td, .di-table th { vertical-align:middle !important; white-space:nowrap; }
    .di-table td.di-wrap { white-space:normal; }
    .di-table tr.di-urgent td { background:#fff3f3 !important; }
    .di-table tr.di-urgent td:first-child { box-shadow: inset 4px 0 0 #d9534f; }
    .di-muted { color:#999; }
    .di-footer { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; }
    .di-footer .pagination { margin:0; }
</style>

<div class="di-toolbar">
    <h2><i class="fas fa-globe"></i> <?= $view->t('ui.title') ?></h2>
    <a class="btn btn-primary" href="<?= $view->url(['action' => 'add']) ?>"><i class="fas fa-plus"></i> <?= $view->t('ui.add_issue') ?></a>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $view::e($flash['type']) ?>"><?= $view::e($flash['text']) ?></div>
<?php endif; ?>

<form method="get" action="addonmodules.php" class="di-filters">
    <input type="hidden" name="module" value="domainissues">
    <select name="status" class="form-control input-sm"><?= $view->options($statusOptions, (string) $filters['status']) ?></select>
    <select name="type" class="form-control input-sm"><?= $view->options($typeOptions, (string) $filters['type']) ?></select>
    <select name="registrar" class="form-control input-sm"><?= $view->options($registrarOptions, (string) $filters['registrar']) ?></select>
    <select name="source" class="form-control input-sm"><?= $view->options($sourceOptions, (string) $filters['source']) ?></select>
    <select name="priority" class="form-control input-sm"><?= $view->options($priorityOptions, (string) $filters['priority']) ?></select>
    <input type="text" name="q" class="form-control input-sm" value="<?= $view::e($filters['q']) ?>" placeholder="<?= $view->t('ui.search_placeholder') ?>">
    <input type="hidden" name="filtered" value="1">
    <label class="checkbox-inline" style="margin-left:4px;">
        <input type="checkbox" name="urgent" value="1" <?= !empty($filters['urgent']) ? 'checked' : '' ?>> <?= $view->t('ui.urgent_only') ?>
    </label>
    <label class="checkbox-inline" title="<?= $view->t('ui.show_expired_help') ?>">
        <input type="checkbox" name="show_expired" value="1" <?= !empty($filters['show_expired']) ? 'checked' : '' ?>> <?= $view->t('ui.show_expired') ?>
    </label>
    <label class="checkbox-inline" title="<?= $view->t('ui.show_redemption_help') ?>">
        <input type="checkbox" name="show_redemption" value="1" <?= !empty($filters['show_redemption']) ? 'checked' : '' ?>> <?= $view->t('ui.show_redemption') ?>
    </label>
    <button type="submit" class="btn btn-default btn-sm"><i class="fas fa-filter"></i> <?= $view->t('ui.filter') ?></button>
    <a href="<?= $view->url(['filtered' => 1]) ?>" class="btn btn-link btn-sm"><?= $view->t('ui.reset') ?></a>
</form>

<p class="di-muted">
    <?= $view->t('ui.showing', [':from' => $from, ':to' => $to, ':total' => $total]) ?>
    &middot; <?= $view->t('ui.urgent_hint', [':days' => $threshold]) ?>
    <?php if ($filters['type'] !== Types::TYPE_REDEMPTION && (empty($filters['show_expired']) || empty($filters['show_redemption']))): ?>
        &middot; <?= $view->t('ui.lifecycle_hidden') ?>
    <?php endif; ?>
</p>

<div class="table-responsive">
<table class="table table-striped table-condensed table-hover di-table">
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
    <?php if ($issues === []): ?>
        <tr><td colspan="12" class="text-center di-muted"><?= $view->t('ui.no_results') ?></td></tr>
    <?php endif; ?>
    <?php foreach ($issues as $issue):
        $urgent = $view->isUrgent($issue, $threshold);
        $clientName = IssueRepository::formatClientName($issue->client_firstname, $issue->client_lastname, $issue->client_companyname);
        $detailUrl = $view->url(['action' => 'view', 'id' => (int) $issue->id]);
        ?>
        <tr class="<?= $urgent ? 'di-urgent' : '' ?>">
            <td><a href="<?= $detailUrl ?>">#<?= (int) $issue->id ?></a></td>
            <td>
                <a href="<?= $detailUrl ?>"><strong><?= $view::e($issue->domain_name) ?></strong></a>
                <?= $view->domainLink($issue->domain_id !== null ? (int) $issue->domain_id : null, $issue->client_id !== null ? (int) $issue->client_id : null) ?>
                <?php if (!empty($issue->whmcs_domain_status)): ?>
                    <br><small class="di-muted"><?= $view::e($issue->whmcs_domain_status) ?></small>
                <?php endif; ?>
            </td>
            <td><?= $view->clientLink($issue->client_id !== null ? (int) $issue->client_id : null, $clientName) ?></td>
            <td><?= $view->label('type', $issue->issue_type) ?></td>
            <td><?= $issue->issue_subtype ? $view->label('subtype', $issue->issue_subtype) : '<span class="di-muted">&mdash;</span>' ?></td>
            <td><?= $issue->registrar ? $view::e($issue->registrar) : '<span class="di-muted">&mdash;</span>' ?></td>
            <td><?= $view->sourceBadge($issue->source) ?></td>
            <td><?= $view->statusBadge($issue->status) ?></td>
            <td><?= $view->priorityBadge($issue->priority) ?></td>
            <td><?= $view->deadlineCell($issue->deadline, $threshold) ?></td>
            <td><?= $view->dateTime($issue->updated_at) ?></td>
            <td class="text-right">
                <a href="<?= $detailUrl ?>" class="btn btn-default btn-xs" title="<?= $view->t('ui.view') ?>"><i class="fas fa-pencil-alt"></i></a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<div class="di-footer">
    <span class="di-muted"><?= $view->t('ui.showing', [':from' => $from, ':to' => $to, ':total' => $total]) ?></span>
    <?= $view->pagination($page, $perPage, $total, $filters) ?>
</div>
