<?php
/**
 * Add / edit page with comments and history (§5.2 form, §5.3 detail).
 *
 * @var \WHMCS\Module\Addon\DomainIssues\Admin\View $view
 * @var object|null $issue                 null when adding
 * @var array<string, mixed> $input        current form values
 * @var array<string, string> $errors      field => message
 * @var object[] $comments
 * @var object[] $logs
 * @var string[] $registrars
 * @var string[] $enabledTypes
 * @var int $threshold
 * @var array{type:string,text:string}|null $flash
 */

use WHMCS\Module\Addon\DomainIssues\IssueRepository;
use WHMCS\Module\Addon\DomainIssues\Lang;
use WHMCS\Module\Addon\DomainIssues\Types;

$isNew = $issue === null;
$id = $isNew ? 0 : (int) $issue->id;
$err = static fn (string $f): string => isset($errors[$f]) ? ' has-error' : '';
$msg = static fn (string $f): string => isset($errors[$f]) ? '<span class="help-block">' . $view::e($errors[$f]) . '</span>' : '';

$typeOptions = [];
foreach ($enabledTypes as $t) {
    $typeOptions[$t] = Lang::label('type', $t);
}
$statusOptions = [];
foreach (Types::STATUSES as $s) {
    $statusOptions[$s] = Lang::label('status', $s);
}
$priorityOptions = [];
foreach (Types::PRIORITIES as $p) {
    $priorityOptions[$p] = Lang::label('priority', $p);
}

$clientName = null;
if (!$isNew && $issue->client_id !== null) {
    $clientName = IssueRepository::formatClientName($issue->client_firstname, $issue->client_lastname, $issue->client_companyname);
}

$activeTab = $isNew ? 'add' : (Types::isTransferType((string) $issue->issue_type) ? 'transfers' : 'other');
?>
<div class="dit" id="dit-app">
<?= $view->render('_topbar', ['active' => $activeTab]) ?>

<div class="dit-title">
    <?php if ($isNew): ?>
        <h2><?= $view->t('ui.add_issue') ?></h2>
    <?php else: ?>
        <h2><?= $view->t('detail.title', [':id' => $id, ':domain' => $issue->domain_name]) ?></h2>
        <?= $view->statusBadge($issue->status) ?> <?= $view->priorityBadge($issue->priority) ?> <?= $view->sourceBadge($issue->source) ?>
    <?php endif; ?>
</div>
<div class="dit-links">
    <a class="dit-btn sm" href="<?= $view->url() ?>"><i class="fas fa-arrow-left"></i> <?= $view->t('ui.back_to_list') ?></a>
    <?php if (!$isNew): ?>
        <a class="dit-btn sm" href="<?= $view->url(['action' => 'add']) ?>"><i class="fas fa-plus"></i> <?= $view->t('ui.add_issue') ?></a>
        <a class="dit-btn sm" href="#dit-comments"><i class="fas fa-comments"></i> <?= $view->t('detail.comments') ?> <span class="dit-badge neutral"><?= count($comments) ?></span></a>
        <a class="dit-btn sm" href="#dit-history"><i class="fas fa-history"></i> <?= $view->t('detail.history') ?> <span class="dit-badge neutral"><?= count($logs) ?></span></a>
    <?php endif; ?>
</div>

<?= $view->flashBar($flash) ?>

<?php if (!$isNew && $issue->source === Types::SOURCE_AUTOMATIC): ?>
    <div class="dit-infobar dit-auto-note"><i class="fas fa-robot"></i> <span><?= $view->t('detail.automatic_note') ?></span></div>
<?php endif; ?>

