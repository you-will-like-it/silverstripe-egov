<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Pdf;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * mPDF adapter. DejaVu Sans covers č, ć, đ, š, ž and Cyrillic.
 */
class MpdfRenderer implements PdfRendererInterface
{
    use Injectable;
    use Configurable;

    /**
     * @config
     * @var array<string, mixed>
     */
    private static array $mpdf_config = [
        'mode' => 'utf-8',
        'format' => 'A4',
        'default_font' => 'dejavusans',
        'default_font_size' => 10,
        'margin_left' => 20,
        'margin_right' => 20,
        'margin_top' => 18,
        'margin_bottom' => 22,
        'margin_footer' => 8,
    ];

    public function render(string $html, array $options = []): string
    {
        $config = (array) static::config()->get('mpdf_config');
        $config['tempDir'] = $this->tempDir();

        $mpdf = new Mpdf($config);
        $mpdf->SetTitle((string) ($options['title'] ?? 'Zahtjev'));
        $mpdf->SetAuthor((string) ($options['author'] ?? ''));
        $mpdf->SetCreator('YouWillLikeIT e-Uprava');
        if (!empty($options['footer'])) {
            $mpdf->SetHTMLFooter(sprintf(
                '<table width="100%%" style="font-size:7.5pt;color:#555;border-top:0.3mm solid #999;"><tr>'
                . '<td>%s</td><td align="right">Strana {PAGENO} od {nbpg}</td></tr></table>',
                htmlspecialchars((string) $options['footer'], ENT_QUOTES)
            ));
        }
        if (!empty($options['watermark'])) {
            $mpdf->SetWatermarkText((string) $options['watermark'], 0.06);
            $mpdf->showWatermarkText = true;
        }
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    private function tempDir(): string
    {
        $base = defined('TEMP_PATH') ? TEMP_PATH : sys_get_temp_dir();
        $dir = $base . DIRECTORY_SEPARATOR . 'egov-mpdf';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        return $dir;
    }
}
