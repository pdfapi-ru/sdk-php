<?php

declare(strict_types=1);

namespace PdfApi\Exception;

/**
 * Ответ API с ошибкой → исключение нужного класса (таблица — sdk/SPEC.md).
 *
 * @internal
 */
final class ErrorFactory
{
    private const BY_CODE = [
        'missing_api_key' => AuthenticationException::class,
        'invalid_api_key' => AuthenticationException::class,
        'email_not_verified' => PermissionDeniedException::class,
        'source_not_allowed' => PermissionDeniedException::class,
        'quota_exceeded' => QuotaExceededException::class,
        'invalid_request' => InvalidRequestException::class,
        'url_not_allowed' => InvalidRequestException::class,
        'payload_too_large' => PayloadTooLargeException::class,
        'rate_limit_exceeded' => RateLimitException::class,
        'render_failed' => RenderException::class,
        'render_timeout' => RenderTimeoutException::class,
        'unavailable' => UnavailableException::class,
    ];

    // Ответ не от API (Caddy отдаёт 413 текстом, прокси — 502 в HTML, сайт — 404
    // страницей, Fastify — {"error": "Bad Request"}): код по статусу.
    private const CODE_BY_STATUS = [
        400 => 'invalid_request', 401 => 'invalid_api_key', 402 => 'quota_exceeded',
        403 => 'source_not_allowed', 404 => 'not_found', 413 => 'payload_too_large',
        429 => 'rate_limit_exceeded', 502 => 'render_failed', 503 => 'unavailable', 504 => 'render_timeout',
    ];

    /** Код API — quota_exceeded, а не название статуса вроде «Bad Request». */
    private const API_CODE = '/^[a-z][a-z0-9_]*$/D';

    private const MESSAGES = [
        'missing_api_key' => 'Не передан ключ API.',
        'invalid_api_key' => 'Неверный или отозванный ключ API.',
        'email_not_verified' => 'Почта аккаунта не подтверждена.',
        'source_not_allowed' => 'Этот формат недоступен на вашем тарифе.',
        'quota_exceeded' => 'Лимит страниц исчерпан.',
        'invalid_request' => 'Некорректный запрос.',
        'url_not_allowed' => 'Адрес ведёт во внутреннюю сеть и запрещён.',
        'payload_too_large' => 'Тело запроса больше 5 МБ.',
        'rate_limit_exceeded' => 'Слишком много запросов.',
        'render_failed' => 'Не удалось создать PDF.',
        'render_timeout' => 'Рендер не уложился в 30 секунд.',
        'unavailable' => 'Сервис временно недоступен.',
        'server_error' => 'Внутренняя ошибка сервера.',
        'not_found' => 'Адрес не найден (HTTP 404). Проверьте базовый адрес: он ведёт на API, например https://pdfapi.ru/api.',
    ];

    /** @param array<string, string> $headers заголовки в нижнем регистре */
    public static function fromResponse(int $status, array $headers, string $body): PdfApiException
    {
        $data = self::parse($body);
        $code = is_string($data['error'] ?? null) && preg_match(self::API_CODE, $data['error']) === 1
            ? $data['error']
            : (self::CODE_BY_STATUS[$status] ?? ($status >= 500 ? 'server_error' : 'unknown_error'));
        $message = is_string($data['message'] ?? null) && trim($data['message']) !== ''
            ? $data['message']
            : (self::MESSAGES[$code] ?? "Ошибка API (HTTP {$status}).");

        $class = self::BY_CODE[$code] ?? PdfApiException::class;
        if ($class === RateLimitException::class) {
            return new RateLimitException($message, $code, $status, self::retryAfterSeconds($headers, $body));
        }
        return new $class($message, $code, $status);
    }

    /**
     * Через сколько секунд можно снова: заголовок Retry-After, иначе retry_after_sec из тела.
     *
     * @param array<string, string> $headers
     */
    public static function retryAfterSeconds(array $headers, string $body): ?float
    {
        $after = self::retryAfter($headers);
        if ($after !== null) {
            return $after;
        }
        $value = self::parse($body)['retry_after_sec'] ?? null;
        return (is_int($value) || is_float($value)) && $value >= 0 ? (float) $value : null;
    }

    /** @return array<string, mixed> */
    private static function parse(string $body): array
    {
        $data = json_decode($body, true);
        return is_array($data) ? $data : []; // не JSON — разберёмся по статусу
    }

    /** @param array<string, string> $headers */
    public static function retryAfter(array $headers): ?float
    {
        $value = trim($headers['retry-after'] ?? '');
        return is_numeric($value) && (float) $value >= 0 ? (float) $value : null;
    }
}
