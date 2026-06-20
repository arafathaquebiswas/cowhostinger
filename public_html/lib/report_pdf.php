<?php
/**
 * Minimal PDF table generator — no external dependencies.
 * Produces PDF/1.4 with built-in Helvetica font (14 standard PDF fonts require no file embedding).
 * Usage:
 *   $pdf = new ReportPDF('A4', 'landscape');
 *   $pdf->setTitle('My Report', date('d M Y'));
 *   $pdf->setColumns(['Name', 'Amount'], [80, 60]);
 *   $pdf->addRow(['Alice', '100.00']);
 *   $pdf->output('report.pdf');
 */
class ReportPDF
{
    // Page dimensions in points (1 inch = 72 pt)
    private float $pw;   // page width
    private float $ph;   // page height
    private float $ml = 36; // margin left
    private float $mr = 36; // margin right
    private float $mt = 36; // margin top
    private float $mb = 36; // margin bottom

    private float $cY;       // current Y position (points from top-left)
    private float $rowH = 18; // row height
    private float $hdrH = 20; // header row height

    private string $title  = 'Report';
    private string $rdate  = '';
    private array  $cols   = [];   // column labels
    private array  $widths = [];   // column widths in points
    private array  $rows   = [];   // data rows (string[])

    // PDF internals
    private array  $objs   = [];   // [id => string]
    private int    $nextId = 1;
    private array  $pages  = [];   // page object IDs
    private array  $pgStreams = []; // content stream IDs per page

    public function __construct(string $size = 'A4', string $orient = 'portrait')
    {
        if (strtolower($size) === 'a4') {
            $this->pw = 595.28;
            $this->ph = 841.89;
        } else { // letter
            $this->pw = 612.0;
            $this->ph = 792.0;
        }
        if (strtolower($orient) === 'landscape') {
            [$this->pw, $this->ph] = [$this->ph, $this->pw];
        }
    }

    public function setTitle(string $title, string $date = ''): void
    {
        $this->title = $title;
        $this->rdate = $date;
    }

    /** $cols: column headers; $widths: widths in mm (null = auto-distribute) */
    public function setColumns(array $cols, ?array $widths = null): void
    {
        $this->cols = $cols;
        $usable = $this->pw - $this->ml - $this->mr;
        if ($widths === null || count($widths) !== count($cols)) {
            $w = round($usable / max(1, count($cols)), 2);
            $this->widths = array_fill(0, count($cols), $w);
        } else {
            // Convert mm to points (1 mm = 2.8346 pt)
            $total_mm = array_sum($widths);
            $this->widths = array_map(fn($v) => round($v / $total_mm * $usable, 2), $widths);
        }
    }

    public function addRow(array $cells): void
    {
        $this->rows[] = $cells;
    }

    /** Stream raw PDF bytes to the browser */
    public function output(string $filename = 'report.pdf'): void
    {
        $pdf = $this->build();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $pdf;
        exit;
    }

