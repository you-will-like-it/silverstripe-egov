<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Verification\TextExtraction;

use SilverStripe\Assets\File;
use SilverStripe\Core\Injector\Injectable;
use Throwable;

/**
 * Delegates to the first extractor that supports the MIME type.
 * Extractors are wired in _config/egov.yml (Injector) and can be replaced,
 * e.g. by a cloud OCR or – once AIGateway supports multimodal input – by a vision call.
 */
class ChainTextExtractor
{
    use Injectable;

    /** @var list<TextExtractorInterface> */
    private array $extractors = [];

    /**
     * @param list<TextExtractorInterface>|null $extractors
     */
    public function __construct(?array $extractors = null)
    {
        $this->extractors = $extractors ?? [PdfTextExtractor::singleton(), TesseractOcrExtractor::singleton()];
    }

    /**
     * @param list<TextExtractorInterface> $extractors
     */
    public function setExtractors(array $extractors): static
    {
        $this->extractors = $extractors;
        return $this;
    }

    /**
     * Extract from a local file (no asset store involved).
     */
    public function extractFromPath(string $path, string $mimeType): string
    {
        foreach ($this->extractors as $candidate) {
            if ($candidate->supports($mimeType)) {
                return $candidate->extract($path, $mimeType);
            }
        }
        return '';
    }

    public function extractFromFile(File $file): string
    {
        $mime = (string) $file->getMimeType();
        $extractor = null;
        foreach ($this->extractors as $candidate) {
            if ($candidate->supports($mime)) {
                $extractor = $candidate;
                break;
            }
        }
        if ($extractor === null) {
            return '';
        }

        $tmp = tempnam(sys_get_temp_dir(), 'egov-doc-');
        if ($tmp === false) {
            return '';
        }
        $path = $tmp . '.' . strtolower((string) $file->getExtension());
        try {
            $stream = $file->getStream();
            if (!is_resource($stream)) {
                return '';
            }
            $out = fopen($path, 'wb');
            if ($out === false) {
                return '';
            }
            stream_copy_to_stream($stream, $out);
            fclose($out);
            fclose($stream);
            chmod($path, 0600);
            return $this->normalise($extractor->extract($path, $mime));
        } catch (Throwable) {
            return '';
        } finally {
            @unlink($path);
            @unlink($tmp);
        }
    }

    private function normalise(string $text): string
    {
        $text = (string) preg_replace("/[ \t]+/u", ' ', $text);
        $text = (string) preg_replace("/\n{3,}/u", "\n\n", $text);
        return trim($text);
    }
}
