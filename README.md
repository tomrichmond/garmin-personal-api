# Garmin Connect Personal API PHP SDK

An early, framework-independent PHP 8.4+ client for the [official Garmin Connect Personal API](https://developer.garmin.com/gc-developer-program/personal/personal-api/). It supports API-key token exchange, paginated activity search, activity details, and activity-file downloads.

## Install and use

Run `composer install` in this repository. The package is not yet published on Packagist. Create a Personal API key in Garmin Connect and keep it in your application's secret store or environment. The key is shown only once and may expire after 30, 90, 180 or 365 days.

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

For a CLI smoke test with your own key and activity ID, run:

```sh
php examples/get-activity.php 123456789
php examples/download-activity.php 123456789 /path/to/private/activity.zip
```

Set `GARMIN_PERSONAL_API_KEY` in your shell first. The details example prints private activity data to stdout; the download example creates a ZIP at the explicit path and refuses to overwrite an existing file. Neither example is run by CI.

Activity lists and details are arrays of Garmin's raw JSON objects, preserving fields added by Garmin. Activity IDs must be positive integers. `downloadZip()` returns the original ZIP response as a binary string in memory; it neither extracts a FIT file nor writes to disk. The [official Python client](https://github.com/garmin/connect-personal-api-python) confirms that the Personal API's download endpoint returns ZIP bytes, despite the initial FIT-download assumption. Treat the bytes as private fitness data and choose a secure storage location if you save them.

The SDK uses Symfony's PSR-18 client and Nyholm's PSR-17 request factory by default. Supply a `Psr\Http\Client\ClientInterface`, a `Psr\Http\Message\RequestFactoryInterface`, and optionally a `Psr\SimpleCache\CacheInterface` through the constructor to integrate with your application. Without an external cache, the token is cached in memory for this client instance. An external cache stores bearer tokens as plaintext; secure its backend and use a private namespace.

The API key is exchanged via `POST` with `Authorization: ApiKey <key>`; data requests use `Authorization: Bearer <token>`. Garmin does not issue refresh tokens. The SDK reacquires a token when its JWT `exp` is within 60 seconds. `AuthenticationException`, `ApiException` (with `statusCode`), and `InvalidResponseException` report failures without embedding response bodies or credentials. PSR-18 network failures are wrapped in an SDK exception with the original exception as its cause.

The activity `start` parameter is an offset and `limit` is the requested page size. This package does not yet add an iterator or enforce a maximum page size. See [Garmin's Swagger documentation](https://developer.garmin.com/gc-developer-program/personal/swagger-docs) for current API behavior.

Run `composer test`, `composer analyse`, and `composer format:check` locally. Formatting follows PER Coding Style 3.x. Tests use synthetic tokens and HTTP doubles. No live credentials are required.

Garmin describes the Personal API as access to an individual's own data, not a commercial multi-user integration. See the [official FAQ](https://developer.garmin.com/gc-developer-program/personal/personal-faq/).
