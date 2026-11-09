# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-11-09

This release contains backward-incompatible changes.
See [UPGRADING](#upgrading-from-1x) below.

### Fixed

- `srem()` ignored the `$member` argument and never removed anything.
- `incr()` / `decr()` with a value of `0` or a negative value incremented/decremented by 1 instead of using `INCRBY` / `DECRBY`.
- `cache_ttl => 0` did not disable expiration: a random 10-99 seconds TTL was always applied.
- The anti-stampede TTL jitter was computed once per client instead of once per write, so keys written by the same process expired together.
- `connect_tries` was ignored: the first connection failure was thrown immediately, and `RedisTriesOverConnectException` was unreachable.
- Connection failures are now reported as `RedisTriesOverConnectException` (a `RedisConnectionException`), with the original phpredis exception as `previous`.
- `disconnect()` opened a connection when the client was not connected yet, and left the client in a "connected" state.
- `getDbIndex()` threw a `TypeError` when no database index was configured.
- `allValues()` threw `RedisKeyNotFoundException` when a key prefix was configured (keys were prefixed twice).
- `setDbIndex()`, `setKeyPrefix()`, `setSerializer()` and `setCompression()` were silently ignored when called after the connection was established. Changing host, port, auth, ssl, persistence or timeout now drops the connection, so that the next command reconnects with the new parameters.
- `exists()` / `has()` could return an integer instead of a boolean with phpredis >= 4.
- `ttl()` returned `-2` for missing keys, instead of `false` as documented.
- `getset()` did not decode the previous value (`null`/`true`/`false` came back as internal sentinel strings) and removed the TTL of the key.
- `mset()` with a TTL was not atomic and stopped writing at the first failure. It now uses `MULTI`/`EXEC`.
- `msetnx()` with a TTL was not all-or-nothing as `MSETNX` is. It now uses `WATCH`/`MULTI`/`EXEC`.
- `evaluate()` / `evalSha()` could throw because of an error left over by a previous command.
- `setPort()` stored a string when given a numeric string.
- `wrap()` could be used to call private methods (e.g. `reconnect`) from outside the class.

### Added

- `declare(strict_types=1)` and native parameter/return types everywhere.
- `sismember()` (correct spelling); `sinterstore()` and `sunionstore()` are now part of `RedisInterface`.
- Optional `$numKeys` argument for `evaluate()` / `evalSha()`, to pass ARGV in addition to KEYS.
- `cache_ttl_jitter` option (`setCacheTTLJitter()` / `getCacheTTLJitter()`), to disable the anti-stampede jitter.
- `auth_password` accepted as an alias of `auth` (the README documented `auth_password`, which was silently ignored).
- `none` and `msgpack` serializers.
- `RedisClient::DEFAULT_CACHE_TTL_SECONDS` constant.
- PSR-16 adapter: `Mfonte\Redisw\Cache\SimpleCache` implements `Psr\SimpleCache\CacheInterface` (new dependency: `psr/simple-cache` ^2.0 || ^3.0).
- Optional per-call `$ttl` (seconds) argument for `set()`, `mset()` and `setnx()`, overriding the configured cache TTL.
- Optional `$default` argument for `mget()`, returned for missing keys (so that stored `false` values can be told apart).
- `deleteAll()`, to delete only the keys of the configured prefix.
- `username` option, for Redis 6 ACL authentication.
- `ssl_context` option, to pass SSL context options (CA file, peer verification, client certificates) to TLS connections.
- `read_timeout` option.
- Descriptive messages on every exception.
- Test suite split into `unit` (no Redis needed) and `integration` suites, covering every public method.
- PHPStan (level max), PHP-CS-Fixer (PSR-12) and GitHub Actions CI on PHP 8.1 - 8.4.
- `LICENSE`, `CONTRIBUTING.md`, `SECURITY.md`, `.gitattributes`, `.editorconfig`, issue and PR templates, Dependabot.

### Changed

- **BC break**: unknown configuration keys passed to `RedisClient::instance()` now throw an `InvalidArgumentException`, instead of being silently ignored.
- **BC break**: `getCacheTTL()` returns the configured TTL in seconds, instead of milliseconds with the jitter already added.
- **BC break**: `wrap()` throws an `InvalidArgumentException` if the method does not exist or is not public, instead of returning `null`. Exceptions thrown by the wrapped call are still swallowed.
- **BC break**: `RedisInterface` methods now declare native parameter and return types; `sismembers()` was replaced by `sismember()` in the interface (`RedisClient::sismembers()` is kept as a deprecated alias).
- **BC break**: `append()` only accepts `string|int|float` (phpredis never serializes appended values).
- **BC break**: the `Mfonte\Redisw\Spec\*` IDE stubs were removed from the package. Use the stubs bundled with your IDE / PHPStan.
- **BC break**: `ext-redis` >= 5.3 is now required (the code already relied on 5.3 features such as LZ4 compression).
- `setDbIndex()` accepts any non-negative index (Redis can be configured with more than 16 databases).
- `allKeys()` uses non-blocking `SCAN` instead of `KEYS`, and returns keys without the configured prefix.
- `allValues()` fetches values with `MGET` in batches instead of one `GET` per key.
- The test suite only ever flushes its own databases (15 and 14 by default), it never calls `FLUSHALL`.

### Removed

- Unused development dependencies `orchestra/testbench` and `mockery/mockery`.

### Upgrading from 1.x

- Replace calls to `sismembers()` with `sismember()`.
- If you relied on `getCacheTTL()` returning milliseconds, multiply its value by 1000.
- Check that your configuration array only contains [documented keys](README.md#configuration).
- If you implement `RedisInterface` yourself, add the native types declared by the interface.

## [1.0.6] - 2023-10-26

### Added

- `force_revalidate` option: `has()` and `exists()` always return `false`, to implement stale-while-revalidate strategies.

## [1.0.5] - 2023-10-20

### Added

- `dbSize()` and `flushDb()` methods.

### Changed

- `flushAll()` uses phpredis `flushAll()`, `flushAllAsync()` uses the raw `FLUSHALL ASYNC` command.

## [1.0.4] - 2023-04-13

### Fixed

- Typo in `setSerializer()`.

## [1.0.3] - 2023-04-13

### Added

- Compression and serializer configuration.
- `wrap()` method, to call commands without throwing exceptions.

## [1.0.2] - 2023-03-22

### Fixed

- Direct use of the phpredis instance instead of the lazy connection getter.
- `disconnect()` no longer throws.

## [1.0.1] - 2023-03-21

### Added

- `has()` method.

## [1.0.0] - 2023-03-21

- Initial release.

[2.0.0]: https://github.com/mauriziofonte/php-redisw/compare/v1.0.6...v2.0.0
[1.0.6]: https://github.com/mauriziofonte/php-redisw/compare/v1.0.5...v1.0.6
[1.0.5]: https://github.com/mauriziofonte/php-redisw/compare/v1.0.4...v1.0.5
[1.0.4]: https://github.com/mauriziofonte/php-redisw/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/mauriziofonte/php-redisw/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/mauriziofonte/php-redisw/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/mauriziofonte/php-redisw/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/mauriziofonte/php-redisw/releases/tag/v1.0.0
