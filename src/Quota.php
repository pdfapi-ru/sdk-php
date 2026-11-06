<?php

declare(strict_types=1);

namespace PdfApi;

/** Квота периода после конвертации — из заголовков X-Quota-*. */
final class Quota
{
    public function __construct(
        public readonly int $limit,
        public readonly int $used,
        public readonly int $remaining,
    ) {
    }
}
