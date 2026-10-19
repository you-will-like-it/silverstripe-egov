<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Pdf;

interface PdfRendererInterface
{
    /**
     * @param array{title?: string, author?: string, footer?: string, watermark?: string} $options
     * @return string Binary PDF
     */
    public function render(string $html, array $options = []): string;
}
