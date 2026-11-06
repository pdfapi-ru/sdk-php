<?php

declare(strict_types=1);

namespace PdfApi\Http;

/** Транспорт на ext-curl — без сторонних зависимостей. */
final class CurlTransport implements Transport
{
    /** Ошибки curl, при которых соединение не установилось и запрос не ушёл. */
    private const NOT_SENT = [
        5,  // CURLE_COULDNT_RESOLVE_PROXY
        6,  // CURLE_COULDNT_RESOLVE_HOST
        7,  // CURLE_COULDNT_CONNECT
    ];
    private const TIMEOUT = 28; // CURLE_OPERATION_TIMEDOUT

    public function send(
        string $method,
        string $url,
        #[\SensitiveParameter] array $headers,
        ?string $body,
        float $timeout,
    ): Response {
        $responseHeaders = [];
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round(min($timeout, 10.0) * 1000),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = []; // 100 Continue и т. п. — берём заголовки последнего ответа
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($ch);
        if ($result === false) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            // Таймаут до установки соединения — запрос тоже не ушёл.
            $connected = (float) curl_getinfo($ch, CURLINFO_CONNECT_TIME) > 0;
            throw new TransportException(
                $error,
                notSent: in_array($errno, self::NOT_SENT, true) || ($errno === self::TIMEOUT && !$connected),
                timeout: $errno === self::TIMEOUT && $connected,
            );
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return new Response($status, $responseHeaders, (string) $result);
    }
}
