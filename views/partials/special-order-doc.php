<?php
declare(strict_types=1);

/* The Special Order document (S.O. No. 2026-530 template, PSA RSSO X): the Special Order, the
   Request for Travel Authority and the list of official participants. One renderer draws it for
   printing and, with $edit, as HR's editor: the same pages, typed on in place, so what HR sees is
   exactly what prints. Styles: assets/css/special-order.css; editing: assets/js/so-editor.js. */

function so_document_assets(bool $edit = false): void
{
    echo '<link rel="stylesheet" href="' . h(versioned_asset('assets/css/special-order.css')) . '">';
    echo '<script src="' . h(versioned_asset('assets/js/so-letterhead.js')) . '" defer></script>';
    if ($edit) echo '<script src="' . h(versioned_asset('assets/js/so-editor.js')) . '" defer></script>';
}

function so_letterhead(string $company, string $office, bool $edit = false): void
{
    echo '<header class="letterhead"><img class="seal" src="assets/psa-logo.svg" alt="">'
        . '<div class="lh-text"><span class="lh-republic">Republic of the Philippines</span>'
        . '<span class="lh-name">' . h($company) . '</span>';
    if ($edit) echo so_text(true, 'soOffice', $office, 'span', 'lh-office', 'REGION / OFFICE LINE');
    elseif ($office !== '') echo '<span class="lh-office">' . h($office) . '</span>';
    echo '</div><img class="bp" src="assets/so/bagong-pilipinas.png" alt="Bagong Pilipinas"></header>';
}

function so_footer(string $text, bool $edit = false): void
{
    echo '<footer class="foot"><img src="assets/so/footer-marks.png" alt="">'
        . so_text($edit, 'footer', $text, 'div', 'foot-text', 'Office address and contact numbers') . '</footer>';
}

/* A piece of text on the document: plain when printing, typed on in place when editing. */
function so_text(bool $edit, string $name, string $value, string $tag = 'span', string $class = '', string $placeholder = ''): string
{
    if (!$edit) return '<' . $tag . ($class !== '' ? ' class="' . h($class) . '"' : '') . '>' . h($value) . '</' . $tag . '>';
    return '<' . $tag . ' class="so-edit' . ($class !== '' ? ' ' . h($class) : '') . '" contenteditable="plaintext-only" spellcheck="true" data-name="' . h($name) . '"'
        . ' data-placeholder="' . h($placeholder) . '" role="textbox" aria-label="' . h($placeholder ?: $name) . '">' . h($value) . '</' . $tag . '>';
}

/* An RTA box: shows X when ticked; a real checkbox (or radio) when editing. */
function so_box(bool $edit, string $name, bool $on, string $value = '1', string $type = 'checkbox'): string
{
    if (!$edit) return '<span class="box">' . ($on ? 'X' : '') . '</span>';
    return '<label class="box so-box"><input type="' . $type . '" name="' . h($name) . '" value="' . h($value) . '"' . ($on ? ' checked' : '') . '><span>X</span></label>';
}

