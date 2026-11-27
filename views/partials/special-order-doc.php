<?php
declare(strict_types=1);

/* The Special Order document (Misamis Oriental PSO template): the Special Order, the
   Request for Travel Authority and the list of official participants. One renderer draws it for
   printing and, with $edit, as HR's editor: the same pages, typed on in place, so what HR sees is
   exactly what prints. Styles: assets/css/special-order.css; editing: assets/js/so-editor.js. */

function so_document_assets(bool $edit = false): void
{
    echo '<link rel="stylesheet" href="' . h(versioned_asset('assets/css/special-order.css')) . '">';
    echo '<script src="' . h(versioned_asset('assets/js/so-letterhead.js')) . '" defer></script>';
    if ($edit) {
        echo '<script src="' . h(versioned_asset('assets/js/so-pdf.js')) . '" defer></script>';
        echo '<script src="' . h(versioned_asset('assets/js/so-export.js')) . '" defer></script>';
        echo '<script src="' . h(versioned_asset('assets/js/so-editor.js')) . '" defer></script>';
    }
}

function so_format_toolbar(): void
{
    echo '<div class="so-format-toolbar" role="toolbar" aria-label="Text formatting">'
        . '<button type="button" data-so-action="undo" aria-label="Undo" title="Undo">↶</button>'
        . '<button type="button" data-so-action="redo" aria-label="Redo" title="Redo">↷</button>'
        . '<span class="so-format-divider"></span>'
        . '<button type="button" data-so-action="bold" aria-label="Bold" title="Bold"><b>B</b></button>'
        . '<button type="button" data-so-action="italic" aria-label="Italic" title="Italic"><i>I</i></button>'
        . '<button type="button" data-so-action="underline" aria-label="Underline" title="Underline"><u>U</u></button>'
        . '<label class="so-color-control" title="Text color">A <input type="color" value="#000000" data-so-color="foreColor" aria-label="Text color"></label>'
        . '<label class="so-color-control" title="Highlight color">▰ <input type="color" value="#fff200" data-so-color="hiliteColor" aria-label="Highlight color"></label>'
        . '<button type="button" data-so-action="link" aria-label="Insert link" title="Insert link">↗</button>'
        . '<button type="button" data-so-action="image" aria-label="Insert image" title="Insert image">▧</button>'
        . '<span class="so-format-divider"></span>'
        . '<select data-so-align aria-label="Text alignment" title="Text alignment"><option value="justifyLeft">Align left</option><option value="justifyCenter">Center</option><option value="justifyRight">Align right</option><option value="justifyFull">Justify</option></select>'
        . '<select data-so-line-height aria-label="Line spacing" title="Line spacing"><option value="1">1.0</option><option value="1.15" selected>1.15</option><option value="1.5">1.5</option><option value="2">2.0</option></select>'
        . '<button type="button" data-so-action="insertLineBreak" aria-label="Insert line break" title="Insert line break">↵</button>'
        . '<button type="button" data-so-action="insertParagraph" aria-label="Start a new paragraph" title="Start a new paragraph">¶</button>'
        . '<button type="button" data-so-action="checklist" aria-label="Insert checklist item" title="Insert checklist item">☐</button>'
        . '<button type="button" data-so-action="insertUnorderedList" aria-label="Bulleted list" title="Bulleted list">•≡</button>'
        . '<button type="button" data-so-action="insertOrderedList" aria-label="Numbered list" title="Numbered list">1≡</button>'
        . '<button type="button" data-so-action="outdent" aria-label="Decrease indent" title="Decrease indent">⇤</button>'
        . '<button type="button" data-so-action="indent" aria-label="Increase indent" title="Increase indent">⇥</button>'
        . '<button type="button" data-so-action="removeFormat" aria-label="Clear formatting" title="Clear formatting">Tx</button>'
        . '<span class="so-format-divider"></span>'
        . '<button type="button" data-so-export-pdf aria-label="Download as PDF" title="Download the current document as a PDF">PDF</button>'
        . '<button type="button" data-so-export-docx aria-label="Download Word document" title="Download Word document">DOCX</button>'
        . '<span class="so-format-divider"></span>'
        . '<button type="button" data-so-print aria-label="Print preview" title="Print preview">▤</button>'
        . '<input type="file" accept="image/png,image/jpeg,image/webp" data-so-image hidden>'
        . '</div>';
}

function so_letterhead(string $company, string $office, bool $edit = false): void
{
    echo '<header class="letterhead"><img class="seal" src="assets/psa-logo.svg" alt="">'
        . '<div class="lh-text"><span class="lh-republic">Republic of the Philippines</span>'
        . '<span class="lh-name">' . h($company) . '</span>';
    if ($edit) echo so_text(true, 'soOffice', $office, 'span', 'lh-office', 'REGION / OFFICE LINE');
    elseif ($office !== '') echo '<span class="lh-office">' . special_order_rich_html($office) . '</span>';
    echo '</div><img class="bp" src="' . h(special_order_header_logo_source()) . '" alt="Administration logo"></header>';
}

