# pdfapi — PHP SDK

Официальная библиотека [pdfapi](https://pdfapi.ru): HTML, страница по адресу, Markdown или docx/xlsx/pptx — в PDF одним вызовом. PHP 8.2+, без сторонних зависимостей (только `ext-curl`).

```bash
composer require pdfapi/pdfapi
```

```php
use PdfApi\Client;

$client = new Client();   // ключ из переменной окружения PDFAPI_KEY

$pdf = $client->html('<h1>Счёт №42</h1>', marginTop: '20mm');
$pdf->save('invoice.pdf');

echo $pdf->pages, ' стр., осталось ', $pdf->quota?->remaining;
```

Ключ выпускается в кабинете на [pdfapi.ru](https://pdfapi.ru). Держите его в переменной окружения, а не в коде:

```bash
export PDFAPI_KEY=pdf_live_...
```

## Методы

| Метод | Что делает |
|---|---|
| `html(string $html, ...параметры)` | HTML → PDF |
| `url(string $url, ...параметры)` | страница по адресу → PDF (тариф premium и выше). Адрес — полный, с `http://` или `https://` |
| `markdown(string $text, ...параметры)` | Markdown → PDF (тариф premium и выше) |
| `office(string $path, ?bool $landscape = null)` | docx, xlsx, pptx с диска → PDF (тариф premium и выше) |
| `officeFromString(string $data, string $filename, ?bool $landscape = null)` | то же из памяти |
| `convert(array $body)` | `POST /v1/convert` с телом как есть |
| `me()` | тариф, расход, остаток страниц и границы периода |

У каждого метода есть и `timeout:` — секунд на этот вызов.

### Параметры страницы

Именованные аргументы, все необязательные:

| Параметр | Пример | По умолчанию |
|---|---|---|
| `landscape` | `true` | `false` |
| `paperWidth`, `paperHeight` | `'8.27in'`, `'297mm'` | A4 |
| `marginTop`, `marginBottom`, `marginLeft`, `marginRight` | `'20mm'` | `'0.39in'` |
| `waitDelay` | `'2s'`, `'500ms'` — подождать, пока скрипт на странице дорисует график; до 10 секунд | `'0s'` |

```php
$pdf = $client->url('https://example.com', landscape: true, waitDelay: '1s');
```

Размер — число и единица `pt`, `px`, `in`, `mm`, `cm` или `pc` без пробела: `'20mm'`, `'1.5cm'`, `'.5in'`. Остальное — ошибка до запроса: `'20'` — это миллиметры или дюймы? А `'20 mm'` и `'20MM'` рендер не понимает.

### Office-документы

```php
$pdf = $client->office('report.docx');                                    // с диска
$pdf = $client->officeFromString($data, 'table.xlsx', landscape: true);   // из памяти — имя с расширением
```

Файл — не больше 3,7 МБ: после base64 тело запроса не должно превысить 5 МБ. docx, xlsx и pptx — ZIP-архивы, поэтому старый `.doc` или PDF, переименованный в `.docx`, отклоняется сразу, не отправляя мегабайты на сервер.

## Результат

`Result`:
- `content` — байты PDF (строка);
- `pages` — сколько страниц списано;
- `quota` — `Quota` с полями `limit`, `used`, `remaining` после этой конвертации;
- `save($path)` — записать в файл и вернуть путь.

`save` пишет атомарно: сначала во временный файл рядом, потом переименовывает поверх. Оборвётся запись или кончится место — на месте останется прежний файл, а не обрезанный PDF. Каталог не создаётся.

## Отдать PDF из веб-приложения

PDF уже в памяти — отдавайте байты, на диск сохранять незачем:

```php
// Laravel
return response($pdf->content, 200, [
    'Content-Type' => 'application/pdf',
    'Content-Disposition' => 'inline; filename="invoice.pdf"',
]);

// Symfony
return new Response($pdf->content, 200, ['Content-Type' => 'application/pdf']);
```

Данные пользователя в HTML — только через `htmlspecialchars` или шаблоны с автоэкранированием (Blade, Twig), иначе это HTML-инъекция в документ. Без фреймворка, с обработкой ошибок, — [`examples/web.php`](examples/web.php).

## Аккаунт и квота

```php
$me = $client->me();
echo "{$me->plan}: осталось {$me->pagesRemaining} из {$me->pagesLimit}, обнулится ",
    $me->periodEnd?->format(DATE_ATOM) ?? 'неизвестно когда', "\n";
```

`Account`: `userId`, `plan` (`free`, `premium`, `business`), `periodStart` и `periodEnd` (когда счётчик начнётся с нуля; `DateTimeImmutable` в UTC или `null`, если сервер не сообщил), `pagesUsed`, `pagesLimit`, `pagesRemaining`, `requestsPerSecond`, `burst`. Запрос страниц не тратит. Проверка для cron — [`examples/quota.php`](examples/quota.php).

## Ошибки

Все ошибки API — наследники `PdfApi\Exception\PdfApiException` с полями `errorCode`, `status` и сообщением по-русски:

```php
use PdfApi\Exception\{QuotaExceededException, RateLimitException, RenderException, PdfApiException};

try {
    $pdf = $client->html($html);
} catch (QuotaExceededException $e) {
    // страницы кончились
} catch (RateLimitException $e) {
    sleep((int) ceil($e->retryAfter ?? 1));
} catch (RenderException $e) {          // и RenderTimeoutException тоже
    // разметка не отрендерилась
} catch (PdfApiException $e) {
    error_log("{$e->errorCode} {$e->status}: {$e->getMessage()}");
}
```

| Исключение | Когда |
|---|---|
| `AuthenticationException` | 401: ключа нет, он неверный или отозван |
| `PermissionDeniedException` | 403: почта не подтверждена или формат недоступен на тарифе |
| `QuotaExceededException` | 402: страницы кончились |
| `InvalidRequestException` | 400: неверный параметр или адрес во внутреннюю сеть (`errorCode === 'url_not_allowed'`) |
| `PayloadTooLargeException` | 413 (наследник `InvalidRequestException`) |
| `RateLimitException` | 429; `retryAfter` — через сколько секунд можно снова |
| `RenderException` | 502: документ не отрендерился |
| `RenderTimeoutException` | 504 (наследник `RenderException`) |
| `UnavailableException` | 503 |
| `ApiConnectionException` | нет связи |
| `ApiTimeoutException` | API не ответил за таймаут (наследник `ApiConnectionException`) |
| `PdfApiException` с `errorCode === 'not_found'` | 404: неверный базовый адрес — обычно в нём забыли `/api` |

Неверные аргументы (пустой HTML, `'20 mm'` вместо `'20mm'`, адрес без `https://`, файл `.pdf` вместо `.docx`) — `\InvalidArgumentException` до запроса; лимит частоты на них не тратится.

## Повторы

SDK сам повторяет запрос, когда это безопасно — по умолчанию до 2 раз (`maxRetries`):
- **429** — через `Retry-After` (или `retry_after_sec` из ответа), но не дольше минуты;
- **503** и **не удалось соединиться** — через 0,5 и 1 с (с небольшим разбросом).

**Таймаут и обрыв посреди ответа не повторяются:** сервер мог успеть сделать PDF и списать страницы, и повтор списал бы их второй раз.

## Настройки клиента

```php
$client = new Client(
    apiKey: 'pdf_live_...',               // иначе PDFAPI_KEY
    baseUrl: 'https://pdfapi.ru/api',     // иначе PDFAPI_BASE_URL
    timeout: 40.0,                        // секунд на запрос; для одного вызова — html(..., timeout: 10.0)
    maxRetries: 2,
    transport: $myTransport,              // свой PdfApi\Http\Transport: прокси, PSR-18
);
```

Таймаут не больше нуля или отрицательное число повторов — `\InvalidArgumentException` сразу.

Ключ скрыт отовсюду:
- его нет в `var_dump` и `print_r` клиента;
- в трассировках исключений он помечен `#[\SensitiveParameter]`.

## Примеры

В папке [`examples/`](examples); запуск из папки SDK — `composer install && PDFAPI_KEY=pdf_live_... php examples/<файл>`:

| Файл | Что показывает |
|---|---|
| `invoice.php` | счёт из HTML-шаблона с экранированием данных |
| `from-url.php` | страница по адресу, альбомная, с ожиданием скриптов |
| `office.php` | docx, xlsx или pptx в PDF рядом с исходным файлом |
| `batch.php` | пачка документов по очереди — при 429 SDK сам подождёт |
| `quota.php` | остаток страниц и дата обнуления — для cron, код выхода 2 при нехватке |
| `web.php` | отдача PDF без фреймворка: `php -S 127.0.0.1:8000 examples/web.php` |

## Разработка

```bash
composer install
vendor/bin/phpunit                    # юнит-тесты и общие кейсы (../tests/cases.json)
PDFAPI_IT_KEY=pdf_live_... PDFAPI_IT_BASE_URL=http://localhost:18080/api vendor/bin/phpunit --group integration
```

Общий контракт SDK — [`../SPEC.md`](../SPEC.md).
