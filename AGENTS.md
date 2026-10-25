# Repository guidance

Build a framework-independent PHP 8.4+ SDK for the official Garmin Connect Personal API. Use strict types, [PER Coding Style 3.x](https://www.php-fig.org/per/coding-style/), PSR-4, PSR-18 transport and PSR-17 factories; support PSR-16 token caching without assuming an application's cache implementation. Keep the public API small and preserve unknown Garmin response fields.

Use [Garmin's Personal API overview](https://developer.garmin.com/gc-developer-program/personal/personal-api/), [FAQ](https://developer.garmin.com/gc-developer-program/personal/personal-faq/), [Swagger documentation](https://developer.garmin.com/gc-developer-program/personal/swagger-docs), and [official Python client](https://github.com/garmin/connect-personal-api-python) to confirm contracts before adding endpoints. Do not borrow endpoints from unofficial Connect clients or the business API.

Never commit credentials or real fitness data. Automated tests must use HTTP doubles and synthetic tokens; CI must not call Garmin. Do not log credentials, tokens, response bodies, or authenticated request headers. Preserve exceptions without exposing response text that may contain secrets.

Personal API access is for an individual's own data; it is not the multi-user commercial integration program. Keep future MCP work outside this package.
