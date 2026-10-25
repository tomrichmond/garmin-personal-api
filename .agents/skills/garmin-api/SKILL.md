---
name: garmin-api
description: Validate and implement official Garmin Connect Personal API contracts in this repository.
---

# Garmin Personal API

Before implementing an endpoint, confirm its method, path, parameters, response and auth in the [Personal API Swagger docs](https://developer.garmin.com/gc-developer-program/personal/swagger-docs); cross-check the [official Python reference](https://github.com/garmin/connect-personal-api-python). Record uncertainty when Swagger details cannot be inspected. Never substitute unofficial Connect or Business API paths.

The [overview](https://developer.garmin.com/gc-developer-program/personal/personal-api/) documents `POST /personalapitoken-service/personal-api/personal-api-key/token` with `Authorization: ApiKey <key>`. The [FAQ](https://developer.garmin.com/gc-developer-program/personal/personal-faq/) says tokens last up to 24 hours and refresh tokens do not exist; reacquire using the key. Personal use is restricted to a user's own data.

Keep credentials out of logs and fixtures. Use synthetic responses in tests. Check file format, pagination and error behavior before naming a public method.
