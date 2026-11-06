<?php
// Отдавать PDF из веб-приложения: обычный PHP-скрипт, без фреймворков.
// В Laravel и Symfony — то же самое, см. README.
//
//     PDFAPI_KEY=pdf_live_... php -S 127.0.0.1:8000 examples/web.php
//     curl -o invoice.pdf 'http://127.0.0.1:8000/invoice/42?customer=ООО%20Ромашка'

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PdfApi\Client;
use PdfApi\Exception\PdfApiException;
use PdfApi\Exception\RateLimitException;

if ($_SERVER['REQUEST_METHOD'] !== 'GET'
    || preg_match('~^/invoice/(\d+)$~', (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $m) !== 1) {
    http_response_code(404);
    return;
}
$number = (int) $m[1];
// Всё, что пришло от пользователя, — только через экранирование: иначе это HTML-инъекция в документ.
$customer = htmlspecialchars((string) ($_GET['customer'] ?? 'Покупатель'), ENT_QUOTES | ENT_HTML5, 'UTF-8');

try {
    $pdf = (new Client())->html("<h1>Счёт №{$number}</h1><p>Покупатель: {$customer}</p><p>К оплате: 12 000 ₽</p>", marginTop: '20mm');
} catch (RateLimitException $e) {
    http_response_code(429);
    header('Retry-After: ' . (int) ceil($e->retryAfter ?? 1));
    echo 'Слишком часто, повторите позже';
    return;
} catch (PdfApiException $e) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo "PDF не получился: {$e->getMessage()}";
    return;
}

header('Content-Type: application/pdf');
header("Content-Disposition: inline; filename=\"invoice-{$number}.pdf\"");
header('Content-Length: ' . strlen($pdf->content));
echo $pdf->content;
