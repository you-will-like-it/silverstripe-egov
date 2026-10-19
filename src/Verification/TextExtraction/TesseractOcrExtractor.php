<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Verification\TextExtraction;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * OCR for scanned/photographed documents (uplatnice are mostly phone photos).
 * Requires the `tesseract` binary with Bosnian/Croatian/Serbian-Latin traineddata:
 *   apt install tesseract-ocr tesseract-ocr-bos tesseract-ocr-hrv tesseract-ocr-srp-latn
 */
class TesseractOcrExtractor implements TextExtractorInterface
{
    use Injectable;
    use Configurable;

    /** @config */
    private static string $binary = 'tesseract';

    /** @config */
    private static string $languages = 'bos+hrv+srp_latn';

    /** @config */
    private static int $timeout = 90;

    public function __construct(private ?ProcessRunner $runner = null)
    {
        $this->runner ??= ProcessRunner::singleton();
    }

    public function isAvailable(): bool
    {
        return $this->runner->binaryAvailable((string) static::config()->get('binary'));
    }

    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, ['image/jpeg', 'image/png', 'image/tiff', 'image/webp'], true);
    }

    public function extract(string $path, string $mimeType): string
    {
        if (!$this->isAvailable()) {
            return '';
        }
        return trim($this->runner->run([
            (string) static::config()->get('binary'),
            $path,
            'stdout',
            '-l',
            (string) static::config()->get('languages'),
            '--psm',
            '3',
        ], (int) static::config()->get('timeout')));
    }
}