<div class="dit-grid-detail<?= $isNew ? ' dit-new' : '' ?>">
    <div>
        <div class="dit-card">
            <h3><?= $isNew ? $view->t('ui.add_issue') : $view->t('ui.edit_issue') ?></h3>

            <form method="post" action="<?= $view->url(['action' => 'save']) ?>" class="form-horizontal" id="di-form" autocomplete="off">
                <?= $view->csrf() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="domain_id" id="di-domain-id" value="<?= $view::e($input['domain_id']) ?>">

                <div class="form-group<?= $err('domain_name') ?>">
                    <label class="col-sm-3 control-label"><?= $view->t('col.domain') ?> *</label>
                    <div class="col-sm-9">
                        <div class="input-group">
                            <input type="text" name="domain_name" id="di-domain" class="form-control" required
                                   list="di-domain-list" value="<?= $view::e($input['domain_name']) ?>" placeholder="example.md">
                            <datalist id="di-domain-list"></datalist>
                            <span class="input-group-btn">
                                <button type="button" class="dit-btn" id="di-lookup"><i class="fas fa-search"></i> <?= $view->t('ui.lookup') ?></button>
                            </span>
                        </div>
                        <div class="dit-lookup" id="di-lookup-result">
                            <?php if (!$isNew && $issue->domain_id): ?>
                                <span class="text-success"><i class="fas fa-link"></i>
                                    <?= $view->t('ui.domain_found', [
                                        ':id' => (int) $issue->domain_id,
                                        ':client' => $clientName ?? '#' . (int) $issue->client_id,
                                        ':registrar' => (string) ($issue->registrar ?: Lang::t('ui.none')),
                                        ':status' => (string) ($issue->whmcs_domain_status ?: '?'),
                                    ]) ?>
                                    <?= $view->domainLink((int) $issue->domain_id, $issue->client_id !== null ? (int) $issue->client_id : null) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <small class="dit-hint"><?= $view->t('ui.domain_help') ?></small>
                        <?= $msg('domain_name') ?>
                    </div>
                </div>

                <div class="form-group<?= $err('client_id') ?>">
                    <label class="col-sm-3 control-label"><?= $view->t('col.client') ?></label>
                    <div class="col-sm-9">
                        <div class="row">
                            <div class="col-xs-4">
                                <input type="text" name="client_id" id="di-client-id" class="form-control" inputmode="numeric"
                                       value="<?= $view::e($input['client_id']) ?>" placeholder="ID (<?= $view->t('ui.optional') ?>)">
                            </div>
                            <div class="col-xs-8 dit-client-name" id="di-client-name">
                                <?= $view->clientLink(!$isNew && $issue->client_id !== null ? (int) $issue->client_id : (ctype_digit((string) $input['client_id']) ? (int) $input['client_id'] : null), $clientName) ?>
                            </div>
                        </div>
                        <small class="dit-hint"><?= $view->t('ui.client_help') ?></small>
                        <?= $msg('client_id') ?>
                    </div>
                </div>

                <div class="form-group<?= $err('issue_type') ?>">
                    <label class="col-sm-3 control-label"><?= $view->t('col.type') ?> *</label>
                    <div class="col-sm-4">
                        <select name="issue_type" class="form-control"><?= $view->options($typeOptions, (string) $input['issue_type']) ?></select>
                        <?= $msg('issue_type') ?>
                    </div>
                    <label class="col-sm-2 control-label"><?= $view->t('col.subtype') ?></label>
                    <div class="col-sm-3<?= $err('issue_subtype') ?>">
                        <input type="text" name="issue_subtype" class="form-control" list="di-subtype-list" maxlength="64"
                               value="<?= $view::e($input['issue_subtype']) ?>" placeholder="<?= $view->t('ui.optional') ?>">
                        <datalist id="di-subtype-list">
                            <?php foreach (Types::SUBTYPES as $st): ?>
                                <option value="<?= $view::e($st) ?>"><?= $view->label('subtype', $st) ?></option>
                            <?php endforeach; ?>
                        </datalist>
                        <?= $msg('issue_subtype') ?>
                    </div>
                </div>

                <div class="form-group<?= $err('registrar') ?>">
                    <label class="col-sm-3 control-label"><?= $view->t('col.registrar') ?></label>
                    <div class="col-sm-4">
                        <input type="text" name="registrar" id="di-registrar" class="form-control" list="di-registrar-list" maxlength="64"
                               value="<?= $view::e($input['registrar']) ?>" placeholder="<?= $view->t('ui.optional') ?>">
                        <datalist id="di-registrar-list">
                            <?php foreach ($registrars as $r): ?>
                                <option value="<?= $view::e($r) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <?= $msg('registrar') ?>
                    </div>
                    <label class="col-sm-2 control-label"><?= $view->t('col.priority') ?></label>
                    <div class="col-sm-3<?= $err('priority') ?>">
                        <select name="priority" class="form-control"><?= $view->options($priorityOptions, (string) $input['priority']) ?></select>
                        <?= $msg('priority') ?>
                    </div>
                </div>

                <div class="form-group<?= $err('status') ?>">
                    <label class="col-sm-3 control-label"><?= $view->t('col.status') ?> *</label>
                    <div class="col-sm-4">
                        <select name="status" class="form-control"><?= $view->options($statusOptions, (string) $input['status']) ?></select>
                        <?= $msg('status') ?>
                    </div>
                    <label class="col-sm-2 control-label"><?= $view->t('col.deadline') ?></label>
                    <div class="col-sm-3<?= $err('deadline') ?>">
                        <input type="date" name="deadline" class="form-control" value="<?= $view::e($input['deadline']) ?>" placeholder="YYYY-MM-DD">
                        <?= $msg('deadline') ?>
                    </div>
                </div>
                <div class="form-group">
                    <div class="col-sm-9 col-sm-offset-3">
                        <small class="dit-hint"><?= $view->t('ui.deadline_help') ?>
                            <?php if (!$isNew && $issue->deadline): ?> &middot; <?= $view->deadlineCell($issue->deadline, $threshold) ?><?php endif; ?>
                        </small>
                    </div>
                </div>

                <div class="form-group<?= $err('details') ?>">
                    <label class="col-sm-3 control-label"><?= $view->t('col.details') ?></label>
                    <div class="col-sm-9">
                        <textarea name="details" class="form-control" rows="5"><?= $view::e($input['details']) ?></textarea>
                        <small class="dit-hint"><?= $view->t('ui.details_help') ?></small>
                        <?= $msg('details') ?>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0">
                    <div class="col-sm-9 col-sm-offset-3">
                        <button type="submit" class="dit-btn primary">
                            <i class="fas fa-save"></i> <?= $isNew ? $view->t('ui.create') : $view->t('ui.save') ?>
                        </button>
                        <a href="<?= $isNew ? $view->url() : $view->url(['action' => 'view', 'id' => $id]) ?>" class="dit-btn"><?= $view->t('ui.cancel') ?></a>
                    </div>
                </div>
            </form>

            <?php if (!$isNew): ?>
                <form method="post" action="<?= $view->url(['action' => 'delete']) ?>" class="dit-card-foot" style="justify-content:flex-end"
                      onsubmit="return confirm(<?= $view::e(json_encode(Lang::t('ui.delete_confirm'))) ?>);">
                    <?= $view->csrf() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <button type="submit" class="dit-btn danger sm"><i class="fas fa-trash"></i> <?= $view->t('ui.delete') ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$isNew): ?>
    <div>
        <div class="dit-card" id="dit-comments">
            <div class="dit-card-head"><h3><i class="fas fa-comments"></i> <?= $view->t('detail.comments') ?></h3><span class="dit-badge neutral"><?= count($comments) ?></span></div>

            <?php if ($comments === []): ?>
                <p class="dit-muted"><?= $view->t('detail.no_comments') ?></p>
            <?php else: ?>
                <div class="dit-comments">
                <?php foreach ($comments as $c): ?>
                    <div class="dit-comment<?= $c->admin_id === null ? ' system' : '' ?>">
                        <div class="dit-comment-meta"><b><?= $view->adminName($c) ?></b><span><?= $view->dateTime($c->created_at) ?></span></div>
                        <div class="dit-comment-body"><?= $view::e($c->comment) ?></div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= $view->url(['action' => 'comment']) ?>">
                <?= $view->csrf() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <textarea name="comment" class="form-control" rows="3" required placeholder="<?= $view->t('detail.comment_placeholder') ?>"></textarea>
                <div style="margin-top:8px"><button type="submit" class="dit-btn blue sm"><i class="fas fa-comment"></i> <?= $view->t('detail.add_comment') ?></button></div>
            </form>
        </div>

        <div class="dit-card" id="dit-history">
            <h3><i class="fas fa-history"></i> <?= $view->t('detail.history') ?></h3>
            <?php if ($logs === []): ?>
                <p class="dit-muted"><?= $view->t('detail.no_history') ?></p>
            <?php else: ?>
                <table class="dit-history">
                    <?php foreach ($logs as $l): ?>
                        <tr>
                            <td class="dit-nowrap dit-muted"><?= $view->dateTime($l->created_at) ?><br><?= $view->adminName($l) ?></td>
                            <td>
                                <span class="dit-field-name"><?= $view->label('field', $l->field) ?></span><br>
                                <?php if ($l->field === 'created'): ?>
                                    <?= $view->statusBadge($l->new_value) ?>
                                <?php elseif ($l->field === 'status'): ?>
                                    <?= $view->statusBadge($l->old_value) ?> <span class="dit-arrow">&rarr;</span> <?= $view->statusBadge($l->new_value) ?>
                                <?php elseif ($l->field === 'priority'): ?>
                                    <?= $view->priorityBadge($l->old_value) ?> <span class="dit-arrow">&rarr;</span> <?= $view->priorityBadge($l->new_value) ?>
                                <?php else: ?>
                                    <code><?= $view::e($l->old_value ?? '—') ?></code> <span class="dit-arrow">&rarr;</span> <code><?= $view::e($l->new_value ?? '—') ?></code>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>
        <div class="dit-card">
            <h3><i class="fas fa-info-circle"></i> <?= $view->t('detail.audit') ?></h3>
            <table class="dit-kv">
                <tr><th><?= $view->t('col.source') ?></th><td><?= $view->sourceBadge($issue->source) ?></td></tr>
                <tr><th><?= $view->t('col.created') ?></th><td><?= $view->dateTime($issue->created_at) ?></td></tr>
                <tr><th><?= $view->t('col.created_by') ?></th><td><?= $issue->created_by ? '#' . (int) $issue->created_by : $view->t('ui.system') ?></td></tr>
                <tr><th><?= $view->t('col.updated') ?></th><td><?= $view->dateTime($issue->updated_at) ?></td></tr>
                <tr><th><?= $view->t('col.resolved') ?></th><td><?= $view->dateTime($issue->resolved_at) ?></td></tr>
                <?php if (!empty($issue->whmcs_domain_status)): ?>
                    <tr><th><?= $view->t('col.whmcs_status') ?></th>
                        <td><?= $view::e($issue->whmcs_domain_status) ?>
                            <?php if (!empty($issue->whmcs_domain_expiry) && !str_starts_with((string) $issue->whmcs_domain_expiry, '0000')): ?>
                                <span class="dit-muted dit-small">(exp. <?= $view::e($issue->whmcs_domain_expiry) ?>)</span>
                            <?php endif; ?>
                        </td></tr>
                <?php endif; ?>
            </table>
        </div>

    </div>
    <?php endif; ?>
