---
name: php-sdk
description: Design and maintain the framework-independent public PHP SDK in this repository.
---

# PHP SDK

Follow [AGENTS.md](../../../AGENTS.md) and [composer.json](../../../composer.json). Keep public signatures typed and stable, with strict types and [PER Coding Style 3.x](https://www.php-fig.org/per/coding-style/). Accept PSR-18 clients, PSR-17 request factories and optional PSR-16 caches. Do not require Laravel or couple resources to a transport implementation.

Prefer simple resource methods and raw decoded response arrays until Garmin's response shape supports useful stable DTOs. Preserve new response fields. Use explicit exceptions for authentication, HTTP errors and malformed responses without including secrets or raw server bodies. Review public API changes for semantic-versioning impact before release.
