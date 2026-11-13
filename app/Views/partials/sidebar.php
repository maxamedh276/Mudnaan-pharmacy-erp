<?php
$navigationBranding = (new \App\Services\ReceiptBrandingService())->get();
$sidebarAppearance = (new \App\Models\SettingModel())->allAssoc();
$navigation = new \App\Services\WorkspaceNavigationService();
$modules = $navigation->modules();
$context = $navigation->context($modules, trim(service('uri')->getRoutePath(), '/'));
$currentUser = session()->get('user') ?? [];
?>
<a class="workspace-brand" href="<?= site_url('workspace') ?>" data-module-link>
    <img data-workspace-logo src="<?= esc(\App\Services\AppearanceSettingsService::logoUrl($sidebarAppearance),'attr') ?>" alt="" onerror="this.onerror=null;this.src='<?= base_url('public/assets/images/mudnaan-logo.jpeg') ?>'">
    <span><strong><?= esc($navigationBranding['store_name']) ?></strong><small>Pharmacy Management</small></span>
</a>
<div class="workspace-sidebar-tools"><span>WORKSPACE MODULES</span><button type="button" onclick="openSearch()" aria-label="Search workspaces"><i data-lucide="search" aria-hidden="true"></i><kbd>Ctrl K</kbd></button></div>
<nav class="workspace-module-list" aria-label="Workspace modules">
    <a class="sidebar-module-card" href="<?= site_url('workspace') ?>" data-module-link data-module="home" <?= $context['moduleId']==='home' ? 'aria-current="true"' : '' ?>><span class="sidebar-module-icon"><i data-lucide="layout-grid" aria-hidden="true"></i></span><span class="sidebar-module-copy"><strong>Home</strong><small>All your pharmacy modules</small></span><i data-lucide="arrow-up-right" class="sidebar-module-arrow" aria-hidden="true"></i></a>
    <?php foreach ($modules as $id=>$module): if ($id==='home') continue; ?>
    <a class="sidebar-module-card" href="<?= site_url('workspace/'.$id) ?>" data-module-link data-module="<?= esc($id,'attr') ?>" <?= $context['moduleId']===$id ? 'aria-current="true"' : '' ?>><span class="sidebar-module-icon"><i data-lucide="<?= esc($module['icon'],'attr') ?>" aria-hidden="true"></i></span><span class="sidebar-module-copy"><strong><?= esc($module['label']) ?></strong><small><?= count($module['groups']) ?> sections · <?= array_sum(array_map(static fn ($group)=>count($group['pages']), $module['groups'])) ?> pages</small></span><i data-lucide="arrow-up-right" class="sidebar-module-arrow" aria-hidden="true"></i></a>
    <?php endforeach; ?>
</nav>
<div class="workspace-profile"><span class="workspace-avatar"><?= esc(strtoupper(substr($currentUser['name'] ?? 'U',0,1))) ?></span><span><strong><?= esc($currentUser['name'] ?? 'User') ?></strong><small><?= esc($currentUser['role_name'] ?? 'Staff') ?></small></span><a href="<?= site_url('logout') ?>" aria-label="Sign out"><i data-lucide="log-out" aria-hidden="true"></i></a></div>
