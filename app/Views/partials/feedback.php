<link rel="stylesheet" href="<?= base_url('assets/css/feedback.css') . '?v=' . filemtime(FCPATH . 'assets/css/feedback.css') ?>">
<div id="toast-container" popover="manual" aria-label="Notifications"></div>
<?php if (!empty($feedbackMessages)): ?>
<script type="application/json" id="feedbackMessages"><?= json_encode($feedbackMessages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endif; ?>
