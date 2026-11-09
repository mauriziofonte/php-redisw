<?php

declare(strict_types=1);

namespace Mfonte\Redisw\Cache;

use Mfonte\Redisw\Exception\RedisException;
use Mfonte\Redisw\Exception\RedisKeyNotFoundException;
use Mfonte\Redisw\RedisClient;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 (Simple Cache) adapter for {@see RedisClient}.
 *
 * - A null TTL applies the client's configured cache TTL (including its anti-stampede jitter).
 * - An explicit TTL is applied exactly; a TTL <= 0 deletes the item, as required by PSR-16.
 * - clear() only deletes the keys of the client's key prefix, or flushes the current database when no prefix is set.
 */
final class SimpleCache implements CacheInterface
{
    /**
     * Characters reserved by PSR-16, that can't be used in keys.
     */
    private const RESERVED_CHARACTERS = '{}()/\\@:';

    public function __construct(private readonly RedisClient $client)
    {
    }

    public function getClient(): RedisClient
    {
        return $this->client;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        self::assertValidKey($key);

        try {
            return $this->client->get($key);
        } catch (RedisKeyNotFoundException) {
            return $default;
        } catch (RedisException $e) {
            throw self::wrap($e);
        }
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        self::assertValidKey($key);
        $seconds = self::ttlToSeconds($ttl);

        try {
            if ($seconds !== null && $seconds <= 0) {
                $this->client->delete($key);

                return true;
            }

            return $this->client->set($key, $value, $seconds);
        } catch (RedisException $e) {
            throw self::wrap($e);
        }
    }

    public function delete(string $key): bool
    {
        self::assertValidKey($key);

        try {
            $this->client->delete($key);

            return true;
        } catch (RedisException $e) {
            throw self::wrap($e);
        }
    }

    public function clear(): bool
    {
        try {
            if ($this->client->getKeyPrefix() === '') {
                $this->client->flushDb();
            } else {
                $this->client->deleteAll();
            }

            return true;
        } catch (RedisException $e) {
            throw self::wrap($e);
        }
    }

    /**
     * @param iterable<mixed> $keys
     *
     * @return array<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keys = self::validKeys($keys);
        if ($keys === []) {
            return [];
        }

        try {
            return $this->client->mget($keys, $default);
        } catch (RedisException $e) {
            throw self::wrap($e);
        }
    }

    /**
     * @param iterable<mixed, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $items = [];
        foreach ($values as $key => $value) {
            $items[self::validKey($key)] = $value;
        }

        if ($items === []) {
            return true;
        }

        $seconds = self::ttlToSeconds($ttl);

        try {
            if ($seconds !== null && $seconds <= 0) {
                $this->client->delete(array_map('strval', array_keys($items)));

                return true;
            }

            return $this->client->mset($items, $seconds);
        } catch (RedisException $e) {
            throw self::wrap($e);
        }
    }

    /**
     * @param iterable<mixed> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $keys = self::validKeys($keys);
        if ($keys === []) {
            return true;
        }

        try {
            $this->client->delete($keys);

            return true;
        } catch (RedisException $e) {
            throw self::wrap($e);
        }
    }

    public function has(string $key): bool
    {
        self::assertValidKey($key);

        try {
            return $this->client->has($key);
        } catch (RedisException $e) {
            throw self::wrap($e);
        }
    }

    /**
     * Validates a key coming from an iterable, where PHP may have turned numeric string keys into integers.
     *
     * @throws InvalidArgumentException
     */
    private static function validKey(mixed $key): string
    {
        if (is_int($key)) {
            $key = (string) $key;
        }

        if (! is_string($key)) {
            throw new InvalidArgumentException('Cache keys must be non-empty strings.');
        }

        self::assertValidKey($key);

        return $key;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function assertValidKey(string $key): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('Cache keys must be non-empty strings.');
        }

        if (strpbrk($key, self::RESERVED_CHARACTERS) !== false) {
            throw new InvalidArgumentException(sprintf(
                'Cache key "%s" contains one or more reserved characters: %s',
                $key,
                self::RESERVED_CHARACTERS
            ));
        }
    }

    /**
     * @param iterable<mixed> $keys
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    private static function validKeys(iterable $keys): array
    {
        $valid = [];
        foreach ($keys as $key) {
            $valid[] = self::validKey($key);
        }

        return $valid;
    }

    /**
     * @return int|null TTL in seconds, or null to use the client's default TTL
     */
    private static function ttlToSeconds(null|int|\DateInterval $ttl): ?int
    {
        if ($ttl instanceof \DateInterval) {
            $now = new \DateTimeImmutable();

            return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
        }

        return $ttl;
    }

    private static function wrap(RedisException $e): CacheException
    {
        return new CacheException($e->getMessage(), (int) $e->getCode(), $e);
    }
}