/* The three pages. $edit adds the editing controls; $employees (edit only) are the people who can be added. */
function so_pages(array $doc, bool $edit = false, array $employees = []): void
{
    $m = $doc['meta'];
    $many = count($doc['participants']) > 1;
    $where = $doc['destination'] !== '' ? ', at ' . $doc['destination'] : '';
    $dates = special_order_dates_text($doc['start'], $doc['end']);
    $date = $doc['issued']->format('j F Y');
    $routing = [];
    foreach (preg_split('/\R/', (string) $m['routing']) as $line) {
        $parts = array_map('trim', explode('|', $line, 2));
        if ($parts[0] !== '') $routing[] = [$parts[0], $parts[1] ?? ''];
    }
    if ($edit) while (count($routing) < 3) $routing[] = ['', ''];
    $t = function (string $name, string $value, string $tag = 'span', string $class = '', string $placeholder = '') use ($edit) {
        return so_text($edit, $name, $value, $tag, $class, $placeholder);
    };
    // The signatory, mirrored on the RTA's FOR block while HR types it on page 1.
    $mirror = function (string $name, string $value) use ($edit) {
        return $edit ? '<span data-mirror="' . h($name) . '">' . h($value) . '</span>' : h($value);
    };
    ?>
    <article class="so-sheet so-p1">
        <?php so_letterhead($doc['company'], $doc['office'], $edit); ?>
        <?php if ($edit || $m['refNo'] !== ''): ?><p class="ref">Reference No. <?= $t('refNo', $m['refNo'], 'span', '', 'RSSO10-26CRASD11-427') ?></p><?php endif; ?>
        <p class="so-no">SPECIAL ORDER NO. <?= $t('soNumber', $doc['number'], 'span', '', '2026-000') ?></p>
        <div class="lines">
            <span>TO</span><span>:</span><b class="so-upper"><?= $t('to', $m['to'], 'span', '', 'ALL CONCERNED') ?></b>
            <span>SUBJECT</span><span>:</span><?= $t('subject', $m['subject'], 'span', '', 'Subject') ?>
            <span>DATE</span><span>:</span><span><?= h($date) ?></span>
        </div>
        <?php if ($edit): ?>
            <div class="body"><?= $t('soBody', $doc['body'], 'div', 'so-body-edit', 'Order text') ?></div>
        <?php else: ?>
            <div class="body"><?php foreach (preg_split('/\R{2,}/', trim($doc['body'])) as $paragraph): ?><p class="para"><?= nl2br(h($paragraph)) ?></p><?php endforeach; ?></div>
        <?php endif; ?>
        <?php if ($edit || $m['forLine'] !== ''): ?><p class="for-line so-upper"><?= $t('forLine', $m['forLine'], 'span', '', 'FOR THE REGIONAL DIRECTOR') ?></p><?php endif; ?>
        <div class="signatory">
            <b class="so-upper"><?= $t('signatoryName', $doc['signatoryName'], 'span', '', 'NAME OF THE SIGNATORY') ?></b>
            <span>(<?= $t('signatoryTitle', $doc['signatoryTitle'], 'span', '', 'Position') ?>)</span>
            <?php if ($edit || $m['signatoryDesignation'] !== ''): ?><?= $t('signatoryDesignation', $m['signatoryDesignation'], 'span', '', 'Designation') ?><?php endif; ?>
        </div>
        <?php if ($edit || $m['initials'] !== ''): ?><p class="initials"><?= $t('initials', $m['initials'], 'span', '', 'Initials') ?></p><?php endif; ?>
        <?php so_footer($m['footer'], $edit); ?>
    </article>

    <article class="so-sheet memo">
        <?php so_letterhead($doc['company'], $doc['office']); ?>
        <p class="memo-title">MEMORANDUM</p>
        <div class="lines">
            <span>FOR</span><span>:</span>
            <span><b class="so-upper"><?= $mirror('signatoryName', $doc['signatoryName']) ?></b><br>(<?= $mirror('signatoryTitle', $doc['signatoryTitle']) ?>)<br><?= $mirror('signatoryDesignation', $m['signatoryDesignation']) ?></span>
            <span>SUBJECT</span><span>:</span><span class="u">Request for Travel Authority</span>
            <span>DATE</span><span>:</span><span><?= h($date) ?></span>
        </div>
        <div class="body"><p class="para">Respectfully requesting authority for the official participant<?= $many ? 's' : '' ?> listed in the attached document<?= $many ? 's' : '' ?> to attend the <?= h($doc['purpose']) ?> on <?= h($dates . $where) ?>.</p></div>
        <div class="checks vehicle">
            <div class="check"><?= so_box($edit, 'vehicle', $m['vehicle'] === 'official', 'official', 'radio') ?><span>With an official vehicle (car/motorcycle)</span></div>
            <p class="vehicle-note">Plate Number: <?= $t('plate', $edit ? $m['plate'] : ($m['vehicle'] === 'official' ? ($m['plate'] ?: 'see attached list') : ''), 'span', '', 'see attached list') ?><br>Drivers: <?= $t('driver', $edit ? $m['driver'] : ($m['vehicle'] === 'official' ? ($m['driver'] ?: 'see attached list') : ''), 'span', '', 'see attached list') ?></p>
            <div class="check"><?= so_box($edit, 'vehicle', $m['vehicle'] !== 'official', 'none', 'radio') ?><span>Without an official vehicle (car/motorcycle)</span></div>
        </div>
        <p class="para second">It is also requested that the concerned officials/employees be entitled to payment/reimbursement of the following chargeable to the general fund:</p>
        <div class="checks allowances"><div class="check"><?= so_box($edit, 'allowances', (bool) $m['allowances']) ?><span>Traveling allowances as per&nbsp; EO No. 77 series of 2019</span></div></div>
        <div class="stack">
            <div class="check"><?= so_box($edit, 'meals', (bool) $m['meals']) ?><span>Meals</span></div>
            <div class="check"><?= so_box($edit, 'incidental', (bool) $m['incidental']) ?><span>Incidental Expenses</span></div>
            <div class="check"><?= so_box($edit, 'lodging', (bool) $m['lodging']) ?><span>Lodging (Hotel Accommodation)</span></div>
        </div>
        <div class="checks expenses"><div class="check"><?= so_box($edit, 'expenses', (bool) $m['expenses']) ?><span>Traveling Expenses<br>Other Expenses</span></div></div>
        <div class="stack second">
            <div class="check"><?= so_box($edit, 'terminal', (bool) $m['terminal']) ?><span>Terminal Fees</span></div>
            <div class="check"><?= so_box($edit, 'fuel', (bool) $m['fuel']) ?><span>Fuel Expenses</span></div>
            <div class="check"><?= $edit ? '<span class="box"></span>' : so_box(false, 'others', $m['others'] !== '') ?><span>Others, specify<?= $edit || $m['others'] !== '' ? ': ' : '' ?><?= $t('others', $m['others'], 'span', '', 'specify') ?></span></div>
        </div>
        <p class="para consider">For your consideration.</p>
        <div class="prepared">
            <b class="so-upper"><?= $t('preparedName', $edit ? $m['preparedName'] : ($m['preparedName'] ?: '____________________'), 'span', '', 'NAME OF THE REQUESTING OFFICER') ?></b>
            <?php if ($edit || $m['preparedTitle'] !== ''): ?><?= $t('preparedTitle', $m['preparedTitle'], 'span', '', 'Position, division') ?><?php endif; ?>
        </div>
        <?php if ($routing): ?>
            <table class="routing" <?= $edit ? 'data-routing' : '' ?>>
                <tr><th>DESIGNATION</th><th>INITIAL</th><th>DATE</th></tr>
                <?php foreach ($routing as [$designation, $initial]): ?>
                    <tr><td><?= $t('routingDesignation[]', $designation, 'span', '', 'ADAS III') ?></td><td><?= $t('routingInitial[]', $initial, 'span', '', 'KJC') ?></td><td><?= h($doc['issued']->format('m/d/Y')) ?></td></tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
        <?php so_footer($m['footer']); ?>
    </article>

    <article class="so-sheet participants-sheet">
        <?php so_letterhead($doc['company'], $doc['office']); ?>
        <p class="list-title">“LIST OF OFFICIAL PARTICIPANTS FOR <?= h(mb_strtoupper($doc['purpose'])) ?>”</p>
        <p class="day"><?= h(mb_strtoupper($dates)) ?>:</p>
        <table class="participants" <?= $edit ? 'data-participants' : '' ?>>
            <colgroup><col class="c1"><col class="c2"><col class="c3"><col class="c4"><col class="c5"></colgroup>
            <thead><tr><th>NAME</th><th>OFFICE/PSO</th><th>POSITION</th><th>ACCOMMODATION</th><th>TRAVEL TIME</th></tr></thead>
            <tbody>
            <?php
            $list = $doc['participants'];
            $spans = [];
            for ($i = 0, $n = count($list); $i < $n; $i = $j) {
                for ($j = $i + 1; $j < $n && !$edit && $list[$j]['travelTime'] === $list[$i]['travelTime']; $j++);
                $spans[$i] = $j - $i;
            }
            foreach ($list as $i => $p): ?>
                <tr data-emp="<?= (int) $p['empId'] ?>">
                    <td><?= h($p['name']) ?><?php if ($edit && !$p['requester']): ?><button type="button" class="so-remove" title="Remove from this SO" aria-label="Remove <?= h($p['name']) ?>">×</button><?php endif; ?></td>
                    <td><?= h($p['office']) ?></td>
                    <td><?= h($p['title']) ?></td>
                    <?php if ($edit): ?>
                        <td class="c"><select class="so-select" data-acc><?php foreach (['LIVE-OUT', 'LIVE-IN'] as $v): ?><option<?= $v === $p['accommodation'] ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></td>
                        <td class="mid"><select class="so-select" data-tt><?php foreach (['INCLUSIVE', 'EXCLUSIVE'] as $v): ?><option<?= $v === $p['travelTime'] ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></td>
                    <?php else: ?>
                        <td class="c"><?= h($p['accommodation']) ?></td>
                        <?php if (isset($spans[$i])): ?><td class="mid" rowspan="<?= $spans[$i] ?>"><?= h($p['travelTime']) ?></td><?php endif; ?>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($edit): ?>
            <div class="so-add-participant">
                <label>Add a participant
                    <select data-add-participant>
                        <option value="">Choose an employee…</option>
                        <?php foreach ($employees as $e): ?>
                            <option value="<?= (int) $e['id'] ?>" data-name="<?= h($e['lastName'] . ', ' . $e['firstName']) ?>" data-office="<?= h($e['location'] ?: $e['department']) ?>" data-title="<?= h($e['title']) ?>"><?= h($e['lastName'] . ', ' . $e['firstName'] . ' — ' . $e['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        <?php endif; ?>
        <?php so_footer($m['footer']); ?>
    </article>
    <?php
}
