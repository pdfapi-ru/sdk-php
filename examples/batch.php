<?php
// Пачка документов по очереди.
//
// Тариф ограничивает частоту (Free — 1 запрос в секунду и до 3 подряд,
// Premium — 10 и 30). Упрётесь в лимит — SDK сам подождёт Retry-After
// и повторит, отдельной паузы в цикле не нужно.
//
//     PDFAPI_KEY=pdf_live_... php examples/batch.php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PdfApi\Client;
use PdfApi\Exception\PdfApiException;

$client = new Client(); // один на всю пачку

for ($n = 1; $n <= 10; $n++) {
    $name = "invoice-{$n}.pdf";
    try {
        $pdf = $client->html("<h1>Счёт №{$n}</h1>");
        $pdf->save($name);
        echo "{$name}: {$pdf->pages} стр.\n";
    } catch (PdfApiException $e) {
        fwrite(STDERR, "{$name}: ошибка {$e->errorCode} — {$e->getMessage()}\n");
    }
}
