<?php

declare(strict_types=1);

namespace PdfApi\Exception;

/** Нет связи с API: сеть, DNS, обрыв соединения. */
class ApiConnectionException extends PdfApiException
{
}
