<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Verification\TextExtraction;

use Smalot\PdfParser\Parser;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use Throwable;

/**
 * Text layer extraction for PDFs; falls back to OCR (pdftoppm → tesseract)
 * for scanned PDFs without a usable text layer.
 */
class PdfTextExtractor implements TextExtractorInterface
{
    use Injectable;
    use Configurable;

    /** Fewer characters than this = treat as scanned PDF. @config */
    private static int $min_text_chars = 40;

    /** @config */
    private static string $pdftotext_binary = 'pdftotext';

    /** @config */
    private static string $pdftoppm_binary = 'pdftoppm';

    /** Pages rasterised for OCR. @config */
    private static int $ocr_max_pages = 3;

    public function __construct(
        private ?ProcessRunner $runner = null,
        private ?TesseractOcrExtractor $ocr = null,
    ) {
        $this->runner ??= ProcessRunner::singleton();
        $this->ocr ??= TesseractOcrExtractor::singleton();
    }

    public function supports(string $mimeType): bool
    {
        return $mimeType === 'application/pdf';
    }

    public function extract(string $path, string $mimeType): string
    {
        $text = $this->textLayer($path);
        if (mb_strlen(trim($text)) >= (int) static::config()->get('min_text_chars')) {
            return $text;
        }
        $ocrText = $this->ocrPages($path);
        return mb_strlen($ocrText) > mb_strlen($text) ? $ocrText : $text;
    }

    private function textLayer(string $path): string
    {
        if (class_exists(Parser::class)) {
            try {
                return trim((new Parser())->parseFile($path)->getText());
            } catch (Throwable) {
                // fall through to binary
            }
        }
        $binary = (string) static::config()->get('pdftotext_binary');
        if (!$this->runner->binaryAvailable($binary)) {
            return '';
        }
        try {
            return trim($this->runner->run([$binary, '-layout', '-enc', 'UTF-8', $path, '-'], 60));
        } catch (Throwable) {
            return '';
        }
    }

    private function ocrPages(string $path): string
    {
        $binary = (string) static::config()->get('pdftoppm_binary');
        if (!$this->ocr->isAvailable() || !$this->runner->binaryAvailable($binary)) {
            return '';
        }
        $prefix = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'egov-ocr-' . bin2hex(random_bytes(6));
        try {
            $this->runner->run([
                $binary, '-r', '200', '-png',
                '-f', '1', '-l', (string) max(1, (int) static::config()->get('ocr_max_pages')),
                $path, $prefix,
            ], 120);
            $chunks = [];
            foreach (glob($prefix . '*.png') ?: [] as $png) {
                $chunks[] = $this->ocr->extract($png, 'image/png');
            }
            return trim(implode("\n\n", $chunks));
        } catch (Throwable) {
            return '';
        } finally {
            foreach (glob($prefix . '*') ?: [] as $tmp) {
                @unlink($tmp);
            }
        }
    }
}
