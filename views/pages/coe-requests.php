<?php
$selectedId = (int) ($_GET['id'] ?? 0);
$requests = coe_requests_for_staff($state['user'], $selectedId > 0 ? $selectedId : null);
$archive = ($_GET['archive'] ?? '') === '1' || ($selectedId > 0 && !empty($requests[0]['archived']));
$search = $archive && is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$fragment = !empty($coeSearchFragment);
$requests = array_map(function ($request) {
    $details = json_decode($request['details'], true) ?: [];
    return $request + ['savedDetails' => $details, 'displayName' => $details['name'] ?? $request['employeeName']];
}, $requests);
$suggestions = [];
if ($selectedId === 0) {
    $requests = array_values(array_filter($requests, function ($request) use ($archive) {
        return (bool) $request['archived'] === $archive;
    }));
    if ($archive) {
        usort($requests, function ($a, $b) {
            return [$b['sentAt'] ?? $b['createdAt'], (int) $b['id']] <=> [$a['sentAt'] ?? $a['createdAt'], (int) $a['id']];
        });
        if (!$fragment) {
            foreach ($requests as $request) {
                $suggestions[] = $request['employeeName'];
                $suggestions[] = $request['purpose'];
            }
        }
        if ($search !== '') {
            $requests = array_values(array_filter($requests, function ($request) use ($search) {
                foreach ([$request['employeeName'], $request['displayName'], $request['purpose']] as $value) {
                    if (mb_stripos($value, $search) !== false) return true;
                }
                return false;
            }));
        }
    }
}
$pageRows = paginate($requests, 'p', 10);
$editError = ($formError['shared']['action'] ?? '') === 'coe.update' ? $formError['shared'] : [];
$sendError = ($formError['shared']['action'] ?? '') === 'coe.send' ? $formError['shared'] : [];
$rows = array_map(function ($request) {
    return $request + [
        'period' => self_date_range($request['start'], $request['end'] ?: $request['start']),
        'submitted' => (new DateTimeImmutable($request['createdAt']))->format('M j, Y'),
    ];
}, $pageRows['rows']);
?>
<?php if (!$fragment): ?>
<div class="employee-dashboard" id="coe-requests-dashboard">
    <div class="page-head">
        <div><h1>COE requests</h1><p>View employee requests, correct details, and print certificates.</p></div>
        <?php if ($selectedId > 0): ?>
            <div class="actions"><?php link_to('coe-requests', $archive ? 'Back to archive' : 'Open requests', $archive ? ['archive' => 1] : [], 'btn'); ?></div>
        <?php endif; ?>
    </div>
    <?php if ($selectedId === 0): ?>
        <nav class="toolbar" aria-label="COE request sections">
            <a class="btn<?= !$archive ? ' primary' : '' ?>" href="<?= h(page_url('coe-requests')) ?>"<?= !$archive ? ' aria-current="page"' : '' ?>>Open requests</a>
            <a class="btn<?= $archive ? ' primary' : '' ?>" href="<?= h(page_url('coe-requests', ['archive' => 1])) ?>"<?= $archive ? ' aria-current="page"' : '' ?>>Archive</a>
        </nav>
        <?php if ($archive): ?>
            <form class="toolbar coe-archive-search" method="get" action="<?= h(page_url('coe-requests')) ?>" data-coe-archive-search>
                <input type="hidden" name="archive" value="1">
                <input class="input grow" name="q" type="search" value="<?= h($search) ?>" placeholder="Search requester or project" aria-label="Search requester or project"
                    autocomplete="off" list="coe-archive-suggestions">
                <datalist id="coe-archive-suggestions" data-options="<?= html_json(array_values(array_unique($suggestions))) ?>"></datalist>
                <button type="submit" class="btn">Search</button>
                <span class="muted" role="status" data-coe-search-status></span>
            </form>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
    <div id="coe-archive-results">
    <?php
    card($archive ? 'Archived COE requests' : 'Certificate of Employment requests');
    $headers = ['Employee', 'Project', 'Period covered', $archive ? 'Sent' : 'Submitted', 'Status'];
    web_table($headers, array_map(function ($request, $index) use ($archive) {
        return [
            function () use ($request, $index) {
                echo '<button class="btn sm" type="button" data-coe-details="coe-details-' . $index
                    . '" aria-haspopup="dialog">' . h($request['displayName']) . '</button>';
            },
            $request['purpose'],
            $request['period'],
            $archive ? ($request['sentAt'] ? (new DateTimeImmutable($request['sentAt']))->format('M j, Y') : '—') : $request['submitted'],
            function () use ($request) { status_badge($request['archived'] ? 'sent' : 'submitted'); },
        ];
    }, $rows, array_keys($rows)));
    if ($selectedId === 0) pagination($pageRows, 'coe-requests', $archive ? ['archive' => 1] + ($search !== '' ? ['q' => $search] : []) : [], 'requests');
    echo '</section>';
    ?>
    <?php foreach ($rows as $index => $request): ?>
        <?php
        $failedEdit = (int) ($editError['values']['requestId'] ?? 0) === (int) $request['id'];
        $failedSend = (int) ($sendError['values']['requestId'] ?? 0) === (int) $request['id'];
        $sendLabel = !empty($request['coeDocumentId']) ? 'Replace soft copy' : 'Send soft copy';
        ?>
        <dialog class="modal sign-out-dialog wide" id="coe-details-<?= $index ?>" aria-labelledby="coe-details-title-<?= $index ?>"<?= $failedEdit || $failedSend || $selectedId === (int) $request['id'] ? ' data-reopen-coe' : '' ?>>
            <div class="modal-head"><h2 id="coe-details-title-<?= $index ?>">Certificate of Employment request</h2></div>
            <div class="modal-body coe-request-details">
                <p><strong><?= h($request['displayName']) ?></strong> <?php status_badge($request['archived'] ? 'sent' : 'submitted'); ?></p>
                <?php employee_request_details($request, false); ?>
                <span><strong>Period covered</strong> <?= h($request['period']) ?></span>
                <span><strong>Submitted</strong> <?= h($request['submitted']) ?></span>
                <?php if (!empty($request['coeDocumentId'])): ?><span class="muted">Soft copy sent to the requester.</span><?php endif; ?>
                <div class="coe-upload-panel" id="coe-send-<?= $index ?>"<?= $failedSend ? '' : ' hidden' ?>>
                    <?php if ($failedSend): ?><p class="field-error" role="alert"><?= h($sendError['message']) ?></p><?php endif; ?>
                    <?php post_form('coe.send', ['requestId' => $request['id'], '_shared_form' => 'coe.send'], false, 'multipart/form-data'); ?>
                    <label class="coe-pdf-picker">
                        <input type="file" name="certificatePdf" accept="application/pdf,.pdf"
                            aria-label="Finished certificate PDF" aria-describedby="coe-upload-hint-<?= $index ?>" required>
                        <span class="coe-pdf-picker-icon" aria-hidden="true"><?= icon('file', 22) ?></span>
                        <span class="coe-pdf-picker-copy">
                            <strong>Finished certificate PDF</strong>
                            <span class="muted" data-coe-filename aria-live="polite">No file selected</span>
                        </span>
                        <span class="btn sm coe-pdf-picker-action" aria-hidden="true">Choose PDF</span>
                    </label>
                    <small class="hint" id="coe-upload-hint-<?= $index ?>">PDF up to <?= COE_PDF_MAX_MB ?> MB. Sending again replaces the previous copy.</small>
                    <?php end_form('Send PDF'); ?>
                </div>
                    <details class="form-disclosure" data-coe-edit<?= $failedEdit ? ' open' : '' ?><?= $failedSend ? ' hidden' : '' ?>>
                        <summary class="btn">Edit request</summary>
                        <?php
                        if ($failedEdit) echo '<p class="field-error" role="alert">' . h($editError['message']) . '</p>';
                        preg_match('/^(MR\.|MS\.|MRS\.) /', $request['displayName'], $title);
                        $details = $request['savedDetails'];
                        $values = [
                            'honorific' => $title[1] ?? '',
                            'position' => $details['position'] ?? '',
                            'project' => $request['purpose'],
                            'area' => $request['destination'],
                            'trainingStart' => $details['trainingStart'] ?? $details['training'] ?? '',
                            'trainingEnd' => $details['trainingEnd'] ?? $details['training'] ?? '',
                            'start' => $request['start'],
                            'end' => $request['end'] ?? $request['start'],
                        ];
                        post_form('coe.update', ['requestId' => $request['id'], '_shared_form' => 'coe.update']);
                        echo '<div class="form-grid coe-request full">';
                        coe_request_fields(($failedEdit ? $editError['values'] : []) + $values);
                        echo '</div>';
                        end_form('Save corrections');
                        ?>
                    </details>
            </div>
            <form class="modal-foot" method="dialog">
                <button class="btn" autofocus>Close</button>
                <a class="btn primary" href="<?= h(page_url('coe-certificate', ['id' => $request['id']])) ?>">Preview certificate</a>
                <button class="btn" type="button" data-coe-send="coe-send-<?= $index ?>" data-send-label="<?= h($sendLabel) ?>" aria-controls="coe-send-<?= $index ?>" aria-expanded="<?= $failedSend ? 'true' : 'false' ?>"><?= h($failedSend ? 'Back to request' : $sendLabel) ?></button>
            </form>
        </dialog>
    <?php endforeach; ?>
    </div>
