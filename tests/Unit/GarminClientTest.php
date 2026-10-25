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