</div>
</div>

<script>
(function () {
    var searchUrl = <?= json_encode($view->rawUrl(['action' => 'ajax_domains'])) ?>;
    var lookupUrl = <?= json_encode($view->rawUrl(['action' => 'ajax_domain_lookup'])) ?>;
    var txtFound = <?= json_encode(Lang::t('ui.domain_found')) ?>;
    var txtNotFound = <?= json_encode(Lang::t('ui.domain_not_found')) ?>;
    var txtNone = <?= json_encode(Lang::t('ui.none')) ?>;

    var input = document.getElementById('di-domain');
    var list = document.getElementById('di-domain-list');
    var domainId = document.getElementById('di-domain-id');
    var clientId = document.getElementById('di-client-id');
    var clientName = document.getElementById('di-client-name');
    var registrar = document.getElementById('di-registrar');
    var result = document.getElementById('di-lookup-result');
    var lookupBtn = document.getElementById('di-lookup');
    if (!input) { return; }

    var cache = {};
    var timer = null;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    function fill(d) {
        domainId.value = d.id;
        if (!clientId.value || clientId.value !== String(d.client_id)) { clientId.value = d.client_id; }
        clientName.innerHTML = '<a href="clientssummary.php?userid=' + parseInt(d.client_id, 10) + '">' + esc(d.client_name || ('#' + d.client_id)) + '</a>';
        if (!registrar.value) { registrar.value = d.registrar || ''; }
        result.innerHTML = '<span class="text-success"><i class="fas fa-link"></i> ' + esc(
            txtFound.replace(':id', d.id).replace(':client', d.client_name || ('#' + d.client_id))
                .replace(':registrar', d.registrar || txtNone).replace(':status', d.status || '?')
        ) + ' <a href="clientsdomains.php?id=' + parseInt(d.id, 10) + '&userid=' + parseInt(d.client_id, 10) + '" target="_blank"><i class="fas fa-external-link-alt"></i></a></span>';
    }

    function notFound() {
        domainId.value = '';
        result.innerHTML = '<span class="text-warning"><i class="fas fa-unlink"></i> ' + esc(txtNotFound) + '</span>';
    }

    function search(q) {
        fetch(searchUrl + '&q=' + encodeURIComponent(q), {credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (rows) {
                list.innerHTML = '';
                cache = {};
                rows.forEach(function (d) {
                    cache[d.domain.toLowerCase()] = d;
                    var o = document.createElement('option');
                    o.value = d.domain;
                    o.label = (d.client_name || '') + (d.registrar ? ' · ' + d.registrar : '');
                    list.appendChild(o);
                });
            })
            .catch(function () {});
    }

    function lookup() {
        var name = input.value.trim().toLowerCase();
        if (!name) { return; }
        if (cache[name]) { fill(cache[name]); return; }
        fetch(lookupUrl + '&domain=' + encodeURIComponent(name), {credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (d) { d && d.found ? fill(d) : notFound(); })
            .catch(function () {});
    }

    input.addEventListener('input', function () {
        clearTimeout(timer);
        var q = input.value.trim();
        if (q.length < 2) { return; }
        if (cache[q.toLowerCase()]) { fill(cache[q.toLowerCase()]); return; }
        timer = setTimeout(function () { search(q); }, 250);
    });
    input.addEventListener('change', lookup);
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); lookup(); }
    });
    lookupBtn.addEventListener('click', lookup);
})();
</script>
