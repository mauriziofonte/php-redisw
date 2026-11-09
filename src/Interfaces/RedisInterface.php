<?php

declare(strict_types=1);

namespace Mfonte\Redisw\Interfaces;

use Mfonte\Redisw\Exception\RedisConnectionException;
use Mfonte\Redisw\Exception\RedisException;
use Mfonte\Redisw\Exception\RedisImpossibleValueException;
use Mfonte\Redisw\Exception\RedisKeyNotFoundException;
use Mfonte\Redisw\Exception\RedisScriptExecutionException;

/**
 * Redis commands interface.
 *
 * Every method may throw a {@see RedisConnectionException} if the connection can't be established,
 * and a {@see RedisException} if phpredis reports an error.
 *
 * @author Maurizio Fonte <fonte.maurizio@gmail.com>
 */
interface RedisInterface
{
    /**
     * Increment the integer value of a key.
     *
     * @return int the value after the increment
     *
     * @throws RedisImpossibleValueException if the key holds a non-integer value
     * @throws RedisException
     */
    public function incr(string $key, int $value = 1): int;

    /**
     * Decrement the integer value of a key.
     *
     * @return int the value after the decrement
     *
     * @throws RedisImpossibleValueException if the key holds a non-integer value
     * @throws RedisException
     */
    public function decr(string $key, int $value = 1): int;

    /**
     * Append a string to the value of a key. The value is appended raw (it is not serialized).
     *
     * @return int the length of the string after the append
     *
     * @throws RedisImpossibleValueException
     * @throws RedisException
     */
    public function append(string $key, string|int|float $value): int;

    /**
     * Get the value of a key.
     *
     * @throws RedisKeyNotFoundException when the key does not exist
     * @throws RedisException
     */
    public function get(string $key): mixed;

    /**
     * Get the values of multiple keys. Missing keys are returned with the $default value.
     *
     * @param array<int, string> $keys
     * @param mixed              $default value returned for missing keys
     *
     * @return array<string, mixed> values, indexed by key
     *
     * @throws RedisException
     */
    public function mget(array $keys, mixed $default = false): array;

    /**
     * Set the value of a key.
     *
     * @param int|null $ttl TTL in seconds. null applies the configured cache TTL, 0 means "no expiration".
     *
     * @throws RedisImpossibleValueException
     * @throws RedisException
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * Set multiple keys atomically.
     *
     * @param array<string, mixed> $values values, indexed by key
     * @param int|null             $ttl    TTL in seconds. null applies the configured cache TTL, 0 means "no expiration".
     *
     * @throws RedisException
     */
    public function mset(array $values, ?int $ttl = null): bool;

