<?php

declare(strict_types=1);

namespace PdfApi\Exception;

/** 401: ключа нет, он неверный или отозван. */
class AuthenticationException extends PdfApiException
{
}
