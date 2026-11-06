<?php

declare(strict_types=1);

namespace PdfApi\Tests;

use PdfApi\Account;
use PdfApi\Client;
use PdfApi\Exception\PdfApiException;
use PdfApi\Exception\RateLimitException;
use PdfApi\Quota;
use PdfApi\Result;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Общие тест-кейсы SDK (sdk/tests/cases.json): что ответил сервер → что сделал SDK. */
final class CasesTest extends TestCase
{
    public static function cases(): iterable
    {
        $file = __DIR__ . '/../../tests/cases.json';
        foreach (json_decode((string) file_get_contents($file), true)['cases'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    #[DataProvider('cases')]
    public function testCase(array $case): void
    {
        $sleeps = [];
        $transport = new FakeTransport(array_map([FakeTransport::class, 'fromSpec'], $case['responses']));
        $client = new Client('pdf_live_test', 'https://pdfapi.test/api', transport: $transport,
            sleep: function (float $s) use (&$sleeps): void { $sleeps[] = $s; });

        $call = $case['call'];
        try {
            $outcome = match ($call['method']) {
                'me' => $client->me(),
                'office' => $client->officeFromString(
                    base64_decode($call['args']['file_base64']), $call['args']['filename'], ...($call['options'] ?? [])),
                default => $client->{$call['method']}(...$call['args'], ...($call['options'] ?? [])),
            };
        } catch (PdfApiException|\InvalidArgumentException $e) {
            $outcome = $e;
        }

        $expect = $case['expect'];
        self::assertCount($expect['attempts'], $transport->requests, 'попыток');

        if (isset($expect['request'])) {
            $req = $transport->requests[0];
            self::assertSame($expect['request']['method'], $req['method']);
            self::assertStringEndsWith($expect['request']['path'], parse_url($req['url'], PHP_URL_PATH));
            if (isset($expect['request']['json'])) {
                self::assertEquals($expect['request']['json'], json_decode((string) $req['body'], true));
            }
            if (isset($expect['request']['raw_contains'])) {
                self::assertStringContainsString($expect['request']['raw_contains'], (string) $req['body']);
            }
        }
        if (isset($expect['result'])) {
            self::assertInstanceOf(Result::class, $outcome);
            self::assertSame(base64_decode($expect['result']['content_base64']), $outcome->content);
            self::assertSame($expect['result']['pages'], $outcome->pages);
            $q = $expect['result']['quota'];
            self::assertEquals($q === null ? null : new Quota($q['limit'], $q['used'], $q['remaining']), $outcome->quota);
        }
        if (isset($expect['me'])) {
            self::assertInstanceOf(Account::class, $outcome);
            $got = [
                'user_id' => $outcome->userId, 'plan' => $outcome->plan,
                'period_start' => $outcome->periodStart?->getTimestamp(), 'period_end' => $outcome->periodEnd?->getTimestamp(),
                'pages_used' => $outcome->pagesUsed, 'pages_limit' => $outcome->pagesLimit,
                'pages_remaining' => $outcome->pagesRemaining, 'requests_per_second' => $outcome->requestsPerSecond,
                'burst' => $outcome->burst,
            ];
            foreach ($expect['me'] as $k => $v) {
                $want = str_starts_with($k, 'period_') && $v !== null ? (new \DateTimeImmutable($v))->getTimestamp() : $v;
                self::assertEquals($want, $got[$k], $k);
            }
        }
        if (isset($expect['argument_error'])) {
            // Ошибка аргумента, а не API: запрос не ушёл.
            self::assertInstanceOf(\InvalidArgumentException::class, $outcome, 'ждали InvalidArgumentException');
            self::assertStringContainsStringIgnoringCase($expect['argument_error']['mentions'], $outcome->getMessage());
        }
        if (isset($expect['error'])) {
            $want = $expect['error'];
            self::assertInstanceOf(PdfApiException::class, $outcome, 'ждали исключение');
            // Классы в кейсах названы как в Python: QuotaExceededError → QuotaExceededException.
            $class = 'PdfApi\\Exception\\' . preg_replace(['/^APIConnection/', '/^APITimeout/', '/Error$/'],
                ['ApiConnection', 'ApiTimeout', 'Exception'], $want['class']);
            self::assertSame($class, $outcome::class);
            self::assertSame($want['code'], $outcome->errorCode);
            self::assertSame($want['status'], $outcome->status);
            if (isset($want['message_contains'])) {
                self::assertStringContainsString($want['message_contains'], $outcome->getMessage());
            }
            if (isset($want['retry_after'])) {
                self::assertInstanceOf(RateLimitException::class, $outcome);
                self::assertEquals($want['retry_after'], $outcome->retryAfter);
            }
        }
        if (isset($expect['sleeps'])) {
            self::assertEquals($expect['sleeps'], $sleeps);
        }
        if (isset($expect['sleeps_between'])) {
            self::assertNotEmpty($sleeps);
            foreach ($sleeps as $s) {
                self::assertGreaterThanOrEqual($expect['sleeps_between'][0], $s);
                self::assertLessThanOrEqual($expect['sleeps_between'][1], $s);
            }
        }
    }
}
