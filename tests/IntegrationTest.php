<?php

declare(strict_types=1);

namespace PdfApi\Tests;

use PdfApi\Client;
use PdfApi\Exception\AuthenticationException;
use PdfApi\Exception\InvalidRequestException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Против настоящего API:
 *     PDFAPI_IT_KEY=pdf_live_... PDFAPI_IT_BASE_URL=http://localhost:18080/api vendor/bin/phpunit --group integration
 */
#[Group('integration')]
final class IntegrationTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $key = getenv('PDFAPI_IT_KEY');
        $base = getenv('PDFAPI_IT_BASE_URL');
        if (!$key || !$base) {
            self::markTestSkipped('нужны PDFAPI_IT_KEY и PDFAPI_IT_BASE_URL');
        }
        $this->client = new Client($key, $base);
    }

    public function testHtmlToPdf(): void
    {
        $pdf = $this->client->html("<h1>PHP SDK</h1><div style='page-break-before:always'>2</div>");
        self::assertStringStartsWith('%PDF', $pdf->content);
        self::assertSame(2, $pdf->pages);
        self::assertNotNull($pdf->quota);
    }

    public function testAllFormatsAndSave(): void
    {
        $fixtures = __DIR__ . '/../../tests/fixtures';
        if (!is_dir($fixtures)) {
            self::markTestSkipped('нет sdk/tests/fixtures');
        }
        $results = [
            $this->client->markdown("# Отчёт\n\n- пункт один\n- пункт два"),
            $this->client->url('https://example.com', waitDelay: '500ms'),
            $this->client->office("{$fixtures}/report.docx"),
            $this->client->office("{$fixtures}/table.xlsx", landscape: true),
            $this->client->officeFromString((string) file_get_contents("{$fixtures}/slides.pptx"), 'slides.pptx'),
        ];
        foreach ($results as $pdf) {
            self::assertStringStartsWith('%PDF', $pdf->content);
            self::assertGreaterThanOrEqual(1, $pdf->pages);
        }
        $path = sys_get_temp_dir() . '/pdfapi-it-' . bin2hex(random_bytes(4)) . '.pdf';
        self::assertSame($path, $results[0]->save($path));
        self::assertSame($results[0]->content, file_get_contents($path));
        unlink($path);
    }

    public function testMeAndErrors(): void
    {
        $me = $this->client->me();
        self::assertGreaterThan(0, $me->pagesLimit);
        self::assertNotSame('', $me->userId);
        self::assertSame($me->pagesLimit, $me->pagesUsed + $me->pagesRemaining);
        self::assertGreaterThan($me->periodStart, $me->periodEnd);
        try {
            (new Client('pdf_live_wrong', $this->client->baseUrl))->html('<p>x</p>');
            self::fail('ждали AuthenticationException');
        } catch (AuthenticationException) {
        }
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('foo');
        $this->client->convert(['source' => 'html', 'html' => '<p>x</p>', 'options' => ['foo' => 'bar']]);
    }
}
