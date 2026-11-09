# Redisw: a Redis client for PHP, built on phpredis

[![CI](https://github.com/mauriziofonte/php-redisw/actions/workflows/ci.yml/badge.svg)](https://github.com/mauriziofonte/php-redisw/actions/workflows/ci.yml)
[![Latest Stable Version](https://poser.pugx.org/mfonte/redisw/v/stable)](https://packagist.org/packages/mfonte/redisw)
[![Total Downloads](https://poser.pugx.org/mfonte/redisw/downloads)](https://packagist.org/packages/mfonte/redisw)
[![PHP Version Require](https://poser.pugx.org/mfonte/redisw/require/php)](https://packagist.org/packages/mfonte/redisw)
[![License](https://poser.pugx.org/mfonte/redisw/license)](LICENSE)

An expressive, strictly-typed wrapper around the [phpredis](https://github.com/phpredis/phpredis) extension, designed for caching:

- lazy connection, with configurable retries;
- a default TTL on every write, with random jitter to avoid cache stampedes;
- safe round trip of `null`, `true` and `false`, which phpredis can't tell apart from a missing key;
- serialization (`php`, `igbinary`, `json`, `msgpack`) and compression (`lzf`, `zstd`, `lz4`);
- consistent exceptions, plus a `wrap()` helper when you'd rather get `null` than an exception;
- hit/miss counters and a "force revalidate" switch for stale-while-revalidate strategies;
- a [PSR-16](https://www.php-fig.org/psr/psr-16/) `CacheInterface` adapter;
- Redis 6 ACL users, TLS with custom SSL context options.

## Requirements

- PHP **>= 8.1**
- `ext-redis` **>= 5.3**
- *optional, recommended*: `ext-igbinary`, for faster and more compact serialization

## Installation

```bash
composer require mfonte/redisw
```

## Usage

```php
<?php

use Mfonte\Redisw\Exception\RedisException;
use Mfonte\Redisw\Exception\RedisKeyNotFoundException;
use Mfonte\Redisw\RedisClient;

$client = RedisClient::instance([
    'host' => '127.0.0.1',
    'port' => 6379,
    'serializer' => 'igbinary',
    'cache_ttl' => 300,
]);

// no connection is opened until the first command
$client->set('user:42', ['name' => 'Ada', 'admin' => true]);

try {
    $user = $client->get('user:42');
} catch (RedisKeyNotFoundException) {
    $user = null; // the key does not exist (or has expired)
} catch (RedisException $e) {
    // connection or server error
}

// don't want exceptions? wrap() returns null instead
$user = $client->wrap('get', 'user:42');
```

### Per-call TTL

```php
$client->set('session:abc', $data, 3600);   // expires in exactly 1 hour (no jitter)
$client->set('config', $config, 0);         // never expires
$client->mset(['a' => 1, 'b' => 2], 120);   // same TTL for every key
```

### PSR-16 (Simple Cache)

```php
use Mfonte\Redisw\Cache\SimpleCache;

$cache = new SimpleCache($client); // a Psr\SimpleCache\CacheInterface

$cache->set('user.42', $user, new DateInterval('PT10M'));
$user = $cache->get('user.42', default: null);
```

A `null` TTL applies the client's `cache_ttl` (including its jitter), and a TTL `<= 0` deletes the item, as required by PSR-16. `clear()` deletes only the keys of the configured `key_prefix`; without a prefix, it flushes the current database.

### TLS and ACL users

```php
$client = RedisClient::instance([
    'host' => 'redis.example.com',
    'port' => 6380,
    'ssl' => true,
    'ssl_context' => [
        'cafile' => '/etc/ssl/certs/redis-ca.pem',
        'verify_peer_name' => true,
    ],
    'username' => 'app',      // Redis >= 6 ACL user
    'auth' => getenv('REDIS_PASSWORD'),
]);
```

### Caching with stale-while-revalidate

```php
if ($client->has('report')) {
    return $client->get('report');
}

$report = buildExpensiveReport();
$client->set('report', $report);

return $report;
```

Setting `force_revalidate` to `true` (for example from a "refresh" request) makes `has()` and `exists()` return `false`, so that values are rebuilt and overwritten. `getHits()` and `getMisses()` count the results of `has()` / `exists()`.

## Configuration

| Key                | Type         | Default | Description                                                                                    |
| ------------------ | ------------ | ------- | ---------------------------------------------------------------------------------------------- |
| `host`             | string       | -       | **Required.** Hostname, IP address or unix socket path.                                        |
| `port`             | int          | -       | **Required.** TCP port (use `0` for unix sockets).                                             |
| `connect_timeout`  | float        | `0`     | Connection timeout, in seconds. `0` means no timeout.                                          |
| `connect_tries`    | int          | `1`     | Number of connection attempts before giving up.                                                |
| `read_timeout`     | float        | `0`     | Read timeout, in seconds. `0` uses the `default_socket_timeout` ini setting.                   |
| `persistent`       | bool         | `false` | Use persistent connections (`pconnect`).                                                       |
| `db_index`         | int          | `null`  | Database to `SELECT` after connecting.                                                         |
| `cache_ttl`        | int          | `60`    | TTL, in seconds, applied to every write. `0` means no expiration.                              |
| `cache_ttl_jitter` | bool         | `true`  | Add a random 10-99 seconds jitter to every TTL, to avoid cache stampedes.                      |
| `auth`             | string       | -       | Password for `AUTH`. `auth_password` is accepted as an alias.                                  |
| `username`         | string       | -       | ACL username (Redis >= 6). Requires `auth`.                                                    |
| `ssl`              | bool         | `false` | Connect over TLS.                                                                              |
| `ssl_context`      | array        | `[]`    | [SSL context options](https://www.php.net/manual/en/context.ssl.php), used when `ssl` is true. |
| `key_prefix`       | string       | `''`    | Prefix for every key. It is normalized to a lowercase slug (`My App` becomes `my-app`).        |
| `serializer`       | string       | `none`  | One of `none`, `php`, `igbinary`, `json`, `msgpack`.                                           |
| `compression`      | string       | `none`  | One of `none`, `lzf`, `zstd`, `lz4` (availability depends on how phpredis was built).          |
| `force_revalidate` | bool         | `false` | Make `has()`, `exists()` and `allKeys()` behave as if the database were empty.                 |

Unknown keys and invalid values throw an `InvalidArgumentException`. Every option also has a fluent setter (`setHost()`, `setCacheTTL()`, ...) that can be called at any time: connection parameters drop the current connection, while the database, prefix, serializer and compression are applied to the live connection immediately.

> **Note:** without a serializer, phpredis stores values as plain strings, so arrays and objects can't be stored. Use a serializer if you store anything other than strings and numbers. Conversely, `incr()`, `decr()` and `append()` work on raw values: don't use them on keys written by `set()` with a serializer.

## API

**Strings**: `get`, `set`, `mget`, `mset`, `setnx`, `msetnx`, `getset`, `incr`, `decr`, `append`, `strlen`, `getbit`, `setbit`

**Keys**: `exists`, `has`, `delete`, `deleteAll` (keys of the current prefix), `rename`, `renamenx`, `expire` (milliseconds), `expireat` (unix timestamp), `ttl` (milliseconds), `persist`, `allKeys`, `allValues`

**Sets**: `sadd`, `srem`, `spop`, `srandmember`, `scard`, `sismember`, `smembers`, `sdiffstore`, `sinterstore`, `sunionstore`

**Scripting**: `evaluate`, `evalSha`

**Server**: `dbSize`, `flushDb`, `flushAll`, `flushAllAsync`, `disconnect`

`allKeys()` and `allValues()` use `SCAN` and `MGET`, so they don't block the server, but they still walk the whole database: avoid them on large datasets.

## Exceptions

All exceptions extend `Mfonte\Redisw\Exception\RedisException`:

| Exception                         | Thrown when                                                       |
| --------------------------------- | ----------------------------------------------------------------- |
| `RedisNotConfiguredException`     | A command is run before host and port are configured.             |
| `RedisConnectionException`        | The connection can't be configured (e.g. `SELECT` fails).         |
| `RedisTriesOverConnectException`  | The connection failed `connect_tries` times (a `RedisConnectionException`). |
| `RedisKeyNotFoundException`       | `get()` is called on a missing key.                               |
| `RedisImpossibleValueException`   | Redis returns an unexpected reply (e.g. `incr()` on a non-integer). |
| `RedisScriptExecutionException`   | A Lua script fails.                                               |
| `Cache\CacheException`            | A `SimpleCache` operation fails (implements PSR-16 `CacheException`). |

## Testing

```bash
composer install
docker run --rm -d -p 6379:6379 redis:7-alpine   # if you don't have a Redis server
composer check                                    # coding style, static analysis and tests
```

The integration tests only use databases 15 and 14 (configurable through `REDIS_DB` and `REDIS_DB_ALT`). See [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## Changelog, contributing and security

- [CHANGELOG.md](CHANGELOG.md) lists the changes of every release, and how to upgrade.
- [CONTRIBUTING.md](CONTRIBUTING.md) explains how to set up the project and send a pull request.
- [SECURITY.md](SECURITY.md) explains how to report a vulnerability privately.

## Credits

A big thank you goes to [alxmsl](https://github.com/alxmsl) for the base implementation on [alxmsl/Redis](https://github.com/alxmsl/Redis), and to [ukko](https://github.com/ukko) for the phpredis autocomplete stubs on [ukko/phpredis-phpdoc](https://github.com/ukko/phpredis-phpdoc), which made this package easier to write.

## License

Released under the [Apache License 2.0](LICENSE).
