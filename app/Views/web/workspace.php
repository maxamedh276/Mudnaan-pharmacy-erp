<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<div class="workspace-hub" data-workspace-hub>
<?php if ($workspaceContext['moduleId'] === 'home'): ?>
    <div class="hub-section-heading"><div><h1>Your pharmacy workspace</h1><p>Choose a module. Everything you need is organised inside.</p></div><span class="workspace-status"><span></span> Ready to work</span></div>
    <div class="hub-module-grid">
    <?php foreach ($workspaceModules as $id=>$module): if ($id === 'home') continue; ?>
        <a href="<?= site_url('workspace/'.$id) ?>" class="hub-module-card" data-module-link>
            <span class="hub-module-icon"><i data-lucide="<?= esc($module['icon'],'attr') ?>" aria-hidden="true"></i></span>
            <span class="hub-module-title"><?= esc($module['label']) ?></span><span class="hub-module-description"><?= esc($module['description']) ?></span>
            <span class="hub-module-footer"><?= count($module['groups']) ?> sections <i data-lucide="arrow-up-right" aria-hidden="true"></i></span>
        </a>
    <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="workspace-panel-status" role="status" hidden></div>
    <div data-workspace-panels></div>
<?php endif; ?>
</div>
<?= $this->endSection() ?>
