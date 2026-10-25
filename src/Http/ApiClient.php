<?php

declare(strict_types=1);

namespace TomRichmond\GarminPersonalApi\Http;

use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use TomRichmond\GarminPersonalApi\Auth\TokenManager;
use TomRichmond\GarminPersonalApi\Exceptions\ApiException;
use TomRichmond\GarminPersonalApi\Exceptions\GarminException;
use TomRichmond\GarminPersonalApi\Exceptions\InvalidResponseException;

final readonly class ApiClient
{
    public function __construct(
        private string $baseUrl,
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private TokenManager $tokenManager,
    ) {}

    /**
     * @param array<string, int|string> $query
     * @return list<array<string, mixed>>
     */
    public function getList(string $path, array $query): array
    {
        $uri = $this->baseUrl . $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $request = $this->requestFactory->createRequest('GET', $uri)
            ->withHeader('Authorization', 'Bearer ' . $this->tokenManager->getToken())
            ->withHeader('Accept', 'application/json');

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new GarminException('Garmin API request failed.', 0, $exception);
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new ApiException($response->getStatusCode());
        }

        try {
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidResponseException('Garmin returned invalid JSON.', 0, $exception);
        }

        if (! is_array($data) || ! array_is_list($data)) {
            throw new InvalidResponseException('Garmin activity search response was not a list.');
        }

        foreach ($data as $item) {
            if (! is_array($item)) {
                throw new InvalidResponseException('Garmin activity search contained an invalid item.');
            }
        }

        return $data;
    }
}
