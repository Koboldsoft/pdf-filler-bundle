<?php
declare(strict_types=1);

namespace Koboldsoft\PdfFillerBundle\Service;

use setasign\Fpdi\Fpdi;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use RuntimeException;

class PdfOcrFiller
{
    function runCmd(string $cmd): array {
        $out = [];
        $code = 0;
        exec($cmd . ' 2>&1', $out, $code);
        return [$code, $out];
    }
    
    function must(bool $cond, string $msg): void {
        if (!$cond) throw new RuntimeException($msg);
    }
    
    
    function makeCleanPdf(string $inputPdf, ?string $outDir = null): string
    {
        if (!is_file($inputPdf)) {
            throw new RuntimeException("Input PDF nicht gefunden: $inputPdf");
        }
        
        $outDir = $outDir ?: sys_get_temp_dir();
        if (!is_dir($outDir) && !@mkdir($outDir, 0777, true) && !is_dir($outDir)) {
            throw new RuntimeException("Kann Output-Ordner nicht erstellen: $outDir");
        }
        
        $cleanPdf = rtrim($outDir, '/').'/clean_' . bin2hex(random_bytes(8)) . '.pdf';
        
        // Helper: run command and capture output
        $run = function (string $cmd): array {
            $out = [];
            $code = 0;
            exec($cmd . ' 2>&1', $out, $code);
            return [$code, implode("\n", $out)];
        };
        
        // Helper: check tool
        $has = function (string $tool) use ($run): bool {
            [$code, ] = $run('command -v ' . escapeshellarg($tool));
            return $code === 0;
        };
        
        // 1) Try qpdf (very often fixes FPDI parser issues)
        if ($has('qpdf')) {
            $cmd = sprintf(
                'qpdf --qdf --object-streams=disable %s %s',
                escapeshellarg($inputPdf),
                escapeshellarg($cleanPdf)
                );
            [$code, $out] = $run($cmd);
            
            if ($code === 0 && is_file($cleanPdf) && filesize($cleanPdf) > 0) {
                return $cleanPdf;
            }
            // cleanup if created but broken
            @unlink($cleanPdf);
        }
        
        // 2) Try Ghostscript (rewrite PDF; can also downlevel compatibility)
        if ($has('gs')) {
            $cmd = sprintf(
                'gs -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/prepress -dNOPAUSE -dQUIET -dBATCH -sOutputFile=%s %s',
                escapeshellarg($cleanPdf),
                escapeshellarg($inputPdf)
                );
            [$code, $out] = $run($cmd);
            
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
}