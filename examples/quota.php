<?php
// Сколько страниц осталось и когда квота обнулится — для cron и мониторинга.
//
//     PDFAPI_KEY=pdf_live_... php examples/quota.php       # предупредить, если осталось меньше 10%
//     PDFAPI_KEY=pdf_live_... php examples/quota.php 25    # …меньше 25%
//
// Код выхода 2 — страниц меньше порога: cron пришлёт письмо, мониторинг поднимет тревогу.
// Запрос страниц не тратит.

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PdfApi\Client;

$threshold = (float) ($argv[1] ?? 10);

$me = (new Client())->me();
$left = $me->pagesLimit > 0 ? 100 * $me->pagesRemaining / $me->pagesLimit : 0.0;
$reset = $me->periodEnd !== null ? $me->periodEnd->format('d.m.Y H:i') . ' UTC' : 'неизвестно когда';

printf("Тариф %s: использовано %d из %d, осталось %d (%d%%).\n", $me->plan, $me->pagesUsed, $me->pagesLimit, $me->pagesRemaining, (int) floor($left));
echo "Счётчик обнулится {$reset}.\n";
if ($left < $threshold) {
    fwrite(STDERR, "Осталось меньше {$threshold}% — пора докупить страницы или сменить тариф.\n");
    exit(2);
}
