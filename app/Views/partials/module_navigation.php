<?php
$navigation = new \App\Services\WorkspaceNavigationService();
$workspaceModules = $workspaceModules ?? $navigation->modules();
$groupQuery = service('request')->getGet('group');
$workspaceContext = $workspaceContext ?? $navigation->context($workspaceModules, trim(service('uri')->getRoutePath(), '/'), is_string($groupQuery) ? $groupQuery : null);
$activeModule = $workspaceContext['module'];
?>
<section class="module-workspace" aria-label="Module workspace" data-active-module="<?= esc($workspaceContext['moduleId'],'attr') ?>">
    <div class="module-heading">
        <div class="module-heading-copy"><span class="module-eyebrow">PHARMACY WORKSPACE</span><h2><?= esc($activeModule['label'] ?? 'Home') ?></h2><p><?= esc($activeModule['description'] ?? 'Choose a module to begin your work.') ?></p></div>
        <a class="workspace-home-link" href="<?= site_url('workspace') ?>" data-module-link><i data-lucide="layout-grid" aria-hidden="true"></i> All modules</a>
    </div>
    <?php if ($activeModule): ?>
    <nav class="module-tabs" role="tablist" aria-label="<?= esc($activeModule['label'].' sections','attr') ?>">
        <?php foreach ($activeModule['groups'] as $key=>$group): ?>
        <button type="button" role="tab" data-workspace-group="<?= esc($key,'attr') ?>" aria-selected="<?= $key === $workspaceContext['groupId'] ? 'true' : 'false' ?>" aria-controls="workspace-group-<?= esc($key,'attr') ?>" id="workspace-tab-<?= esc($key,'attr') ?>" tabindex="<?= $key === $workspaceContext['groupId'] ? '0' : '-1' ?>"><?= esc($group['label']) ?></button>
        <?php endforeach; ?>
    </nav>
    <?php foreach ($activeModule['groups'] as $key=>$group): ?>
    <div id="workspace-group-<?= esc($key,'attr') ?>" role="tabpanel" aria-labelledby="workspace-tab-<?= esc($key,'attr') ?>" <?= $key !== $workspaceContext['groupId'] ? 'hidden' : '' ?>>
    <nav class="module-subtabs" role="tablist" aria-label="<?= esc($group['label'].' pages','attr') ?>">
        <?php foreach ($group['pages'] as $index=>$page): $selected = $page['route'] === $workspaceContext['activeRoute'] || (!$workspaceContext['activeRoute'] && $index===0); ?>
        <button type="button" role="tab" data-workspace-page="<?= site_url($page['route']) ?>" aria-selected="<?= $selected ? 'true' : 'false' ?>" tabindex="<?= $selected ? '0' : '-1' ?>"><?= esc($page['label']) ?></button>
        <?php endforeach; ?>
    </nav>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</section>
