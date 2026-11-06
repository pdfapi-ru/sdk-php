<?php

declare(strict_types=1);

namespace PdfApi\Exception;

/** 429: слишком часто. $retryAfter — через сколько секунд можно снова. */
class RateLimitException extends PdfApiException
{
    public function __construct(
        string $message,
        string $errorCode,
        ?int $status = null,
        public readonly ?float $retryAfter = null,
    ) {
        parent::__construct($message, $errorCode, $status);
    }
}