    /**
     * Set the value of a key, only if it does not exist.
     *
     * @param int|null $ttl TTL in seconds. null applies the configured cache TTL, 0 means "no expiration".
     *
     * @return bool true if the key was set, false if it already existed
     *
     * @throws RedisException
     */
    public function setnx(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * Set multiple keys, only if none of them exists (all-or-nothing).
     *
     * @param array<string, mixed> $values values, indexed by key
     *
     * @return bool true if all keys were set, false if at least one key already existed
     *
     * @throws RedisException
     */
    public function msetnx(array $values): bool;

    /**
     * Atomically set the value of a key and return its previous value.
     *
     * @return mixed the previous value, or false if the key did not exist
     *
     * @throws RedisException
     */
    public function getset(string $key, mixed $value): mixed;

    /**
     * Delete one or more keys.
     *
     * @param string|array<int, string> $keys
     *
     * @return int the number of deleted keys
     *
     * @throws RedisException
     */
    public function delete(string|array $keys): int;

    /**
     * Check if a key exists.
     *
     * @throws RedisException
     */
    public function exists(string $key): bool;

    /**
     * Rename a key.
     *
     * @return bool false if the source key does not exist
     *
     * @throws RedisException
     */
    public function rename(string $source, string $destination): bool;

    /**
     * Rename a key, only if the destination key does not exist.
     *
     * @return bool false if the source key does not exist, or the destination key already exists
     *
     * @throws RedisException
     */
    public function renamenx(string $source, string $destination): bool;

    /**
     * Get the length of the string stored at a key.
     *
     * @throws RedisImpossibleValueException
     * @throws RedisException
     */
    public function strlen(string $key): int;

    /**
     * Set a TTL on a key.
     *
     * @param int $timeout TTL in milliseconds
     *
     * @return bool false if the key does not exist
     *
     * @throws RedisException
     */
    public function expire(string $key, int $timeout): bool;

    /**
     * Set the expiration of a key as a unix timestamp.
     *
     * @param int $timestamp unix timestamp, in seconds
     *
     * @return bool false if the key does not exist
     *
     * @throws RedisException
     */
    public function expireat(string $key, int $timestamp): bool;

    /**
     * Get the remaining TTL of a key.
     *
     * @return int|false TTL in milliseconds, or false if the key has no TTL or does not exist
     *
     * @throws RedisException
     */
    public function ttl(string $key): int|false;

    /**
     * Remove the TTL of a key.
     *
     * @return bool false if the key has no TTL or does not exist
     *
     * @throws RedisException
     */
    public function persist(string $key): bool;

    /**
     * Get the bit value at an offset.
     *
     * @throws RedisImpossibleValueException
     * @throws RedisException
     */
    public function getbit(string $key, int $offset): int;

    /**
     * Set the bit value at an offset.
     *
     * @param int $value bit value; any non-zero value is treated as 1
     *
     * @return int the previous bit value
     *
     * @throws RedisImpossibleValueException
     * @throws RedisException
     */
    public function setbit(string $key, int $offset, int $value): int;

    /**
     * Evaluate a Lua script.
     *
     * @param array<int, mixed> $arguments script arguments: the first $numKeys are KEYS, the rest are ARGV
     * @param int|null          $numKeys   number of arguments to pass as KEYS. Defaults to all of them.
     *
     * @throws RedisScriptExecutionException when the script fails
     * @throws RedisException
     */
    public function evaluate(string $code, array $arguments = [], ?int $numKeys = null): mixed;

    /**
     * Evaluate a cached Lua script by its SHA1 digest.
     *
     * @param array<int, mixed> $arguments script arguments: the first $numKeys are KEYS, the rest are ARGV
     * @param int|null          $numKeys   number of arguments to pass as KEYS. Defaults to all of them.
     *
     * @throws RedisScriptExecutionException when the script fails
     * @throws RedisException
     */
    public function evalSha(string $sha, array $arguments = [], ?int $numKeys = null): mixed;

    /**
     * Add a member to a set.
     *
     * @return int the number of members added
     *
     * @throws RedisImpossibleValueException
     * @throws RedisException
     */
    public function sadd(string $key, mixed $member): int;

    /**
     * Remove and return a random member of a set.
     *
     * @return mixed the member, or false if the set is empty
     *
     * @throws RedisException
     */
    public function spop(string $key): mixed;

    /**
     * Return a random member of a set.
     *
     * @return mixed the member, or false if the set is empty
     *
     * @throws RedisException
     */
    public function srandmember(string $key): mixed;

    /**
     * Get the number of members of a set.
     *
     * @throws RedisException
     */
    public function scard(string $key): int;

    /**
     * Check if a value is a member of a set.
     *
     * @throws RedisException
     */
    public function sismember(string $key, mixed $member): bool;

    /**
     * Get all the members of a set.
     *
     * @return array<int, mixed>
     *
     * @throws RedisException
     */
    public function smembers(string $key): array;

    /**
     * Remove a member from a set.
     *
     * @return int the number of members removed
     *
     * @throws RedisException
     */
    public function srem(string $key, mixed $member): int;

    /**
     * Store the difference between the first set and all the following ones.
     *
     * @param array<int, string> $sources
     *
     * @return int the size of the resulting set
     *
     * @throws RedisImpossibleValueException
     * @throws RedisException
     */
    public function sdiffstore(string $destination, array $sources): int;

    /**
     * Store the intersection of the given sets.
     *
     * @param array<int, string> $sources
     *
     * @return int the size of the resulting set
     *
     * @throws RedisImpossibleValueException
     * @throws RedisException
     */
    public function sinterstore(string $destination, array $sources): int;

    /**
     * Store the union of the given sets.
     *
     * @param array<int, string> $sources
     *
     * @return int the size of the resulting set
     *
     * @throws RedisImpossibleValueException
     * @throws RedisException
     */
    public function sunionstore(string $destination, array $sources): int;
}
