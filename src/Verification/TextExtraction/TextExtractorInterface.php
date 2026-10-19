<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Verification\TextExtraction;

/**
 * Extracts plain text from an uploaded document so it can be checked by rules
 * and sent (redacted) to the text-only AIGateway.
 */
interface TextExtractorInterface
{
    public function supports(string $mimeType): bool;

    /**
     * @param string $path Absolute path of a local temporary copy of the file.
     * @return string Extracted text ('' when nothing could be read).
     */
    public function extract(string $path, string $mimeType): string;
}
