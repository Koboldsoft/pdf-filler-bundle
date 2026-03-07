<?php
declare(strict_types=1);

namespace Koboldsoft\PdfFillerBundle\Service;

use setasign\Fpdi\Fpdi;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class PdfOcrFiller
{
    public function fill(
        string $inputPdf,
        string $outputPdf,
        int $dpi,
        string $lang,
        string $vomText,
        string $bisText,
        string $massnahmeText
    ): void {
        $this->must(is_file($inputPdf), "Input PDF nicht gefunden: $inputPdf");

        $this->assertToolExists('pdftoppm', "pdftoppm nicht gefunden. Installiere: sudo apt-get install poppler-utils");
        $this->assertToolExists('tesseract', "tesseract nicht gefunden. Installiere: sudo apt-get install tesseract-ocr");

        $tmpDir = sys_get_temp_dir() . '/ocr_' . bin2hex(random_bytes(4));
        $this->must(@mkdir($tmpDir, 0777, true) || is_dir($tmpDir), "Kann Temp-Ordner nicht erstellen: $tmpDir");

        $prefix  = $tmpDir . '/page';
        $pagePng = $tmpDir . '/page-1.png';

        $pxToMm = fn(float $px) => $px * 25.4 / $dpi;
        $norm = function (string $s): string {
            $s = mb_strtolower(trim($s));
            $s = preg_replace('~[^a-zäöüß0-9]+~u', '', $s) ?? $s;
            return $s;
        };

        // 1) Render page 1
        $render = new Process(['pdftoppm', '-f', '1', '-l', '1', '-png', '-r', (string)$dpi, $inputPdf, $prefix]);
        $render->run();
        if (!$render->isSuccessful()) {
            throw new ProcessFailedException($render);
        }
        $this->must(is_file($pagePng), "Render OK, aber Bild fehlt: $pagePng");

        // 2) OCR TSV
        $ocr = new Process(['tesseract', $pagePng, 'stdout', '-l', $lang, 'tsv']);
        $ocr->run();
        if (!$ocr->isSuccessful()) {
            throw new ProcessFailedException($ocr);
        }

        $tsv = trim($ocr->getOutput());
        $this->must($tsv !== '', 'tesseract lieferte keine TSV Daten.');

        $rows = preg_split('~\R~u', $tsv) ?: [];
        $this->must(count($rows) > 1, 'tesseract lieferte zu wenig TSV Daten.');

        // Header weg
        array_shift($rows);

        $foundVom = null;
        $foundBis = null;
        $foundMass = null;

        foreach ($rows as $row) {
            $cols = explode("\t", $row);
            if (count($cols) < 12) {
                continue;
            }
            $text = $cols[11] ?? '';
            if (trim($text) === '') {
                continue;
            }

            $n = $norm($text);

            if ($foundVom === null && $n === 'vom') {
                $foundVom = $this->bboxFromCols($cols, $text);
            } elseif ($foundBis === null && $n === 'bis') {
                $foundBis = $this->bboxFromCols($cols, $text);
            } elseif ($foundMass === null && ($n === 'maßnahmebezeichnung' || $n === 'massnahmebezeichnung')) {
                $foundMass = $this->bboxFromCols($cols, $text);
            }

            if ($foundVom && $foundBis && $foundMass) {
                break;
            }
        }

        $this->must($foundVom !== null, "Wort 'vom' wurde nicht erkannt (OCR). Teste lang=eng oder prüfe Scanqualität.");
        $this->must($foundBis !== null, "Wort 'bis' wurde nicht erkannt (OCR). Teste lang=eng oder prüfe Scanqualität.");
        $this->must($foundMass !== null, "Wort 'Maßnahmebezeichnung' wurde nicht erkannt (OCR). Teste lang=eng oder prüfe Scanqualität.");

        // Position rechts neben Wort
        $offsetAfterWordPx = 120;
        $baselineAdjustPx  = 10;

        $mmXVom  = $pxToMm($foundVom['left'] + $foundVom['width'] + $offsetAfterWordPx);
        $mmYVom  = $pxToMm($foundVom['top']  + $baselineAdjustPx);

        $mmXBis  = $pxToMm($foundBis['left'] + $foundBis['width'] + $offsetAfterWordPx);
        $mmYBis  = $pxToMm($foundBis['top']  + $baselineAdjustPx);

        $mmXMass = $pxToMm($foundMass['left'] + $foundMass['width'] + $offsetAfterWordPx);
        $mmYMass = $pxToMm($foundMass['top']  + $baselineAdjustPx);

        // 4) Write with FPDI
        $pdf = new Fpdi();
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);

        $pdf->setSourceFile($inputPdf);
        $tpl  = $pdf->importPage(1);
        $size = $pdf->getTemplateSize($tpl);

        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $pdf->useTemplate($tpl, 0, 0, $size['width'], $size['height']);

        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0);

        // kleine Offsets (mm)
        $xVom  = $mmXVom  + 4;
        $yVom  = $mmYVom  - 3;

        $xBis  = $mmXBis  + 4;
        $yBis  = $mmYBis  - 3;

        $xMass = $mmXMass + 10;
        $yMass = $mmYMass - 3;

        $pdf->SetXY($xVom, $yVom);
        $pdf->Cell(38, 6, $vomText, 0, 0, 'L');

        $pdf->SetXY($xBis, $yBis);
        $pdf->Cell(38, 6, $bisText, 0, 0, 'L');

        $pdf->SetXY($xMass, $yMass);
        $pdf->Cell(80, 6, $massnahmeText, 0, 0, 'L');

        $pdf->Output('F', $outputPdf);

        // cleanup best-effort
        @unlink($pagePng);
        @glob($tmpDir . '/*');
        @rmdir($tmpDir);

        $this->must(is_file($outputPdf), "Output PDF wurde nicht erstellt: $outputPdf");
    }

    private function bboxFromCols(array $cols, string $text): array
    {
        return [
            'left'   => (int) ($cols[6] ?? 0),
            'top'    => (int) ($cols[7] ?? 0),
            'width'  => (int) ($cols[8] ?? 0),
            'height' => (int) ($cols[9] ?? 0),
            'text'   => $text,
        ];
    }

    private function must(bool $cond, string $msg): void
    {
        if (!$cond) {
            throw new \RuntimeException($msg);
        }
    }

    private function assertToolExists(string $cmd, string $msg): void
    {
        $p = new Process(['sh', '-lc', 'command -v ' . escapeshellarg($cmd) . ' >/dev/null 2>&1']);
        $p->run();
        if (!$p->isSuccessful()) {
            throw new \RuntimeException($msg);
        }
    }
}