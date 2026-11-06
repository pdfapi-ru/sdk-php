<?php

declare(strict_types=1);

namespace PdfApi\Exception;

/** API не ответил за отведённое время. */
class ApiTimeoutException extends ApiConnectionException
{
}
