# Garmin Connect Personal API PHP SDK

An early, framework-independent PHP 8.4+ client for the [official Garmin Connect Personal API](https://developer.garmin.com/gc-developer-program/personal/personal-api/). It supports API-key token exchange, activities, and selected health endpoints.

## Requirements and installation

PHP 8.4+ and Composer are required. Install the package in your application with:

```sh
composer require tomrichmond/garmin-personal-api
```

Create a Personal API key in Garmin Connect and keep it in your application's secret store or environment. The key is shown only once and may expire after 30, 90, 180 or 365 days. For a local shell session, use `export GARMIN_PERSONAL_API_KEY='your-key'`; do not put the key in source code or commit it.

## Activities

```php
use TomRichmond\GarminPersonalApi\GarminClient;

$garmin = new GarminClient(apiKey: $_ENV['GARMIN_PERSONAL_API_KEY']);
$activities = $garmin->activities()->list(
    startDate: '2026-10-01',
    endDate: '2026-10-25',
    start: 0,
    limit: 20,
);

$activity = $garmin->activities()->get(123456789);
$zipBytes = $garmin->activities()->downloadZip(123456789);
```

`list()` returns one page. For an unknown number of pages, `iterate()` yields activity arrays as they are fetched:

```php
foreach ($garmin->activities()->iterate(
    startDate: '2026-10-01',
    endDate: '2026-10-25',
    limit: 20,
    maxPages: 10,
) as $activity) {
    // Process one activity at a time.
}
```

The iterator is lazy: it makes no request until iteration starts. `start` is a zero-based offset and `limit` is the requested page size. It advances the offset by the number of records actually returned, including when Garmin returns fewer than requested. It stops on an empty page or at `maxPages` if supplied. Without `maxPages`, a final empty-page request is needed to confirm the end. Choose a bounded date range or `maxPages` if you need to limit API calls; the SDK does not automatically retry or pace requests. Garmin's [Personal API FAQ](https://developer.garmin.com/gc-developer-program/personal/personal-faq/) does not state a numeric rate limit, so do not assume one. Invalid pagination arguments throw `InvalidArgumentException` before a request is sent.

For a CLI smoke test with your own key and activity ID, run:

```sh
php examples/get-activity.php 123456789
php examples/download-activity.php 123456789 /path/to/private/activity.zip
```

Set `GARMIN_PERSONAL_API_KEY` in your shell first. The details example prints private activity data to stdout; the download example creates a ZIP at the explicit path and refuses to overwrite an existing file. Neither example is run by CI.

Activity lists and details are arrays of Garmin's raw JSON objects, preserving fields added by Garmin. Activity IDs must be positive integers. `downloadZip()` returns the original ZIP response as a binary string in memory; it neither extracts a FIT file nor writes to disk. The [official Python client](https://github.com/garmin/connect-personal-api-python) confirms that the Personal API's download endpoint returns ZIP bytes, despite the initial FIT-download assumption. Treat the bytes as private fitness data and choose a secure storage location if you save them.

## Health data

```php
$heartRate = $garmin->health()->dailyHeartRate('2026-10-25');
$heartRateZones = $garmin->health()->heartRateZones();
$hrv = $garmin->health()->dailyHrv('2026-10-25');
$hrvHistory = $garmin->health()->dailyHrvSummaries('2026-10-01', '2026-10-25');
$sleep = $garmin->health()->dailySleep('2026-10-25');
$sleepHistory = $garmin->health()->dailySleepSummaries('2026-10-01', '2026-10-25');
$bodyBattery = $garmin->health()->dailyBodyBattery('2026-10-01', '2026-10-25');
$dailyActivity = $garmin->health()->dailySummaries('2026-10-01', '2026-10-25');
$steps = $garmin->health()->dailySteps('2026-10-01', '2026-10-25');
$stress = $garmin->health()->dailyStress('2026-10-01', '2026-10-25');
```

Dates must be valid `YYYY-MM-DD` calendar dates, and the start must not follow the end. `dailyBodyBattery()` accepts an optional third `chunks` argument. These methods return Garmin's decoded JSON as an array, or `null` for an empty or JSON `null` response. They do not normalise fields, so newly added metrics remain accessible. The Personal API does not document a separate resting-heart-rate endpoint; inspect the raw heart-rate or daily-summary response for any such data Garmin supplies. The [Personal API Swagger](https://developer.garmin.com/gc-developer-program/personal/swagger-docs) also does not currently list a standalone training-readiness or recovery endpoint, so this SDK does not invent one or use the distinct Business Training API.

## Authentication and error handling

The SDK uses Symfony's PSR-18 client and Nyholm's PSR-17 request factory by default. Supply a `Psr\Http\Client\ClientInterface`, a `Psr\Http\Message\RequestFactoryInterface`, and optionally a `Psr\SimpleCache\CacheInterface` through the constructor to integrate with your application. Without an external cache, the token is cached in memory for this client instance. An external cache stores bearer tokens as plaintext; secure its backend and use a private namespace.

The API key is exchanged via `POST` with `Authorization: ApiKey <key>`; data requests use `Authorization: Bearer <token>`. Garmin does not issue refresh tokens. The SDK reacquires a token when its JWT `exp` is within 60 seconds.

API and transport failures extend `GarminException` (invalid caller arguments instead throw `InvalidArgumentException`). `AuthenticationException` covers token exchange or token validity failures; `ApiException` exposes the data request's HTTP `statusCode` (including 429); `InvalidResponseException` covers unexpected response shapes or invalid JSON. PSR-18 transport failures are wrapped in `GarminException` with the original exception as its cause. Exceptions deliberately omit response bodies and credentials. Avoid logging authenticated requests, raw response bodies, or the original transport exception without reviewing your logger's redaction.

```php
use TomRichmond\GarminPersonalApi\Exceptions\ApiException;
use TomRichmond\GarminPersonalApi\Exceptions\AuthenticationException;
use TomRichmond\GarminPersonalApi\Exceptions\GarminException;

try {
    $activities = $garmin->activities()->list();
} catch (AuthenticationException $exception) {
    // Ask the user to check or replace their Personal API key.
} catch (ApiException $exception) {
    // Handle $exception->statusCode without logging private response data.
} catch (GarminException $exception) {
    // Handle transport or malformed-response failures.
}
```

## Development

For development, clone the repository and run `composer install`. Run `composer validate --strict`, `composer test`, `composer analyse`, and `composer format:check` locally. Formatting follows PER Coding Style 3.x. GitHub Actions runs these checks on PHP 8.4 and 8.5. Tests use synthetic tokens and HTTP doubles; CI does not need a Garmin key or make live API requests. Do not commit credentials or private activity data.

Garmin describes the Personal API as access to an individual's own data, not a commercial multi-user integration. See the [official FAQ](https://developer.garmin.com/gc-developer-program/personal/personal-faq/).
