<?php $copyrightYear = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Nairobi')))->format('Y'); ?>
<style>
    .app-copyright { flex-shrink: 0; margin-top: 16px; padding: 10px 12px; text-align: center; color: #64748b; font-family: Arial, sans-serif; font-size: 12px; font-weight: 400; line-height: 18px; overflow-wrap: anywhere; }
    .pos-shell > .app-copyright { margin-top: 0; padding: 6px 12px; border-top: 1px solid #e2e8f0; background: #fff; }
    @media print { .app-copyright { display: none !important; } }
</style>
<footer class="app-copyright" data-app-copyright>&copy; <?= $copyrightYear ?> Mudnaan Technology. All rights reserved.</footer>
