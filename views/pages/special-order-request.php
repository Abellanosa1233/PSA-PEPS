<?php
/* Request Special Order (employee): page 1 of the Special Order as it will be issued
   (S.O. No. 2026-530 template), with the activity, dates and venue filled in on the document.
   HR assigns the number, signatory and date when it generates the SO. */
$me = (int) $user['employeeId'];
$self = null;
foreach ($state['employees'] as $e) if ($e['id'] === $me) $self = $e;
$defaults = special_order_defaults();
$draft = $_SESSION['so_draft'] ?? [];
$value = function (string $key, string $default = '') use ($draft) { return (string) ($draft[$key] ?? $default); };
$jobOrder = $self && in_array($self['type'], ['Job Order', 'Contract of Service Worker'], true);
$company = $state['settings']['company'] ?? 'Philippine Statistics Authority';
so_document_assets(true);
?>
<form method="post" data-so-editor class="so-editor">
    <?php hidden_inputs(['_action' => 'employee.request.create', '_token' => $_SESSION['web_csrf'], 'empId' => $me, 'type' => 'special_order']); ?>
    <div class="page-head so-editor-head">
        <div>
            <h1>Request Special Order</h1>
            <p>Fill in the blanks on the Special Order: the activity, the dates and the venue. HR assigns the number, date and signatory, and lists everyone attending.</p>
        </div>
        <div class="actions">
            <a class="btn" href="<?= h(page_url('special-orders', ['clear' => 1])) ?>">Cancel</a>
            <button class="btn primary" type="submit">Submit request</button>
        </div>
    </div>
    <div class="so-desk">
        <article class="so-sheet so-p1">
            <?php so_letterhead($company, $defaults['office']); ?>
            <p class="so-no">SPECIAL ORDER NO. <span class="muted-blank">(assigned by HR)</span></p>
            <div class="lines">
                <span>TO</span><span>:</span><b><?= h(mb_strtoupper($defaults['to'])) ?></b>
                <span>SUBJECT</span><span>:</span><span>Authority to Attend the <span data-mirror="purpose"><?= h($value('purpose')) ?></span></span>
                <span>DATE</span><span>:</span><span class="muted-blank">(date of issue)</span>
            </div>
            <div class="body">
                <p class="para">The official participant listed in the attached document is hereby authorized to attend the
                    <?= so_text(true, 'purpose', $value('purpose'), 'span', '', 'name of the activity') ?>
                    on <input class="blank" type="date" name="start" value="<?= h($value('start', today())) ?>" required aria-label="Start date">
                    to <input class="blank" type="date" name="end" value="<?= h($value('end')) ?>" aria-label="End date (leave empty for one day)">,
                    at <?= so_text(true, 'destination', $value('destination'), 'span', '', 'venue and address') ?>.</p>
                <p class="para">Payment/reimbursement of allowable traveling expenses based on EO No. 77 series of 2019 is chargeable to the general fund, subject to the usual accounting and auditing rules and regulations.</p>
                <?php if ($jobOrder): ?><p class="para">Job Order/Contract of Service Worker shall be entitled to payment of travelling expenses following Office Memorandum 2025-175 subject to the usual accounting and auditing rules and regulations.</p><?php endif; ?>
                <p class="para">THIS SPECIAL ORDER SHALL TAKE EFFECT ON DATE SPECIFIED, INCLUSIVE OF TRAVEL TIME, AND REMAIN IN FORCE UNTIL SOONER REVOKED OR MODIFIED.</p>
            </div>
            <p class="for-line"><?= h(mb_strtoupper($defaults['forLine'])) ?></p>
            <div class="signatory">
                <b><?= $defaults['signatoryName'] !== '' ? h(mb_strtoupper($defaults['signatoryName'])) : '<span class="muted-blank">(signatory)</span>' ?></b>
                <span>(<?= h($defaults['signatoryTitle']) ?>)</span>
                <?php if ($defaults['signatoryDesignation'] !== ''): ?><span><?= h($defaults['signatoryDesignation']) ?></span><?php endif; ?>
            </div>
            <?php so_footer($defaults['footer']); ?>
        </article>
    </div>
    <div class="card so-request-extra">
        <label class="field"><span>Notes for HR (optional): organizer, invitation or memo reference</span>
            <input class="input" name="details" maxlength="1000" value="<?= h($value('details')) ?>" placeholder="e.g. Invitation memo dated November 18, 2026"></label>
        <p class="muted">Leave the end date empty for a one-day activity.</p>
    </div>
</form>
