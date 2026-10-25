<?php

declare(strict_types=1);

namespace TomRichmond\GarminPersonalApi;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\HttpClient\Psr18Client;
use TomRichmond\GarminPersonalApi\Auth\TokenManager;
use TomRichmond\GarminPersonalApi\Http\ApiClient;
use TomRichmond\GarminPersonalApi\Resources\Activities;
use TomRichmond\GarminPersonalApi\Resources\Health;

final readonly class GarminClient
{
    private Activities $activities;

    private Health $health;

    /** @param callable(): int|null $clock */
    public function __construct(
        string $apiKey,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?CacheInterface $cache = null,
        ?callable $clock = null,
    ) {
        $baseUrl = 'https://personal.connectapi.garmin.com';
        $httpClient ??= new Psr18Client();
        $requestFactory ??= new Psr17Factory();
        $tokens = new TokenManager($apiKey, $baseUrl, $httpClient, $requestFactory, $cache, $clock);
        $apiClient = new ApiClient($baseUrl, $httpClient, $requestFactory, $tokens);
        $this->activities = new Activities($apiClient);
        $this->health = new Health($apiClient);
    }

    public function activities(): Activities
    {
        return $this->activities;
    }

    public function health(): Health
    {
        return $this->health;
    }
}
