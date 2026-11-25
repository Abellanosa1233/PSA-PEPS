<?php
declare(strict_types=1);

function employee_workbook_cell(string $address, $value, int $style): string
{
    if (is_int($value) || is_float($value)) {
        return '<c r="' . $address . '" s="' . $style . '"><v>' . json_encode($value) . '</v></c>';
    }

    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $value);
    $value = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<c r="' . $address . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . $value . '</t></is></c>';
}

function download_employee_workbook(array $rows): void
{
    $headers = array_shift($rows);
    if (!is_array($headers) || count($headers) !== 14) {
        throw new InvalidArgumentException('Employee workbook data must contain 14 columns.');
    }
    foreach ($rows as $row) {
        if (!is_array($row) || count($row) !== count($headers)) {
            throw new InvalidArgumentException('Employee workbook rows must match the header columns.');
        }
    }

    $columnCount = count($headers);
    $lastColumn = chr(64 + $columnCount);
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $recordCount = count($rows);
    $sheetRows = '';
    $sheetRows .= '<row r="1" ht="38" customHeight="1">' . employee_workbook_cell('A1', 'EMPLOYEE DIRECTORY', 1) . '</row>';
    $sheetRows .= '<row r="2" ht="24" customHeight="1">' . employee_workbook_cell('A2', 'Human Resource Management System', 2) . '</row>';
    $sheetRows .= '<row r="3" ht="22" customHeight="1">' . employee_workbook_cell('A3', $recordCount . ' employee' . ($recordCount === 1 ? '' : 's') . ' | Generated ' . $now->format('F j, Y g:i A'), 3) . '</row>';
    $sheetRows .= '<row r="4" ht="10" customHeight="1"></row>';
    $sheetRows .= '<row r="5" ht="32" customHeight="1">';
    foreach ($headers as $column => $heading) {
        $sheetRows .= employee_workbook_cell(chr(65 + $column) . '5', $heading, 4);
    }
    $sheetRows .= '</row>';

    foreach ($rows as $index => $values) {
        $rowNumber = $index + 6;
        $banded = $index % 2 === 1;
        $sheetRows .= '<row r="' . $rowNumber . '" ht="26" customHeight="1">';
        foreach ($values as $column => $value) {
            $cellAddress = chr(65 + $column) . $rowNumber;
            if (($column === 9 || $column === 10) && $value !== '' && $value !== null) {
                $day = new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
                $value = (int) (25569 + $day->getTimestamp() / 86400);
                $style = $banded ? 10 : 9;
            } elseif ($column === 11 && $value !== '' && $value !== null) {
                $value = (float) $value;
                $style = $banded ? 8 : 7;
            } elseif ($column === 0 && $value !== '' && $value !== null) {
                $value = (int) $value;
                $style = $banded ? 12 : 11;
            } elseif ($column === 13) {
                $style = $value === 'Active' ? 13 : 14;
            } else {
                $style = $banded ? 6 : 5;
            }
            $sheetRows .= employee_workbook_cell($cellAddress, $value, $style);
        }
        $sheetRows .= '</row>';
    }

    $widths = [9, 20, 20, 34, 21, 28, 20, 16, 22, 17, 17, 21, 25, 16];
    $columns = '';
    foreach ($widths as $index => $width) {
        $column = $index + 1;
        $columns .= '<col min="' . $column . '" max="' . $column . '" width="' . $width . '" customWidth="1"/>';
    }

    $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $office = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $package = 'http://schemas.openxmlformats.org/package/2006/relationships';
    $relationship = function (array $targets) use ($office, $package): string {
        $xml = '<Relationships xmlns="' . $package . '">';
        foreach ($targets as $index => [$type, $target]) {
            $xml .= '<Relationship Id="rId' . ($index + 1) . '" Type="' . $office . '/' . $type . '" Target="' . $target . '"/>';
        }
        return $xml . '</Relationships>';
    };

    $lastRow = count($rows) + 5;
    $parts = [
        '[Content_Types].xml' => <<<'XML'
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>
XML
        ,
        '_rels/.rels' => $relationship([['officeDocument', 'xl/workbook.xml']]),
        'xl/workbook.xml' => '<workbook xmlns="' . $main . '" xmlns:r="' . $office . '"><bookViews><workbookView/></bookViews><sheets><sheet name="Employees" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => $relationship([['worksheet', 'worksheets/sheet1.xml'], ['styles', 'styles.xml']]),
        'xl/worksheets/sheet1.xml' => '<worksheet xmlns="' . $main . '">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            . '<dimension ref="A1:' . $lastColumn . $lastRow . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A6" sqref="A6"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="26"/><cols>' . $columns . '</cols><sheetData>' . $sheetRows . '</sheetData>'
            . '<autoFilter ref="A5:' . $lastColumn . $lastRow . '"/>'
            . '<mergeCells count="3"><mergeCell ref="A1:' . $lastColumn . '1"/><mergeCell ref="A2:' . $lastColumn . '2"/><mergeCell ref="A3:' . $lastColumn . '3"/></mergeCells>'
            . '<pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/><pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/></worksheet>',
        'xl/styles.xml' => <<<'XML'
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <numFmts count="2"><numFmt numFmtId="164" formatCode="dd mmm yyyy"/><numFmt numFmtId="165" formatCode="₱#,##0.00"/></numFmts>
  <fonts count="6">
    <font><sz val="11"/><color rgb="FF243247"/><name val="Calibri"/></font>
    <font><b/><sz val="18"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
    <font><sz val="10"/><color rgb="FF607086"/><name val="Calibri"/></font>
    <font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
    <font><b/><sz val="10"/><color rgb="FF157F3C"/><name val="Calibri"/></font>
    <font><b/><sz val="10"/><color rgb="FF526174"/><name val="Calibri"/></font>
  </fonts>
  <fills count="6">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF0038A8"/><bgColor rgb="FF0038A8"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF2F6FC"/><bgColor rgb="FFF2F6FC"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFE7F6EC"/><bgColor rgb="FFE7F6EC"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFEDF1F5"/><bgColor rgb="FFEDF1F5"/></patternFill></fill>
  </fills>
  <borders count="2">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border><left/><right/><top/><bottom style="thin"><color rgb="FFDCE4EE"/></bottom><diagonal/></border>
  </borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="15">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>
    <xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>
    <xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>
    <xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>
    <xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>
    <xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center" indent="1"/></xf>
    <xf numFmtId="165" fontId="0" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center" indent="1"/></xf>
    <xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="164" fontId="0" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="4" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="5" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
  </cellXfs>
  <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>
XML
        ,
    ];

    $temp = tempnam(sys_get_temp_dir(), 'psa-employees-');
    if ($temp === false) throw new RuntimeException('Could not create the employee workbook.');
    $zip = $temp . '.zip';
    try {
        $archive = new PharData($zip, 0, null, Phar::ZIP);
        $archive->startBuffering();
        foreach ($parts as $path => $xml) {
            $archive->addFromString($path, '<?xml version="1.0" encoding="UTF-8"?>' . $xml);
        }
        $archive->stopBuffering();
        unset($archive);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="PSA-Employee-Directory-' . $now->format('Y-m-d') . '.xlsx"');
        readfile($zip);
    } finally {
        if (is_file($zip)) unlink($zip);
        unlink($temp);
    }
    exit;
}
