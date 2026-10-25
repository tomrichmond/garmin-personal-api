<?php

declare(strict_types=1);

namespace TomRichmond\GarminPersonalApi\Auth;

use Closure;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use TomRichmond\GarminPersonalApi\Exceptions\AuthenticationException;
use TomRichmond\GarminPersonalApi\Exceptions\InvalidResponseException;

final class TokenManager
{
    private const string TOKEN_PATH = '/personalapitoken-service/personal-api/personal-api-key/token';

    private ?string $token = null;

    private int $expiresAt = 0;

    /** @var (Closure(): int)|null */
    private readonly ?Closure $clock;

    /** @param (callable(): int)|null $clock */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly ?CacheInterface $cache = null,
        ?callable $clock = null,
    ) {
        if (trim($apiKey) === '') {
            throw new InvalidArgumentException('A Garmin Personal API key is required.');
        }

        $this->clock = $clock === null ? null : $clock(...);
    }

    private static function expiration(string $token): int
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new InvalidResponseException('Garmin token response is not a JWT.');
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if ($payload === false) {
            throw new InvalidResponseException('Garmin token response has invalid encoding.');
        }

        try {
            $claims = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidResponseException('Garmin token response has invalid claims.', 0, $exception);
        }

        if (! is_array($claims) || ! isset($claims['exp']) || ! is_int($claims['exp'])) {
            throw new InvalidResponseException('Garmin token response has no valid expiry.');
        }

        return $claims['exp'];
    }

    public function getToken(): string
    {
        $now = $this->now();
        if ($this->token !== null && $this->expiresAt > $now + 60) {
            return $this->token;
        }

        $cacheKey = $this->cacheKey();
        // PSR-16 requires support for this 64-character hexadecimal key.
        //noinspection PhpUnhandledExceptionInspection
        $cached = $this->cache?->get($cacheKey);
        if (is_array($cached)
            && isset($cached['token'], $cached['expiresAt'])
            && is_string($cached['token'])
            && is_int($cached['expiresAt'])
            && $cached['expiresAt'] > $now + 60
        ) {
            $this->token = $cached['token'];
            $this->expiresAt = $cached['expiresAt'];

            return $this->token;
        }

        $request = $this->requestFactory->createRequest('POST', $this->baseUrl . self::TOKEN_PATH)
            ->withHeader('Authorization', 'ApiKey ' . $this->apiKey)
            ->withHeader('Accept', '*/*');

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new AuthenticationException('Garmin token request failed.', 0, $exception);
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new AuthenticationException(sprintf('Garmin token request returned HTTP %d.', $response->getStatusCode()));
        }

        $token = trim((string) $response->getBody());
        $expiration = self::expiration($token);
        if ($expiration <= $now + 60) {
            throw new InvalidResponseException('Garmin returned an expired access token.');
        }

        $this->token = $token;
        $this->expiresAt = $expiration;
        // PSR-16 requires support for this 64-character hexadecimal key.
        //noinspection PhpUnhandledExceptionInspection
        $this->cache?->set($cacheKey, ['token' => $token, 'expiresAt' => $expiration], max(1, $expiration - $now - 60));

        return $token;
    }

    private function cacheKey(): string
    {
        return hash('sha256', $this->baseUrl . "\0" . $this->apiKey);
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
