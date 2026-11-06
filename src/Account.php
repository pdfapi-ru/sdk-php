<?php

declare(strict_types=1);

namespace PdfApi;

/** Тариф и расход — ответ GET /v1/me. */
final class Account
{
    /**
     * @param \DateTimeImmutable|null $periodStart начало периода, UTC; null — сервер не сообщил
     * @param \DateTimeImmutable|null $periodEnd когда счётчик страниц начнётся с нуля, UTC; null — сервер не сообщил
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $plan,
        public readonly ?\DateTimeImmutable $periodStart,
        public readonly ?\DateTimeImmutable $periodEnd,
        public readonly int $pagesUsed,
        public readonly int $pagesLimit,
        public readonly int $pagesRemaining,
        public readonly float $requestsPerSecond,
        public readonly int $burst,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $limits = is_array($data['limits'] ?? null) ? $data['limits'] : [];
        // Старый API присылал только дату начала — «2026-10-01», это полночь UTC.
        $period = $data['period'] ?? null;
        $legacyStart = is_string($period) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $period) === 1 ? "{$period}T00:00:00Z" : null;
        return new self(
            (string) ($data['user_id'] ?? ''),
            (string) ($data['plan'] ?? ''),
            self::date($data['period_start'] ?? null) ?? self::date($legacyStart),
            self::date($data['period_end'] ?? null),
            (int) ($usage['pages_used'] ?? 0),
            (int) ($usage['pages_limit'] ?? 0),
            (int) ($usage['pages_remaining'] ?? 0),
            (float) ($limits['requests_per_second'] ?? 0),
            (int) ($limits['burst'] ?? 0),
        );
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
