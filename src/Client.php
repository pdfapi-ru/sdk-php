<?php

declare(strict_types=1);

namespace PdfApi;

use PdfApi\Exception\ApiConnectionException;
use PdfApi\Exception\ApiTimeoutException;
use PdfApi\Exception\ErrorFactory;
use PdfApi\Exception\PdfApiException;
use PdfApi\Http\CurlTransport;
use PdfApi\Http\Response;
use PdfApi\Http\Transport;
use PdfApi\Http\TransportException;

/**
 * Клиент pdfapi.
 *
 *     $client = new \PdfApi\Client();               // ключ из PDFAPI_KEY
 *     $pdf = $client->html('<h1>Счёт №42</h1>', marginTop: '20mm');
 *     $pdf->save('invoice.pdf');
 *
 * Поведение одинаково с SDK на других языках — см. sdk/SPEC.md.
 */
final class Client
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://pdfapi.ru/api';
    public const DEFAULT_TIMEOUT = 40.0;      // рендер — до 30 с, плюс запас
    public const DEFAULT_MAX_RETRIES = 2;
    /** Тело запроса ограничено 5 МБ, а base64 раздувает файл на треть. */
    public const OFFICE_MAX_BYTES = 3_700_000;
    private const MAX_RETRY_AFTER = 60.0;
    private const OFFICE_EXTENSIONS = ['docx', 'xlsx', 'pptx'];
    /** docx, xlsx и pptx — ZIP-архивы, начинаются с «PK\x03\x04». */
    private const ZIP_MAGIC = "PK\x03\x04";
    /** Число и единица, как понимает рендер. Без единицы сервер считает дюймами, но "20" почти всегда задумано как мм. */
    private const SIZE = '/^(\d+(\.\d*)?|\.\d+)(pt|px|in|mm|cm|pc)$/D';
    /** waitDelay — как на сервере: число и s или ms, не больше 10 секунд. */
    private const WAIT_DELAY = '/^(\d+(?:\.\d+)?)(ms|s)$/D';
    private const MAX_WAIT_DELAY = 10.0;

    public readonly string $baseUrl;
    private readonly string $apiKey;
    private readonly Transport $transport;
    /** @var callable(float): void */
    private $sleep;

    /**
     * @param string|null $apiKey ключ API; по умолчанию — переменная окружения PDFAPI_KEY
     * @param string|null $baseUrl адрес API; по умолчанию PDFAPI_BASE_URL или https://pdfapi.ru/api
     * @param float $timeout таймаут одного запроса, секунд; у каждого метода есть и свой timeout — для одного вызова
     * @param int $maxRetries сколько раз повторять 429, 503 и ошибки соединения
     * @param Transport|null $transport свой транспорт (прокси, PSR-18, тесты)
     * @param (callable(float): void)|null $sleep пауза между повторами (для тестов)
     */
    public function __construct(
        #[\SensitiveParameter] ?string $apiKey = null,
        ?string $baseUrl = null,
        public readonly float $timeout = self::DEFAULT_TIMEOUT,
        public readonly int $maxRetries = self::DEFAULT_MAX_RETRIES,
        ?Transport $transport = null,
        ?callable $sleep = null,
    ) {
        $key = trim($apiKey ?? (string) getenv('PDFAPI_KEY'));
        if ($key === '') {
            throw new \InvalidArgumentException(
                'Не задан ключ API: передайте apiKey или задайте переменную окружения PDFAPI_KEY.'
            );
        }
        $this->apiKey = $key;
        self::checkTimeout($timeout);
        if ($maxRetries < 0) {
            throw new \InvalidArgumentException("maxRetries: {$maxRetries} — нужно целое число от 0");
        }
        $url = $baseUrl ?: (getenv('PDFAPI_BASE_URL') ?: self::DEFAULT_BASE_URL);
        $this->baseUrl = rtrim($url, '/');
        $this->transport = $transport ?? new CurlTransport();
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    // ---------------------------------------------------------------- конвертация

    /**
     * HTML → PDF. Размеры — строками с единицами: "20mm", "8.27in"; waitDelay: "2s".
     */
    public function html(
        string $html,
        ?bool $landscape = null,
        ?string $paperWidth = null,
        ?string $paperHeight = null,
        ?string $marginTop = null,
        ?string $marginBottom = null,
        ?string $marginLeft = null,
        ?string $marginRight = null,
        ?string $waitDelay = null,
        ?float $timeout = null,
    ): Result {
        return $this->convert(self::textBody('html', $html, self::pageOptions(get_defined_vars())), $timeout);
    }

    /** Страница по публичному адресу → PDF (тариф premium и выше). */
    public function url(
        string $url,
        ?bool $landscape = null,
        ?string $paperWidth = null,
        ?string $paperHeight = null,
        ?string $marginTop = null,
        ?string $marginBottom = null,
        ?string $marginLeft = null,
        ?string $marginRight = null,
        ?string $waitDelay = null,
        ?float $timeout = null,
    ): Result {
        return $this->convert(self::textBody('url', $url, self::pageOptions(get_defined_vars())), $timeout);
    }

    /** Markdown → PDF (тариф premium и выше). */
    public function markdown(
        string $markdown,
        ?bool $landscape = null,
        ?string $paperWidth = null,
        ?string $paperHeight = null,
        ?string $marginTop = null,
        ?string $marginBottom = null,
        ?string $marginLeft = null,
        ?string $marginRight = null,
        ?string $waitDelay = null,
        ?float $timeout = null,
    ): Result {
        return $this->convert(self::textBody('markdown', $markdown, self::pageOptions(get_defined_vars())), $timeout);
    }

    /** docx, xlsx или pptx с диска → PDF. */
    public function office(string $path, ?bool $landscape = null, ?float $timeout = null): Result
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new \InvalidArgumentException("Не удалось прочитать файл {$path}");
        }
        return $this->officeFromString($data, basename($path), $landscape, $timeout);
    }

    /** docx, xlsx или pptx из памяти → PDF. По расширению $filename сервер понимает формат. */
    public function officeFromString(string $data, string $filename, ?bool $landscape = null, ?float $timeout = null): Result
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, self::OFFICE_EXTENSIONS, true)) {
            throw new \InvalidArgumentException("{$filename}: поддерживаются только .docx, .xlsx и .pptx");
        }
        if ($data === '') {
            throw new \InvalidArgumentException("{$filename}: файл пустой");
        }
        if (!str_starts_with($data, self::ZIP_MAGIC)) {
            // Старый .doc, PDF или картинка с чужим расширением: сервер всё равно отклонит.
            throw new \InvalidArgumentException("{$filename}: не похож на docx, xlsx или pptx — это не ZIP-архив");
        }
        if (strlen($data) > self::OFFICE_MAX_BYTES) {
            $mb = number_format(strlen($data) / 1_000_000, 1, ',', '');
            throw new \InvalidArgumentException(
                "{$filename}: {$mb} МБ — больше предела 3,7 МБ (после base64 тело запроса превысило бы 5 МБ)"
            );
        }
        $body = ['source' => 'office', 'filename' => basename($filename), 'fileBase64' => base64_encode($data)];
        if ($landscape !== null) {
            $body['options'] = ['landscape' => $landscape];
        }
        return $this->convert($body, $timeout);
    }

    /**
     * Низкоуровневый POST /v1/convert с телом как есть.
     *
     * @param array<string, mixed> $body
     */
    public function convert(array $body, ?float $timeout = null): Result
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $response = $this->send('POST', '/v1/convert', $json, $timeout);
        return Result::fromResponse($response->headers, $response->body);
    }

    /** Тариф, расход, остаток страниц и границы текущего периода. */
    public function me(?float $timeout = null): Account
    {
        $data = json_decode($this->send('GET', '/v1/me', null, $timeout)->body, true);
        if (!is_array($data)) {
            throw new PdfApiException('Ответ /v1/me не JSON.', 'server_error', 200);
        }
        return Account::fromArray($data);
    }

    // ---------------------------------------------------------------- тела запросов

    private const OPTION_NAMES = [
        'paperWidth', 'paperHeight', 'marginTop', 'marginBottom', 'marginLeft', 'marginRight', 'waitDelay',
    ];

    /**
     * Параметры страницы из аргументов метода: только заданные.
     *
     * @param array<string, mixed> $args
     * @return array<string, bool|string>
     */
    private static function pageOptions(array $args): array
    {
        $out = [];
        if ($args['landscape'] !== null) {
            $out['landscape'] = $args['landscape'];
        }
        foreach (self::OPTION_NAMES as $name) {
            $value = $args[$name];
            if ($value === null) {
                continue;
            }
            if ($name === 'waitDelay') {
                $ok = preg_match(self::WAIT_DELAY, $value, $m) === 1
                    && (float) $m[1] / ($m[2] === 'ms' ? 1000 : 1) <= self::MAX_WAIT_DELAY;
                if (!$ok) {
                    throw new \InvalidArgumentException(
                        "waitDelay: \"{$value}\" — нужно от 0 до 10 секунд в s или ms, например \"2s\" или \"500ms\""
                    );
                }
            } elseif (preg_match(self::SIZE, $value) !== 1) {
                // Иначе рендер падает с 502, и опечатка выглядит как сбой сервиса.
                throw new \InvalidArgumentException(
                    "{$name}: \"{$value}\" — нужно число с единицами pt, px, in, mm, cm или pc без пробела, например \"20mm\""
                );
            }
            $out[$name] = $value;
        }
        return $out;
    }

    private static function checkTimeout(float $timeout): float
    {
        if (!is_finite($timeout) || $timeout <= 0) {
            throw new \InvalidArgumentException('timeout: нужно конечное число секунд больше нуля, например 40');
        }
        return $timeout;
    }

    /**
     * @param array<string, bool|string> $options
     * @return array<string, mixed>
     */
    private static function textBody(string $source, string $value, array $options): array
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException("{$source}: пустое содержимое");
        }
        if ($source === 'url') {
            $value = trim($value); // пробелы по краям рендер не прощает
            if (preg_match('~^https?://\S~i', $value) !== 1) {
                throw new \InvalidArgumentException(
                    "url: \"{$value}\" — нужен полный адрес с http:// или https://, например https://example.com"
                );
            }
        }
        $body = ['source' => $source, $source => $value];
        if ($options !== []) {
            $body['options'] = $options;
        }
        return $body;
    }

    // ---------------------------------------------------------------- сеть

    private function send(string $method, string $path, ?string $body, ?float $timeout = null): Response
    {
        $timeout = $timeout === null ? $this->timeout : self::checkTimeout($timeout);
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'User-Agent' => sprintf('pdfapi-php/%s (php %s)', self::VERSION, PHP_VERSION),
            'Accept' => 'application/pdf, application/json',
        ];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json; charset=utf-8';
        }

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->transport->send($method, $this->baseUrl . $path, $headers, $body, $timeout);
            } catch (TransportException $e) {
                if ($e->notSent && $attempt < $this->maxRetries) {
                    ($this->sleep)(self::backoff($attempt));
                    continue;
                }
                throw $e->timeout
                    ? new ApiTimeoutException(
                        "API не ответил за {$timeout} с. Запрос мог быть выполнен — повторять его "
                        . 'автоматически небезопасно: страницы списались бы дважды.',
                        'timeout',
                        null,
                        $e,
                    )
                    : new ApiConnectionException("Нет связи с {$this->baseUrl}: {$e->getMessage()}", 'connection_error', null, $e);
            }

            if ($response->status >= 200 && $response->status < 300) {
                return $response;
            }
            $delay = self::retryDelay($attempt, $response);
            if ($delay !== null && $attempt < $this->maxRetries) {
                ($this->sleep)($delay);
                continue;
            }
            throw ErrorFactory::fromResponse($response->status, $response->headers, $response->body);
        }
    }

    /** 0,5 → 1 → 2 с … с разбросом ±25%, чтобы клиенты не били хором. */
    private static function backoff(int $attempt): float
    {
        return 0.5 * (2 ** $attempt) * (0.75 + mt_rand() / mt_getrandmax() * 0.5);
    }

    /**
     * Через сколько повторить; null — не повторять. Повторяются только 429 и 503:
     * сервер ничего не сделал и страниц не списал.
     */
    private static function retryDelay(int $attempt, Response $response): ?float
    {
        if ($response->status === 429) {
            $after = ErrorFactory::retryAfterSeconds($response->headers, $response->body);
            return min($after ?? self::backoff($attempt), self::MAX_RETRY_AFTER);
        }
        return $response->status === 503 ? self::backoff($attempt) : null;
    }

    /** Ключ не показывается ни в var_dump, ни в print_r. */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'timeout' => $this->timeout, 'maxRetries' => $this->maxRetries];
    }
}
