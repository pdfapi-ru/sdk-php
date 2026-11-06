<?php
// Счёт из HTML-шаблона.
//
//     composer install && PDFAPI_KEY=pdf_live_... php examples/invoice.php
//
// В своём проекте вместо строки с require — автозагрузка Composer вашего проекта.

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PdfApi\Client;
use PdfApi\Exception\PdfApiException;

$rub = static fn (int $amount): string => number_format($amount, 0, ',', ' ') . ' ₽'; // 12 000 ₽
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

$items = [['Подписка Premium, октябрь', 1, 1990], ['Дополнительные страницы', 2, 500]];
$rows = '';
$total = 0;
foreach ($items as [$name, $qty, $price]) {
    $rows .= "<tr><td>{$e($name)}</td><td>{$qty}</td><td>{$rub($qty * $price)}</td></tr>";
    $total += $qty * $price;
}

$html = <<<HTML
    <style>
      body { font-family: sans-serif; }
      table { width: 100%; border-collapse: collapse; }
      td, th { border-bottom: 1px solid #ddd; padding: 8px; text-align: left; }
    </style>
    <h1>Счёт №42</h1>
    <table><tr><th>Услуга</th><th>Кол-во</th><th>Сумма</th></tr>{$rows}</table>
    <h2>Итого: {$rub($total)}</h2>
    HTML;

try {
    $pdf = (new Client())->html($html, marginTop: '20mm', marginBottom: '20mm');
} catch (PdfApiException $ex) {
    fwrite(STDERR, "Не получилось: {$ex->getMessage()}\n");
    exit(1);
}
$pdf->save('invoice.pdf');
echo "invoice.pdf: {$pdf->pages} стр., осталось ", $pdf->quota?->remaining ?? '?', "\n";