    /** Return PDF as string */
    public function getString(): string
    {
        return $this->build();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private build pipeline
    // ──────────────────────────────────────────────────────────────────────────

    private function build(): string
    {
        $this->objs = [];
        $this->nextId = 1;
        $this->pages = [];
        $this->pgStreams = [];

        // Allocate fixed IDs:
        // 1 = catalog, 2 = pages tree, 3 = font Helvetica, 4 = font Helvetica-Bold
        $catalogId  = $this->allocId(); // 1
        $pagesId    = $this->allocId(); // 2
        $fontRId    = $this->allocId(); // 3  (regular)
        $fontBId    = $this->allocId(); // 4  (bold)

        // Render all content, building page streams
        $allRows = $this->rows;
        $headerText = $this->renderHeader($fontBId, $fontRId);
        $hdrLines   = $this->renderColumnHeader($fontBId);

        $usableH = $this->ph - $this->mt - $this->mb - 60; // 60pt reserved for title+header
        $rowsPerPage = (int)floor($usableH / $this->rowH);

        $rowChunks = array_chunk($allRows, max(1, $rowsPerPage));
        if (empty($rowChunks)) $rowChunks = [[]];

        foreach ($rowChunks as $pgIdx => $chunk) {
            $pgObjId  = $this->allocId();
            $strObjId = $this->allocId();
            $this->pages[]    = $pgObjId;
            $this->pgStreams[] = $strObjId;

            $stream = $headerText . $hdrLines . $this->renderRows($chunk, $pgIdx, count($rowChunks), $fontBId, $fontRId);
            $this->setObj($strObjId, $this->streamObj($stream));
            $this->setObj($pgObjId,  $this->pageObj($pagesId, $strObjId, $fontRId, $fontBId));
        }

        // Font objects
        $this->setObj($fontRId, $this->fontObj('Helvetica'));
        $this->setObj($fontBId, $this->fontObj('Helvetica-Bold'));

        // Pages tree
        $kidsStr = implode(' ', array_map(fn($id) => "{$id} 0 R", $this->pages));
        $this->setObj($pagesId,
            "<< /Type /Pages /Count " . count($this->pages) . " /Kids [{$kidsStr}] >>");

        // Catalog
        $this->setObj($catalogId,
            "<< /Type /Catalog /Pages {$pagesId} 0 R >>");

        return $this->assemble($catalogId);
    }

    private function allocId(): int
    {
        return $this->nextId++;
    }

    private function setObj(int $id, string $body): void
    {
        $this->objs[$id] = $body;
    }

    private function fontObj(string $name): string
    {
        return "<< /Type /Font /Subtype /Type1 /BaseFont /{$name} /Encoding /WinAnsiEncoding >>";
    }

    private function pageObj(int $pagesId, int $streamId, int $fR, int $fB): string
    {
        return "<< /Type /Page /Parent {$pagesId} 0 R "
             . "/MediaBox [0 0 {$this->pw} {$this->ph}] "
             . "/Resources << /Font << /FR {$fR} 0 R /FB {$fB} 0 R >> >> "
             . "/Contents {$streamId} 0 R >>";
    }

    private function streamObj(string $content): string
    {
        $len = strlen($content);
        return "<< /Length {$len} >>\nstream\n{$content}\nendstream";
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Content generation helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function renderHeader(int $fbId, int $frId): string
    {
        $out  = '';
        $y    = $this->ph - $this->mt;

        // Title
        $out .= "BT /FB 14 Tf " . $this->ml . " {$y} Td (" . $this->pdfStr($this->title) . ") Tj ET\n";

        // Date right-aligned (approx)
        if ($this->rdate) {
            $dateX = $this->pw - $this->mr - 120;
            $out  .= "BT /FR 10 Tf {$dateX} {$y} Td (" . $this->pdfStr($this->rdate) . ") Tj ET\n";
        }

        // Horizontal line below title
        $lineY = $y - 18;
        $out  .= "0.7 0.7 0.7 RG " . $this->ml . " {$lineY} m " . ($this->pw - $this->mr) . " {$lineY} l S\n";

        // Row count line
        $subtitleY = $lineY - 12;
        $cnt = count($this->rows);
        $out .= "BT /FR 9 Tf " . $this->ml . " {$subtitleY} Td ("
              . $this->pdfStr("Total records: {$cnt} | Generated: " . date('d M Y H:i'))
              . ") Tj ET\n";

        // Column header starts here
        $this->cY = $subtitleY - 6;
        return $out;
    }

    private function renderColumnHeader(int $fbId): string
    {
        $out  = '';
        $y    = $this->cY - $this->hdrH;
        $x    = $this->ml;

        // Dark background for header row
        $out .= "0.18 0.27 0.44 rg\n";  // dark blue fill
        $out .= "{$x} {$y} " . ($this->pw - $this->ml - $this->mr) . " {$this->hdrH} re f\n";
        $out .= "1 1 1 rg\n"; // white text fill

        // Column labels
        $textY = $y + 5;
        foreach ($this->cols as $i => $label) {
            $cellX = $x + 4;
            $out  .= "BT /FB 8.5 Tf {$cellX} {$textY} Td (" . $this->pdfStr($label) . ") Tj ET\n";
            $x    += $this->widths[$i] ?? 60;
        }

        $this->cY = $y;
        return $out;
    }

    private function renderRows(array $rows, int $pgIdx, int $totalPages, int $fbId, int $frId): string
    {
        $out = '';
        $y   = $this->cY;

        foreach ($rows as $ri => $row) {
            $y -= $this->rowH;
            $x  = $this->ml;

            // Alternating row background
            if ($ri % 2 === 0) {
                $out .= "0.96 0.97 0.99 rg {$x} {$y} " . ($this->pw - $this->ml - $this->mr) . " {$this->rowH} re f\n";
            }

            // Text
            $out .= "0 0 0 rg\n";
            $textY = $y + 4;
            foreach ($row as $ci => $cell) {
                $cellX   = $x + 4;
                $maxPts  = ($this->widths[$ci] ?? 60) - 8;
                $out    .= "BT /FR 8 Tf {$cellX} {$textY} Td (" . $this->pdfStr($this->truncate((string)$cell, $maxPts)) . ") Tj ET\n";
                $x      += $this->widths[$ci] ?? 60;
            }

            // Bottom border of row
            $lineY = $y;
            $out  .= "0.88 0.88 0.88 RG 0.3 w " . $this->ml . " {$lineY} m " . ($this->pw - $this->mr) . " {$lineY} l S\n";
        }

        // Page number footer
        $footY = $this->mb - 10;
        $pgNum = $pgIdx + 1;
        $out  .= "BT /FR 8 Tf " . ($this->pw / 2 - 30) . " {$footY} Td (Page {$pgNum} of {$totalPages}) Tj ET\n";

        return $out;
    }

    /** Encode a string for use inside PDF parentheses literal. */
    private function pdfStr(string $s): string
    {
        // Convert UTF-8 to latin-1 (best-effort; drop unmappable chars)
        $s = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s) ?: $s;
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ''], $s);
    }

    /** Truncate string so it fits within $maxPts at 8pt Helvetica (~0.5 pt/char avg). */
    private function truncate(string $s, float $maxPts): string
    {
        $approxChars = (int)($maxPts / 4.5);
        if (strlen($s) > $approxChars && $approxChars > 3) {
            return substr($s, 0, $approxChars - 1) . '…';
        }
        return $s;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PDF structure assembly
    // ──────────────────────────────────────────────────────────────────────────

    private function assemble(int $catalogId): string
    {
        $out     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        ksort($this->objs);

        foreach ($this->objs as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xrefPos = strlen($out);
        $count   = count($this->objs) + 1;
        $out    .= "xref\n0 {$count}\n";
        $out    .= "0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $off = $offsets[$i] ?? 0;
            $out .= str_pad((string)$off, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }

        $out .= "trailer\n<< /Size {$count} /Root {$catalogId} 0 R >>\n";
        $out .= "startxref\n{$xrefPos}\n%%EOF\n";

        return $out;
    }
}
