<?php

declare(strict_types=1);

namespace Koboldsoft\PdfFillerBundle\Controller;

use Koboldsoft\PdfFillerBundle\Service\PdfOcrFiller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FilesModel;
use Contao\StringUtil;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;

class PdfFillerController extends AbstractController
{
    private ContaoFramework $contao;
    private PdfOcrFiller $filler;
    
    public function __construct(ContaoFramework $contao, PdfOcrFiller $filler)
    {
        $this->contao = $contao;
        $this->filler = $filler;
    }
    
    /**
     * @Route("/_pdf-filler/upload/{moduleId}", name="pdf_filler_upload", methods={"POST"})
     */
    public function upload(int $moduleId, Request $request, SessionInterface $session): Response
    {
        $this->contao->initialize();
        
        // --- Eingaben ---
        $vom       = (string) $request->request->get('vom', '');
        $bis       = (string) $request->request->get('bis', '');
        $massnahme = (string) $request->request->get('massnahme', '');
        
        // Modul laden (tl_module) um Konfig zu holen
        $moduleModel = \Contao\ModuleModel::findByPk($moduleId);
        if (null === $moduleModel) {
            return new Response('Modul nicht gefunden', 404);
        }
        
        $dpi  = (int) ($moduleModel->pdfFillerDpi ?: 300);
        $lang = (string) ($moduleModel->pdfFillerLang ?: 'deu');
        
        // Upload-Ordner
        $uploadFolderUuid = $moduleModel->pdfFillerUploadFolder;
        $uploadFolder = null;
        if ($uploadFolderUuid) {
            $uploadFolder = FilesModel::findByUuid($uploadFolderUuid);
        }
        
        // Template-PDF (Fallback, wenn kein Upload)
        $templatePdf = null;
        if ($moduleModel->pdfFillerTemplatePdf) {
            $templatePdf = FilesModel::findByUuid($moduleModel->pdfFillerTemplatePdf);
        }
        
        /** @var UploadedFile|null $file */
        $file = $request->files->get('pdf');
        
        // --- Input PDF ermitteln ---
        $inputPdfPath = null;
        
        if ($file instanceof UploadedFile && $file->isValid()) {
            // Security: nur PDF
            if ($file->getClientMimeType() !== 'application/pdf' && $file->getMimeType() !== 'application/pdf') {
                return new Response('Nur PDF erlaubt.', 400);
            }
            
            // Zielpfad
            $targetDir = $uploadFolder ? TL_ROOT . '/' . $uploadFolder->path : TL_ROOT . '/var/tmp';
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0775, true);
            }
            
            $safeName = 'upload_' . bin2hex(random_bytes(8)) . '.pdf';
            $moved = $file->move($targetDir, $safeName);
            $inputPdfPath = $moved->getPathname();
        } else {
            if (!$templatePdf) {
                return new Response('Kein Upload und kein Template-PDF konfiguriert.', 400);
            }
            $inputPdfPath = TL_ROOT . '/' . $templatePdf->path;
        }
        
        // --- Ausgabe-PDF Pfad ---
        $outDir = $uploadFolder ? TL_ROOT . '/' . $uploadFolder->path : TL_ROOT . '/var/tmp';
        if (!is_dir($outDir)) {
            @mkdir($outDir, 0775, true);
        }
        $outputPdfPath = $outDir . '/filled_' . bin2hex(random_bytes(8)) . '.pdf';
        
        // --- Erzeugen ---
        try {
            $this->filler->fill(
                inputPdf: $inputPdfPath,
                outputPdf: $outputPdfPath,
                dpi: $dpi,
                lang: $lang,
                vomText: $vom,
                bisText: $bis,
                massnahmeText: $massnahme
                );
        } catch (\Throwable $e) {
            return new Response("Fehler: " . $e->getMessage(), 500);
        }
        
        // Token für Download im Session-Speicher
        $token = bin2hex(random_bytes(16));
        $map = $session->get('pdf_filler_tokens', []);
        $map[$token] = [
            'path' => $outputPdfPath,
            'ts'   => time(),
        ];
        // optional: alte Tokens aufräumen
        foreach ($map as $t => $info) {
            if (($info['ts'] ?? 0) < time() - 3600) {
                unset($map[$t]);
            }
        }
        $session->set('pdf_filler_tokens', $map);
        
        // Redirect auf Download
        return $this->redirect('/_pdf-filler/download?token=' . $token);
    }
    
    /**
     * @Route("/_pdf-filler/download", name="pdf_filler_download", methods={"GET"})
     */
    public function download(Request $request, SessionInterface $session): Response
    {
        $token = (string) $request->query->get('token', '');
        if ($token === '') {
            return new Response('Token fehlt.', 400);
        }
        
        $map = $session->get('pdf_filler_tokens', []);
        if (!isset($map[$token]['path'])) {
            return new Response('Token ungültig oder abgelaufen.', 404);
        }
        
        $path = (string) $map[$token]['path'];
        if (!is_file($path)) {
            return new Response('Datei nicht gefunden.', 404);
        }
        
        // optional: One-time token
        unset($map[$token]);
        $session->set('pdf_filler_tokens', $map);
        
        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'filled.pdf'
            );
        $response->headers->set('Content-Type', 'application/pdf');
        
        return $response;
    }
}

