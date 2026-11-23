<?php
$notifications = [];
if ($notice !== '') $notifications[] = ['success', 'Success', 'check', $notice];
if ($error !== '') $notifications[] = [$errorLevel, $errorLevel === 'error' ? 'Error' : 'Action failed', 'x', $error];
?>
<div class="toasts web-notifications" id="action-notifications">
    <?php foreach ($notifications as [$level, $label, $glyph, $message]): ?>
        <div class="toast web-toast <?= h($level) ?>" role="<?= $level === 'success' ? 'status' : 'alert' ?>">
            <?= icon($glyph) ?>
            <div><strong><?= h($label) ?></strong><div><?= h($message) ?></div></div>
            <button type="button" class="web-toast-dismiss" aria-label="Dismiss notification" hidden><?= icon('x', 14) ?></button>
        </div>
    <?php endforeach; ?>
</div>
<script src="<?= h(versioned_asset('assets/js/notifications.js')) ?>" defer></script>