<?php if (!$fragment): ?>
</div>
<script>
    document.querySelector('[data-reopen-coe]')?.showModal();
    const coeDashboard = document.getElementById('coe-requests-dashboard');
    coeDashboard.addEventListener('change', (event) => {
        const input = event.target.closest('input[name="certificatePdf"]');
        if (input) input.closest('.coe-pdf-picker').querySelector('[data-coe-filename]').textContent = input.files[0]?.name ?? 'No file selected';
    });
    coeDashboard.addEventListener('click', (event) => {
        const send = event.target.closest('[data-coe-send]');
        if (send) {
            const upload = document.getElementById(send.dataset.coeSend);
            const edit = send.closest('dialog').querySelector('[data-coe-edit]');
            const opening = upload.hidden;
            upload.hidden = !opening;
            edit.hidden = opening;
            send.setAttribute('aria-expanded', String(opening));
            send.textContent = opening ? 'Back to request' : send.dataset.sendLabel;
            if (opening) {
                edit.open = false;
                upload.querySelector('input[type="file"]').focus();
            }
            return;
        }
        const trigger = event.target.closest('tbody tr')?.querySelector('[data-coe-details]');
        if (!trigger) return;
        const dialog = document.getElementById(trigger.dataset.coeDetails);
        dialog.showModal();
        dialog.addEventListener('close', () => trigger.focus(), { once: true });
    });
</script>
<script src="<?= h(versioned_asset('assets/js/coe-archive-search.js')) ?>" defer></script>
<?php endif; ?>