function so_footer(string $text, bool $edit = false): void
{
    $fixedAddress = special_order_fixed_footer();
    echo '<footer class="foot"><img src="assets/coe-footer-certification.png" alt="PSA certification marks">'
        . '<div class="foot-text">' . h($fixedAddress) . '</div></footer>';
}

/* A piece of text on the document: plain when printing, typed on in place when editing. */
function so_text(bool $edit, string $name, string $value, string $tag = 'span', string $class = '', string $placeholder = ''): string
{
    if (!$edit) return '<' . $tag . ($class !== '' ? ' class="' . h($class) . '"' : '') . '>' . special_order_rich_html($value) . '</' . $tag . '>';
    return '<' . $tag . ' class="so-edit' . ($class !== '' ? ' ' . h($class) : '') . '" contenteditable="true" spellcheck="true" data-name="' . h($name) . '"'
        . ' data-placeholder="' . h($placeholder) . '" role="textbox" aria-label="' . h($placeholder ?: $name) . '">' . special_order_rich_html($value) . '</' . $tag . '>';
}

function so_body_html(string $body): string
{
    if (preg_match('/<\/?(?:b|strong|i|em|u|br|p|div|span|ul|ol|li|a|img|font|blockquote)\b/i', $body)) return special_order_sanitize_rich_html($body);
    $paragraphs = preg_split('/\R{2,}/', trim($body));
    return implode('', array_map(function ($paragraph) {
        return '<p class="para">' . special_order_rich_html($paragraph) . '</p>';
    }, $paragraphs));
}

/* An RTA box: shows X when ticked; a real checkbox (or radio) when editing. */
function so_box(bool $edit, string $name, bool $on, string $value = '1', string $type = 'checkbox'): string
{
    if (!$edit) return '<span class="box">' . ($on ? 'X' : '') . '</span>';
    return '<label class="box so-box"><input type="' . $type . '" name="' . h($name) . '" value="' . h($value) . '"' . ($on ? ' checked' : '') . '><span>X</span></label>';
}

function so_recipient_block(bool $edit, string $value, bool $auto = false): string
{
    $rich = preg_match('/<\/?(?:b|strong|i|em|u|br|p|div|span|ul|ol|li|a|img|font|blockquote)\b/i', $value) === 1;
    if ($rich) {
        $class = 'so-recipient-list' . ($edit ? ' so-edit' : '');
        return '<div class="' . $class . '"' . ($edit ? ' contenteditable="true" spellcheck="true" data-name="to" data-auto-recipient="' . ($auto ? '1' : '0') . '" data-placeholder="Recipient name(s) or all concerned officials and personnel" role="textbox" aria-label="To"' : '') . '>'
            . special_order_sanitize_rich_html($value) . '</div>';
    }
    $entries = preg_split('/\n\s*\n/', trim($value));
    $parts = [];
    foreach ($entries as $entry) {
        $lines = preg_split('/\R/', trim($entry));
        if (count($lines) > 1 && trim($lines[0]) !== '') {
            $parts[] = '<b>' . h(trim(array_shift($lines))) . '</b><br><span>' . h(implode(' ', array_map('trim', $lines))) . '</span>';
        } elseif (trim($entry) !== '') {
            $parts[] = h(trim($entry));
        }
    }
    $class = 'so-recipient-list' . ($edit ? ' so-edit' : '');
    if (!$edit) return '<div class="' . $class . '">' . implode('<br><br>', $parts) . '</div>';
    return '<div class="' . $class . '" contenteditable="true" spellcheck="true" data-name="to"'
        . ' data-auto-recipient="' . ($auto ? '1' : '0') . '" data-placeholder="Recipient name(s) or all concerned officials and personnel" role="textbox" aria-label="To">'
        . implode('<br><br>', $parts) . '</div>';
}

