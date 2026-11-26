<?php
/**
 * PDF Export Helper (Batch 6) — domPDF di-install MANUAL, tanpa Composer.
 *
 * Cara install (sekali saja):
 *   1. Unduh dompdf dari https://github.com/dompdf/dompdf/releases
 *   2. Ekstrak isi folder ke /library/dompdf/ sehingga menjadi:
 *      /library/dompdf/autoload.inc.php
 *      /library/dompdf/src/...
 *      /library/dompdf/lib/...
 *   3. Pastikan folder library/dompdf/vendor (bila ada) dapat ditulis untuk cache font.
 *
 * Bila domPDF belum tersedia, helper ini memberi pesan panduan (bukan fatal error).
 */

require_once __DIR__ . '/url.php';

/** Apakah domPDF tersedia? */
function dompdf_available() {
    return file_exists(__DIR__ . '/../library/dompdf/autoload.inc.php')
        || file_exists(__DIR__ . '/../library/dompdf/vendor/autoload.php');
}

/** Lokasi file autoload domPDF. */
function dompdf_autoload_path() {
    $candidates = [
        __DIR__ . '/../library/dompdf/autoload.inc.php',
        __DIR__ . '/../library/dompdf/vendor/autoload.php',
    ];
    foreach ($candidates as $c) {
        if (file_exists($c)) return $c;
    }
    return null;
}

/**
 * Render HTML menjadi PDF dan kirim ke browser.
 * @param string $html  HTML lengkap (boleh memakai CSS inline)
 * @param string $filename nama file unduhan
 * @param string $orientation portrait|landscape
 * @return bool true bila PDF terkirim, false bila domPDF tidak tersedia
 */
function render_pdf($html, $filename, $orientation = 'portrait') {
    $autoload = dompdf_autoload_path();
    if (!$autoload) {
        return false;
    }
    require_once $autoload;

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);   // logo LJU diambil dari dms.lju.co.id
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('chroot', realpath(__DIR__ . '/..'));
    $options->set('tempDir', sys_get_temp_dir());

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', $orientation);
    $dompdf->render();

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $dompdf->output();
    return true;
}

/**
 * Halaman HTML siap cetak (dipakai untuk PDF dan preview browser).
 */
function render_report_html($title, $subtitle, $bodyHtml, $isPdf = false) {
    $logo = defined('LOGO_URL') ? LOGO_URL : '';
    $appName = defined('APP_NAME') ? APP_NAME : 'LJU IT Helpdesk';
    $css = $isPdf
        ? '<style>
            @page { margin: 28px 32px; }
            body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #1a2332; }
            h1 { font-size: 15px; margin: 0 0 2px; }
            h2 { font-size: 12px; margin: 18px 0 6px; padding-bottom: 4px; border-bottom: 1px solid #d8dfe9; }
            .muted { color: #6b7686; }
            .small { font-size: 9px; }
            table { width: 100%; border-collapse: collapse; margin-top: 6px; }
            th { background: #f2f5fa; text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: .03em; color: #55617a; }
            th, td { padding: 5px 7px; border: 1px solid #dce3ee; }
            .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #0b5cad; padding-bottom: 10px; }
            .brand { display: flex; gap: 10px; align-items: center; }
            .brand img { width: 38px; }
            table.kpi { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 10px 0 4px; }
            table.kpi td { border: 1px solid #dce3ee; border-radius: 4px; padding: 8px 10px; }
            .kpi .label { font-size: 8px; text-transform: uppercase; color: #6b7686; letter-spacing: .05em; }
            .kpi .value { font-size: 15px; font-weight: bold; }
            .footer { margin-top: 16px; font-size: 8px; color: #8794a6; text-align: center; border-top: 1px solid #e3e8f0; padding-top: 6px; }
        </style>'
        : '<style>
            body { font-family: "Segoe UI", system-ui, sans-serif; margin: 0; padding: 28px; background: #fff; color: #1a2332; font-size: 12px; }
            h1 { font-size: 19px; margin: 0 0 2px; }
            h2 { font-size: 14px; margin: 26px 0 8px; padding-bottom: 6px; border-bottom: 1px solid #e3e8f0; }
            .muted { color: #6b7686; }
            .small { font-size: 11px; }
            table { width: 100%; border-collapse: collapse; margin-top: 8px; }
            th { background: #f8fafc; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #6b7686; }
            th, td { padding: 7px 9px; border-bottom: 1px solid #e3e8f0; }
            .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #0b5cad; padding-bottom: 12px; margin-bottom: 6px; }
            .brand { display: flex; gap: 12px; align-items: center; }
            .brand img { width: 44px; }
            table.kpi { width: 100%; border-collapse: separate; border-spacing: 8px 0; margin: 14px 0; }
            table.kpi td { border: 1px solid #e3e8f0; border-radius: 8px; padding: 12px 14px; vertical-align: top; }
            table.kpi .label { font-size: 10px; text-transform: uppercase; color: #6b7686; letter-spacing: .05em; }
            table.kpi .value { font-size: 20px; font-weight: 800; margin-top: 2px; }
            .footer { margin-top: 26px; font-size: 10px; color: #8794a6; text-align: center; border-top: 1px solid #e3e8f0; padding-top: 10px; }
            @media print { body { padding: 0; } .no-print { display: none; } }
        </style>';

    return '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>' . $css . '</head><body>'
        . '<div class="head"><div class="brand">'
        . ($logo ? '<img src="' . htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') . '" alt="LJU">' : '')
        . '<div><h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<div class="muted small">' . htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') . '</div></div></div>'
        . '<div class="small muted" style="text-align:right">' . htmlspecialchars($appName, ENT_QUOTES, 'UTF-8')
        . '<br>Dicetak: ' . date('d M Y H:i') . '</div></div>'
        . $bodyHtml
        . '<div class="footer">PT Lampung Jasa Utama — ' . htmlspecialchars($appName, ENT_QUOTES, 'UTF-8')
        . ' · helpdesk.lju.co.id</div>'
        . '</body></html>';
}
