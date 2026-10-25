---
name: testing
description: Test Garmin SDK behavior with isolated HTTP doubles and synthetic credentials.
---

# Testing

Use [AGENTS.md](../../../AGENTS.md) and [phpunit.xml](../../../phpunit.xml). Never make live Garmin calls in automated tests or CI. Assert request method, path, query, Authorization scheme, response decoding and typed failures using a PSR-18 double.

Use synthetic JWTs with controlled `exp` and an injected clock to test token reuse, nearing expiry and reacquisition. Test malformed token/JSON, authentication errors, network failures and status handling. Avoid recording real responses or credentials in fixtures.
