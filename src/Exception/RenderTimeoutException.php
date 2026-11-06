<?php

declare(strict_types=1);

namespace PdfApi\Exception;

/** 504: рендер не уложился в 30 секунд. */
class RenderTimeoutException extends RenderException
{
}
