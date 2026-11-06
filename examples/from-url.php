<?php
// Страница по адресу в PDF (тариф premium и выше).
//
//     PDFAPI_KEY=pdf_live_... php examples/from-url.php https://example.com

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PdfApi\Client;
use PdfApi\Exception\InvalidRequestException;
use PdfApi\Exception\PermissionDeniedException;

$url = $argv[1] ?? 'https://example.com';

try {
    // waitDelay — подождать, пока скрипты на странице дорисуют содержимое.
    $pdf = (new Client())->url($url, landscape: true, waitDelay: '1s');
} catch (PermissionDeniedException $e) {
    fwrite(STDERR, "{$e->getMessage()} — нужен тариф premium\n");
    exit(1);
} catch (InvalidRequestException $e) {
    fwrite(STDERR, $e->getMessage() . "\n"); // например, url_not_allowed: адрес во внутреннюю сеть
    exit(1);
}
echo $pdf->save('page.pdf'), ": {$pdf->pages} стр.\n";
