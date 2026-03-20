<?php
// hello
declare(strict_types=1);

namespace Koboldsoft\PdfFillerBundle\Controller;

use Koboldsoft\PdfFillerBundle\Repository\MmAuftragRepository;
use Koboldsoft\PdfFillerBundle\Repository\TlMemberRepository;
use Koboldsoft\PdfFillerBundle\Repository\MmMassnahmeRepository;
use Koboldsoft\PdfFillerBundle\Service\PdfOcrFiller;
use setasign\Fpdi\Fpdi;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class PdfFillerController extends AbstractController
{
    private PdfOcrFiller $pdfOcrFiller;
    private MmAuftragRepository $auftragRepo;
    private TlMemberRepository $memberRepo;
    private MmMassnahmeRepository $massnahmeRepo;

    public function __construct(PdfOcrFiller $pdfOcrFiller, MmAuftragRepository $auftragRepo, TlMemberRepository $memberRepo, MmMassnahmeRepository $massnahmeRepo)
    {
        $this->pdfOcrFiller = $pdfOcrFiller;
        $this->auftragRepo = $auftragRepo;
        $this->memberRepo = $memberRepo;
        $this->massnahmeRepo = $massnahmeRepo;
    }

    /**
     * @Route("/pdffiller/upload_afa", name="pdf_filler_upload_afa", methods={"GET", "POST"})
     */
    public function uploadAfa(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return new Response('Formular per GET aufgerufen.');
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('pdf');
        $auftragId = (int) $request->request->get('id');

        if (!$file instanceof UploadedFile) {
            return new Response('Keine Datei hochgeladen.', 400);
        }

        $auftrag = $this->auftragRepo->findAuftragById($auftragId);
        if (!$auftrag) {
            return new Response('Auftrag nicht gefunden.', 404);
        }
        
        $id_coach = (int) $auftrag->getIdCoach();
        
        $coach = $this->memberRepo->findMemberById($id_coach);
        if (!$coach) {
            return new Response('Coach nicht gefunden.', 404);
        }
        
        $id_massnahme = (int) $auftrag->getIdMassnahme();
        
        $massnahme = $this->massnahmeRepo->findMassnahmeById($id_massnahme);
        if (!$massnahme) {
            return new Response('Massnahme nicht gefunden.', 404);
        }
        
       
        $firstname = $coach->getFirstname();
        
        $lastname = $coach->getLastname();
        
        $phone = $coach->getPhone();

        $inputPdf = $file->getPathname();

        $dpi = 300;
        $lang = 'deu';

        $vomText = $auftrag->getDatumEintritt();
        $bisText = $auftrag->getDatumAustritt();
        $beginnText = $massnahme->getBeginn();
        $endeText =  $massnahme->getEnde();

        $massnahmebezeichnungText = utf8_decode((string) $auftrag->getFMassnahme());
        $massnahmenummerText = utf8_decode(str_replace('/', '     ', (string) $auftrag->getFMassnahmenr()));

        // Dummy-Daten
        $nameDesMassnahmetraegersText = utf8_decode('digi.camp SLE GmbH');
        $anschriftTraegerText = utf8_decode('An der Kolonnade 11, 10117 Berlin');
        
        $nameTelefonAnsprechpartnerText = utf8_decode($firstname.' '.$lastname.', Tel.:'.$phone);
        $ortUndDatumText = utf8_decode('Berlin,');

        $this->pdfOcrFiller->must(file_exists($inputPdf), 'Input PDF nicht gefunden: ' . $inputPdf);

        [$code1, $out1] = $this->pdfOcrFiller->runCmd('command -v pdftoppm');
        [$code2, $out2] = $this->pdfOcrFiller->runCmd('command -v tesseract');

        $this->pdfOcrFiller->must($code1 === 0, "pdftoppm nicht gefunden.\n" . implode("\n", $out1));
        $this->pdfOcrFiller->must($code2 === 0, "tesseract nicht gefunden.\n" . implode("\n", $out2));

        $tmpDir = sys_get_temp_dir() . '/ocr_' . bin2hex(random_bytes(4));
        $this->pdfOcrFiller->must(
            @mkdir($tmpDir, 0777, true) || is_dir($tmpDir),
            'Kann Temp-Ordner nicht erstellen: ' . $tmpDir
        );

        $prefix = $tmpDir . '/page';
        $pagePng = $tmpDir . '/page-1.png';

        $pxToMm = static fn(float $px): float => $px * 25.4 / $dpi;

        $norm = static function (string $s): string {
            $s = mb_strtolower(trim($s));
            return preg_replace('~[^a-zäöüß0-9]+~u', '', $s) ?? $s;
        };

        $hasAnyNeedle = static function (string $haystack, array $needles): bool {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return true;
                }
            }
            return false;
        };

        $cmdRender = sprintf(
            'pdftoppm -f 1 -l 1 -png -r %d %s %s',
            $dpi,
            escapeshellarg($inputPdf),
            escapeshellarg($prefix)
        );

        [$renderCode, $renderOut] = $this->pdfOcrFiller->runCmd($cmdRender);
        $this->pdfOcrFiller->must($renderCode === 0, "pdftoppm Fehler:\n" . implode("\n", $renderOut));
        $this->pdfOcrFiller->must(file_exists($pagePng), 'Render OK, aber Bild fehlt: ' . $pagePng);

        $cmdOcr = sprintf(
            'tesseract %s stdout -l %s tsv',
            escapeshellarg($pagePng),
            escapeshellarg($lang)
        );

        [$ocrCode, $tsvOut] = $this->pdfOcrFiller->runCmd($cmdOcr);
        $this->pdfOcrFiller->must($ocrCode === 0, "tesseract Fehler:\n" . implode("\n", $tsvOut));
        $this->pdfOcrFiller->must(count($tsvOut) > 1, 'tesseract lieferte keine TSV Daten.');

        array_shift($tsvOut);

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

        foreach ($tsvOut as $i => $row) {
            $cols = explode("\t", $row);
            if (count($cols) < 12) {
                continue;
            }

            $text = trim($cols[11] ?? '');
            if ($text === '') {
                continue;
            }
            
            $n = $norm($text);
            

            $box = [
                'left' => (int) $cols[6],
                'top' => (int) $cols[7],
                'width' => (int) $cols[8],
                'height' => (int) $cols[9],
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

            if (
                $found['massnahmebezeichnung'] === null
                && $hasAnyNeedle($n, [
                    'maßnahmebezeichnung',
                    'massnahmebezeichnung',
                ])
            ) {
                $found['massnahmebezeichnung'] = $box;
            }

            if (
                $found['massnahmenummer'] === null
                && $hasAnyNeedle($n, [
                    'maßnahmenummer',
                    'massnahmenummer',
                ])
            ) {
                $found['massnahmenummer'] = $box;
            }

            if ($found['name_des_massnahmetraegers'] === null) {
                $word1 = $norm($text);
                
                $next1 = $tsvOut[$i + 1] ?? null;
                $next2 = $tsvOut[$i + 2] ?? null;
                
                if ($next1 !== null && $next2 !== null) {
                    $cols1 = explode("\t", $next1);
                    $cols2 = explode("\t", $next2);
                    
                    if (count($cols1) >= 12 && count($cols2) >= 12) {
                        $w2 = $norm(trim($cols1[11] ?? ''));
                        $w3 = $norm(trim($cols2[11] ?? ''));
                        
                        if (
                            $word1 === 'name'
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

            // Erstes Auftreten von "Vollständige Anschrift" => Träger
            if (
                $found['anschrift_traeger'] === null
                && $hasAnyNeedle($n, [
                    'vollständigeanschrift',
                    'vollstandigeanschrift',
                    'anschrift',
                ])
            ) {
                $found['anschrift_traeger'] = $box;
                continue;
            }

           if (
                $found['ansprechpartner_beim_traeger'] === null
                && $hasAnyNeedle($n, [
                    'ansprechpartner',
                ])
            ) {
                $found['ansprechpartner_beim_traeger'] = $box;
            }

            if (
                $found['name_telefon_ansprechpartner'] === null
                && $hasAnyNeedle($n, [
                    'telefonnummer',
                    'telefon',
                    'ansprechpartners',
                    'ansprechpartner',
                ])
            ) {
                $found['name_telefon_ansprechpartner'] = $box;
            }

            if ($found['ort_und_datum'] === null) {
                $word1 = $n;
                
                $next1 = $tsvOut[$i + 1] ?? null;
                $next2 = $tsvOut[$i + 2] ?? null;
                
                if ($next1 !== null && $next2 !== null) {
                    $cols1 = explode("\t", $next1);
                    $cols2 = explode("\t", $next2);
                    
                    if (count($cols1) >= 12 && count($cols2) >= 12) {
                        $w2 = $norm(trim($cols1[11] ?? ''));
                        $w3 = $norm(trim($cols2[11] ?? ''));
                        
                        $phrase = $word1 . $w2 . $w3;
                        
                        if (
                            ($word1 === 'ort' || $word1 === '0rt') &&
                            $w2 === 'und' &&
                            ($w3 === 'datum' || str_starts_with($w3, 'datum'))
                            ) {
                                $found['ort_und_datum'] = $box;
                            }
                            
                            // Alternative Sammelprüfung
                            if (
                                $found['ort_und_datum'] === null &&
                                (
                                    $phrase === 'ortunddatum' ||
                                    $phrase === '0rtunddatum'
                                    )
                                ) {
                                    $found['ort_und_datum'] = $box;
                                }
                    }
                }
            }

            if (
                $found['vom']
                && $found['bis']
                && $found['beginn']
                && $found['ende']
                && $found['massnahmebezeichnung']
                && $found['massnahmenummer']
                && $found['anschrift_traeger']
                && $found['ansprechpartner_beim_traeger']
                && $found['name_telefon_ansprechpartner']
                ) {
                    break;
                }
        }

        $foundVom = $found['vom'];
        $foundBis = $found['bis'];
        $foundBeginn = $found['beginn'];
        $foundEnde = $found['ende'];
        $foundMassnahmebezeichnung = $found['massnahmebezeichnung'];
        $foundMassnahmenummer = $found['massnahmenummer'];
        
        $foundNameDesMassnahmetraegers = $found['name_des_massnahmetraegers'];
        $foundAnschriftTraeger = $found['anschrift_traeger'];
        $foundAnsprechpartnerBeimTraeger = $found['ansprechpartner_beim_traeger'];
        $foundNameTelefonAnsprechpartner = $found['name_telefon_ansprechpartner'];
        $foundOrtUndDatum = $found['ort_und_datum'];

        // Fallbacks
        if ($foundNameDesMassnahmetraegers === null && $foundAnschriftTraeger !== null) {
            $foundNameDesMassnahmetraegers = [
                'left' => $foundAnschriftTraeger['left'],
                'top' => $foundAnschriftTraeger['top'] - 55,
                'width' => $foundAnschriftTraeger['width'],
                'height' => $foundAnschriftTraeger['height'],
            ];
        }

        if ($foundAnsprechpartnerBeimTraeger === null && $foundNameTelefonAnsprechpartner !== null) {
            $foundAnsprechpartnerBeimTraeger = [
                'left' => $foundNameTelefonAnsprechpartner['left'],
                'top' => $foundNameTelefonAnsprechpartner['top'] - 45,
                'width' => $foundNameTelefonAnsprechpartner['width'],
                'height' => $foundNameTelefonAnsprechpartner['height'],
            ];
        }

        if ($foundOrtUndDatum === null && $foundNameTelefonAnsprechpartner !== null) {
            $foundOrtUndDatum = [
                'left' => $foundNameTelefonAnsprechpartner['left'],
                'top' => $foundNameTelefonAnsprechpartner['top'],
                'width' => $foundNameTelefonAnsprechpartner['width'],
                'height' => $foundNameTelefonAnsprechpartner['height'],
            ];
        }

        $this->pdfOcrFiller->must($foundVom !== null, "Wort 'vom' wurde nicht erkannt.");
        $this->pdfOcrFiller->must($foundBis !== null, "Wort 'bis' wurde nicht erkannt.");
        $this->pdfOcrFiller->must($foundBeginn !== null, "Wort 'Beginn' wurde nicht erkannt.");
        $this->pdfOcrFiller->must($foundEnde !== null, "Wort 'Ende' wurde nicht erkannt.");
        $this->pdfOcrFiller->must($foundMassnahmebezeichnung !== null, "Wort 'Maßnahmebezeichnung' wurde nicht erkannt.");
        $this->pdfOcrFiller->must($foundMassnahmenummer !== null, "Wort 'Maßnahmenummer' wurde nicht erkannt.");
        
        $this->pdfOcrFiller->must($foundNameDesMassnahmetraegers !== null, "Feld 'Name des Maßnahmeträgers' wurde nicht erkannt.");
        $this->pdfOcrFiller->must($foundAnschriftTraeger !== null, "Feld 'Vollständige Anschrift' (Träger) wurde nicht erkannt.");
        $this->pdfOcrFiller->must($foundNameTelefonAnsprechpartner !== null, "Feld 'Name und Telefonnummer des Ansprechpartners' wurde nicht erkannt.");
        $this->pdfOcrFiller->must($foundOrtUndDatum !== null, "Feld 'Ort und Datum' wurde nicht erkannt.");

        $offsetAfterWordPx = 120;
        $baselineAdjustPx = 10;

        $mmXVom = $pxToMm($foundVom['left'] + $foundVom['width'] + $offsetAfterWordPx);
        $mmYVom = $pxToMm($foundVom['top'] + $baselineAdjustPx);

        $mmXBis = $pxToMm($foundBis['left'] + $foundBis['width'] + $offsetAfterWordPx);
        $mmYBis = $pxToMm($foundBis['top'] + $baselineAdjustPx);

        $mmXBeginn = $pxToMm($foundBeginn['left'] + $foundBeginn['width'] + $offsetAfterWordPx);
        $mmYBeginn = $pxToMm($foundBeginn['top'] + $baselineAdjustPx);

        $mmXEnde = $pxToMm($foundEnde['left'] + $foundEnde['width'] + $offsetAfterWordPx);
        $mmYEnde = $pxToMm($foundEnde['top'] + $baselineAdjustPx);

        $mmXMassnahmebezeichnung = $pxToMm($foundMassnahmebezeichnung['left'] + $foundMassnahmebezeichnung['width'] + $offsetAfterWordPx);
        $mmYMassnahmebezeichnung = $pxToMm($foundMassnahmebezeichnung['top'] + $baselineAdjustPx);

        $mmXMassnahmenummer = $pxToMm($foundMassnahmenummer['left'] + $foundMassnahmenummer['width'] + $offsetAfterWordPx);
        $mmYMassnahmenummer = $pxToMm($foundMassnahmenummer['top'] + $baselineAdjustPx);

        $mmXNameDesMassnahmetraegers = $pxToMm($foundNameDesMassnahmetraegers['left'] - 20);
        $mmYNameDesMassnahmetraegers = $pxToMm($foundNameDesMassnahmetraegers['top'] - 80);

        $mmXAnschriftTraeger = $pxToMm($foundAnschriftTraeger['left'] - 50);
        $mmYAnschriftTraeger = $pxToMm($foundAnschriftTraeger['top'] + 18);

        $mmXNameTelefonAnsprechpartner = $pxToMm($foundNameTelefonAnsprechpartner['left'] - 10);
        $mmYNameTelefonAnsprechpartner = $pxToMm($foundNameTelefonAnsprechpartner['top'] + 100);

        $mmXOrtUndDatum = $pxToMm($foundOrtUndDatum['left'] - 0);
        $mmYOrtUndDatum = $pxToMm($foundOrtUndDatum['top'] + 370);

        $pdf = new Fpdi();

        try {
            $pdf->setSourceFile($inputPdf);
        } catch (\Throwable $e) {
            $clean = $this->pdfOcrFiller->makeCleanPdf($inputPdf);
            $pdf->setSourceFile($clean);
        }

        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);

        $tpl = $pdf->importPage(1);
        $size = $pdf->getTemplateSize($tpl);

        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $pdf->useTemplate($tpl, 0, 0, $size['width'], $size['height']);

        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0);

        $pdf->SetXY($mmXVom + 4, $mmYVom - 3);
        $pdf->Cell(38, 6, $vomText, 0, 0, 'L');

        $pdf->SetXY($mmXBis + 4, $mmYBis - 3);
        $pdf->Cell(38, 6, $bisText, 0, 0, 'L');

        $pdf->SetXY($mmXBeginn + 4, $mmYBeginn - 3);
        $pdf->Cell(38, 6, $beginnText, 0, 0, 'L');

        $pdf->SetXY($mmXEnde + 4, $mmYEnde - 3);
        $pdf->Cell(38, 6, $endeText, 0, 0, 'L');

        $pdf->SetXY($mmXMassnahmebezeichnung + 10, $mmYMassnahmebezeichnung - 3);
        $pdf->Cell(38, 6, $massnahmebezeichnungText, 0, 0, 'L');

        $pdf->SetXY($mmXMassnahmenummer + 18, $mmYMassnahmenummer - 3);
        $pdf->Cell(38, 6, $massnahmenummerText, 0, 0, 'L');

        $pdf->SetXY($mmXNameDesMassnahmetraegers, $mmYNameDesMassnahmetraegers);
        $pdf->Cell(150, 6, $nameDesMassnahmetraegersText, 0, 0, 'L');

        $pdf->SetXY($mmXAnschriftTraeger - 16, $mmYAnschriftTraeger - 7);
        $pdf->MultiCell(150, 5, $anschriftTraegerText, 0, 'L');

        $pdf->SetXY($mmXNameTelefonAnsprechpartner, $mmYNameTelefonAnsprechpartner);
        $pdf->MultiCell(150, 5, $nameTelefonAnsprechpartnerText, 0, 'L');

        $pdf->SetXY($mmXOrtUndDatum, $mmYOrtUndDatum);
        $pdf->Cell(80, 6, $ortUndDatumText, 0, 0, 'L');

        return new Response(
            $pdf->Output('S'),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="filled.pdf"',
            ]
        );
    }
    
    /**
     * @Route("/pdffiller/upload_jc", name="pdf_filler_upload_jc", methods={"GET", "POST"})
     */
    public function uploadJc(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return new Response('Formular per GET aufgerufen.');
        }
        
        /** @var UploadedFile|null $file */
        $file = $request->files->get('pdf');
        $auftragId = filter_var($request->request->get('id'), FILTER_VALIDATE_INT);
        
        if (!$file instanceof UploadedFile) {
            return new Response('Keine Datei hochgeladen.', 400);
        }
        
        if ($auftragId === false) {
            return new Response('Ungültige Auftrags-ID.', 400);
        }
        
        $auftrag = $this->auftragRepo->findAuftragById($auftragId);
        if (!$auftrag) {
            return new Response('Auftrag nicht gefunden.', 404);
        }
        
        $idCoach = (int) $auftrag->getIdCoach();
        $coach = $this->memberRepo->findMemberById($idCoach);
        if (!$coach) {
            return new Response('Coach nicht gefunden.', 404);
        }
        
        $idMassnahme = (int) $auftrag->getIdMassnahme();
        $massnahme = $this->massnahmeRepo->findMassnahmeById($idMassnahme);
        if (!$massnahme) {
            return new Response('Massnahme nicht gefunden.', 404);
        }
        
        $firstname = (string) $coach->getFirstname();
        $lastname = (string) $coach->getLastname();
        $phone = (string) $coach->getPhone();
        
        $inputPdf = $file->getPathname();
        
        $dpi = 300;
        $lang = 'deu';
        
        $vomText = (string) $auftrag->getDatumEintritt();
        $bisText = (string) $auftrag->getDatumAustritt();
        
        $beginnText = (string) $massnahme->getBeginn();
        $endeText = (string) $massnahme->getEnde();
        
        $massnahmebezeichnungText = utf8_decode((string) $auftrag->getFMassnahme());
        $massnahmenummerText = utf8_decode( (string) $auftrag->getFMassnahmenr());
        
        $nameDesMassnahmetraegersText = utf8_decode('digi.camp SLE GmbH');
        $anschriftTraegerText = utf8_decode('An der Kolonnade 11, 10117 Berlin');
        $telefonnummerText = utf8_decode($phone);
        $zulassungszeitraumText = utf8_decode($beginnText . ' - ' . $endeText);
        $nameDesAnsprechpartnersText = utf8_decode($firstname . ' ' . $lastname);
        
        $this->pdfOcrFiller->must(file_exists($inputPdf), 'Input PDF nicht gefunden: ' . $inputPdf);
        
        [$code1, $out1] = $this->pdfOcrFiller->runCmd('command -v pdftoppm');
        [$code2, $out2] = $this->pdfOcrFiller->runCmd('command -v tesseract');
        
        $this->pdfOcrFiller->must($code1 === 0, "pdftoppm nicht gefunden.\n" . implode("\n", $out1));
        $this->pdfOcrFiller->must($code2 === 0, "tesseract nicht gefunden.\n" . implode("\n", $out2));
        
        $tmpDir = sys_get_temp_dir() . '/ocr_' . bin2hex(random_bytes(4));
        $this->pdfOcrFiller->must(
            @mkdir($tmpDir, 0777, true) || is_dir($tmpDir),
            'Kann Temp-Ordner nicht erstellen: ' . $tmpDir
            );
        
        $prefix = $tmpDir . '/page';
        $pagePng = $tmpDir . '/page-4.png';
        
        $pxToMm = static fn(float $px): float => $px * 25.4 / $dpi;
        
        $norm = static function (string $s): string {
            $s = mb_strtolower(trim($s));
            return preg_replace('~[^a-zäöüß0-9]+~u', '', $s) ?? $s;
        };
        
        $cmdRender = sprintf(
            'pdftoppm -f 4 -l 4 -png -r %d %s %s',
            $dpi,
            escapeshellarg($inputPdf),
            escapeshellarg($prefix)
            );
        
        [$renderCode, $renderOut] = $this->pdfOcrFiller->runCmd($cmdRender);
        $this->pdfOcrFiller->must($renderCode === 0, "pdftoppm Fehler:\n" . implode("\n", $renderOut));
        $this->pdfOcrFiller->must(file_exists($pagePng), 'Render OK, aber Bild fehlt: ' . $pagePng);
        
        $cmdOcr = sprintf(
            'tesseract %s stdout -l %s tsv',
            escapeshellarg($pagePng),
            escapeshellarg($lang)
            );
        
        [$ocrCode, $tsvOut] = $this->pdfOcrFiller->runCmd($cmdOcr);
        $this->pdfOcrFiller->must($ocrCode === 0, "tesseract Fehler:\n" . implode("\n", $tsvOut));
        $this->pdfOcrFiller->must(count($tsvOut) > 1, 'tesseract lieferte keine TSV Daten.');
        
        array_shift($tsvOut);
        
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
        ];
        
        foreach ($tsvOut as $i => $row) {
            $cols = explode("\t", $row);
            if (count($cols) < 12) {
                continue;
            }
            
            $text = trim($cols[11] ?? '');
            if ($text === '') {
                continue;
            }
            
            $box = [
                'left' => (int) $cols[6],
                'top' => (int) $cols[7],
                'width' => (int) $cols[8],
                'height' => (int) $cols[9],
            ];
            
            $words = [];
            for ($j = 0; $j < 6; $j++) {
                $nextRow = $tsvOut[$i + $j] ?? null;
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
                
                $words[] = $norm($word);
            }
            
            $joined = implode('', $words);
            $w1 = $words[0] ?? '';
            
            if (
                $found['massnahmetraeger'] === null
                && (
                    str_contains($joined, 'maßnahmeträger')
                    || str_contains($joined, 'massnahmetraeger')
                    )
                ) {
                    $found['massnahmetraeger'] = $box;
                    continue;
                }
                
                if (
                    $found['anschrift'] === null
                    && $w1 === 'anschrift'
                    ) {
                        $found['anschrift'] = $box;
                        continue;
                    }
                    
                    if (
                        $found['telefonnummer'] === null
                        && str_contains($joined, 'telefonnummer')
                        ) {
                            $found['telefonnummer'] = $box;
                            continue;
                        }
                        
                        if (
                            $found['nummer_der_massnahme'] === null
                            && (
                                str_contains($joined, 'nummerdermaßnahme')
                                || str_contains($joined, 'nummerdermassnahme')
                                )
                            ) {
                                $found['nummer_der_massnahme'] = $box;
                                continue;
                            }
                            
                            if (
                                $found['bezeichnung_der_massnahme'] === null
                                && (
                                    str_contains($joined, 'bezeichnungdermaßnahme')
                                    || str_contains($joined, 'bezeichnungdermassnahme')
                                    )
                                ) {
                                    $found['bezeichnung_der_massnahme'] = $box;
                                    continue;
                                }
                                
                                if (
                                    $found['zulassungszeitraum'] === null
                                    && str_contains($joined, 'zulassungszeitraum')
                                    ) {
                                        $found['zulassungszeitraum'] = $box;
                                        continue;
                                    }
                                    
                                    if (
                                        $found['beginn_der_teilnahme'] === null
                                        && (
                                            str_contains($joined, 'beginderteilnahme')
                                            || str_contains($joined, 'beginnderteilnahme')
                                            )
                                        ) {
                                            $found['beginn_der_teilnahme'] = $box;
                                            continue;
                                        }
                                        
                                        if (
                                            $found['ende_der_teilnahme'] === null
                                            && str_contains($joined, 'endederteilnahme')
                                            ) {
                                                $found['ende_der_teilnahme'] = $box;
                                                continue;
                                            }
                                            
                                            if (
                                                $found['name_des_ansprechpartners'] === null
                                                && str_contains($joined, 'namedesansprechpartners')
                                                ) {
                                                    $found['name_des_ansprechpartners'] = $box;
                                                    continue;
                                                }
                                                
                                                if (
                                                    $found['massnahmetraeger']
                                                    && $found['anschrift']
                                                    && $found['telefonnummer']
                                                    && $found['nummer_der_massnahme']
                                                    && $found['bezeichnung_der_massnahme']
                                                    && $found['zulassungszeitraum']
                                                    && $found['beginn_der_teilnahme']
                                                    && $found['ende_der_teilnahme']
                                                    && $found['name_des_ansprechpartners']
                                                    ) {
                                                        break;
                                                    }
        }
        
        $foundMassnahmetraeger = $found['massnahmetraeger'];
        $foundAnschrift = $found['anschrift'];
        $foundTelefonnummer = $found['telefonnummer'];
        $foundNummerDerMassnahme = $found['nummer_der_massnahme'];
        $foundBezeichnungDerMassnahme = $found['bezeichnung_der_massnahme'];
        $foundZulassungszeitraum = $found['zulassungszeitraum'];
        $foundBeginnDerTeilnahme = $found['beginn_der_teilnahme'];
        $foundEndeDerTeilnahme = $found['ende_der_teilnahme'];
        $foundNameDesAnsprechpartners = $found['name_des_ansprechpartners'];
        
        $this->pdfOcrFiller->must($foundMassnahmetraeger !== null, "Feld 'Maßnahmeträger' wurde nicht erkannt.");
        $this->pdfOcrFiller->must($foundAnschrift !== null, "Feld 'Anschrift' wurde nicht erkannt.");
        $this->pdfOcrFiller->must($foundTelefonnummer !== null, "Feld 'Telefonnummer' wurde nicht erkannt.");
        $this->pdfOcrFiller->must($foundNummerDerMassnahme !== null, "Feld 'Nummer der Maßnahme' wurde nicht erkannt.");
        $this->pdfOcrFiller->must($foundBezeichnungDerMassnahme !== null, "Feld 'Bezeichnung der Maßnahme' wurde nicht erkannt.");
        $this->pdfOcrFiller->must($foundZulassungszeitraum !== null, "Feld 'Zulassungszeitraum' wurde nicht erkannt.");
        $this->pdfOcrFiller->must($foundBeginnDerTeilnahme !== null, "Feld 'Beginn der Teilnahme' wurde nicht erkannt.");
        $this->pdfOcrFiller->must($foundEndeDerTeilnahme !== null, "Feld 'Ende der Teilnahme' wurde nicht erkannt.");
        $this->pdfOcrFiller->must($foundNameDesAnsprechpartners !== null, "Feld 'Name des Ansprechpartners' wurde nicht erkannt.");
        
        $offsetAfterWordPx = 120;
        $baselineAdjustPx = 10;
        
        $mmXMassnahmetraeger = $pxToMm($foundMassnahmetraeger['left'] + $foundMassnahmetraeger['width'] + $offsetAfterWordPx + 150);
        $mmYMassnahmetraeger = $pxToMm($foundMassnahmetraeger['top'] + $baselineAdjustPx);
        
        $mmXAnschrift = $pxToMm($foundAnschrift['left'] + $foundAnschrift['width'] + $offsetAfterWordPx + 310);
        $mmYAnschrift = $pxToMm($foundAnschrift['top'] + $baselineAdjustPx);
        
        $mmXTelefonnummer = $pxToMm($foundTelefonnummer['left'] + $foundTelefonnummer['width'] + $offsetAfterWordPx + 178);
        $mmYTelefonnummer = $pxToMm($foundTelefonnummer['top'] + $baselineAdjustPx);
        
        $mmXNummerDerMassnahme = $pxToMm($foundNummerDerMassnahme['left'] + $foundNummerDerMassnahme['width'] + $offsetAfterWordPx + 320);
        $mmYNummerDerMassnahme = $pxToMm($foundNummerDerMassnahme['top'] + $baselineAdjustPx);
        
        $mmXBezeichnungDerMassnahme = $pxToMm($foundBezeichnungDerMassnahme['left'] + $foundBezeichnungDerMassnahme['width'] + $offsetAfterWordPx + 245);
        $mmYBezeichnungDerMassnahme = $pxToMm($foundBezeichnungDerMassnahme['top'] + $baselineAdjustPx);
        
        $mmXZulassungszeitraum = $pxToMm($foundZulassungszeitraum['left'] + $foundZulassungszeitraum['width'] + $offsetAfterWordPx + 97);
        $mmYZulassungszeitraum = $pxToMm($foundZulassungszeitraum['top'] + $baselineAdjustPx);
        
        $mmXBeginnDerTeilnahme = $pxToMm($foundBeginnDerTeilnahme['left'] + $foundBeginnDerTeilnahme['width'] + $offsetAfterWordPx + 350);
        $mmYBeginnDerTeilnahme = $pxToMm($foundBeginnDerTeilnahme['top'] + $baselineAdjustPx);
        
        $mmXEndeDerTeilnahme = $pxToMm($foundEndeDerTeilnahme['left'] + $foundEndeDerTeilnahme['width'] + $offsetAfterWordPx + 380);
        $mmYEndeDerTeilnahme = $pxToMm($foundEndeDerTeilnahme['top'] + $baselineAdjustPx);
        
        $mmXNameDesAnsprechpartners = $pxToMm($foundNameDesAnsprechpartners['left'] + $foundNameDesAnsprechpartners['width'] + $offsetAfterWordPx + 370);
        $mmYNameDesAnsprechpartners = $pxToMm($foundNameDesAnsprechpartners['top'] + $baselineAdjustPx);
        
        $pdf = new Fpdi();
        
        try {
            $pdf->setSourceFile($inputPdf);
        } catch (\Throwable $e) {
            $clean = $this->pdfOcrFiller->makeCleanPdf($inputPdf);
            $pdf->setSourceFile($clean);
        }
        
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0);
        
        $tpl = $pdf->importPage(4);
        $size = $pdf->getTemplateSize($tpl);
        
        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $pdf->useTemplate($tpl, 0, 0, $size['width'], $size['height']);
        
        $pdf->SetXY($mmXMassnahmetraeger + 4, $mmYMassnahmetraeger - 3);
        $pdf->Cell(120, 6, $nameDesMassnahmetraegersText, 0, 0, 'L');
        
        $pdf->SetXY($mmXAnschrift + 4, $mmYAnschrift - 3);
        $pdf->MultiCell(120, 5, $anschriftTraegerText, 0, 'L');
        
        /*
        $pdf->SetXY($mmXTelefonnummer + 4, $mmYTelefonnummer - 3);
        $pdf->Cell(80, 6, $telefonnummerText, 0, 0, 'L');
        */
        
        $pdf->SetXY($mmXNummerDerMassnahme + 4, $mmYNummerDerMassnahme - 3);
        $pdf->Cell(80, 6, $massnahmenummerText, 0, 0, 'L');
        
        $pdf->SetXY($mmXBezeichnungDerMassnahme + 4, $mmYBezeichnungDerMassnahme - 3);
        $pdf->Cell(120, 6, $massnahmebezeichnungText, 0, 0, 'L');
        
        $pdf->SetXY($mmXZulassungszeitraum + 4, $mmYZulassungszeitraum - 3);
        $pdf->Cell(80, 6, $zulassungszeitraumText, 0, 0, 'L');
        
        $pdf->SetXY($mmXBeginnDerTeilnahme + 4, $mmYBeginnDerTeilnahme - 3);
        $pdf->Cell(80, 6, $vomText, 0, 0, 'L');
        
        $pdf->SetXY($mmXEndeDerTeilnahme + 4, $mmYEndeDerTeilnahme - 3);
        $pdf->Cell(80, 6, $bisText, 0, 0, 'L');
        
        $pdf->SetXY($mmXNameDesAnsprechpartners + 4, $mmYNameDesAnsprechpartners - 3);
        $pdf->Cell(120, 6, $nameDesAnsprechpartnersText.', Tel.: '.$telefonnummerText, 0, 0, 'L');
        
        return new Response(
            $pdf->Output('S'),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="filled.pdf"',
            ]
            );
    }
}