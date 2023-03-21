<?php

namespace Mfonte\Redisw\Interfaces;

/**
 * Redis commands interface
 * @author Maurizio Fonte <fonte.maurizio@gmail.com>
 * @date 2023-03-21
 */
interface RedisInterface
{
    /**
     * Increment key value
     * @param string $key key
     * @param int $value value for increment
     * @return int current value
     * @throws RedisConnectException exception on connection to redis instance
     */
    public function incr(string $key, int $value = 1);

    /**
     * Decrement key value
     * @param string $key key
     * @param int $value value for increment
     * @return int current value
     * @throws RedisConnectException exception on connection to redis instance
     */
    public function decr(string $key, int $value = 1);

    /**
     * Append string value
     * @param string $key key
     * @param mixed $value appended value
     * @return int length of a key after append
     * @throws RedisConnectException
     */
    public function append(string $key, $value);

    /**
     * Get key value
     * @param string $key key
     * @return mixed key value
     * @throws RedisConnectException exception on connection to redis instance
     * @throws RedisKeyNotFoundException when key not found
     */
    public function get(string $key);

    /**
     * Get multiple keys values
     * @param array $keys keys
     * @return array values
     * @throws RedisConnectException exception on connection to redis instance
     */
    public function mget(array $keys);

    /**
     * Set key value
     * @param string $key key
     * @param mixed $value value
     * @return bool operation result
     * @throws RedisConnectException exception on connection to redis instance
     */
    public function set(string $key, $value);

    /**
     * Set multiple key values
     * @param array $values key and values
     * @return bool operation result
     * @throws RedisConnectException exception on connection to redis instance
     */
    public function mset(array $values);

    /**
     * Set key value if not exists
     * @param string $key key
     * @param mixed $value value
     * @return bool returns true, if operation complete succesfull, else false
     * @throws RedisConnectException exception on connection to redis instance
     */
    public function setnx(string $key, $value);

    /**
     * Set multiple key values
     * @param array $values key and values
     * @return bool operation result
     * @throws RedisConnectException exception on connection to redis instance
     */
    public function msetnx(array $values);

    /**
     * GetSet implementation
     * @param string $key key
     * @param mixed $value value
     * @return bool|mixed previous value of a key. If key did not set, method returns false
     * @throws RedisConnectException exception on connection to redis instance
     */
    public function getset(string $key, $value);

    /**
     * Delete key or keys
     * @param string|array $keys key or keys array
     * @return int count of deleted keys
     * @throws RedisConnectException exception on connection to redis instance
     */
    public function delete($keys);

    /**
     * Check if key exists
     * @param string $key key
     * @return bool check result
     * @throws RedisConnectException exception on connection to redis instance
     */
    public function exists(string $key);

    /**
     * Rename key
     * @param string $source current key name
     * @param string $destination needed key name
     * @return bool operation result. If false, source key not found
     * @throws RedisConnectException exception on connection to redis instance
     */
    public function rename(string $source, string $destination);

    /**
     * Rename key if needed key name was not
     * @param string $source current key name
     * @param string $destination needed key name
     * @return bool operation result. If false, source key not found or needed key name found
     * @throws RedisConnectException exception on connection to redis instance
     */
    public function renamenx(string $source, string $destination);

    /**
     * Get string length of a key
     * @param string $key key
     * @return int key value length
     * @throws RedisConnectException exception on connection to redis instance
     */
    public function strlen(string $key);

    /**
     * Set ttl for a key
     * @param string $key key
     * @param int $timeout ttl in milliseconds
     * @return bool operation result. If false ttl cound not be set, or key not found
     * @throws RedisConnectException exception on connection to redis instance
     */
    public function expire(string $key, int $timeout);

    /**
     * Set time of life for the key
     * @param string $key key
     * @param int $timestamp unix timestamp of time of death
     * @return bool operation result. If false timestamp cound not be set, or key not found
     * @throws RedisConnectException exception on connection to redis instance
     */
    public function expireat(string $key, int $timestamp);

    /**
     * Get ttl of the key
     * @param string $key key
     * @return int|bool ttl in milliseconds or false, if ttl is not set or key not found
     * @throws RedisConnectException exception on connection to redis instance
     */
    public function ttl(string $key);

    /**
     * Remove ttl from the key
     * @param string $key key
     * @return bool if true ttl was removed successful, if false ttl did not set, or key not found
     * @throws RedisConnectException exception on connection to redis instance
     */
    public function persist(string $key);

    /**
     * Get key bit
     * @param string $key key
     * @param int $offset bit offset
     * @return int bit value at the offset
     * @throws RedisConnectException exception on connection to redis instance
     */
    public function getbit(string $key, int $offset);

    /**
     * Set key bit
     * @param string $key key
     * @param int $offset bit offset
     * @param int $value bit value. May be 0 or 1
     * @return int bit value before operation complete
     * @throws RedisConnectException exception on connection to redis instance
     */
    public function setbit(string $key, int $offset, int $value);

    /**
     * Evaluate Lua code
     * @param string $code string of Lua code
     * @param array $arguments array of Lua script arguments
     * @return mixed code execution result
     * @throws RedisConnectException exception on connection to redis instance
     * @throws RedisScriptExecutionException when script execution faled
     */
    public function evaluate(string $code, array $arguments = []);

    /**
     * Evaluate Lua code by hash
     * @param string $sha SHA1 string of Lua code
     * @param array $arguments array of Lua script arguments
     * @return mixed code execution result
     * @throws RedisConnectException exception on connection to redis instance
     * @throws RedisScriptExecutionException when script execution faled
     */
    public function evalSha(string $sha, array $arguments = []);

    /**
     * Add member to the set
     * @param string $key key
     * @param mixed $member set member
     * @return int count of added members
     * @throws RedisConnectException exception on connection to redis instance
     */
    public function sadd(string $key, $member);

    /**
     * Pop (remove and return) a random member from the set
     * @param string $key key
     * @return mixed set member
     * @throws RedisConnectException exception on connection to redis instance
     */
    public function spop(string $key);

    /**
     * Return random member from the set
     * @param string $key key
     * @return mixed set member
     * @throws RedisConnectException exception on connection to redis instance
     */
    public function srandmember(string $key);

    /**
     * Returns size of the set
     * @param string $key set
     * @return int members count of the set
     * @throws RedisConnectException exception on connection to redis instance
     */
    public function scard(string $key);

    /**
     * Check that member is a member of the set
     * @param string $key key
     * @param mixed $member member
     * @return bool check result
     * @throws RedisConnectException exception on connection to redis instance
     */
    public function sismembers(string $key, $member);

    /**
     * Returns all members of the set
     * @param string $key key
     * @return array all members of the set
     * @throws RedisConnectException exception on connection to redis instance
     */
    public function smembers(string $key);

    /**
     * Remove member from the set
     * @param string $key key
     * @param mixed $member set member
     * @return int count of removed elements
     * @throws RedisConnectException exception on connection to redis instance
     */
    public function srem(string $key, $member);

    /**
     * Create difference set
     * @param string $destination key for result set
     * @param array $sources source keys
     * @return int size of result set
     * @throws RedisConnectException exception on connection to redis instance
     */
    public function sdiffstore(string $destination, array $sources);
}
