<?php
/* Generate SO/RTA (HR): the Special Order, the Request for Travel Authority and the list of
   participants, following the Misamis Oriental PSO template, typed on in place. Saving
   keeps it as a draft for the CSS's signature until the signed copy is uploaded. */
$id = (int) ($_GET['id'] ?? 0);
$order = null;
foreach ($state['employeeRequests'] as $r) {
    if ($r['id'] === $id && $r['type'] === 'special_order') $order = $r;
}
if ($order && $order['groupId'] !== null) {
    foreach ($state['employeeRequests'] as $r) if ($r['id'] === $order['docId']) $order = $r;
}
if (!$order) {
    echo '<div class="page-head"><div><h1>Special Order</h1></div></div><p class="card web-message">That Special Order request no longer exists. ';
    link_to('special-orders', 'Back to Special Orders');
    echo '</p>';
    return;
}
// A new SO also lists everyone else who requested the same activity on the same start date.
$suggested = [];
if (empty($order['generatedAt'])) {
    foreach ($state['employeeRequests'] as $r) {
        if ($r['type'] === 'special_order' && $r['groupId'] === null && $r['id'] !== $order['id'] && empty($r['generatedAt']) && $r['status'] !== 'rejected'
            && $r['start'] === $order['start'] && mb_strtolower(trim($r['purpose'])) === mb_strtolower(trim($order['purpose']))) {
            $suggested[] = $r;
        }
    }
}
$doc = special_order_document($state, $order, $suggested);
$active = array_values(array_filter($state['employees'], function ($e) { return $e['status'] === 'Active'; }));
usort($active, function ($a, $b) { return [$a['lastName'], $a['firstName']] <=> [$b['lastName'], $b['firstName']]; });
$signed = $doc['signed'];
so_document_assets(!$signed);
?>
<form method="post" enctype="multipart/form-data" data-so-editor class="so-editor">
    <?php hidden_inputs(['_action' => 'specialorder.generate', '_token' => $_SESSION['web_csrf'], 'id' => $doc['id'], 'toManual' => empty($doc['autoTo']) ? 1 : 0]); ?>
    <div class="page-head so-editor-head">
        <div>
            <h1><?= $doc['generated'] ? 'Special Order ' . h(special_order_plain_text((string) $doc['number'])) : 'Generate SO/RTA' ?></h1>
            <p><?= $signed
                ? 'This Special Order is signed. It can no longer be changed.'
                : 'The three-page PDF layout is the default template. Use the toolbar for formatting, colors, links, lists, alignment and spacing. Font size and family stay as in the template. Click the RTA boxes or adjust the participant list.' ?>
                <?= $suggested ? ' ' . count($suggested) . ' other employee' . (count($suggested) === 1 ? '' : 's') . ' requested the same activity and ' . (count($suggested) === 1 ? 'is' : 'are') . ' already on the list.' : '' ?></p>
        </div>
        <div class="actions">
            <a class="btn" href="<?= h(page_url('special-orders')) ?>">Back</a>
            <?php if ($doc['generated']): ?><a class="btn" href="<?= h(page_url('special-order-print', ['id' => $doc['id']])) ?>" target="_blank" rel="noopener">Print</a><?php endif; ?>
            <?php if (!$signed): ?><button class="btn" type="button" data-so-print>Print current view</button><?php endif; ?>
            <?php if (!$signed): ?><label class="so-logo-control">Header logo (all SOs) <input type="file" name="soHeaderLogo" accept="image/png,image/jpeg"></label><label class="so-logo-reset"><input type="checkbox" name="resetSoLogo" value="1"> Restore default</label><?php endif; ?>
            <?php if (!$signed): ?><button class="btn primary" type="submit"><?= $doc['generated'] ? 'Save changes' : 'Generate SO/RTA' ?></button><?php endif; ?>
        </div>
    </div>
    <?php if (!$signed) so_format_toolbar(); ?>
    <div class="so-desk"><?php so_pages($doc, !$signed, $active); ?></div>
</form>
