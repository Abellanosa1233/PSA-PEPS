<?php
if (!$certificate) http_response_code(404);
$details = $certificate ? json_decode($certificate['details'], true) : [];
$details = is_array($details) ? $details : [];
$complete = $certificate && !empty($details['name']) && !empty($details['position'])
    && $certificate['destination'] !== '' && $certificate['end'] !== null;
$trainingStart = $details['trainingStart'] ?? $details['training'] ?? null;
$trainingEnd = $details['trainingEnd'] ?? $trainingStart;
$onContract = in_array($certificate['employmentType'] ?? '', ['Job Order', 'Contract of Service Worker', 'Contract'], true);
$issueDate = new DateTimeImmutable();
$chiefSpecialists = $chiefSpecialists ?? [];
$chiefName = count($chiefSpecialists) === 1 ? mb_strtoupper($chiefSpecialists[0], 'UTF-8') : '';
$mandays = function (string $start, string $end): string {
    $days = (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days + 1;
    return '(' . $days . ($days === 1 ? ' MANDAY)' : ' MANDAYS)');
};
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Certificate of Employment · HRMS</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #eef1f5;
            color: #111;
            font: 12pt/1.6 Arial, sans-serif;
        }

        .controls {
            position: sticky;
            top: 0;
            z-index: 2;
            max-width: 1050px;
            max-height: 50vh;
            overflow-y: auto;
            margin: 16px auto;
            padding: 16px 20px;
            background: #fff;
            border: 1px solid #d7e0ec;
            border-radius: 12px;
            box-shadow: 0 2px 8px #1720330d;
            font: 14px/1.4 Arial, sans-serif;
        }

        .editor-top, .editor-tools, .editor-group {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
        }

        .editor-top { justify-content: space-between; }
        .editor-top strong { font-size: 17px; }
        .editor-tools + .editor-tools { margin-top: 10px; }
        .editor-tools { padding-top: 10px; border-top: 1px solid #e2e9f2; }
        .editor-group label { display: inline-flex; align-items: center; gap: 6px; }
        .controls :is(button, select, input[type="number"]), .preview-bar button {
            min-height: 34px;
            padding: 5px 9px;
            border: 1px solid #cbd8e8;
            border-radius: 6px;
            background: #fff;
            color: #172033;
            font: inherit;
        }
        .controls button, .preview-bar button { cursor: pointer; }
        .controls button:hover, .controls button[aria-pressed="true"] { background: #eaf1ff; }
        .controls #print-certificate { background: #0038a8; color: white; border-color: #0038a8; font-weight: 600; }
        .controls input[type="number"] { width: 72px; }
        .controls [hidden] { display: none; }
        .controls button:disabled { opacity: .5; cursor: default; }
        .editor-back {
            display: inline-flex;
            align-items: center;
            min-height: 34px;
            padding: 5px 9px;
            border: 1px solid #cbd8e8;
            border-radius: 6px;
            background: #fff;
            color: #0038a8;
            font-weight: 600;
            text-decoration: none;
        }
        .editor-back:hover, .editor-back:focus-visible { background: #eaf1ff; }
        .controls .editor-help { margin-bottom: 12px; color: #4b5b70; }
        .certificate [hidden] { display: none; }
        #certificate-preview {
            width: min(940px, calc(100vw - 24px));
            max-height: 94vh;
            padding: 0;
            border: 1px solid #cbd8e8;
            border-radius: 12px;
            background: #eef1f5;
            color: #172033;
            overflow: auto;
        }
        #certificate-preview::backdrop { background: #17203399; }
        .preview-bar {
            position: sticky;
            top: 0;
            z-index: 2;
            padding: 12px 16px;
            background: #fff;
            border-bottom: 1px solid #cbd8e8;
            font: 14px/1.4 Arial, sans-serif;
        }
        .preview-bar .editor-top { margin: 0; }
        .preview-bar p { margin: 6px 0 0; color: #4b5b70; }
        .preview-bar p[data-warning] { color: #92400e; font-weight: 600; }
        .preview-content { overflow-x: auto; padding: 16px; }
        .editable {
            position: relative;
            left: var(--text-x, 0px);
            top: var(--text-y, 0px);
        }
        .editable img {
            display: inline-block;
            width: auto;
            height: auto;
            max-width: 100%;
            max-height: .85in;
            object-fit: contain;
            vertical-align: middle;
        }
        .letterhead img, .letter-footer img {
            position: relative;
            left: var(--image-x, 0px);
            top: var(--image-y, 0px);
        }
        .certificate-image-layer {
            position: absolute;
            inset: .25in .95in .55in;
            z-index: 1;
            pointer-events: none;
        }
        .certificate-image-layer img {
            position: absolute;
            width: 16%;
            max-height: 18%;
            object-fit: contain;
            pointer-events: auto;
            touch-action: none;
            cursor: grab;
        }
        .certificate-image-layer img:active { cursor: grabbing; }

        .controls p {
            margin: 8px 0 0;
            font-size: 10pt;
        }

        .certificate {
            position: relative;
            isolation: isolate;
            display: flex;
            flex-direction: column;
            width: min(8.5in, 100%);
            min-height: 14in;
            margin: 0 auto 24px;
            padding: .25in .95in .55in;
            background: white;
        }

        .watermark {
            position: absolute;
            z-index: -1;
            top: 28%;
            left: 50%;
            width: 77.6%;
            transform: translateX(-50%);
            opacity: .16;
            pointer-events: none;
        }

        .letterhead {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #444;
            font-size: 10pt;
            line-height: 1.35;
        }

        .letterhead > img {
            width: calc(1in * var(--logo-scale, 1));
            height: calc(1in * var(--logo-scale, 1));
            flex-shrink: 0;
            object-fit: contain;
        }

        .letterhead-copy {
            flex: 1;
            min-width: 0;
        }

        .letterhead-agency {
            font-size: 15pt;
        }

        .letterhead-office {
            font-size: 13pt;
        }

        .editable {
            min-height: 1em;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        h1 {
            margin: 12pt 0 30pt;
            font-size: 32pt;
            line-height: 1.1;
            text-align: center;
            text-decoration: underline;
            text-underline-offset: .08em;
        }

        .letter {
            min-height: 6in;
        }

        .letter p {
            margin: 0 0 11pt;
        }

        .letter .paragraph {
            text-align: justify;
            text-indent: 2em;
            font-size: 20px;
        }

        .signature {
            align-self: flex-end;
            margin: 26pt 0 24pt;
            width: 66%;
            text-align: center;
            font-size: 11pt;
            line-height: 1.25;
        }

        .signature-name {
            font-size: 20px;
        }

        .signature-title {
            font-size: 18px;
        }

        .letter-footer {
            display: flex;
            align-items: center;
            gap: 9pt;
            margin-top: auto;
            padding: 18pt 0 0 .75in;
            font-size: 9pt;
            line-height: 1.3;
        }

        .letter-footer > img {
            width: calc(1.03in * var(--logo-scale, 1));
            max-width: 45%;
            height: auto;
            object-fit: contain;
        }

        .letter-footer .editable {
            flex: 1;
            min-width: 0;
        }

        @media screen {
            #certificate-document .editable:is(:hover, :focus, [data-active]) { outline: 1px solid #829ecd; outline-offset: 3px; }
            #certificate-document img[data-selected] { outline: 2px solid #0038a8; }
            .letterhead > img, .letter-footer > img { cursor: grab; touch-action: none; }
            .letterhead > img:active, .letter-footer > img:active { cursor: grabbing; }
        }

        @media screen and (max-width: 620px) {
            .certificate {
                padding-inline: 20px;
            }
            .certificate-image-layer { inset-inline: 20px; }

            .letterhead > img {
                width: calc(60px * var(--logo-scale, 1));
                height: calc(60px * var(--logo-scale, 1));
            }

            .letter-footer {
                padding-left: 0;
            }
        }

        @page {
            size: legal portrait;
            margin: .25in .95in .55in;
        }

        .certificate.preview-page {
            width: 8.5in;
            min-height: 14in;
            padding: .25in .95in .55in;
            margin: 0 auto;
            box-shadow: 0 2px 10px #17203326;
            pointer-events: none;
        }
        .preview-page .certificate-image-layer { inset: .25in .95in .55in; }
        .preview-page .letterhead > img { width: calc(1in * var(--logo-scale, 1)); height: calc(1in * var(--logo-scale, 1)); }
        .preview-page .letter-footer { padding-left: .75in; }
        .preview-page .certificate-image-layer img { pointer-events: none; }

        @media print {
            body {
                background: white;
            }

            .controls, #certificate-preview {
                display: none;
            }

            .certificate {
                width: 100%;
                min-height: calc(13.2in - 1pt);
                margin: 0;
                padding: 0;
            }

            .watermark {
                width: 100%;
            }
            .certificate-image-layer { inset: 0; }

            .signature,
            .letter-footer {
                break-inside: avoid;
            }
        }
    </style>
</head>

<body>
    <div class="controls" id="certificate-editor">
        <div class="editor-top">
            <div class="editor-group"><a class="editor-back" href="<?= h(page_url('coe-requests')) ?>">Back to COE requests</a><strong>Certificate editor</strong></div>
            <?php if ($complete): ?>
                <div class="editor-group">
                    <button type="button" id="certificate-undo" disabled>Undo</button>
                    <button type="button" id="certificate-redo" disabled>Redo</button>
                    <button type="button" id="certificate-restore-defaults">Restore defaults</button>
                    <button type="button" id="certificate-preview-open">Print preview</button>
                    <button type="button" id="print-certificate">Print certificate</button>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($complete): ?>
        <?php if ($chiefName === ''): ?>
            <p role="status"><?= $chiefSpecialists ? 'Multiple active employees hold the Chief Statistical Specialist position. Confirm the assignment in Employees.' : 'Assign the Chief Statistical Specialist position to an active employee to fill the signatory name.' ?></p>
        <?php endif; ?>
            <p class="editor-help">Click a text area to edit it, or choose a section below. Changes apply to this print copy.</p>
            <div class="editor-tools" role="group" aria-label="Text formatting">
                <label for="certificate-section">Section</label>
                <select id="certificate-section">
                    <option value="certificate-country">Header country</option>
                    <option value="certificate-agency" selected>Header agency</option>
                    <option value="certificate-office">Header office</option>
                    <option value="certificate-footer-text">Footer text</option>
                </select>
                <button type="button" data-format="bold" aria-pressed="false"><strong>Bold</strong></button>
                <button type="button" data-format="underline" aria-pressed="false"><u>Underline</u></button>
                <label>Size (px) <input id="certificate-font-size" type="number" min="6" max="48" step="1" aria-label="Section font size in pixels"></label>
                <button type="button" id="certificate-reset-font">Reset size</button>
                <label for="certificate-align">Align</label>
                <select id="certificate-align"><option value="left">Left</option><option value="center">Center</option><option value="right">Right</option></select>
                <label>Horizontal <input id="certificate-x" type="number" min="-96" max="96" value="0" aria-label="Horizontal text offset in pixels"></label>
                <label>Vertical <input id="certificate-y" type="number" min="-96" max="96" value="0" aria-label="Vertical text offset in pixels"></label>
                <button type="button" id="certificate-reset-position">Reset position</button>
                <button type="button" id="certificate-clear-text">Clear section</button>
            </div>
            <div class="editor-tools" role="group" aria-label="Images">
                <label for="certificate-image-target">Image placement</label>
                <select id="certificate-image-target">
                    <option value="inline">Inside selected text area</option>
                    <option value="free">Anywhere on the certificate</option>
                    <option value="certificate-logo">PSA logo</option>
                    <option value="certificate-right-logo">Bagong Pilipinas logo</option>
                    <option value="certificate-footer-image">Footer certification and QR</option>
                </select>
                <input id="certificate-image-file" type="file" accept="image/png,image/jpeg,image/webp" hidden>
                <button type="button" id="certificate-add-image">Insert image</button>
                <button type="button" id="certificate-remove-image">Remove image</button>
                <label>Image X <input id="certificate-image-x" type="number" min="-160" max="160" value="0" aria-label="Horizontal image offset in pixels" disabled></label>
                <label>Image Y <input id="certificate-image-y" type="number" min="-160" max="160" value="0" aria-label="Vertical image offset in pixels" disabled></label>
                <button type="button" id="certificate-reset-image" disabled>Reset image position</button>
                <label>Logo size (%) <input id="certificate-logo-size" type="number" min="25" max="175" value="100" aria-label="Selected logo size as a percentage" disabled></label>
                <button type="button" id="certificate-save-logo-sizes">Save logo sizes</button>
            </div>
            <p id="certificate-branding-message" role="status"></p>
        <?php else: ?>
            <p><?= $certificate ? 'Complete the employment details on the request before printing.' : 'COE request not found.' ?></p>
        <?php endif; ?>
    </div>
    <?php if ($complete): ?>
        <article class="certificate" id="certificate-document">
            <div class="certificate-image-layer" id="certificate-image-layer"></div>
            <img class="watermark" src="assets/psa-logo.svg" alt="">
            <header class="letterhead">
                <img id="certificate-logo" src="assets/coe-psa-seal.webp" alt="Philippine Statistics Authority seal">
                <div class="letterhead-copy">
                    <div id="certificate-country" class="editable" contenteditable="true" role="textbox" aria-label="Header country">Republic of the Philippines</div>
                    <div id="certificate-agency" class="editable letterhead-agency" contenteditable="true" role="textbox" aria-label="Header agency"><u>Philippine Statistics Authority</u></div>
                    <div id="certificate-office" class="editable letterhead-office" contenteditable="true" role="textbox" aria-label="Header office">Misamis Oriental</div>
                </div>
                <img id="certificate-right-logo" src="assets/coe-bagong-pilipinas.png" alt="Bagong Pilipinas logo">
            </header>
            <div class="letter">
                <h1>CERTIFICATION</h1>
                <p style="font-size: 20px;">TO WHOM IT MAY CONCERN:</p><br>
                <p class="paragraph">This is to certify that <strong><?= h($details['name']) ?></strong> has been employed in this office as <strong><?= h($details['position']) ?></strong><?= $onContract ? ' on contract basis' : '' ?> assigned in <?= h($certificate['destination']) ?> during <?= h(rtrim($certificate['purpose'], " .")) ?>, covering the period from <?= h(self_date_range($certificate['start'], $certificate['end'])) ?> <strong><?= h($mandays($certificate['start'], $certificate['end'])) ?></strong><?php if ($trainingStart && $trainingEnd): ?>, with training from <?= h(self_date_range($trainingStart, $trainingEnd)) ?> <strong><?= h($mandays($trainingStart, $trainingEnd)) ?></strong><?php endif; ?>.</p>
                <br><p class="paragraph">Further, the above-mentioned person is <strong>CLEARED</strong> of all his/her accountabilities during the said operation. This certification is issued upon the request of the above-named person for whatever purpose it may serve.</p>
                <br><p class="paragraph">Issued this <?= h($issueDate->format('jS')) ?> day of <?= h($issueDate->format('F Y')) ?> at Cagayan de Oro City, Misamis Oriental, Philippines.</p>
            </div><br><br><br><br>
            <div class="signature">
                <?php if ($chiefName !== ''): ?><strong class="signature-name"><?= h($chiefName) ?></strong><br><?php endif; ?>
                <span class="signature-title">Chief Statistical Specialist</span><br>
                <span style="font-size: 18px;">Provincial Statistical Office – Misamis Oriental</span>
            </div>
            <footer class="letter-footer">
                <img id="certificate-footer-image" src="assets/coe-footer-certification.png" alt="Certification and QR code">
                <div id="certificate-footer-text" class="editable" contenteditable="true" role="textbox" aria-label="Certificate footer" aria-multiline="true">3rd Floor, Limketkai Module-2 BPO &amp; Cyberpark Bldg., Rosario Crescent<br>Limketkai Center, Brgy. 31, Cagayan de Oro City<br>Contact Number: 09565766106<br>Email: misamisoriental@psa.gov.ph</div>
            </footer>
        </article>
        <dialog id="certificate-preview" aria-labelledby="certificate-preview-title">
            <div class="preview-bar">
                <div class="editor-top">
                    <strong id="certificate-preview-title">Print preview · Legal</strong>
                    <form method="dialog" class="editor-group">
                        <button type="submit">Close preview</button>
                        <button type="button" id="certificate-preview-print">Print certificate</button>
                    </form>
                </div>
                <p id="certificate-preview-status" role="status"></p>
            </div>
            <div class="preview-content" id="certificate-preview-content"></div>
        </dialog>
        <script src="<?= h(versioned_asset('assets/js/coe-certificate-editor.js')) ?>" defer></script>
    <?php endif; ?>
</body>

</html>
