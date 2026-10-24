<?php
/**
 * TopHost top bar (gradient strip with pill tabs) + stylesheet, shared by every admin page.
 * Navigation only: the tabs are plain links to the existing list filters / add form.
 *
 * @var \WHMCS\Module\Addon\DomainIssues\Admin\View $view
 * @var string $active  list | transfers | other | add | ''
 */

use WHMCS\Module\Addon\DomainIssues\Config;

$version = '';
if (function_exists('domainissues_config')) {
    $version = (string) (domainissues_config()['version'] ?? '');
}
$tabs = [
    'list' => [$view->t('ui.all_issues'), $view->url()],
    'transfers' => [$view->t('widget.problem_transfers'), $view->url(['type' => 'cat:transfers'])],
];
if (Config::hasOtherCategoriesEnabled()) {
    $tabs['other'] = [$view->t('widget.other_issues'), $view->url(['type' => 'cat:other'])];
}
$tabs['add'] = ['+ ' . $view->t('ui.add_issue'), $view->url(['action' => 'add'])];
?>
<link rel="stylesheet" href="<?= $view::e($view::assetsUrl()) ?>/dit.css?v=<?= $view::e($version) ?>">
<div class="dit-topbar">
    <div class="dit-brand"><i>&#9873;</i> <?= $view->t('ui.title') ?><?php if ($version !== ''): ?> <span class="dit-ver">v<?= $view::e($version) ?></span><?php endif; ?></div>
    <nav class="dit-nav">
        <?php foreach ($tabs as $key => [$label, $href]): ?>
            <a href="<?= $href ?>" class="<?= $key === ($active ?? '') ? 'active' : '' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </nav>
</div>
