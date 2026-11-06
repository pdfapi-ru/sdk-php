<?php

declare(strict_types=1);

namespace PdfApi\Http;

/**
 * Как отправить запрос. По умолчанию — CurlTransport; свой нужен для прокси,
 * PSR-18 клиента или тестов.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers
     * @throws TransportException когда ответа нет
     */
    public function send(
        string $method,
        string $url,
        #[\SensitiveParameter] array $headers,
        ?string $body,
        float $timeout,
    ): Response;
}
