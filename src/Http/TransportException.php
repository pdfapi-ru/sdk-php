<?php

declare(strict_types=1);

namespace PdfApi\Http;

/**
 * Запрос не дошёл до ответа.
 *
 * $notSent — соединение даже не установилось (DNS, отказ в соединении): такой
 * запрос безопасно повторить. Иначе сервер мог успеть отрендерить и списать
 * страницы — повтор списал бы их второй раз.
 */
final class TransportException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $notSent = false,
        public readonly bool $timeout = false,
    ) {
        parent::__construct($message);
    }
}
