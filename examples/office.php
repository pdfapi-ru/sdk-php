<?php
// docx, xlsx или pptx в PDF (тариф premium и выше).
//
//     PDFAPI_KEY=pdf_live_... php examples/office.php report.docx

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PdfApi\Client;

$source = $argv[1] ?? 'report.docx';

$pdf = (new Client())->office($source); // из памяти — officeFromString($data, 'report.docx')
$target = preg_replace('/\.(docx|xlsx|pptx)$/i', '', $source) . '.pdf';
echo $pdf->save($target), ": {$pdf->pages} стр.\n";
