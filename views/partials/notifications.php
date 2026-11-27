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
        </div>
    <?php endforeach; ?>
</div>
<script>
    (() => {
        const notifications = document.getElementById('action-notifications');
        notifications.hidden = true;
        window.addEventListener('pageshow', () => {
            notifications.hidden = false;
            window.setTimeout(() => notifications.remove(), 2000);
        }, { once: true });
    })();
</script>
