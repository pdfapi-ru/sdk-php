<?php

declare(strict_types=1);

namespace PdfApi\Exception;

/** 413: тело запроса больше 5 МБ. */
class PayloadTooLargeException extends InvalidRequestException
{
}
