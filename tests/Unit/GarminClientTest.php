<?php

declare(strict_types=1);

namespace TomRichmond\GarminPersonalApi\Tests\Unit;

use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use TomRichmond\GarminPersonalApi\Exceptions\ApiException;
use TomRichmond\GarminPersonalApi\Exceptions\AuthenticationException;
use TomRichmond\GarminPersonalApi\Exceptions\GarminException;
use TomRichmond\GarminPersonalApi\Exceptions\InvalidResponseException;
use TomRichmond\GarminPersonalApi\GarminClient;
use TomRichmond\GarminPersonalApi\Resources\Health;

final class GarminClientTest extends TestCase
{
    private static function jwt(int $expiration): string
    {
        return 'header.' . rtrim(strtr(base64_encode(json_encode(['exp' => $expiration], JSON_THROW_ON_ERROR)), '+/', '-_'), '=') . '.signature';
    }

    public function testExchangesKeyAndListsActivitiesWithExpectedQuery(): void
    {
        $now = 1_800_000_000;
        $http = new QueueClient([
            new Response(200, [], self::jwt($now + 3600)),
            new Response(200, [], '[{"activityId":123,"futureField":"kept"}]'),
        ]);
        $garmin = new GarminClient('synthetic-key', $http, clock: static fn(): int => $now);

        self::assertSame([['activityId' => 123, 'futureField' => 'kept']], $garmin->activities()->list(
            startDate: '2026-10-01',
            endDate: '2026-10-25',
            start: 20,
            limit: 10,
            activityType: 'running',
        ));
        self::assertSame('POST', $http->requests[0]->getMethod());
        self::assertSame('/personalapitoken-service/personal-api/personal-api-key/token', $http->requests[0]->getUri()->getPath());
        self::assertSame('ApiKey synthetic-key', $http->requests[0]->getHeaderLine('Authorization'));
        self::assertSame('GET', $http->requests[1]->getMethod());
        self::assertSame('/activitylist-service/activities/search/activities', $http->requests[1]->getUri()->getPath());
        parse_str($http->requests[1]->getUri()->getQuery(), $query);
        self::assertSame(['start' => '20', 'limit' => '10', 'startDate' => '2026-10-01', 'endDate' => '2026-10-25', 'activityType' => 'running'], $query);
        self::assertSame('Bearer ' . self::jwt($now + 3600), $http->requests[1]->getHeaderLine('Authorization'));
    }

    public function testReusesTokenUntilItNearsExpiry(): void
    {
        $now = 1_800_000_000;
        $http = new QueueClient([
            new Response(200, [], self::jwt($now + 120)),
            new Response(200, [], '[]'),
            new Response(200, [], '[]'),
            new Response(200, [], self::jwt($now + 3600)),
            new Response(200, [], '[]'),
        ]);
        $garmin = new GarminClient('synthetic-key', $http, clock: static function () use (&$now): int {
            return $now;
        });
        $garmin->activities()->list();
        $garmin->activities()->list();
        $now += 61;
        $garmin->activities()->list();

        self::assertCount(5, $http->requests);
        self::assertSame('POST', $http->requests[3]->getMethod());
    }

    public function testActivityIteratorIsLazyAndAdvancesByReturnedCount(): void
    {
        $now = 1_800_000_000;
        $http = new QueueClient([
            new Response(200, [], self::jwt($now + 3600)),
            new Response(200, [], '[{"activityId":1},{"activityId":2}]'),
            new Response(200, [], '[{"activityId":3}]'),
            new Response(200, [], '[]'),
        ]);
        $garmin = new GarminClient('synthetic-key', $http, clock: static fn(): int => $now);
        $activities = $garmin->activities()->iterate(startDate: '2026-10-01', limit: 5);

        self::assertCount(0, $http->requests);
        self::assertSame([['activityId' => 1], ['activityId' => 2], ['activityId' => 3]], iterator_to_array($activities));
        self::assertCount(4, $http->requests);

        foreach ([0, 2, 3] as $page => $start) {
            parse_str($http->requests[$page + 1]->getUri()->getQuery(), $query);
            self::assertSame((string) $start, $query['start']);
            self::assertSame('5', $query['limit']);
            self::assertSame('2026-10-01', $query['startDate']);
        }
    }

    public function testActivityIteratorCanLimitPages(): void
    {
        $now = 1_800_000_000;
        $http = new QueueClient([
            new Response(200, [], self::jwt($now + 3600)),
            new Response(200, [], '[{"activityId":1}]'),
        ]);
        $garmin = new GarminClient('synthetic-key', $http, clock: static fn(): int => $now);

        self::assertSame([['activityId' => 1]], iterator_to_array($garmin->activities()->iterate(maxPages: 1)));
        self::assertCount(2, $http->requests);
    }

