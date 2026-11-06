<?php

declare(strict_types=1);

namespace PdfApi\Exception;

/** 502: документ не отрендерился — обычно дело в разметке. */
class RenderException extends PdfApiException
{
}
