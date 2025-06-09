<?php
try {
    require_once '../vendor/tecnickcom/tcpdf/tcpdf.php';
    $font_path = '../vendor/tecnickcom/tcpdf/fonts/THSarabunNew.ttf';
    if (!file_exists($font_path)) {
        die("Error: THSarabunNew.ttf not found at $font_path");
    }
    $fontname = TCPDF_FONTS::addTTFfont($font_path, 'TrueTypeUnicode', '', 32);
    echo 'Font added successfully: ' . $fontname;
} catch (Exception $e) {
    die('Error converting font: ' . $e->getMessage());
}
?>