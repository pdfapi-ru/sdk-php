<?php

declare(strict_types=1);

namespace PdfApi\Exception;

/** 402: страницы периода кончились или документ больше остатка. */
class QuotaExceededException extends PdfApiException
{
}
