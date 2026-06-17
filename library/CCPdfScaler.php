<?php
/**
 * PDF Scaler — OpenCart port
 * Accurate port of class-cc-pdf-scaler.php (WooCommerce plugin)
 * Replaces wp_upload_dir()/wp_mkdir_p()/WP_Error with plain PHP
 */

namespace Opencart\Extension\Couriercenter\Library;

class CCPdfScaler {

    const SCALE_FACTOR = 0.95;

    private static bool $libraries_loaded = false;

    private static function load_libraries(): void {
        if (self::$libraries_loaded) return;

        $base = __DIR__ . '/lib';

        $fpdf = $base . '/fpdf/fpdf.php';
        if (!file_exists($fpdf)) throw new \Exception('FPDF library not found at: ' . $fpdf);
        require_once $fpdf;

        $fpdi = $base . '/fpdi/src/autoload.php';
        if (!file_exists($fpdi)) throw new \Exception('FPDI autoload not found at: ' . $fpdi);
        require_once $fpdi;

        self::$libraries_loaded = true;
    }

    /**
     * Scale down PDF content to 95% so it fits within printer printable area.
     *
     * @param string $pdf_content Raw PDF bytes
     * @param float  $scale_factor Scale factor (default 0.95)
     * @return string Scaled PDF bytes
     * @throws \Exception on library load failure or processing error
     */
    public static function scale_pdf(string $pdf_content, float $scale_factor = self::SCALE_FACTOR): string {
        self::load_libraries();

        $tmp_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cc_temp';
        if (!is_dir($tmp_dir)) mkdir($tmp_dir, 0755, true);

        $tmp_file = $tmp_dir . DIRECTORY_SEPARATOR . 'voucher-' . uniqid() . '.pdf';
        if (file_put_contents($tmp_file, $pdf_content) === false) {
            throw new \Exception('Δεν ήταν δυνατή η εγγραφή προσωρινού PDF');
        }

        try {
            $pdf        = new \setasign\Fpdi\Fpdi();
            $page_count = $pdf->setSourceFile($tmp_file);

            for ($page_num = 1; $page_num <= $page_count; $page_num++) {
                $template_id = $pdf->importPage($page_num);
                $size        = $pdf->getTemplateSize($template_id);

                $pdf->AddPage(
                    $size['width'] > $size['height'] ? 'L' : 'P',
                    [$size['width'], $size['height']]
                );

                $new_width  = $size['width']  * $scale_factor;
                $new_height = $size['height'] * $scale_factor;
                $x_offset   = ($size['width']  - $new_width)  / 2;
                $y_offset   = ($size['height'] - $new_height) / 2;

                $pdf->useTemplate($template_id, $x_offset, $y_offset, $new_width, $new_height);
            }

            $scaled = $pdf->Output('S');
        } finally {
            @unlink($tmp_file);
        }

        return $scaled;
    }

    /**
     * Arrange multi-page PDF (one 100x150mm voucher per page) into A4 4-up layout.
     *
     * @param string $pdf_data Raw PDF bytes
     * @return string PDF bytes with A4 4-up layout
     * @throws \Exception on library load failure or processing error
     */
    public static function arrange_4up(string $pdf_data): string {
        self::load_libraries();

        $margin_x = 4;
        $margin_y = 4;
        $gap      = 3;

        $total_w = 210 - (2 * $margin_x);
        $total_h = 297 - (2 * $margin_y);
        $w       = ($total_w - $gap) / 2;
        $h       = ($total_h - $gap) / 2;

        $positions = [
            [$margin_x,             $margin_y            ],
            [$margin_x + $w + $gap, $margin_y            ],
            [$margin_x,             $margin_y + $h + $gap],
            [$margin_x + $w + $gap, $margin_y + $h + $gap],
        ];

        $fpdi = new \setasign\Fpdi\Fpdi();
        $fpdi->SetAutoPageBreak(false);

        $page_count = $fpdi->setSourceFile(
            \setasign\Fpdi\PdfParser\StreamReader::createByString($pdf_data)
        );

        $slot = 0;
        for ($i = 1; $i <= $page_count; $i++) {
            if ($slot % 4 === 0) $fpdi->AddPage('P', [210, 297]);
            $tpl = $fpdi->importPage($i);
            $pos = $positions[$slot % 4];
            $fpdi->useTemplate($tpl, $pos[0], $pos[1], $w, $h);
            $slot++;
        }

        return $fpdi->Output('S');
    }
}
