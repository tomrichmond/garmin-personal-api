<?php

declare(strict_types=1);

namespace TomRichmond\GarminPersonalApi\Http;

use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
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
        $response = $this->getResponse($path, $query);

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

    /** @return array<string, mixed> */
    public function getObject(string $path): array
    {
        $body = (string) $this->getResponse($path)->getBody();

        try {
            $object = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidResponseException('Garmin returned invalid JSON.', 0, $exception);
        }

        if (! is_object($object) || ! is_array($data)) {
            throw new InvalidResponseException('Garmin activity detail response was not an object.');
        }

        return $data;
    }

    /**
     * @param array<string, int|string> $query
     * @return array<array-key, mixed>|null
     */
    public function getJson(string $path, array $query = []): ?array
    {
        $body = (string) $this->getResponse($path, $query)->getBody();
        if ($body === '') {
            return null;
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidResponseException('Garmin returned invalid JSON.', 0, $exception);
        }

        if ($data !== null && ! is_array($data)) {
            throw new InvalidResponseException('Garmin returned an unexpected JSON value.');
        }

        return $data;
    }

    public function getBytes(string $path): string
    {
        $body = (string) $this->getResponse(
            $path,
            accept: 'application/zip, application/x-zip-compressed, application/octet-stream',
        )->getBody();

        if ($body === '') {
            throw new InvalidResponseException('Garmin activity download response was empty.');
        }

        return $body;
    }

    /** @param array<string, int|string> $query */
    private function getResponse(string $path, array $query = [], string $accept = 'application/json'): ResponseInterface
    {
        $uri = $this->baseUrl . $path;
        if ($query !== []) {
            $uri .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $request = $this->requestFactory->createRequest('GET', $uri)
            ->withHeader('Authorization', 'Bearer ' . $this->tokenManager->getToken())
            ->withHeader('Accept', $accept);

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new GarminException('Garmin API request failed.', 0, $exception);
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new ApiException($response->getStatusCode());
        }

        return $response;
    }
}
