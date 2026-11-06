<?php

declare(strict_types=1);

namespace PdfApi\Http;

/** Ответ HTTP: статус, заголовки (имена в нижнем регистре) и тело. */
final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }
}