/* The three pages. $edit adds the editing controls; $employees (edit only) are the people who can be added. */
function so_pages(array $doc, bool $edit = false, array $employees = [], bool $editParticipants = true): void
{
    $m = $doc['meta'];
    $many = count($doc['participants']) > 1;
    $where = $doc['destination'] !== '' ? ', at ' . $doc['destination'] : '';
    $dates = special_order_dates_text($doc['start'], $doc['end']);
    $participantEdit = $edit && $editParticipants;
    $date = $m['issueDate'];
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
        return $edit ? '<span data-mirror="' . h($name) . '">' . special_order_rich_html($value) . '</span>' : special_order_rich_html($value);
    };
    ?>
    <article class="so-sheet so-p1">
        <?php so_letterhead($doc['company'], $doc['office'], $edit); ?>
        <?php if ($edit || $m['refNo'] !== ''): ?><p class="ref">Reference No. <?= $t('refNo', $m['refNo'], 'span', '', '27PSO43-TO-0001') ?></p><?php endif; ?>
        <p class="so-no">SPECIAL ORDER NO. <?= $t('soNumber', $doc['number'], 'span', '', '2027-0001') ?></p>
        <div class="lines">
            <span>TO</span><span>:</span><?= so_recipient_block($edit, $m['to'], !empty($doc['autoTo'])) ?>
            <span>SUBJECT</span><span>:</span><?= $t('subject', $m['subject'], 'span', '', 'Subject') ?>
            <span>DATE</span><span>:</span><?= $edit ? $t('issueDate', $date, 'span', '', 'July 30, 2026') : '<span>' . special_order_rich_html($date) . '</span>' ?>
        </div>
        <?php if ($edit): ?>
            <div class="body"><?= $t('soBody', $doc['body'], 'div', 'so-body-edit', 'Order text') ?></div>
        <?php else: ?>
            <div class="body so-body-print"><?= so_body_html($doc['body']) ?></div>
        <?php endif; ?>
        <?php if ($m['forLine'] !== ''): ?><p class="for-line so-upper"><?= $t('forLine', $m['forLine'], 'span', '', 'FOR THE REGIONAL DIRECTOR') ?></p><?php endif; ?>
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
            <span>DATE</span><span>:</span><?= $mirror('issueDate', $date) ?>
        </div>
        <div class="body"><?= $t('memoBody', $m['memoBody'], $edit ? 'div' : 'p', 'para', 'Travel authority request') ?></div>
        <div class="checks vehicle">
            <div class="check"><?= so_box($edit, 'vehicle', $m['vehicle'] === 'official', 'official', 'radio') ?><span>With official vehicle (car/motorcycle)</span></div>
            <p class="vehicle-note">Plate Number: <?= $t('plate', $edit ? $m['plate'] : ($m['vehicle'] === 'official' ? ($m['plate'] ?: 'see attached list') : ''), 'span', '', 'see attached list') ?><br>Driver/s: <?= $t('driver', $edit ? $m['driver'] : ($m['vehicle'] === 'official' ? ($m['driver'] ?: 'see attached list') : ''), 'span', '', 'see attached list') ?></p>
            <div class="check"><?= so_box($edit, 'vehicle', $m['vehicle'] !== 'official', 'none', 'radio') ?><span>Without official vehicle (car/motorcycle)</span></div>
        </div>
        <p class="para second">It is also requested that the concerned officials/employees be entitled to payment/reimbursement of the following chargeable to the general fund:</p>
        <?php if (count(array_filter($doc['participants'], function ($p) { return $p['type'] === 'Regular'; }))): ?>
            <div class="checks allowances"><div class="check"><?= so_box($edit, 'allowances', (bool) $m['allowances']) ?><span>Traveling allowance as per EO No. 298 series of 2004 / EO No. 77 series of 2019</span></div></div>
            <div class="stack">
                <div class="check"><?= so_box($edit, 'meals', (bool) $m['meals']) ?><span>Meals</span></div>
                <div class="check"><?= so_box($edit, 'incidental', (bool) $m['incidental']) ?><span>Incidental Expenses</span></div>
                <div class="check"><?= so_box($edit, 'lodging', (bool) $m['lodging']) ?><span>Lodging (Hotel Accommodation)</span></div>
            </div>
        <?php endif; ?>
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
        <table class="participants" <?= $participantEdit ? 'data-participants' : '' ?>>
            <colgroup><col class="c1"><col class="c2"><col class="c3"><col class="c4"><col class="c5"></colgroup>
            <thead><tr><th>NAME</th><th>OFFICE/PSO</th><th>POSITION</th><th>ACCOMMODATION</th><th>TRAVEL TIME</th></tr></thead>
            <tbody>
            <?php
            $list = $doc['participants'];
            $spans = [];
            for ($i = 0, $n = count($list); $i < $n; $i = $j) {
                for ($j = $i + 1; $j < $n && !$participantEdit && $list[$j]['travelTime'] === $list[$i]['travelTime']; $j++);
                $spans[$i] = $j - $i;
            }
            foreach ($list as $i => $p): ?>
                <tr data-emp="<?= (int) $p['empId'] ?>">
                    <td><?= h($p['name']) ?><?php if ($participantEdit && !$p['requester']): ?><button type="button" class="so-remove" title="Remove from this SO" aria-label="Remove <?= h($p['name']) ?>">×</button><?php endif; ?></td>
                    <td><?= h($p['office']) ?></td>
                    <td><?= h($p['title']) ?></td>
                    <?php if ($participantEdit): ?>
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
        <?php if ($participantEdit): ?>
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
