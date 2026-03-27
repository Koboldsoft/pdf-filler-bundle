<?php
declare(strict_types=1);

namespace Koboldsoft\PdfFillerBundle\Controller;

use Koboldsoft\PdfFillerBundle\Repository\MmAuftragRepository;
use Koboldsoft\PdfFillerBundle\Repository\MmMassnahmeRepository;
use Koboldsoft\PdfFillerBundle\Repository\TlMemberRepository;
use Koboldsoft\PdfFillerBundle\Service\PdfOcrFiller;
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

    public function __construct(
        PdfOcrFiller $pdfOcrFiller,
        MmAuftragRepository $auftragRepo,
        TlMemberRepository $memberRepo,
        MmMassnahmeRepository $massnahmeRepo
    ) {
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
        if (! $request->isMethod('POST')) {
            return new Response('Formular per GET aufgerufen.', 405);
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('pdf');
        $auftragId = filter_var($request->request->get('id'), FILTER_VALIDATE_INT);

        if (! $file instanceof UploadedFile) {
            return new Response('Keine Datei hochgeladen.', 400);
        }

        if (! $file->isValid()) {
            return new Response('Upload fehlgeschlagen: ' . $file->getErrorMessage(), 400);
        }

        if ($auftragId === false) {
            return new Response('Ungültige Auftrags-ID.', 400);
        }

        $loaded = $this->loadAuftragData($auftragId);
        if ($loaded instanceof Response) {
            return $loaded;
        }

        try {
            $values = $this->pdfOcrFiller->buildAfaPdfValues(
                $loaded['auftrag'],
                $loaded['coach'],
                $loaded['massnahme']
            );

            $pdfContent = $this->pdfOcrFiller->fillAfaPdf($file, $values);

            return new Response($pdfContent, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="filled.pdf"',
            ]);
        } catch (\Throwable $e) {
            return new Response('Fehler beim Verarbeiten der PDF: ' . $e->getMessage(), 500);
        }
    }

    /**
     * @Route("/pdffiller/upload_jc", name="pdf_filler_upload_jc", methods={"GET", "POST"})
     */
    public function uploadJc(Request $request): Response
    {
        if (! $request->isMethod('POST')) {
            return new Response('Formular per GET aufgerufen.', 405);
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('pdf');
        $auftragId = filter_var($request->request->get('id'), FILTER_VALIDATE_INT);

        if (! $file instanceof UploadedFile) {
            return new Response('Keine Datei hochgeladen.', 400);
        }

        if (! $file->isValid()) {
            return new Response('Upload fehlgeschlagen: ' . $file->getErrorMessage(), 400);
        }

        if ($auftragId === false) {
            return new Response('Ungültige Auftrags-ID.', 400);
        }

        $loaded = $this->loadAuftragData($auftragId);
        if ($loaded instanceof Response) {
            return $loaded;
        }

        try {
            $values = $this->pdfOcrFiller->buildJcPdfValues(
                $loaded['auftrag'],
                $loaded['coach'],
                $loaded['massnahme']
            );

            $pdfContent = $this->pdfOcrFiller->fillJcPdf($file, $values);

            return new Response($pdfContent, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="filled.pdf"',
            ]);
        } catch (\Throwable $e) {
            return new Response('Fehler beim Verarbeiten der PDF: ' . $e->getMessage(), 500);
        }
    }

    /**
     * @return array|Response
     */
    private function loadAuftragData(int $auftragId)
    {
        $auftrag = $this->auftragRepo->findAuftragById($auftragId);
        if (! $auftrag) {
            return new Response('Auftrag nicht gefunden.', 404);
        }
        
        $coach = $this->memberRepo->findMemberById((int) $auftrag->getIdCoach());
        if (! $coach) {
            return new Response('Coach nicht gefunden.', 404);
        }
        
        $massnahme = $this->massnahmeRepo->findMassnahmeById((int) $auftrag->getIdMassnahme());
        if (! $massnahme) {
            return new Response('Massnahme nicht gefunden.', 404);
        }
        
        return [
            'auftrag' => $auftrag,
            'coach' => $coach,
            'massnahme' => $massnahme,
        ];
    }
}