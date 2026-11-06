<?php

declare(strict_types=1);

namespace PdfApi\Exception;

/**
 * Ошибка pdfapi: код из ответа API, HTTP-статус и текст (см. sdk/SPEC.md).
 *
 * Код API — в $errorCode (getCode() у исключений PHP — число, занят).
 */
class PdfApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly ?int $status = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function __toString(): string
    {
        $where = $this->status !== null ? "HTTP {$this->status}, " : '';
        return static::class . ": {$this->getMessage()} ({$where}{$this->errorCode})";
    }
}