    public function testActivityIteratorRejectsInvalidPageLimitBeforeRequest(): void
    {
        $http = new QueueClient([]);
        $garmin = new GarminClient('synthetic-key', $http);

        $this->expectException(InvalidArgumentException::class);
        $garmin->activities()->iterate(maxPages: 0);
    }

    public function testCacheKeyIsPortableAcrossPsr16Implementations(): void
    {
        $now = 1_800_000_000;
        $key = hash('sha256', 'https://personal.connectapi.garmin.com' . "\0" . 'synthetic-key');
        self::assertSame(64, strlen($key));

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::once())->method('get')->with($key)->willReturn(null);
        $cache->expects(self::once())->method('set')->with($key, self::isArray(), 3540)->willReturn(true);

        $http = new QueueClient([
            new Response(200, [], self::jwt($now + 3600)),
            new Response(200, [], '[]'),
        ]);
        $garmin = new GarminClient('synthetic-key', $http, cache: $cache, clock: static fn(): int => $now);

        self::assertSame([], $garmin->activities()->list());
    }

    public function testGetsActivityDetailsAndPreservesUnknownFields(): void
    {
        $now = 1_800_000_000;
        $http = new QueueClient([
            new Response(200, [], self::jwt($now + 3600)),
            new Response(200, [], '{"activityId":123,"futureField":{"nested":[1,2]}}'),
        ]);
        $garmin = new GarminClient('synthetic-key', $http, clock: static fn(): int => $now);

        self::assertSame(
            ['activityId' => 123, 'futureField' => ['nested' => [1, 2]]],
            $garmin->activities()->get(123),
        );
        self::assertSame('GET', $http->requests[1]->getMethod());
        self::assertSame('/activity-service/activity/123', $http->requests[1]->getUri()->getPath());
        self::assertSame('application/json', $http->requests[1]->getHeaderLine('Accept'));
        self::assertSame('Bearer ' . self::jwt($now + 3600), $http->requests[1]->getHeaderLine('Authorization'));
    }

    public function testDownloadsUnmodifiedActivityZipBytes(): void
    {
        $now = 1_800_000_000;
        $bytes = "PK\x03\x04\x00\xff\x00";
        $http = new QueueClient([
            new Response(200, [], self::jwt($now + 3600)),
            new Response(200, ['Content-Type' => 'application/zip'], $bytes),
        ]);
        $garmin = new GarminClient('synthetic-key', $http, clock: static fn(): int => $now);

        self::assertSame($bytes, $garmin->activities()->downloadZip(123));
        self::assertSame('GET', $http->requests[1]->getMethod());
        self::assertSame('/download-service/files/activity/123', $http->requests[1]->getUri()->getPath());
        self::assertSame(
            'application/zip, application/x-zip-compressed, application/octet-stream',
            $http->requests[1]->getHeaderLine('Accept'),
        );
        self::assertSame('Bearer ' . self::jwt($now + 3600), $http->requests[1]->getHeaderLine('Authorization'));
    }

    public function testRejectsNonPositiveActivityIdsBeforeMakingRequests(): void
    {
        $http = new QueueClient([]);
        $garmin = new GarminClient('synthetic-key', $http);

        foreach ([static fn() => $garmin->activities()->get(0), static fn() => $garmin->activities()->downloadZip(-1)] as $call) {
            try {
                $call();
                self::fail('Expected invalid activity ID.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('Activity ID must be a positive integer.', $exception->getMessage());
            }
        }

        self::assertSame([], $http->requests);
    }

    public function testRejectsNonObjectActivityDetail(): void
    {
        $http = new QueueClient([new Response(200, [], self::jwt(time() + 3600)), new Response(200, [], '[]')]);
        $garmin = new GarminClient('synthetic-key', $http);

        $this->expectException(InvalidResponseException::class);
        $garmin->activities()->get(123);
    }

    public function testRejectsMalformedActivityDetailJsonWithoutExposingBody(): void
    {
        $http = new QueueClient([new Response(200, [], self::jwt(time() + 3600)), new Response(200, [], '{private')]);
        $garmin = new GarminClient('synthetic-key', $http);

        try {
            $garmin->activities()->get(123);
            self::fail('Expected malformed JSON error.');
        } catch (InvalidResponseException $exception) {
            self::assertStringNotContainsString('private', $exception->getMessage());
        }
    }

    public function testRejectsEmptyActivityDownload(): void
    {
        $http = new QueueClient([new Response(200, [], self::jwt(time() + 3600)), new Response(200, [], '')]);
        $garmin = new GarminClient('synthetic-key', $http);

        $this->expectException(InvalidResponseException::class);
        $garmin->activities()->downloadZip(123);
    }

    public function testActivityDownloadHttpErrorDoesNotExposeBody(): void
    {
        $http = new QueueClient([new Response(200, [], self::jwt(time() + 3600)), new Response(404, [], 'private response')]);
        $garmin = new GarminClient('synthetic-key', $http);

        try {
            $garmin->activities()->downloadZip(123);
            self::fail('Expected API error.');
        } catch (ApiException $exception) {
            self::assertSame(404, $exception->statusCode);
            self::assertStringNotContainsString('private response', $exception->getMessage());
        }
    }

    public function testHealthMethodsUseDocumentedPathsAndQueries(): void
    {
        $cases = [
            [static fn(Health $health): ?array => $health->dailyHeartRate('2026-10-25'), '/wellness-service/wellness/dailyHeartRate', ['date' => '2026-10-25']],
            [static fn(Health $health): ?array => $health->heartRateZones(), '/biometric-service/heartRateZones/v2', []],
            [static fn(Health $health): ?array => $health->dailyHrv('2026-10-25'), '/hrv-service/hrv/2026-10-25', []],
            [static fn(Health $health): ?array => $health->dailyHrvSummaries('2026-10-01', '2026-10-25'), '/hrv-service/hrv/daily/2026-10-01/2026-10-25', []],
            [static fn(Health $health): ?array => $health->dailySleep('2026-10-25'), '/sleep-service/sleep/dailySleep', ['date' => '2026-10-25']],
            [static fn(Health $health): ?array => $health->dailySleepSummaries('2026-10-01', '2026-10-25'), '/sleep-service/sleep/dailySleepSummariesByDate', ['startDate' => '2026-10-01', 'endDate' => '2026-10-25']],
            [static fn(Health $health): ?array => $health->dailyBodyBattery('2026-10-01', '2026-10-25', 2), '/wellness-service/wellness/bodyBattery/reports/daily', ['startDate' => '2026-10-01', 'endDate' => '2026-10-25', 'chunks' => '2']],
            [static fn(Health $health): ?array => $health->dailyBodyBattery('2026-10-01', '2026-10-25'), '/wellness-service/wellness/bodyBattery/reports/daily', ['startDate' => '2026-10-01', 'endDate' => '2026-10-25']],
            [static fn(Health $health): ?array => $health->dailySummaries('2026-10-01', '2026-10-25'), '/usersummary-service/v2/dailysummaries', ['startDate' => '2026-10-01', 'endDate' => '2026-10-25']],
            [static fn(Health $health): ?array => $health->dailySteps('2026-10-01', '2026-10-25'), '/usersummary-service/stats/steps/daily/2026-10-01/2026-10-25', []],
            [static fn(Health $health): ?array => $health->dailyStress('2026-10-01', '2026-10-25'), '/usersummary-service/stats/stress/daily/2026-10-01/2026-10-25', []],
        ];

        foreach ($cases as [$call, $expectedPath, $expectedQuery]) {
            $now = 1_800_000_000;
            $http = new QueueClient([
                new Response(200, [], self::jwt($now + 3600)),
                new Response(200, [], '{"futureMetric":{"value":123}}'),
            ]);
            $garmin = new GarminClient('synthetic-key', $http, clock: static fn(): int => $now);

            self::assertSame(['futureMetric' => ['value' => 123]], $call($garmin->health()));
            self::assertSame('GET', $http->requests[1]->getMethod());
            self::assertSame($expectedPath, $http->requests[1]->getUri()->getPath());
            self::assertSame('application/json', $http->requests[1]->getHeaderLine('Accept'));
            self::assertSame('Bearer ' . self::jwt($now + 3600), $http->requests[1]->getHeaderLine('Authorization'));
            parse_str($http->requests[1]->getUri()->getQuery(), $query);
            self::assertSame($expectedQuery, $query);
        }
    }

    public function testHealthAllowsArrayAndEmptyResponsesWithoutDroppingFields(): void
    {
        $now = 1_800_000_000;
        $http = new QueueClient([
            new Response(200, [], self::jwt($now + 3600)),
            new Response(200, [], '[{"futureField":true}]'),
            new Response(204, [], ''),
            new Response(200, [], 'null'),
        ]);
        $garmin = new GarminClient('synthetic-key', $http, clock: static fn(): int => $now);

        self::assertSame([['futureField' => true]], $garmin->health()->dailySteps('2026-10-25', '2026-10-25'));
        self::assertNull($garmin->health()->dailySleep('2026-10-25'));
        self::assertNull($garmin->health()->dailyHrv('2026-10-25'));
        self::assertCount(4, $http->requests);
    }

    public function testHealthRejectsInvalidDatesBeforeMakingRequests(): void
    {
        $http = new QueueClient([]);
        $health = new GarminClient('synthetic-key', $http)->health();

        foreach ([
            static fn() => $health->dailyHeartRate('2026-02-30'),
            static fn() => $health->dailyHrv('../invalid'),
            static fn() => $health->dailySleepSummaries('2026-10-26', '2026-10-25'),
        ] as $call) {
            try {
                $call();
                self::fail('Expected invalid date.');
            } catch (InvalidArgumentException) {
            }
        }

        self::assertSame([], $http->requests);
    }

    public function testHealthRejectsMalformedAndScalarJsonWithoutExposingBodies(): void
    {
        foreach (['{private', '"private"'] as $body) {
            $http = new QueueClient([new Response(200, [], self::jwt(time() + 3600)), new Response(200, [], $body)]);
            $garmin = new GarminClient('synthetic-key', $http);

            try {
                $garmin->health()->dailyHrv('2026-10-25');
                self::fail('Expected invalid response.');
            } catch (InvalidResponseException $exception) {
                self::assertStringNotContainsString('private', $exception->getMessage());
            }
        }
    }

    public function testHealthHttpFailurePreservesStatusWithoutBody(): void
    {
        $http = new QueueClient([new Response(200, [], self::jwt(time() + 3600)), new Response(429, [], 'private response')]);
        $garmin = new GarminClient('synthetic-key', $http);

        try {
            $garmin->health()->dailyBodyBattery('2026-10-25', '2026-10-25');
            self::fail('Expected API error.');
        } catch (ApiException $exception) {
            self::assertSame(429, $exception->statusCode);
            self::assertStringNotContainsString('private response', $exception->getMessage());
        }
    }

    public function testHealthAndActivitiesShareOneAccessToken(): void
    {
        $now = 1_800_000_000;
        $http = new QueueClient([
            new Response(200, [], self::jwt($now + 3600)),
            new Response(200, [], '[]'),
            new Response(200, [], '{}'),
        ]);
        $garmin = new GarminClient('synthetic-key', $http, clock: static fn(): int => $now);

        self::assertSame([], $garmin->activities()->list());
        self::assertSame([], $garmin->health()->dailyHeartRate('2026-10-25'));
        self::assertCount(3, $http->requests);
    }

    public function testAuthenticationFailureDoesNotExposeResponse(): void
    {
        $garmin = new GarminClient('synthetic-key', new QueueClient([new Response(401, [], 'sensitive response')]));
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('HTTP 401');
        $garmin->activities()->list();
    }

    public function testMalformedTokenIsRejected(): void
    {
        $garmin = new GarminClient('synthetic-key', new QueueClient([new Response(200, [], '{"access_token":"not-jwt"}')]));
        $this->expectException(InvalidResponseException::class);
        $garmin->activities()->list();
    }

    public function testApiErrorHasStatus(): void
    {
        $http = new QueueClient([new Response(200, [], self::jwt(time() + 3600)), new Response(429, [], 'rate limited')]);
        $garmin = new GarminClient('synthetic-key', $http);

        try {
            $garmin->activities()->list();
            self::fail('Expected API error.');
        } catch (ApiException $exception) {
            self::assertSame(429, $exception->statusCode);
        }
    }

    public function testMalformedActivityJsonIsRejected(): void
    {
        $http = new QueueClient([new Response(200, [], self::jwt(time() + 3600)), new Response(200, [], '{')]);
        $garmin = new GarminClient('synthetic-key', $http);
        $this->expectException(InvalidResponseException::class);
        $garmin->activities()->list();
    }

    public function testTransportFailureIsWrappedWithoutCredentialInMessage(): void
    {
        $http = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('timeout') extends RuntimeException implements ClientExceptionInterface {};
            }
        };
        $garmin = new GarminClient('synthetic-key', $http);

        try {
            $garmin->activities()->list();
            self::fail('Expected transport failure.');
        } catch (GarminException $exception) {
            self::assertInstanceOf(AuthenticationException::class, $exception);
            self::assertStringNotContainsString('synthetic-key', $exception->getMessage());
            self::assertInstanceOf(ClientExceptionInterface::class, $exception->getPrevious());
        }
    }
}

final class QueueClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param list<ResponseInterface> $responses */
    public function __construct(private array $responses) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return array_shift($this->responses) ?? throw new LogicException('Unexpected HTTP request.');
    }
}
