<?php

declare(strict_types=1);

namespace PdfApi;

/**
 * Готовый PDF: $content — байты файла, $pages — сколько страниц списано,
 * $quota — остаток квоты. Нет заголовков — null.
 */
final class Result
{
    public function __construct(
        public readonly string $content,
        public readonly ?int $pages,
        public readonly ?Quota $quota,
    ) {
    }

    /**
     * Записать PDF в файл и вернуть путь.
     *
     * Атомарно: сначала во временный файл рядом, затем переименование поверх —
     * оборвётся запись, на месте останется прежний файл, а не обрезанный PDF.
     * Права — как у обычного нового файла (с учётом umask). Каталог не создаётся.
     *
     * @throws \RuntimeException если записать не удалось
     */
    public function save(string $path): string
    {
        $tmp = dirname($path) . DIRECTORY_SEPARATOR . '.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        error_clear_last();
        $file = @fopen($tmp, 'xb'); // x — только новый файл; права как у обычного, с учётом umask
        if ($file === false) {
            throw new \RuntimeException("Не удалось записать {$path}: " . self::lastError());
        }
        try {
            try {
                $written = fwrite($file, $this->content) === strlen($this->content) && fflush($file) && self::sync($file);
            } finally {
                $closed = fclose($file);
            }
            if (!$written || !$closed || !@rename($tmp, $path)) {
                throw new \RuntimeException("Не удалось записать {$path}: " . self::lastError());
            }
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        }
        return $path;
    }

    private static function lastError(): string
    {
        return error_get_last()['message'] ?? 'ошибка записи';
    }

    /**
     * Сброс файла на диск; свойство, чтобы тесты могли изобразить сбой.
     *
     * @internal
     * @var (\Closure(resource): bool)|null
     */
    public static ?\Closure $syncFile = null;

    /** @param resource $file */
    private static function sync($file): bool
    {
        return self::$syncFile !== null ? (self::$syncFile)($file) : fsync($file);
    }

    /** @param array<string, string> $headers */
    public static function fromResponse(array $headers, string $body): self
    {
        $int = static fn (string $name): ?int =>
            isset($headers[$name]) && ctype_digit($headers[$name]) ? (int) $headers[$name] : null;
        [$limit, $used, $remaining] = [$int('x-quota-limit'), $int('x-quota-used'), $int('x-quota-remaining')];
        $quota = $limit !== null && $used !== null && $remaining !== null ? new Quota($limit, $used, $remaining) : null;
        return new self($body, $int('x-pages-rendered'), $quota);
    }

    /** Байты PDF в var_dump не нужны. */
    public function __debugInfo(): array
    {
        return ['pages' => $this->pages, 'size' => strlen($this->content), 'quota' => $this->quota];
    }
}
