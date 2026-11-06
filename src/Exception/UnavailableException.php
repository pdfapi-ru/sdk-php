<?php

declare(strict_types=1);

namespace PdfApi\Exception;

/** 503: рендер временно недоступен или очередь полна. */
class UnavailableException extends PdfApiException
{
}
