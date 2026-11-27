<?php
declare(strict_types=1);

/* Printable SO/RTA (S.O. No. 2026-530 template): the Special Order, the Request for Travel
   Authority and the list of official participants, drawn by so_pages() so it matches the editor.
   HR can open any SO; an employee only one they are a participant of (checked in index.php). */
$id = (int) ($_GET['id'] ?? 0);
$order = null;
foreach ($state['employeeRequests'] as $r) {
    if ($r['id'] === $id && $r['type'] === 'special_order') $order = $r;
}
if ($order && $order['groupId'] !== null) {
    // A participant's row: print the SO it belongs to.
    foreach ($state['employeeRequests'] as $r) {
        if ($r['id'] === $order['docId']) $order = $r;
    }
}
$isAdmin = is_admin($user);
$doc = $order && $order['generatedAt'] ? special_order_document($state, $order) : null;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $doc ? h('S.O. No. ' . $doc['number']) : 'Special Order' ?> · HRMS</title>
<?php so_document_assets(); ?>
<style>
  body { margin: 0; background: #e9edf2; }
  .toolbar { position: sticky; top: 0; z-index: 2; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 12px 16px; background: #fff; border-bottom: 1px solid #cfd8e3; font: 13px/1.4 "Segoe UI", Arial, sans-serif; color: #1b2a3d; }
  .toolbar .btn { height: 32px; padding: 0 14px; border-radius: 6px; border: 1px solid #b9c5d4; background: #fff; font: 600 12px/30px inherit; color: #1b2a3d; text-decoration: none; cursor: pointer; }
  .toolbar .btn.primary { background: #1f4e8c; border-color: #1f4e8c; color: #fff; }
  .toolbar .hint { color: #405065; }
  .sheet-wrap { overflow-x: auto; padding: 16px 0; }
  .empty { max-width: 520px; margin: 40px auto; background: #fff; padding: 24px; border-radius: 8px; text-align: center; font-family: "Segoe UI", Arial, sans-serif; }
  @media print { body { background: #fff; } .toolbar { display: none; } .sheet-wrap { padding: 0; overflow: visible; } }
</style>
</head>
<body>
<div class="toolbar">
    <a class="btn" href="<?= h(page_url('special-orders')) ?>">Back to Special Orders</a>
    <?php if ($doc): ?>
        <?php if ($isAdmin && !$doc['signed']): ?><a class="btn" href="<?= h(page_url('special-order-edit', ['id' => $doc['id']])) ?>">Edit</a><?php endif; ?>
        <button class="btn primary" type="button" onclick="window.print()">Print SO/RTA</button>
        <span class="hint">3 pages: Special Order, Request for Travel Authority, list of participants. Print on A4 with “Margins: None”.<?= $isAdmin ? ' After signing, scan it and use <b>Upload signed SO</b>.' : '' ?></span>
    <?php endif; ?>
</div>
<?php if (!$doc): ?>
    <div class="empty"><strong>This Special Order hasn’t been prepared yet.</strong><p><?= $isAdmin ? 'Open Special Orders and choose <b>Generate SO/RTA</b> first.' : 'HR prepares it after your request. You’ll see it in Special Orders when it’s ready.' ?></p></div>
<?php else: ?>
    <div class="sheet-wrap"><?php so_pages($doc); ?></div>
<?php endif; ?>
</body>
</html>
