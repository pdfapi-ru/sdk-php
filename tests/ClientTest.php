<?php

declare(strict_types=1);

namespace PdfApi\Tests;

use PdfApi\Client;
use PdfApi\Exception\ApiConnectionException;
use PdfApi\Exception\ApiTimeoutException;
use PdfApi\Exception\AuthenticationException;
use PdfApi\Exception\InvalidRequestException;
use PdfApi\Exception\PayloadTooLargeException;
use PdfApi\Exception\RenderException;
use PdfApi\Exception\RenderTimeoutException;
use PdfApi\Exception\UnavailableException;
use PdfApi\Http\CurlTransport;
use PdfApi\Http\Response;
use PdfApi\Http\TransportException;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const KEY = 'pdf_live_secret_test_key_123';

    protected function setUp(): void
    {
        putenv('PDFAPI_KEY');
        putenv('PDFAPI_BASE_URL');
    }

    private static function pdf(): Response
    {
        return new Response(200, ['x-pages-rendered' => '1'], '%PDF-1.4');
    }

    private static function client(FakeTransport $t, array &$sleeps = [], int $maxRetries = 2): Client
    {
        return new Client(self::KEY, 'https://pdfapi.test/api/', maxRetries: $maxRetries, transport: $t,
            sleep: function (float $s) use (&$sleeps): void { $sleeps[] = $s; });
    }

    public function testNoKeyFailsAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PDFAPI_KEY');
        new Client();
    }

    public function testSettingsFromEnv(): void
    {
        putenv('PDFAPI_KEY=pdf_live_env');
        putenv('PDFAPI_BASE_URL=http://localhost:18080/api/');
        $t = new FakeTransport([self::pdf()]);
        $c = new Client(transport: $t);
        $c->html('<p>x</p>');
        self::assertSame('http://localhost:18080/api', $c->baseUrl);
        self::assertSame('Bearer pdf_live_env', $t->requests[0]['headers']['Authorization']);
        putenv('PDFAPI_BASE_URL');
        self::assertSame(Client::DEFAULT_BASE_URL, (new Client(self::KEY))->baseUrl);
    }

    public function testHeaders(): void
    {
        $t = new FakeTransport([self::pdf()]);
        self::client($t)->html('<p>x</p>');
        $r = $t->requests[0];
        self::assertSame('https://pdfapi.test/api/v1/convert', $r['url']);
        self::assertSame('Bearer ' . self::KEY, $r['headers']['Authorization']);
        self::assertStringStartsWith('pdfapi-php/' . Client::VERSION . ' (php ', $r['headers']['User-Agent']);
    }

    public function testKeyNeverLeaks(): void
    {
        ini_set('zend.exception_ignore_args', '0'); // как в разработке: аргументы попадают в трассировку
        $t = new FakeTransport([new Response(401, [], '{"error":"invalid_api_key"}')]);
        $client = self::client($t);
        self::assertStringNotContainsString(self::KEY, print_r($client, true));
        ob_start();
        var_dump($client);
        self::assertStringNotContainsString(self::KEY, (string) ob_get_clean());
        try {
            $client->html('<p>x</p>');
            self::fail('ждали исключение');
        } catch (AuthenticationException $e) {
            self::assertStringNotContainsString(self::KEY, (string) $e);
            self::assertStringNotContainsString(self::KEY, $e->getTraceAsString());
        }
    }

    public function testKeyNeverLeaksThroughTransportTrace(): void
    {
        // Исходное исключение транспорта помнит аргументы вызова, включая
        // заголовки с ключом. #[\SensitiveParameter] (PHP 8.2+) их скрывает.
        ini_set('zend.exception_ignore_args', '0');
        $t = new FakeTransport([new TransportException('reset by peer')]);
        try {
            self::client($t)->html('<p>x</p>');
            self::fail('ждали исключение');
        } catch (ApiConnectionException $e) {
            $all = (string) $e . $e->getTraceAsString() . $e->getPrevious()?->getTraceAsString() . print_r($e->getPrevious()?->getTrace(), true);
            self::assertStringNotContainsString(self::KEY, $all);
        }
    }

    public function testExceptionHierarchy(): void
    {
        self::assertInstanceOf(InvalidRequestException::class, new PayloadTooLargeException('x', 'payload_too_large'));
        self::assertInstanceOf(RenderException::class, new RenderTimeoutException('x', 'render_timeout'));
        self::assertInstanceOf(ApiConnectionException::class, new ApiTimeoutException('x', 'timeout'));
    }

    public function testOptionsAreSentAsGiven(): void
    {
        $t = new FakeTransport([self::pdf()]);
        self::client($t)->html('<p>x</p>', landscape: false, marginTop: '20mm', waitDelay: '1s');
        self::assertSame(['landscape' => false, 'marginTop' => '20mm', 'waitDelay' => '1s'],
            json_decode($t->requests[0]['body'], true)['options']);
    }

    public function testBadArgumentsFailBeforeRequest(): void
    {
        $t = new FakeTransport([self::pdf()]);
        $c = self::client($t);
        foreach ([
            fn () => $c->html('<p>x</p>', marginTop: '  '),
            fn () => $c->markdown(' '),
            fn () => $c->officeFromString('%PDF', 'scan.pdf'),
            fn () => $c->officeFromString("PK\x03\x04" . str_repeat('0', Client::OFFICE_MAX_BYTES), 'big.pptx'),
            fn () => $c->html('<p>x</p>', marginTop: '20'),
            fn () => $c->html('<p>x</p>', waitDelay: '11s'),
            fn () => $c->html('<p>x</p>', timeout: 0),
            fn () => $c->office('/нет/такого/файла.docx'),
        ] as $call) {
            try {
                $call();
                self::fail('ждали InvalidArgumentException');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertCount(0, $t->requests);
    }

    public function testOfficeFromFile(): void
    {
        $path = sys_get_temp_dir() . '/Отчёт-' . bin2hex(random_bytes(4)) . '.docx';
        file_put_contents($path, "PK\x03\x04docx");
        $t = new FakeTransport([self::pdf()]);
        self::client($t)->office($path, landscape: true);
        unlink($path);
        $body = json_decode($t->requests[0]['body'], true);
        self::assertSame(basename($path), $body['filename']);
        self::assertSame(base64_encode("PK\x03\x04docx"), $body['fileBase64']);
        self::assertSame(['landscape' => true], $body['options']);
    }

    public function testBadSettingsFailAtConstruction(): void
    {
        foreach ([['timeout' => 0.0], ['timeout' => -1.0], ['timeout' => INF], ['timeout' => NAN], ['maxRetries' => -1]] as $args) {
            try {
                new Client(self::KEY, ...$args);
                self::fail('ждали InvalidArgumentException: ' . json_encode(array_keys($args)));
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertTrue(true);
    }

    public function testPerCallTimeoutAndCharset(): void
    {
        $t = new FakeTransport([self::pdf()]);
        self::client($t)->html('<p>x</p>', timeout: 5.0);
        self::assertSame(5.0, $t->requests[0]['timeout']);
        self::assertSame('application/json; charset=utf-8', $t->requests[0]['headers']['Content-Type']);
    }

    public function testSaveReplacesFileAndLeavesNoTemp(): void
    {
        $dir = self::tempDir();
        file_put_contents("{$dir}/out.pdf", 'old content that is longer than the new one');
        self::assertSame("{$dir}/out.pdf", (new \PdfApi\Result('%PDF-new', 1, null))->save("{$dir}/out.pdf"));
        self::assertSame('%PDF-new', file_get_contents("{$dir}/out.pdf"));
        self::assertSame(['out.pdf'], array_values(array_diff(scandir($dir), ['.', '..'])));
    }

    public function testSaveFailureKeepsOldFile(): void
    {
        $dir = self::tempDir();
        file_put_contents("{$dir}/out.pdf", '%PDF-old');
        \PdfApi\Result::$syncFile = static fn ($file): bool => false; // диск отвалился
        try {
            (new \PdfApi\Result('%PDF-new', 1, null))->save("{$dir}/out.pdf");
            self::fail('ждали RuntimeException');
        } catch (\RuntimeException) {
        } finally {
            \PdfApi\Result::$syncFile = null;
        }
        self::assertSame('%PDF-old', file_get_contents("{$dir}/out.pdf"));
        self::assertSame(['out.pdf'], array_values(array_diff(scandir($dir), ['.', '..'])));
    }

    public function testSavePermissionsAndMissingDirectory(): void
    {
        $dir = self::tempDir();
        (new \PdfApi\Result('%PDF', 1, null))->save("{$dir}/out.pdf");
        self::assertSame(0o666 & ~umask(), fileperms("{$dir}/out.pdf") & 0o777);
        try {
            (new \PdfApi\Result('%PDF', 1, null))->save("{$dir}/нет/out.pdf");
            self::fail('ждали RuntimeException');
        } catch (\RuntimeException) {
        }
        self::assertFalse(is_dir("{$dir}/нет"));
    }

    private static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/pdfapi-' . bin2hex(random_bytes(4));
        mkdir($dir);
        return $dir;
    }

    public function testSaveAndDebugInfo(): void
    {
        $result = self::client(new FakeTransport([self::pdf()]))->html('<p>x</p>');
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        $result->save($path);
        self::assertSame('%PDF-1.4', file_get_contents($path));
        unlink($path);
        self::assertStringNotContainsString('%PDF', print_r($result, true));
    }

    public function testConnectionErrorBeforeSendingIsRetried(): void
    {
        $sleeps = [];
        $t = new FakeTransport([new TransportException('refused', notSent: true), self::pdf()]);
        self::assertSame(1, self::client($t, $sleeps)->html('<p>x</p>')->pages);
        self::assertCount(2, $t->requests);
        self::assertCount(1, $sleeps);
        self::assertGreaterThanOrEqual(0.375, $sleeps[0]);
        self::assertLessThanOrEqual(0.625, $sleeps[0]);
    }

    public function testConnectionErrorAfterRetries(): void
    {
        $t = new FakeTransport([new TransportException('refused', notSent: true)]);
        $this->expectException(ApiConnectionException::class);
        try {
            self::client($t)->html('<p>x</p>');
        } finally {
            self::assertCount(3, $t->requests);
        }
    }

    public function testTimeoutAndBrokenResponseAreNotRetried(): void
    {
        // Сервер мог успеть отрендерить и списать страницы — повтор списал бы их дважды.
        foreach ([
            [new TransportException('timeout', timeout: true), ApiTimeoutException::class],
            [new TransportException('reset by peer'), ApiConnectionException::class],
        ] as [$error, $class]) {
            $sleeps = [];
            $t = new FakeTransport([$error, self::pdf()]);
            try {
                self::client($t, $sleeps)->html('<p>x</p>');
                self::fail('ждали исключение');
            } catch (ApiConnectionException $e) {
                self::assertSame($class, $e::class);
            }
            self::assertCount(1, $t->requests);
            self::assertSame([], $sleeps);
        }
    }

    public function testMaxRetriesZero(): void
    {
        $t = new FakeTransport([new Response(503, [], '{"error":"unavailable"}'), self::pdf()]);
        $this->expectException(UnavailableException::class);
        try {
            self::client($t, maxRetries: 0)->html('<p>x</p>');
        } finally {
            self::assertCount(1, $t->requests);
        }
    }

    public function testCurlTransportReportsRefusedConnectionAsNotSent(): void
    {
        try {
            (new CurlTransport())->send('GET', 'http://127.0.0.1:1/', [], null, 3.0);
            self::fail('ждали TransportException');
        } catch (TransportException $e) {
            self::assertTrue($e->notSent);
            self::assertFalse($e->timeout);
        }
    }
}
