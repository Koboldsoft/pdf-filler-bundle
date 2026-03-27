<?php
declare(strict_types=1);

namespace Koboldsoft\PdfFillerBundle\Service;

use RuntimeException;
use setasign\Fpdi\Fpdi;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class PdfOcrFiller
{
    public function runCmd(string $cmd): array
    {
        $out = [];
        $code = 0;
        exec($cmd . ' 2>&1', $out, $code);

        return [$code, $out];
    }

    public function must(bool $cond, string $msg): void
    {
        if (! $cond) {
            throw new RuntimeException($msg);
        }
    }

    public function makeCleanPdf(string $inputPdf, ?string $outDir = null): string
    {
        if (! is_file($inputPdf)) {
            throw new RuntimeException("Input PDF nicht gefunden: $inputPdf");
        }

        $outDir = $outDir ?: sys_get_temp_dir();
        if (! is_dir($outDir) && ! @mkdir($outDir, 0777, true) && ! is_dir($outDir)) {
            throw new RuntimeException("Kann Output-Ordner nicht erstellen: $outDir");
        }

        $cleanPdf = rtrim($outDir, '/') . '/clean_' . bin2hex(random_bytes(8)) . '.pdf';

        $run = function (string $cmd): array {
            $out = [];
            $code = 0;
            exec($cmd . ' 2>&1', $out, $code);

            return [$code, implode("\n", $out)];
        };

        $has = function (string $tool) use ($run): bool {
            [$code] = $run('command -v ' . escapeshellarg($tool));
            return $code === 0;
        };

        if ($has('qpdf')) {
            $cmd = sprintf(
                'qpdf --qdf --object-streams=disable %s %s',
                escapeshellarg($inputPdf),
                escapeshellarg($cleanPdf)
            );
            [$code] = $run($cmd);

            if ($code === 0 && is_file($cleanPdf) && filesize($cleanPdf) > 0) {
                return $cleanPdf;
            }

            @unlink($cleanPdf);
        }

        if ($has('gs')) {
            $cmd = sprintf(
                'gs -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/prepress -dNOPAUSE -dQUIET -dBATCH -sOutputFile=%s %s',
                escapeshellarg($cleanPdf),
                escapeshellarg($inputPdf)
            );
            [$code] = $run($cmd);

            if ($code === 0 && is_file($cleanPdf) && filesize($cleanPdf) > 0) {
                return $cleanPdf;
            }

            @unlink($cleanPdf);
        }

        throw new RuntimeException(
            "PDF konnte nicht bereinigt werden. Installiere qpdf oder ghostscript.\n" .
            "Debian/Ubuntu: sudo apt-get install qpdf ghostscript"
        );
    }

    public function fillAfaPdf(
        UploadedFile $file,
        array $values,
        int $relevantPage = 1,
        int $dpi = 300,
        string $lang = 'deu'
    ): string {
        $workDir = sys_get_temp_dir() . '/ocr_afa_' . bin2hex(random_bytes(8));
        $uploadDir = $workDir . '/upload';

        try {
            if (! mkdir($uploadDir, 0777, true) && ! is_dir($uploadDir)) {
                throw new RuntimeException('Kann Temp-Ordner nicht erstellen: ' . $uploadDir);
            }

            $safeFilename = 'input_' . bin2hex(random_bytes(8)) . '.pdf';
            $storedFile = $file->move($uploadDir, $safeFilename);
            $inputPdf = $storedFile->getRealPath();

            $this->must(
                $inputPdf !== false && $inputPdf !== '' && is_file($inputPdf),
                'Input PDF nicht gefunden: ' . var_export($inputPdf, true)
            );

            $this->assertBinaryExists('pdftoppm');
            $this->assertBinaryExists('tesseract');

            $pagePng = $this->renderPdfPageToPng($inputPdf, $workDir, $relevantPage, $dpi);
            $tsvRows = $this->runTesseractTsv($pagePng, $lang);
            $found = $this->detectAfaFields($tsvRows);

            return $this->renderAfaPdf($inputPdf, $relevantPage, $dpi, $found, $values);
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    public function fillJcPdf(
        UploadedFile $file,
        array $values,
        int $relevantPage = 1,
        int $dpi = 300,
        string $lang = 'deu'
    ): string {
        $workDir = sys_get_temp_dir() . '/ocr_jc_' . bin2hex(random_bytes(8));
        $uploadDir = $workDir . '/upload';

        try {
            if (! mkdir($uploadDir, 0777, true) && ! is_dir($uploadDir)) {
                throw new RuntimeException('Kann Temp-Ordner nicht erstellen: ' . $uploadDir);
            }

            $safeFilename = 'input_' . bin2hex(random_bytes(8)) . '.pdf';
            $storedFile = $file->move($uploadDir, $safeFilename);
            $inputPdf = $storedFile->getRealPath();

            $this->must(
                $inputPdf !== false && $inputPdf !== '' && is_file($inputPdf),
                'Input PDF nicht gefunden: ' . var_export($inputPdf, true)
            );

            $this->assertBinaryExists('pdftoppm');
            $this->assertBinaryExists('tesseract');

            $pagePng = $this->renderPdfPageToPng($inputPdf, $workDir, $relevantPage, $dpi);
            $tsvRows = $this->runTesseractTsv($pagePng, $lang);
            $found = $this->detectJcFields($tsvRows);

            return $this->renderJcPdf($inputPdf, $relevantPage, $dpi, $found, $values);
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    public function buildAfaPdfValues(object $auftrag, object $coach, object $massnahme): array
    {
        $firstname = (string) $coach->getFirstname();
        $lastname = (string) $coach->getLastname();
        $phone = (string) $coach->getPhone();

        if (empty($phone)) {
            $ansprechpartner = utf8_decode($firstname . ' ' . $lastname);
        } else {
            $ansprechpartner = utf8_decode($firstname . ' ' . $lastname . ', Tel.: ' . $phone);
        }

        return [
            'vom' => (string) $auftrag->getDatumEintritt(),
            'bis' => (string) $auftrag->getDatumAustritt(),
            'beginn' => (string) $massnahme->getBeginn(),
            'ende' => (string) $massnahme->getEnde(),
            'massnahmebezeichnung' => utf8_decode((string) $auftrag->getFMassnahme()),
            'massnahmenummer' => utf8_decode(str_replace('/', '     ', (string) $auftrag->getFMassnahmenr())),
            'name_des_massnahmetraegers' => utf8_decode('digi.camp SLE GmbH'),
            'anschrift_traeger' => utf8_decode('An der Kolonnade 11, 10117 Berlin'),
            'name_telefon_ansprechpartner' => $ansprechpartner,
            'ort_und_datum' => utf8_decode('Berlin, ' . date('d.m.Y')),
        ];
    }

    public function buildJcPdfValues(object $auftrag, object $coach, object $massnahme): array
    {
        $toPdf = static function (?string $value): string {
            $value = trim((string) $value);
            $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT', $value);

            return $converted !== false ? $converted : $value;
        };

        $firstname = (string) $coach->getFirstname();
        $lastname = (string) $coach->getLastname();
        $phoneAnsprechpartner = (string) $coach->getPhone();

        if (empty($phoneAnsprechpartner)) {
            $nameDesAnsprechpartnersText = $toPdf($firstname . ' ' . $lastname);
        } else {
            $nameDesAnsprechpartnersText = $toPdf($firstname . ' ' . $lastname . ', Tel.: ' . $phoneAnsprechpartner);
        }

        return [
            'massnahmetraeger' => $toPdf('digi.camp SLE GmbH'),
            'anschrift' => $toPdf('An der Kolonnade 11, 10117 Berlin'),
            'telefonnummer' => $toPdf('030 629346927'),
            'nummer_der_massnahme' => $toPdf((string) $auftrag->getFMassnahmenr()),
            'bezeichnung_der_massnahme' => $toPdf((string) $auftrag->getFMassnahme()),
            'zulassungszeitraum' => $toPdf((string) $massnahme->getBeginn() . ' - ' . (string) $massnahme->getEnde()),
            'beginn_der_teilnahme' => $toPdf((string) $auftrag->getDatumEintritt()),
            'ende_der_teilnahme' => $toPdf((string) $auftrag->getDatumAustritt()),
            'teilnahme_am_modul' => $toPdf('Einzelcoaching - Inhalt siehe AVGS'),
            'genauere_beschreibung' => $toPdf('Siehe Anlage'),
            'name_des_ansprechpartners' => $nameDesAnsprechpartnersText,
        ];
    }

    private function assertBinaryExists(string $binary): void
    {
        [$code, $output] = $this->runCmd('command -v ' . escapeshellarg($binary));
        $this->must($code === 0, $binary . " nicht gefunden.\n" . implode("\n", $output));
    }

    private function renderPdfPageToPng(string $inputPdf, string $workDir, int $page, int $dpi): string
    {
        $prefix = $workDir . '/page';
        $pagePng = $workDir . '/page-1.png';

        $cmd = sprintf(
            'pdftoppm -f %d -l %d -png -r %d %s %s',
            $page,
            $page,
            $dpi,
            escapeshellarg($inputPdf),
            escapeshellarg($prefix)
        );

        [$code, $output] = $this->runCmd($cmd);

        $this->must($code === 0, "pdftoppm Fehler:\n" . implode("\n", $output));
        $this->must(is_file($pagePng), 'Render OK, aber Bild fehlt: ' . $pagePng);

        return $pagePng;
    }

    private function runTesseractTsv(string $imagePath, string $lang): array
    {
        $cmd = sprintf(
            'tesseract %s stdout -l %s tsv',
            escapeshellarg($imagePath),
            escapeshellarg($lang)
        );

        [$code, $output] = $this->runCmd($cmd);

        $this->must($code === 0, "tesseract Fehler:\n" . implode("\n", $output));
        $this->must(count($output) > 1, 'tesseract lieferte keine TSV Daten.');

        array_shift($output);

        return $output;
    }

    private function normalizeOcrText(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        return preg_replace('~[^\p{L}\p{N}]+~u', '', $text) ?? $text;
    }

    private function hasAnyNeedle(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function detectAfaFields(array $tsvRows): array
    {
        $found = [
            'vom' => null,
            'bis' => null,
            'beginn' => null,
            'ende' => null,
            'massnahmebezeichnung' => null,
            'massnahmenummer' => null,
            'name_des_massnahmetraegers' => null,
            'anschrift_traeger' => null,
            'ansprechpartner_beim_traeger' => null,
            'name_telefon_ansprechpartner' => null,
            'ort_und_datum' => null,
        ];

        foreach ($tsvRows as $i => $row) {
            $cols = explode("\t", $row);
            if (count($cols) < 12) {
                continue;
            }

            $text = trim($cols[11] ?? '');
            if ($text === '') {
                continue;
            }

            $n = $this->normalizeOcrText($text);

            $box = [
                'left' => (int) ($cols[6] ?? 0),
                'top' => (int) ($cols[7] ?? 0),
                'width' => (int) ($cols[8] ?? 0),
                'height' => (int) ($cols[9] ?? 0),
            ];

            if ($found['vom'] === null && str_ends_with($n, 'vom')) {
                $found['vom'] = $box;
            }

            if ($found['bis'] === null && str_ends_with($n, 'bis')) {
                $found['bis'] = $box;
            }

            if ($found['beginn'] === null && str_ends_with($n, 'beginn')) {
                $found['beginn'] = $box;
            }

            if ($found['ende'] === null && $n === 'ende') {
                $found['ende'] = $box;
            }

            if ($found['massnahmebezeichnung'] === null && $this->hasAnyNeedle($n, [
                'maßnahmebezeichnung',
                'massnahmebezeichnung',
            ])) {
                $found['massnahmebezeichnung'] = $box;
            }

            if ($found['massnahmenummer'] === null && $this->hasAnyNeedle($n, [
                'maßnahmenummer',
                'massnahmenummer',
            ])) {
                $found['massnahmenummer'] = $box;
            }

            if ($found['name_des_massnahmetraegers'] === null) {
                $next1 = $tsvRows[$i + 1] ?? null;
                $next2 = $tsvRows[$i + 2] ?? null;

                if ($next1 !== null && $next2 !== null) {
                    $cols1 = explode("\t", $next1);
                    $cols2 = explode("\t", $next2);

                    if (count($cols1) >= 12 && count($cols2) >= 12) {
                        $w1 = $this->normalizeOcrText($text);
                        $w2 = $this->normalizeOcrText(trim($cols1[11] ?? ''));
                        $w3 = $this->normalizeOcrText(trim($cols2[11] ?? ''));

                        if (
                            $w1 === 'name'
                            && $w2 === 'des'
                            && (
                                $w3 === 'maßnahmeträgers'
                                || $w3 === 'maßnahmetragers'
                                || $w3 === 'massnahmetraegers'
                                || $w3 === 'massnahmetragers'
                            )
                        ) {
                            $found['name_des_massnahmetraegers'] = $box;
                        }
                    }
                }
            }

            if ($found['anschrift_traeger'] === null && $this->hasAnyNeedle($n, [
                'vollständigeanschrift',
                'vollstandigeanschrift',
                'anschrift',
            ])) {
                $found['anschrift_traeger'] = $box;
                continue;
            }

            if ($found['ansprechpartner_beim_traeger'] === null && $this->hasAnyNeedle($n, [
                'ansprechpartner',
            ])) {
                $found['ansprechpartner_beim_traeger'] = $box;
            }

            if ($found['name_telefon_ansprechpartner'] === null && $this->hasAnyNeedle($n, [
                'telefonnummer',
                'telefon',
                'ansprechpartners',
                'ansprechpartner',
            ])) {
                $found['name_telefon_ansprechpartner'] = $box;
            }

            if ($found['ort_und_datum'] === null) {
                $next1 = $tsvRows[$i + 1] ?? null;
                $next2 = $tsvRows[$i + 2] ?? null;

                if ($next1 !== null && $next2 !== null) {
                    $cols1 = explode("\t", $next1);
                    $cols2 = explode("\t", $next2);

                    if (count($cols1) >= 12 && count($cols2) >= 12) {
                        $w1 = $n;
                        $w2 = $this->normalizeOcrText(trim($cols1[11] ?? ''));
                        $w3 = $this->normalizeOcrText(trim($cols2[11] ?? ''));

                        $phrase = $w1 . $w2 . $w3;

                        if (($w1 === 'ort' || $w1 === '0rt') && $w2 === 'und' && ($w3 === 'datum' || str_starts_with($w3, 'datum'))) {
                            $found['ort_und_datum'] = $box;
                        }

                        if ($found['ort_und_datum'] === null && ($phrase === 'ortunddatum' || $phrase === '0rtunddatum')) {
                            $found['ort_und_datum'] = $box;
                        }
                    }
                }
            }

            if (
                $found['vom'] &&
                $found['bis'] &&
                $found['beginn'] &&
                $found['ende'] &&
                $found['massnahmebezeichnung'] &&
                $found['massnahmenummer'] &&
                $found['anschrift_traeger'] &&
                $found['ansprechpartner_beim_traeger'] &&
                $found['name_telefon_ansprechpartner']
            ) {
                break;
            }
        }

        $foundNameDesMassnahmetraegers = $found['name_des_massnahmetraegers'];
        $foundAnschriftTraeger = $found['anschrift_traeger'];
        $foundAnsprechpartnerBeimTraeger = $found['ansprechpartner_beim_traeger'];
        $foundNameTelefonAnsprechpartner = $found['name_telefon_ansprechpartner'];
        $foundOrtUndDatum = $found['ort_und_datum'];

        if ($foundNameDesMassnahmetraegers === null && $foundAnschriftTraeger !== null) {
            $found['name_des_massnahmetraegers'] = [
                'left' => $foundAnschriftTraeger['left'],
                'top' => $foundAnschriftTraeger['top'] - 55,
                'width' => $foundAnschriftTraeger['width'],
                'height' => $foundAnschriftTraeger['height'],
            ];
        }

        if ($foundAnsprechpartnerBeimTraeger === null && $foundNameTelefonAnsprechpartner !== null) {
            $found['ansprechpartner_beim_traeger'] = [
                'left' => $foundNameTelefonAnsprechpartner['left'],
                'top' => $foundNameTelefonAnsprechpartner['top'] - 45,
                'width' => $foundNameTelefonAnsprechpartner['width'],
                'height' => $foundNameTelefonAnsprechpartner['height'],
            ];
        }

        if ($foundOrtUndDatum === null && $foundNameTelefonAnsprechpartner !== null) {
            $found['ort_und_datum'] = [
                'left' => $foundNameTelefonAnsprechpartner['left'],
                'top' => $foundNameTelefonAnsprechpartner['top'],
                'width' => $foundNameTelefonAnsprechpartner['width'],
                'height' => $foundNameTelefonAnsprechpartner['height'],
            ];
        }

        $this->must($found['vom'] !== null, "Wort 'vom' wurde nicht erkannt.");
        $this->must($found['bis'] !== null, "Wort 'bis' wurde nicht erkannt.");
        $this->must($found['beginn'] !== null, "Wort 'Beginn' wurde nicht erkannt.");
        $this->must($found['ende'] !== null, "Wort 'Ende' wurde nicht erkannt.");
        $this->must($found['massnahmebezeichnung'] !== null, "Wort 'Maßnahmebezeichnung' wurde nicht erkannt.");
        $this->must($found['massnahmenummer'] !== null, "Wort 'Maßnahmenummer' wurde nicht erkannt.");
        $this->must($found['name_des_massnahmetraegers'] !== null, "Feld 'Name des Maßnahmeträgers' wurde nicht erkannt.");
        $this->must($found['anschrift_traeger'] !== null, "Feld 'Vollständige Anschrift' (Träger) wurde nicht erkannt.");
        $this->must($found['name_telefon_ansprechpartner'] !== null, "Feld 'Name und Telefonnummer des Ansprechpartners' wurde nicht erkannt.");
        $this->must($found['ort_und_datum'] !== null, "Feld 'Ort und Datum' wurde nicht erkannt.");

        return $found;
    }

    private function detectJcFields(array $tsvRows): array
    {
        $found = [
            'massnahmetraeger' => null,
            'anschrift' => null,
            'telefonnummer' => null,
            'nummer_der_massnahme' => null,
            'bezeichnung_der_massnahme' => null,
            'zulassungszeitraum' => null,
            'beginn_der_teilnahme' => null,
            'ende_der_teilnahme' => null,
            'name_des_ansprechpartners' => null,
            'teilnahme_am_modul' => null,
            'genauere_beschreibung' => null,
        ];

        foreach ($tsvRows as $i => $row) {
            $cols = explode("\t", $row);
            if (count($cols) < 12) {
                continue;
            }

            $text = trim($cols[11] ?? '');
            if ($text === '') {
                continue;
            }

            $box = [
                'left' => (int) ($cols[6] ?? 0),
                'top' => (int) ($cols[7] ?? 0),
                'width' => (int) ($cols[8] ?? 0),
                'height' => (int) ($cols[9] ?? 0),
            ];

            $words = [];
            for ($j = 0; $j < 8; $j++) {
                $nextRow = $tsvRows[$i + $j] ?? null;
                if ($nextRow === null) {
                    break;
                }

                $nextCols = explode("\t", $nextRow);
                if (count($nextCols) < 12) {
                    break;
                }

                $word = trim($nextCols[11] ?? '');
                if ($word === '') {
                    break;
                }

                $words[] = $this->normalizeOcrText($word);
            }

            $joined = implode('', $words);
            $w1 = $words[0] ?? '';

            if ($found['massnahmetraeger'] === null && (
                str_contains($joined, 'maßnahmeträger')
                || str_contains($joined, 'massnahmetraeger')
            )) {
                $found['massnahmetraeger'] = $box;
                continue;
            }

            if ($found['anschrift'] === null && $w1 === 'anschrift') {
                $found['anschrift'] = $box;
                continue;
            }

            if ($found['telefonnummer'] === null && str_contains($joined, 'telefonnummer')) {
                $found['telefonnummer'] = $box;
                continue;
            }

            if ($found['nummer_der_massnahme'] === null && (
                str_contains($joined, 'nummerdermaßnahme')
                || str_contains($joined, 'nummerdermassnahme')
            )) {
                $found['nummer_der_massnahme'] = $box;
                continue;
            }

            if ($found['bezeichnung_der_massnahme'] === null && (
                str_contains($joined, 'bezeichnungdermaßnahme')
                || str_contains($joined, 'bezeichnungdermassnahme')
            )) {
                $found['bezeichnung_der_massnahme'] = $box;
                continue;
            }

            if ($found['teilnahme_am_modul'] === null && str_contains($joined, 'teilnahmeammodulinhalt')) {
                $found['teilnahme_am_modul'] = $box;
                continue;
            }

            if ($found['zulassungszeitraum'] === null && str_contains($joined, 'zulassungszeitraum')) {
                $found['zulassungszeitraum'] = $box;
                continue;
            }

            if ($found['beginn_der_teilnahme'] === null && (
                str_contains($joined, 'beginnderteilnahme')
                || str_contains($joined, 'beginderteilnahme')
            )) {
                $found['beginn_der_teilnahme'] = $box;
                continue;
            }

            if ($found['ende_der_teilnahme'] === null && str_contains($joined, 'endederteilnahme')) {
                $found['ende_der_teilnahme'] = $box;
                continue;
            }

            if ($found['genauere_beschreibung'] === null && (
                str_contains($joined, 'ggfgenauerebeschreibung')
                || str_contains($joined, 'genauerebeschreibung')
            )) {
                $found['genauere_beschreibung'] = $box;
                continue;
            }

            if ($found['name_des_ansprechpartners'] === null && str_contains($joined, 'namedesansprechpartners')) {
                $found['name_des_ansprechpartners'] = $box;
                continue;
            }

            if (
                $found['massnahmetraeger'] !== null &&
                $found['anschrift'] !== null &&
                $found['telefonnummer'] !== null &&
                $found['nummer_der_massnahme'] !== null &&
                $found['bezeichnung_der_massnahme'] !== null &&
                $found['zulassungszeitraum'] !== null &&
                $found['beginn_der_teilnahme'] !== null &&
                $found['teilnahme_am_modul'] !== null &&
                $found['ende_der_teilnahme'] !== null &&
                $found['genauere_beschreibung'] !== null &&
                $found['name_des_ansprechpartners'] !== null
            ) {
                break;
            }
        }

        $this->must($found['massnahmetraeger'] !== null, "Feld 'Maßnahmeträger' wurde nicht erkannt.");
        $this->must($found['anschrift'] !== null, "Feld 'Anschrift' wurde nicht erkannt.");
        $this->must($found['telefonnummer'] !== null, "Feld 'Telefonnummer' wurde nicht erkannt.");
        $this->must($found['nummer_der_massnahme'] !== null, "Feld 'Nummer der Maßnahme' wurde nicht erkannt.");
        $this->must($found['bezeichnung_der_massnahme'] !== null, "Feld 'Bezeichnung der Maßnahme' wurde nicht erkannt.");
        $this->must($found['zulassungszeitraum'] !== null, "Feld 'Zulassungszeitraum' wurde nicht erkannt.");
        $this->must($found['beginn_der_teilnahme'] !== null, "Feld 'Beginn der Teilnahme' wurde nicht erkannt.");
        $this->must($found['teilnahme_am_modul'] !== null, "Feld 'Teilnahme am Modul / Inhalt' wurde nicht erkannt.");
        $this->must($found['ende_der_teilnahme'] !== null, "Feld 'Ende der Teilnahme' wurde nicht erkannt.");
        $this->must($found['genauere_beschreibung'] !== null, "Feld '(ggf. genauere Beschreibung)' wurde nicht erkannt.");
        $this->must($found['name_des_ansprechpartners'] !== null, "Feld 'Name des Ansprechpartners' wurde nicht erkannt.");

        return $found;
    }

    private function renderAfaPdf(
        string $inputPdf,
        int $relevantPage,
        int $dpi,
        array $found,
        array $values
    ): string {
        $pxToMm = static fn (float $px): float => $px * 25.4 / $dpi;

        $offsetAfterWordPx = 120;
        $baselineAdjustPx = 10;

        $mmXVom = $pxToMm($found['vom']['left'] + $found['vom']['width'] + $offsetAfterWordPx);
        $mmYVom = $pxToMm($found['vom']['top'] + $baselineAdjustPx);

        $mmXBis = $pxToMm($found['bis']['left'] + $found['bis']['width'] + $offsetAfterWordPx);
        $mmYBis = $pxToMm($found['bis']['top'] + $baselineAdjustPx);

        $mmXBeginn = $pxToMm($found['beginn']['left'] + $found['beginn']['width'] + $offsetAfterWordPx);
        $mmYBeginn = $pxToMm($found['beginn']['top'] + $baselineAdjustPx);

        $mmXEnde = $pxToMm($found['ende']['left'] + $found['ende']['width'] + $offsetAfterWordPx);
        $mmYEnde = $pxToMm($found['ende']['top'] + $baselineAdjustPx);

        $mmXMassnahmebezeichnung = $pxToMm($found['massnahmebezeichnung']['left'] + $found['massnahmebezeichnung']['width'] + $offsetAfterWordPx);
        $mmYMassnahmebezeichnung = $pxToMm($found['massnahmebezeichnung']['top'] + $baselineAdjustPx);

        $mmXMassnahmenummer = $pxToMm($found['massnahmenummer']['left'] + $found['massnahmenummer']['width'] + $offsetAfterWordPx);
        $mmYMassnahmenummer = $pxToMm($found['massnahmenummer']['top'] + $baselineAdjustPx);

        $mmXNameDesMassnahmetraegers = $pxToMm($found['name_des_massnahmetraegers']['left'] - 20);
        $mmYNameDesMassnahmetraegers = $pxToMm($found['name_des_massnahmetraegers']['top'] - 80);

        $mmXAnschriftTraeger = $pxToMm($found['anschrift_traeger']['left'] - 50);
        $mmYAnschriftTraeger = $pxToMm($found['anschrift_traeger']['top'] + 18);

        $mmXNameTelefonAnsprechpartner = $pxToMm($found['name_telefon_ansprechpartner']['left'] - 10);
        $mmYNameTelefonAnsprechpartner = $pxToMm($found['name_telefon_ansprechpartner']['top'] + 100);

        $mmXOrtUndDatum = $pxToMm($found['ort_und_datum']['left']);
        $mmYOrtUndDatum = $pxToMm($found['ort_und_datum']['top'] + 370);

        $pdf = new Fpdi();

        try {
            $pdf->setSourceFile($inputPdf);
        } catch (\Throwable $e) {
            $clean = $this->makeCleanPdf($inputPdf);
            $pdf->setSourceFile($clean);
        }

        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);

        $tpl = $pdf->importPage($relevantPage);
        $size = $pdf->getTemplateSize($tpl);

        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $pdf->useTemplate($tpl, 0, 0, $size['width'], $size['height']);

        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0);

        $pdf->SetXY($mmXVom + 4, $mmYVom - 3);
        $pdf->Cell(38, 6, $values['vom'], 0, 0, 'L');

        $pdf->SetXY($mmXBis + 4, $mmYBis - 3);
        $pdf->Cell(38, 6, $values['bis'], 0, 0, 'L');

        $pdf->SetXY($mmXBeginn + 4, $mmYBeginn - 3);
        $pdf->Cell(38, 6, $values['beginn'], 0, 0, 'L');

        $pdf->SetXY($mmXEnde + 4, $mmYEnde - 3);
        $pdf->Cell(38, 6, $values['ende'], 0, 0, 'L');

        $pdf->SetXY($mmXMassnahmebezeichnung + 10, $mmYMassnahmebezeichnung - 3);
        $pdf->Cell(38, 6, $values['massnahmebezeichnung'], 0, 0, 'L');

        $pdf->SetXY($mmXMassnahmenummer + 18, $mmYMassnahmenummer - 3);
        $pdf->Cell(38, 6, $values['massnahmenummer'], 0, 0, 'L');

        $pdf->SetXY($mmXNameDesMassnahmetraegers, $mmYNameDesMassnahmetraegers);
        $pdf->Cell(150, 6, $values['name_des_massnahmetraegers'], 0, 0, 'L');

        $pdf->SetXY($mmXAnschriftTraeger - 16, $mmYAnschriftTraeger - 7);
        $pdf->MultiCell(150, 5, $values['anschrift_traeger'], 0, 'L');

        $pdf->SetXY($mmXNameTelefonAnsprechpartner, $mmYNameTelefonAnsprechpartner);
        $pdf->MultiCell(150, 5, $values['name_telefon_ansprechpartner'], 0, 'L');

        $pdf->SetXY($mmXOrtUndDatum, $mmYOrtUndDatum);
        $pdf->Cell(80, 6, $values['ort_und_datum'], 0, 0, 'L');

        return $pdf->Output('S');
    }

    private function renderJcPdf(
        string $inputPdf,
        int $relevantPage,
        int $dpi,
        array $found,
        array $values
    ): string {
        $pxToMm = static fn (float $px): float => $px * 25.4 / $dpi;

        $offsetAfterWordPx = 120;
        $baselineAdjustPx = 10;

        $mmXMassnahmetraeger = $pxToMm($found['massnahmetraeger']['left'] + $found['massnahmetraeger']['width'] + $offsetAfterWordPx + 150);
        $mmYMassnahmetraeger = $pxToMm($found['massnahmetraeger']['top'] + $baselineAdjustPx);

        $mmXAnschrift = $pxToMm($found['anschrift']['left'] + $found['anschrift']['width'] + $offsetAfterWordPx + 305);
        $mmYAnschrift = $pxToMm($found['anschrift']['top'] + $baselineAdjustPx);

        $mmXTelefonnummer = $pxToMm($found['telefonnummer']['left'] + $found['telefonnummer']['width'] + $offsetAfterWordPx + 180);
        $mmYTelefonnummer = $pxToMm($found['telefonnummer']['top'] + $baselineAdjustPx);

        $mmXNummerDerMassnahme = $pxToMm($found['nummer_der_massnahme']['left'] + $found['nummer_der_massnahme']['width'] + $offsetAfterWordPx + 315);
        $mmYNummerDerMassnahme = $pxToMm($found['nummer_der_massnahme']['top'] + $baselineAdjustPx);

        $mmXBezeichnungDerMassnahme = $pxToMm($found['bezeichnung_der_massnahme']['left'] + $found['bezeichnung_der_massnahme']['width'] + $offsetAfterWordPx + 240);
        $mmYBezeichnungDerMassnahme = $pxToMm($found['bezeichnung_der_massnahme']['top'] + $baselineAdjustPx);

        $mmXZulassungszeitraum = $pxToMm($found['zulassungszeitraum']['left'] + $found['zulassungszeitraum']['width'] + $offsetAfterWordPx + 97);
        $mmYZulassungszeitraum = $pxToMm($found['zulassungszeitraum']['top'] + $baselineAdjustPx);

        $mmXBeginnDerTeilnahme = $pxToMm($found['beginn_der_teilnahme']['left'] + $found['beginn_der_teilnahme']['width'] + $offsetAfterWordPx + 347);
        $mmYBeginnDerTeilnahme = $pxToMm($found['beginn_der_teilnahme']['top'] + $baselineAdjustPx);

        $mmXTeilnahmeAmModul = $pxToMm($found['teilnahme_am_modul']['left'] + $found['teilnahme_am_modul']['width'] + $offsetAfterWordPx + 275);
        $mmYTeilnahmeAmModul = $pxToMm($found['teilnahme_am_modul']['top'] + $baselineAdjustPx);

        $mmXEndeDerTeilnahme = $pxToMm($found['ende_der_teilnahme']['left'] + $found['ende_der_teilnahme']['width'] + $offsetAfterWordPx + 380);
        $mmYEndeDerTeilnahme = $pxToMm($found['ende_der_teilnahme']['top'] + $baselineAdjustPx);

        $mmXGenauereBeschreibung = $pxToMm(
            $found['genauere_beschreibung']['left']
            + $found['genauere_beschreibung']['width']
            + $offsetAfterWordPx
            + 390
        );
        $mmYGenauereBeschreibung = $pxToMm($found['genauere_beschreibung']['top'] + $baselineAdjustPx);

        $mmXNameDesAnsprechpartners = $pxToMm($found['name_des_ansprechpartners']['left'] + $found['name_des_ansprechpartners']['width'] + $offsetAfterWordPx + 370);
        $mmYNameDesAnsprechpartners = $pxToMm($found['name_des_ansprechpartners']['top'] + $baselineAdjustPx);

        $pdf = new Fpdi();

        try {
            $pdf->setSourceFile($inputPdf);
        } catch (\Throwable $e) {
            $clean = $this->makeCleanPdf($inputPdf);
            $pdf->setSourceFile($clean);
        }

        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0);

        $tpl = $pdf->importPage($relevantPage);
        $size = $pdf->getTemplateSize($tpl);

        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $pdf->useTemplate($tpl, 0, 0, $size['width'], $size['height']);

        $pdf->SetXY($mmXMassnahmetraeger + 4, $mmYMassnahmetraeger - 3);
        $pdf->Cell(120, 6, $values['massnahmetraeger'], 0, 0, 'L');

        $pdf->SetXY($mmXAnschrift + 4, $mmYAnschrift - 3);
        $pdf->MultiCell(120, 5, $values['anschrift'], 0, 'L');

        $pdf->SetXY($mmXTelefonnummer + 4, $mmYTelefonnummer - 3);
        $pdf->Cell(80, 6, $values['telefonnummer'], 0, 0, 'L');

        $pdf->SetXY($mmXNummerDerMassnahme + 4, $mmYNummerDerMassnahme - 3);
        $pdf->Cell(80, 6, $values['nummer_der_massnahme'], 0, 0, 'L');

        $pdf->SetXY($mmXBezeichnungDerMassnahme + 4, $mmYBezeichnungDerMassnahme - 3);
        $pdf->Cell(120, 6, $values['bezeichnung_der_massnahme'], 0, 0, 'L');

        $pdf->SetXY($mmXZulassungszeitraum + 4, $mmYZulassungszeitraum - 3);
        $pdf->Cell(80, 6, $values['zulassungszeitraum'], 0, 0, 'L');

        $pdf->SetXY($mmXBeginnDerTeilnahme + 4, $mmYBeginnDerTeilnahme - 3);
        $pdf->Cell(80, 6, $values['beginn_der_teilnahme'], 0, 0, 'L');

        $pdf->SetXY($mmXEndeDerTeilnahme + 4, $mmYEndeDerTeilnahme - 3);
        $pdf->Cell(80, 6, $values['ende_der_teilnahme'], 0, 0, 'L');

        $pdf->SetXY($mmXTeilnahmeAmModul + 4, $mmYTeilnahmeAmModul - 3);
        $pdf->Cell(120, 6, $values['teilnahme_am_modul'], 0, 0, 'L');

        $pdf->SetXY($mmXGenauereBeschreibung + 4, $mmYGenauereBeschreibung - 3);
        $pdf->Cell(120, 6, $values['genauere_beschreibung'], 0, 0, 'L');

        $pdf->SetXY($mmXNameDesAnsprechpartners + 4, $mmYNameDesAnsprechpartners - 3);
        $pdf->Cell(120, 6, $values['name_des_ansprechpartners'], 0, 0, 'L');

        return $pdf->Output('S');
    }

    private function removeDirectory(?string $path): void
    {
        if ($path === null || ! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}