<?php
/**
 * Minimal XLSX writer — no external dependencies.
 * Uses PHP's built-in ZipArchive (standard on all shared hosting).
 * Produces a valid Office Open XML workbook with one sheet.
 *
 * Usage:
 *   $xlsx = new ReportXLSX('Sheet1');
 *   $xlsx->addRow(['Name', 'Amount', 'Date'], true);  // true = bold header
 *   $xlsx->addRow(['Alice', 150.00, '2024-01-15']);
 *   $xlsx->output('report.xlsx');
 */
class ReportXLSX
{
    private string $sheetName;
    private array  $rows   = [];   // [ [cell, ...], ... ]
    private array  $bold   = [];   // row index => bool
    private array  $shared = [];   // shared strings index => string
    private array  $sIdx   = [];   // string => shared-string index

    public function __construct(string $sheetName = 'Sheet1')
    {
        $this->sheetName = $sheetName;
    }

    public function addRow(array $cells, bool $bold = false): void
    {
        $this->rows[] = $cells;
        $this->bold[count($this->rows) - 1] = $bold;
    }

    /** Stream XLSX bytes to browser */
    public function output(string $filename = 'report.xlsx'): void
    {
        $data = $this->build();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
        header('Content-Length: ' . strlen($data));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $data;
        exit;
    }

    /** Return raw XLSX bytes */
    public function getString(): string
    {
        return $this->build();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Build pipeline
    // ──────────────────────────────────────────────────────────────────────────

    private function build(): string
    {
        // Collect shared strings
        $this->shared = [];
        $this->sIdx   = [];
        $sheetXml     = $this->buildSheet();
        $ssXml        = $this->buildSharedStrings();
        $stylesXml    = $this->buildStyles();

        // Write to a temp file then read it back (ZipArchive needs a filename)
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create XLSX temp file.');
        }

        $zip->addFromString('[Content_Types].xml',      $this->contentTypes());
        $zip->addFromString('_rels/.rels',               $this->rootRels());
        $zip->addFromString('xl/workbook.xml',           $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels',$this->workbookRels());
        $zip->addFromString('xl/worksheets/sheet1.xml',  $sheetXml);
        $zip->addFromString('xl/sharedStrings.xml',      $ssXml);
        $zip->addFromString('xl/styles.xml',             $stylesXml);
        $zip->close();

        $data = file_get_contents($tmp);
        unlink($tmp);
        return $data;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // XML builders
    // ──────────────────────────────────────────────────────────────────────────

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml"  ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    }

    private function workbook(): string
    {
        $name = $this->xmlStr($this->sheetName);
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . $name . '" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';
    }

    private function buildSheet(): string
    {
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
              . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
              . '<sheetData>';

        foreach ($this->rows as $ri => $row) {
            $isBold = $this->bold[$ri] ?? false;
            $rowNum = $ri + 1;
            $xml   .= '<row r="' . $rowNum . '">';

            foreach ($row as $ci => $val) {
                $col  = $this->colLetter($ci);
                $ref  = $col . $rowNum;
                $xml .= $this->cell($ref, $val, $isBold);
            }

            $xml .= '</row>';
        }

        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    private function cell(string $ref, mixed $val, bool $bold): string
    {
        // Null → empty string
        if ($val === null) $val = '';

        // Style index: 0=normal, 1=bold, 2=number, 3=bold+number, 4=date, 5=bold+date
        if (is_float($val) || (is_string($val) && is_numeric($val) && str_contains($val, '.'))) {
            $s = $bold ? 3 : 2;
            $v = number_format((float)$val, 2, '.', '');
            return '<c r="' . $ref . '" s="' . $s . '"><v>' . $v . '</v></c>';
        }

        if (is_int($val) || (is_string($val) && ctype_digit((string)$val))) {
            $s = $bold ? 1 : 0;
            return '<c r="' . $ref . '" s="' . $s . '"><v>' . (int)$val . '</v></c>';
        }

        // String (shared)
        $sval = (string)$val;
        $s    = $bold ? 1 : 0;
        $idx  = $this->addShared($sval);
        return '<c r="' . $ref . '" t="s" s="' . $s . '"><v>' . $idx . '</v></c>';
    }

    private function addShared(string $s): int
    {
        if (!isset($this->sIdx[$s])) {
            $this->sIdx[$s]       = count($this->shared);
            $this->shared[]       = $s;
        }
        return $this->sIdx[$s];
    }

    private function buildSharedStrings(): string
    {
        $count = count($this->shared);
        $xml   = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
               . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
               . ' count="' . $count . '" uniqueCount="' . $count . '">';
        foreach ($this->shared as $s) {
            $xml .= '<si><t xml:space="preserve">' . $this->xmlStr($s) . '</t></si>';
        }
        $xml .= '</sst>';
        return $xml;
    }

    private function buildStyles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2">'
        .   '<font><sz val="11"/><name val="Calibri"/></font>'                       // 0 normal
        .   '<font><sz val="11"/><name val="Calibri"/><b/></font>'                  // 1 bold
        . '</fonts>'
        . '<fills count="2">'
        .   '<fill><patternFill patternType="none"/></fill>'
        .   '<fill><patternFill patternType="gray125"/></fill>'
        . '</fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="6">'
        .   '<xf numFmtId="0"  fontId="0" fillId="0" borderId="0" xfId="0"/>'       // 0 normal
        .   '<xf numFmtId="0"  fontId="1" fillId="0" borderId="0" xfId="0"/>'       // 1 bold
        .   '<xf numFmtId="2"  fontId="0" fillId="0" borderId="0" xfId="0"/>'       // 2 decimal
        .   '<xf numFmtId="2"  fontId="1" fillId="0" borderId="0" xfId="0"/>'       // 3 bold decimal
        .   '<xf numFmtId="14" fontId="0" fillId="0" borderId="0" xfId="0"/>'       // 4 date
        .   '<xf numFmtId="14" fontId="1" fillId="0" borderId="0" xfId="0"/>'       // 5 bold date
        . '</cellXfs>'
        . '</styleSheet>';
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /** Convert 0-based column index to Excel letter(s): 0→A, 25→Z, 26→AA */
    private function colLetter(int $idx): string
    {
        $letters = '';
        $idx++;
        while ($idx > 0) {
            $idx--;
            $letters = chr(65 + ($idx % 26)) . $letters;
            $idx     = (int)($idx / 26);
        }
        return $letters;
    }

    private function xmlStr(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
