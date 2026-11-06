<?php

declare(strict_types=1);

namespace PdfApi\Tests;

use PdfApi\Http\Response;
use PdfApi\Http\Transport;

/** Подменный транспорт: отвечает по очереди (Response или исключение) и запоминает запросы. */
final class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeout: float}> */
    public array $requests = [];

    /** @param list<Response|\Throwable> $script */
    public function __construct(private array $script)
    {
    }

    public function send(string $method, string $url, #[\SensitiveParameter] array $headers, ?string $body, float $timeout): Response
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeout];
        $item = $this->script[min(count($this->requests) - 1, count($this->script) - 1)];
        if ($item instanceof \Throwable) {
            throw $item;
        }
        return $item;
    }

    /** Ответ из спецификации общих кейсов (sdk/tests/cases.json). */
    public static function fromSpec(array $spec): Response
    {
        $headers = array_change_key_case($spec['headers'] ?? [], CASE_LOWER);
        if (array_key_exists('json', $spec)) {
            $body = json_encode($spec['json'], JSON_UNESCAPED_UNICODE);
        } elseif (array_key_exists('text', $spec)) {
            $body = $spec['text'];
        } else {
            $body = base64_decode($spec['body_base64'] ?? '');
        }
        return new Response($spec['status'], $headers, $body);
    }
}
