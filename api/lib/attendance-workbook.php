<?php
declare(strict_types=1);

function attendance_workbook_cell(string $address, $value, int $style): string
{
    if (is_int($value) || is_float($value)) {
        return '<c r="' . $address . '" s="' . $style . '"><v>' . json_encode($value) . '</v></c>';
    }
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $value);
    $value = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<c r="' . $address . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . $value . '</t></is></c>';
}

function download_attendance_workbook(string $date, array $rows): void
{
    $day = new DateTimeImmutable($date, new DateTimeZone('UTC'));
    $headings = [
        ['Philippine Statistics Authority', 1, 23],
        ['Northern Mindanao', 2, 17],
        ['Misamis Oriental, Cagayan de Oro City', 2, 17],
        ['Daily Attendance Register | ' . $day->format('F j, Y'), 2, 20],
    ];
    $sheetRows = '';
    $merges = '';
    foreach ($headings as $index => [$heading, $style, $height]) {
        $row = $index + 1;
        $sheetRows .= '<row r="' . $row . '" ht="' . $height . '" customHeight="1">' . attendance_workbook_cell('B' . $row, $heading, $style) . '</row>';
        $merges .= '<mergeCell ref="B' . $row . ':G' . $row . '"/>';
    }
    $dateSerial = (int) (25569 + $day->getTimestamp() / 86400);
    foreach ($rows as $index => $values) {
        $row = $index + 6;
        $sheetRows .= '<row r="' . $row . '">';
        foreach ($values as $column => $value) {
            $style = $index === 0 ? 3 : 0;
            if ($index > 0 && $column === 0) {
                $value = $dateSerial;
                $style = 4;
            } elseif ($index > 0 && $column >= 5 && $value !== '' && $value !== null) {
                [$hour, $minute] = explode(':', $value);
                $value = ((int) $hour * 60 + (int) $minute) / 1440;
                $style = 5;
            }
            $sheetRows .= attendance_workbook_cell(chr(65 + $column) . $row, $value, $style);
        }
        $sheetRows .= '</row>';
    }

    $columns = '';
    foreach ([15, 28, 39, 20, 17, 14, 14] as $index => $width) {
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
  <Default Extension="png" ContentType="image/png"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
  <Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>
</Types>
XML
        ,
        '_rels/.rels' => $relationship([['officeDocument', 'xl/workbook.xml']]),
        'xl/workbook.xml' => '<workbook xmlns="' . $main . '" xmlns:r="' . $office . '"><bookViews><workbookView/></bookViews><sheets><sheet name="Attendance" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => $relationship([['worksheet', 'worksheets/sheet1.xml'], ['styles', 'styles.xml']]),
        'xl/worksheets/sheet1.xml' => '<worksheet xmlns="' . $main . '" xmlns:r="' . $office . '">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="6" topLeftCell="A7" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>' . $columns . '</cols><sheetData>' . $sheetRows . '</sheetData><autoFilter ref="A6:G' . $lastRow . '"/>'
            . '<mergeCells count="4">' . $merges . '</mergeCells><drawing r:id="rId1"/></worksheet>',
        'xl/worksheets/_rels/sheet1.xml.rels' => $relationship([['drawing', '../drawings/drawing1.xml']]),
        'xl/drawings/_rels/drawing1.xml.rels' => $relationship([['image', '../media/psa-logo.png']]),
        'xl/styles.xml' => <<<'XML'
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <numFmts count="2"><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/><numFmt numFmtId="165" formatCode="h:mm AM/PM"/></numFmts>
  <fonts count="3">
    <font><sz val="11"/><name val="Calibri"/></font>
    <font><b/><sz val="17"/><color rgb="FF0038A8"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
  </fonts>
  <fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0038A8"/><bgColor indexed="64"/></patternFill></fill></fills>
  <borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="6">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
    <xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>
    <xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>
  </cellXfs>
  <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>
XML
        ,
        'xl/drawings/drawing1.xml' => <<<'XML'
<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <xdr:oneCellAnchor>
    <xdr:from><xdr:col>0</xdr:col><xdr:colOff>76200</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>76200</xdr:rowOff></xdr:from>
    <xdr:ext cx="838200" cy="838200"/>
    <xdr:pic>
      <xdr:nvPicPr><xdr:cNvPr id="1" name="Philippine Statistics Authority logo"/><xdr:cNvPicPr/></xdr:nvPicPr>
      <xdr:blipFill><a:blip r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>
      <xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="838200" cy="838200"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>
    </xdr:pic>
    <xdr:clientData/>
  </xdr:oneCellAnchor>
</xdr:wsDr>
XML
        ,
    ];

    $temp = tempnam(sys_get_temp_dir(), 'psa-attendance-');
    if ($temp === false) throw new RuntimeException('Could not create the attendance workbook.');
    $zip = $temp . '.zip';
    try {
        $archive = new PharData($zip, 0, null, Phar::ZIP);
        $archive->startBuffering();
        foreach ($parts as $path => $xml) {
            $archive->addFromString($path, '<?xml version="1.0" encoding="UTF-8"?>' . $xml);
        }
        $archive->addFile(__DIR__ . '/../../assets/psa-logo-excel.png', 'xl/media/psa-logo.png');
        $archive->stopBuffering();
        unset($archive);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="PSA-Attendance-' . $date . '.xlsx"');
        readfile($zip);
    } finally {
        if (is_file($zip)) unlink($zip);
        unlink($temp);
    }
    exit;
}
