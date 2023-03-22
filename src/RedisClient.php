<?php

namespace Mfonte\Redisw;

use Mfonte\Redisw\Exception\RedisConnectionException;
use Mfonte\Redisw\Exception\RedisImpossibleValueException;
use Mfonte\Redisw\Exception\RedisKeyNotFoundException;
use Mfonte\Redisw\Exception\RedisNotConfiguredException;
use Mfonte\Redisw\Exception\RedisScriptExecutionException;
use Mfonte\Redisw\Exception\RedisTriesOverConnectException;
use Mfonte\Redisw\Interfaces\RedisInterface;
use Mfonte\Redisw\Spec\Redis;

/**
 * Redis Wrapper on the phpredis extension.
 *
 * @author Maurizio Fonte <fonte.maurizio@gmail.com>
 * @date 2023-03-21
 */
final class RedisClient implements RedisInterface
{
    /**
     * Pre-defined redis cache ttl seconds
     *
     * @var int
     */
    private static $defaultRedisCacheTtlSeconds = 60;

    /**
     * @var bool are we correctly connected to redis, or not
     */
    private bool $isConnected = false;

    /**
     * @var string redis instance hostname
     */
    private string $host = '';

    /**
     * @var int redis instance port
     */
    private int $port = -1;

    /**
     * @var float redis instance connect timeout
     */
    private float $connectTimeout = 0;

    /**
     * @var int number of tries for connect to redis instance
     */
    private $connectTries = 1;

    /**
     * @var bool use persistence connection, or not
     */
    private bool $persistent = false;

    /**
     * @var int|null redis instance database index
     */
    private ?int $dbIndex = null;

    /**
     * @var int redis instance cache ttl
     */
    private int $cacheTTL = 0;

    /**
     * @var string redis instance auth password
     */
    private string $authPassword = '';

    /**
     * @var bool use ssl connection, or not
     */
    private bool $ssl = false;

    /**
     * @var string redis key prefix
     */
    private string $keyPrefix = '';

    /**
     * @var string|null compression method
     */
    private ?string $compression = null;

    /**
     * @var Redis phpredis object instance
     */
    private ?\Redis $phpredis = null;

    /**
     * @var int number of hits
     */
    private $hits = 0;

    /**
     * @var int number of misses
     */
    private $misses = 0;

    /**
     * Static Redisw factory method
     *
     * @param array $config [host, port, connect_timeout, connect_tries, persistent, db_index, cache_ttl, auth_password, ssl, key_prefix]
     *
     * @return self
     */
    public static function instance(array $config) : self
    {
        if (! array_key_exists('host', $config) || ! array_key_exists('port', $config)) {
            throw new \InvalidArgumentException();
        }

        $client = new self();
        $client->setHost($config['host'])->setPort($config['port']);
        if (array_key_exists('connect_timeout', $config)) {
            $client->setConnectTimeout($config['connect_timeout']);
        }
        if (array_key_exists('connect_tries', $config)) {
            $client->setConnectTries($config['connect_tries']);
        }
        if (array_key_exists('persistent', $config)) {
            $client->setPersistent($config['persistent']);
        }
        if (array_key_exists('db_index', $config)) {
            $client->setDbIndex($config['db_index']);
        }
        if (array_key_exists('cache_ttl', $config)) {
            $client->setCacheTTL($config['cache_ttl']);
        } else {
            $client->setCacheTTL(self::$defaultRedisCacheTtlSeconds);
        }
        if (array_key_exists('auth_password', $config)) {
            $client->setAuthPassword($config['auth_password']);
        }
        if (array_key_exists('ssl', $config)) {
            $client->setSsl($config['ssl']);
        }
        if (array_key_exists('key_prefix', $config)) {
            $client->setKeyPrefix($config['key_prefix']);
        }

        return $client;
    }

    /**
     * Getter of phpredis object
     *
     * @return Redis phpredis object instance
     *
     * @throws RedisNotConfiguredException if any of required redis connect parameters are loose
     */
    private function connection()
    {
        if (is_null($this->phpredis)) {
            if ($this->isConfigured()) {
                $this->reconnect();
            } else {
                throw new RedisNotConfiguredException();
            }
        }

        return $this->phpredis;
    }

    /**
     * Check required connection parameters configuration method
     *
     * @return bool check result
     */
    private function isConfigured() : bool
    {
        return ! empty($this->host) && $this->port >= 0 && $this->port <= 65535;
    }

    /**
     * Reconnect to the redis instance
     *
     * @return bool connection result. Always true.
     *
     * @throws RedisConnectionException if connection could not established by RedisException cause
     * @throws RedisTriesOverConnectException if connection could not established because tries was over
     */
    private function reconnect() : bool
    {
        $count = 0;
        do {
            $count += 1;

            try {
                // create new phpredis object
                $this->phpredis = new \Redis();

                // set host (with tls:// prefix if ssl is true)
                $host = ($this->ssl) ? 'tls://' . $this->host : $this->host;
                
                if ($this->persistent) {
                    $this->phpredis->pconnect($host, $this->port, $this->connectTimeout);
                } else {
                    $this->phpredis->connect($host, $this->port, $this->connectTimeout);
                }

                // check if we have to authenticate
                if (! empty($this->authPassword)) {
                    $this->phpredis->auth($this->authPassword);
                }

                // check if we have a DB index to select
                if ($this->dbIndex !== null) {
                    $this->phpredis->select($this->dbIndex);
                }
                
                // check if we have a Key prefix
                if (! empty($this->keyPrefix)) {
                    $this->phpredis->setOption(\Redis::OPT_PREFIX, $this->keyPrefix);
                }

                // if we have the IGBINARY serializer, set it. Otherwise, fallback to JSON, then to native PHP
                if (defined('\Redis::SERIALIZER_IGBINARY')) {
                    $this->phpredis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_IGBINARY);
                } elseif (defined('\Redis::SERIALIZER_JSON')) {
                    $this->phpredis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_JSON);
                } else {
                    $this->phpredis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
                }

                // check if we have to compress
                if ($this->compression !== null) {
                    switch($this->compression) {
                        case 'lzf':
                            $compression = \Redis::COMPRESSION_LZF;

                            break;
                        case 'zstd':
                            $compression = \Redis::COMPRESSION_ZSTD;

                            break;
                        case 'lz4':
                            $compression = \Redis::COMPRESSION_LZ4;

                            break;
                        default:
                            $compression = \Redis::COMPRESSION_NONE;
                    }
                    $this->phpredis->setOption(\Redis::OPT_COMPRESSION, $compression);
                }

                // test ping to check if connection is ok
                if ($this->phpredis->ping('pong') === 'pong') {
                    $this->isConnected = true;

                    return true;
                } else {
                    throw new RedisConnectionException("Redis Ping test failed");
                }
            } catch (\RedisException $ex) {
                throw new RedisConnectionException("Redis Connection failed: " . $ex->getMessage(), $ex->getCode(), $ex);
            }
        } while ($count < $this->connectTries);

        $this->phpredis = null;

        throw new RedisTriesOverConnectException("Redis Connection failed after $count tries");
    }

    /**
     * Encodes a value to be stored inside a Redis key.
     * This is to avoid issues with serialization of null, false and true, and to avoid errors while getting the value back from Redis.
     * In fact, literal FALSE is considered as an exception.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private function encode($value)
    {
        if (is_null($value)) {
            return '##REDIS__NULL__VAR##';
        }
        if ($value === false) {
            return '##REDIS__FALSE__VAR##';
        }
        if ($value === true) {
            return '##REDIS__TRUE__VAR##';
        }

        return $value;
    }

    /**
     * Decodes a value stored inside a Redis key.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private function decode($value)
    {
        if ($value === '##REDIS__NULL__VAR##') {
            return null;
        }
        if ($value === '##REDIS__FALSE__VAR##') {
            return false;
        }
        if ($value === '##REDIS__TRUE__VAR##') {
            return true;
        }

        return $value;
    }

    /**
     * Getter of isConnected parameter
     *
     * @return bool
     */
    public function getIsConnected() : bool
    {
        return $this->isConnected;
    }

    /**
     * Setter of connection timeout parameter
     *
     * @param float $connectTimeout connection timeout value
     *
     * @throws \InvalidArgumentException
     * @return RedisClient self
     */
    public function setConnectTimeout($connectTimeout) : RedisClient
    {
        if (! is_numeric($connectTimeout) || (float)$connectTimeout < 0) {
            throw new \InvalidArgumentException("Invalid connect timeout value. Connect timeout value must be a positive float.");
        }
        
        $this->connectTimeout = (float) $connectTimeout;

        return $this;
    }

    /**
     * Getter of connection timeout exception
     *
     * @return float connect timeout value
     */
    public function getConnectTimeout() : float
    {
        return $this->connectTimeout;
    }

    /**
     * Setter of number of connection tries
     *
     * @param int $connectTries connection tries count
     *
     * @throws \InvalidArgumentException
     * @return RedisClient self
     */
    public function setConnectTries($connectTries) : RedisClient
    {
        if (! is_numeric($connectTries) || (int)$connectTries < 1) {
            throw new \InvalidArgumentException("Invalid connect tries value. Connect tries value must be a positive integer.");
        }

        $this->connectTries = (int) $connectTries;

        return $this;
    }

    /**
     * Getter of number of connection tries
     *
     * @return int connection tries count
     */
    public function getConnectTries() : int
    {
        return $this->connectTries;
    }

    /**
     * Setter for redis instance hostname or ip address
     *
     * @param string $host hostname or ip address
     *
     * @throws \InvalidArgumentException
     * @return RedisClient self
     */
    public function setHost($host) : RedisClient
    {
        if (! is_string($host) || empty($host)) {
            throw new \InvalidArgumentException("Invalid host value. Host value must be a non empty string.");
        }

        $this->host = (string) $host;

        return $this;
    }

    /**
     * Getter of redis instance hostname
     *
     * @return string redis instance hostname or ip address
     */
    public function getHost() : ?string
    {
        return $this->host;
    }

    /**
     * Setter of redis instance connection port
     *
     * @param int $port redis instance connection port
     *
     * @throws \InvalidArgumentException
     * @return RedisClient self
     */
    public function setPort($port) : RedisClient
    {
        if (! is_numeric($port) || (int)$port < 0 || (int)$port > 65535) {
            throw new \InvalidArgumentException("Invalid port value. Port value must be a number between 0 and 65535.");
        }

        $this->port = $port;

        return $this;
    }

    /**
     * Getter of redis instance connection port
     *
     * @return int redis instance connection port
     */
    public function getPort() : int
    {
        return $this->port;
    }

    /**
     * Setter of redis instance connection ssl
     *
     * @param bool $ssl redis instance connection ssl
     *
     * @return RedisClient self
     */
    public function setSsl($ssl) : RedisClient
    {
        if (! is_bool($ssl)) {
            throw new \InvalidArgumentException("Invalid ssl value. Ssl value must be a true/false boolean.");
        }

        $this->ssl = $ssl;

        return $this;
    }

    /**
     * Getter of redis instance connection ssl
     *
     * @return bool redis instance connection ssl
     */
    public function getSsl() : bool
    {
        return $this->ssl;
    }

    /**
     * Setter of redis instance auth password
     *
     * @param string $authPassword redis instance auth password
     *
     * @return RedisClient self
     */
    public function setAuthPassword($authPassword) : RedisClient
    {
        if (! is_string($authPassword) || empty($authPassword)) {
            throw new \InvalidArgumentException("Invalid auth password. Auth password must be a non empty string.");
        }
        
        $this->authPassword = $authPassword;

        return $this;
    }

    /**
     * Getter of redis instance auth password
     *
     * @return string redis instance auth password
     */
    public function getAuthPassword() : ?string
    {
        return $this->authPassword;
    }

    /**
     * Use persistent connection or not
     *
     * @param bool $persistent if is set to true, pconnect will use, overwise not
     *
     * @return RedisClient self
     */
    public function setPersistent($persistent) : RedisClient
    {
        if (! is_bool($persistent)) {
            throw new \InvalidArgumentException("Invalid persistent value. Persistent value must be a true/false boolean.");
        }

        $this->persistent = $persistent;

        return $this;
    }

    /**
     * Use persistent connection or not
     *
     * @return bool if is set to true, pconnect will use, overwise not
     */
    public function getPersistent() : bool
    {
        return $this->persistent;
    }

    /**
     * Setter of redis instance Database Index
     *
     * @param int $dbIndex redis instance Database Index
     *
     * @return RedisClient self
     */
    public function setDbIndex($dbIndex) : RedisClient
    {
        if (! is_numeric($dbIndex) || (int)$dbIndex < 0 || (int)$dbIndex > 15) {
            throw new \InvalidArgumentException("Invalid database index. Database index must be a positive integer between 0 and 15.");
        }

        $this->dbIndex = (int) $dbIndex;

        return $this;
    }

    /**
     * Getter of redis instance Database Index
     *
     * @return int redis instance Database Index
     */
    public function getDbIndex() : int
    {
        return $this->dbIndex;
    }

    /**
     * Setter of cache ttl
     *
     * @param int $ttl cache ttl
     *
     * @return RedisClient self
     */
    public function setCacheTTL($ttl) : RedisClient
    {
        if (! is_numeric($ttl) || (int)$ttl < 0) {
            throw new \InvalidArgumentException("Invalid cache ttl value. Ttl must be a positive integer representing the number of seconds of cache lifetime.");
        }

        // ttl must be converted to milliseconds.
        // we're also adding a random value between 10 and 99 seconds to avoid cache stampede
        $ttl = (int) $ttl * 1000 + rand(10000, 99000);

        $this->cacheTTL = $ttl;

        return $this;
    }

    /**
     * Getter of cache ttl
     *
     * @return int cache ttl
     */
    public function getCacheTTL() : int
    {
        return $this->cacheTTL;
    }

    /**
     * Setter of Redis Compression.
     * Valid compression values are: lzf, zstd, lz4
     *
     * @param string $compression compression
     *
     * @return RedisClient self
     */
    public function setCompression($compression) : RedisClient
    {
        if (! is_string($compression) || ! in_array($compression, ['lzf', 'zstd', 'lz4'])) {
            throw new \InvalidArgumentException("Invalid compression value. Valid values are: lzf, zstd, lz4");
        }

        if ($compression === 'lzf' && ! defined('\Redis::COMPRESSION_LZF')) {
            throw new \InvalidArgumentException("LZF compression is not supported by your version of phpredis.");
        }

        if ($compression === 'zstd' && ! defined('\Redis::COMPRESSION_ZSTD')) {
            throw new \InvalidArgumentException("ZSTD compression is not supported by your version of phpredis.");
        }

        if ($compression === 'lz4' && ! defined('\Redis::COMPRESSION_LZ4')) {
            throw new \InvalidArgumentException("LZ4 compression is not supported by your version of phpredis.");
        }

        $this->compression = $compression;

        return $this;
    }

    /**
     * Getter of Redis Compression.
     * Valid compression values are: lzf, zstd, lz4
     *
     * @return string compression
     */
    public function getCompression() : string
    {
        return $this->compression;
    }

    /**
     * Setter of key prefix
     *
     * @param string $keyPrefix key prefix
     *
     * @return RedisClient self
     */
    public function setKeyPrefix(string $keyPrefix) : RedisClient
    {
        // the key prefix must be a slugged string
        $keyPrefix = preg_replace('/[^a-z0-9\-]/', '-', strtolower($keyPrefix));
        $keyPrefix = trim(preg_replace('/\-+/', '-', $keyPrefix), '-');

        $this->keyPrefix = $keyPrefix;

        return $this;
    }

    /**
     * Getter of key prefix
     *
     * @return string key prefix
     */
    public function getKeyPrefix() : string
    {
        return $this->keyPrefix;
    }

    /**
     * Getter of cache hits (keys found when checking for has() or exists())
     *
     * @return int
     */
    public function getHits() : int
    {
        return $this->hits;
    }

    /**
     * Getter of cache misses (keys not found when checking for has() or exists())
     *
     * @return int
     */
    public function getMisses() : int
    {
        return $this->misses;
    }

    /*
     * custom redis methods
     */

    /**
     * Get all keys and relative values
     *
     * @return array
     */
    public function allValues()
    {
        try {
            $keys = $this->connection()->keys('*');
            $result = array_map(function ($key) {
                return $this->connection()->get($key);
            }, $keys);
                    
            return array_combine($keys, $result);
        } catch (\Exception $e) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Get all keys
     *
     * @return array
     */
    public function allKeys()
    {
        try {
            return $this->connection()->keys('*');
        } catch (\Exception $e) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Flush all Redis keys asynchronously
     *
     * @return void
     */
    public function flushAll()
    {
        try {
            $this->connection()->rawCommand('FLUSHALL', 'ASYNC');
        } catch (\Exception $e) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Disconnect from Redis
     *
     * @return void
     */
    public function disconnect()
    {
        try {
            $this->connection()->close();
        } catch (\Exception $e) {
            // no-op. Do not throw exception on disconnect
        }
    }

    /**
     * Check if a key exists
     *
     * @param string $key key
     *
     * @return bool
     */
    public function has(string $key) : bool
    {
        return $this->exists($key);
    }

    /*
     * phpredis interface implementation
     */

    /**
     * Increment key value
     *
     * @param string $key key
     * @param int $value value for increment
     * @return int current value
     *
     * @throws RedisImpossibleValueException exception on impossible value
     * @throws RedisConnectionException exception on connection to redis instance
     */
    public function incr(string $key, int $value = 1) : int
    {
        $value = (int) $value;

        try {
            $result = ($value > 1)
                ? $this->connection()->incrBy($key, $value)
                : $this->connection()->incr($key);
            if ($result !== false) {
                return $result;
            }

            throw new RedisImpossibleValueException();
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Decrement key value
     *
     * @param string $key key
     * @param int $value value for increment
     * @return int current value
     *
     * @throws RedisImpossibleValueException exception on impossible value
     * @throws RedisConnectionException exception on connection to redis instance
     */
    public function decr(string $key, int $value = 1) : int
    {
        $value = (int) $value;

        try {
            $result = ($value > 1)
                ? $this->connection()->decrBy($key, $value)
                : $this->connection()->decr($key);
            if ($result !== false) {
                return $result;
            }

            throw new RedisImpossibleValueException();
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Append string value
     *
     * @param string $key key
     * @param mixed $value appended value
     * @return int length of a key after append
     *
     * @throws RedisImpossibleValueException exception on impossible value
     * @throws RedisConnectionException exception on connection to redis instance
     */
    public function append(string $key, $value) : int
    {
        try {
            $result = $this->connection()->append($key, $value);
            if ($result !== false) {
                return $result;
            }

            throw new RedisImpossibleValueException();
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Get key value
     *
     * @param string $key key
     *
     * @return mixed value
     *
     * @throws RedisConnectionException exception on connection to redis instance
     * @throws RedisKeyNotFoundException when key not found
     */
    public function get(string $key)
    {
        try {
            $result = $this->connection()->get($key);
            if ($result === false) {
                throw new RedisKeyNotFoundException();
            }

            return $this->decode($result);
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Get multiple keys values
     *
     * @param array $keys keys
     * @return array values
     *
     * @throws RedisConnectionException exception on connection to redis instance
     * @throws RedisImpossibleValueException when impossible value
     */
    public function mget(array $keys)
    {
        try {
            $result = $this->connection()->mGet($keys);
            if ($result !== false) {
                $result = array_map([$this, 'decode'], $result);

                return array_combine($keys, $result);
            }

            throw new RedisImpossibleValueException();
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Set key value
     *
     * @param string $key key
     * @param mixed $value value
     * @return bool operation result
     *
     * @throws RedisConnectionException exception on connection to redis instance
     * @throws RedisImpossibleValueException exception on impossible value
     */
    public function set(string $key, $value)
    {
        try {
            if ($this->cacheTTL > 0) {
                $result = $this->connection()->psetex($key, $this->cacheTTL, $this->encode($value));
            } else {
                $result = $this->connection()->set($key, $this->encode($value));
            }

            if ($result !== false) {
                return $result;
            }

            throw new RedisImpossibleValueException();
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Set multiple key values
     *
     * @param array $values key and values
     * @return bool operation result
     *
     * @throws RedisConnectionException exception on connection to redis instance
     */
    public function mset(array $values)
    {
        try {
            if ($this->cacheTTL > 0) {
                $result = true;
                foreach ($values as $key => $value) {
                    $result = $result && $this->connection()->psetex($key, $this->cacheTTL, $this->encode($value));
                }

                return $result;
            } else {
                $values = array_map([$this, 'encode'], $values);

                return $this->connection()->mset($values);
            }
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Set key value if not exists
     *
     * @param string $key key
     * @param mixed $value value
     * @return bool returns true, if operation complete succesfull, else false
     *
     * @throws RedisConnectionException exception on connection to redis instance
     */
    public function setnx(string $key, $value)
    {
        try {
            if ($this->cacheTTL > 0) {
                $result = $this->connection()->set($key, $this->encode($value), ['nx', 'px' => $this->cacheTTL]);
            } else {
                $result = $this->connection()->setnx($key, $this->encode($value));
            }

            return $result;
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Set multiple key values
     *
     * @param array $values key and values
     * @return bool operation result
     *
     * @throws RedisConnectionException exception on connection to redis instance
     */
    public function msetnx(array $values)
    {
        try {
            if ($this->cacheTTL > 0) {
                $result = true;
                foreach ($values as $key => $value) {
                    $result = $result && $this->connection()->set($key, $this->encode($value), ['nx', 'px' => $this->cacheTTL]);
                }

                return $result;
            }

            $values = array_map([$this, 'encode'], $values);

            return $this->connection()->msetnx($values);
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * GetSet implementation
     *
     * @param string $key key
     * @param mixed $value value
     * @return bool|mixed previous value of a key. If key did not set, method returns false
     *
     * @throws RedisConnectionException exception on connection to redis instance
     */
    public function getset(string $key, $value)
    {
        try {
            return $this->connection()->getSet($key, $this->encode($value));
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Delete key or keys
     *
     * @param string|array $keys key or keys array
     * @return int count of deleted keys
     *
     * @throws RedisConnectionException exception on connection to redis instance
     */
    public function delete($keys)
    {
        try {
            $result = $this->connection()->del($keys);
            if ($result !== false) {
                return $result;
            }

            throw new RedisImpossibleValueException();
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Check if key exists
     *
     * @param string $key key
     * @return bool check result
     *
     * @throws RedisConnectionException exception on connection to redis instance
     */
    public function exists(string $key) : bool
    {
        try {
            $exists = $this->connection()->exists($key);
            if ($exists) {
                $this->hits++;
            } else {
                $this->misses++;
            }

            return $exists;
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Rename key
     *
     * @param string $source current key name
     * @param string $destination needed key name
     * @return bool operation result. If false, source key not found
     *
     * @throws RedisConnectionException exception on connection to redis instance
     */
    public function rename(string $source, string $destination)
    {
        try {
            return $this->connection()->rename($source, $destination);
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Rename key if needed key name was not
     *
     * @param string $source current key name
     * @param string $destination needed key name
     * @return bool operation result. If false, source key not found or needed key name found
     *
     * @throws RedisConnectionException exception on connection to redis instance
     */
    public function renamenx(string $source, string $destination)
    {
        try {
            return $this->connection()->renamenx($source, $destination);
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Get string length of a key
     *
     * @param string $key key
     * @return int key value length
     *
     * @throws RedisConnectionException exception on connection to redis instance
     * @throws RedisImpossibleValueException exception on impossible value
     */
    public function strlen(string $key)
    {
        try {
            $result = $this->connection()->strlen($key);
            if ($result !== false) {
                return $result;
            }

            throw new RedisImpossibleValueException();
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Set ttl for a key
     *
     * @param string $key key
     * @param int $timeout ttl in milliseconds
     * @return bool operation result. If false ttl cound not be set, or key not found
     *
     * @throws RedisConnectionException exception on connection to redis instance
     */
    public function expire(string $key, int $timeout)
    {
        try {
            return $this->connection()->pexpire($key, $timeout);
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Set time of life for the key
     *
     * @param string $key key
     * @param int $timestamp unix timestamp of time of death
     * @return bool operation result. If false timestamp cound not be set, or key not found
     *
     * @throws RedisConnectionException exception on connection to redis instance
     */
    public function expireat(string $key, int $timestamp)
    {
        try {
            return $this->connection()->expireat($key, $timestamp);
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Get ttl of the key
     *
     * @param string $key key
     * @return int|bool ttl in milliseconds or false, if ttl is not set or key not found
     *
     * @throws RedisConnectionException exception on connection to redis instance
     */
    public function ttl(string $key)
    {
        try {
            $result = $this->connection()->pttl($key);

            return ($result != -1) ? $result : false;
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Remove ttl from the key
     *
     * @param string $key key
     * @return bool if true ttl was removed successful, if false ttl did not set, or key not found
     *
     * @throws RedisConnectionException exception on connection to redis instance
     */
    public function persist(string $key)
    {
        try {
            return $this->connection()->persist($key);
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Get key bit
     *
     * @param string $key key
     * @param int $offset bit offset
     * @return int bit value at the offset
     *
     * @throws RedisConnectionException exception on connection to redis instance
     * @throws RedisImpossibleValueException exception on impossible value
     */
    public function getbit(string $key, int $offset) : int
    {
        $offset = (int) $offset;

        try {
            $result = $this->connection()->getBit($key, $offset);
            if ($result !== false) {
                return $result;
            }

            throw new RedisImpossibleValueException();
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Set key bit
     *
     * @param string $key key
     * @param int $offset bit offset
     * @param int $value bit value. May be 0 or 1
     * @return int bit value before operation complete
     *
     * @throws RedisConnectionException exception on connection to redis instance
     * @throws RedisImpossibleValueException exception on impossible value
     */
    public function setbit(string $key, int $offset, int $value) : int
    {
        $offset = (int) $offset;
        $value = (int) (bool) $value;

        try {
            $result = $this->connection()->setBit($key, $offset, $value);
            if ($result !== false) {
                return $result;
            }

            throw new RedisImpossibleValueException();
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Evaluate Lua code
     *
     * @param string $code string of Lua code
     * @param array $arguments array of Lua script arguments
     * @return mixed code execution result
     *
     * @throws RedisConnectionException exception on connection to redis instance
     * @throws RedisScriptExecutionException when script execution faled
     */
    public function evaluate(string $code, array $arguments = [])
    {
        try {
            if (empty($arguments)) {
                $result = $this->connection()->eval($code);
            } else {
                $result = $this->connection()->eval($code, $arguments, count($arguments));
            }

            $lastError = $this->connection()->getLastError();
            $this->connection()->clearLastError();
            if (is_null($lastError)) {
                return $result;
            }

            throw new RedisScriptExecutionException($lastError);
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Evaluate Lua code by hash
     *
     * @param string $sha SHA1 string of Lua code
     * @param array $arguments array of Lua script arguments
     * @return mixed code execution result
     *
     * @throws RedisConnectionException exception on connection to redis instance
     * @throws RedisScriptExecutionException when script execution faled
     */
    public function evalSha(string $sha, array $arguments = [])
    {
        try {
            if (empty($arguments)) {
                $result = $this->connection()->evalSha($sha);
            } else {
                $result = $this->connection()->evalSha($sha, $arguments, count($arguments));
            }

            $lastError = $this->connection()->getLastError();
            $this->connection()->clearLastError();
            if (is_null($lastError)) {
                return $result;
            }

            throw new RedisScriptExecutionException($lastError);
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Add member to the set
     *
     * @param string $key key
     * @param mixed $member set member
     * @return int count of added members
     *
     * @throws RedisConnectionException exception on connection to redis instance
     * @throws RedisImpossibleValueException exception on impossible value
     */
    public function sadd(string $key, $member)
    {
        try {
            $result = $this->connection()->sAdd($key, $member);
            if ($result !== false) {
                return $result;
            }

            throw new RedisImpossibleValueException();
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Pop (remove and return) a random member from the set
     *
     * @param string $key key
     * @return mixed set member
     *
     * @throws RedisConnectionException exception on connection to redis instance
     */
    public function spop(string $key)
    {
        try {
            return $this->connection()->sPop($key);
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Return random member from the set
     *
     * @param string $key key
     * @return mixed set member
     *
     * @throws RedisConnectionException exception on connection to redis instance
     */
    public function srandmember(string $key)
    {
        try {
            return $this->connection()->sRandMember($key);
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Returns size of the set
     *
     * @param string $key set
     * @return int members count of the set
     *
     * @throws RedisConnectionException exception on connection to redis instance
     */
    public function scard(string $key)
    {
        try {
            return $this->connection()->sCard($key);
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Check that member is a member of the set
     *
     * @param string $key key
     * @param mixed $member member
     * @return bool check result
     *
     * @throws RedisConnectionException exception on connection to redis instance
     */
    public function sismembers(string $key, $member)
    {
        try {
            return $this->connection()->sIsMember($key);
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Returns all members of the set
     *
     * @param string $key key
     * @return array all members of the set
     *
     * @throws RedisConnectionException exception on connection to redis instance
     */
    public function smembers(string $key)
    {
        try {
            return $this->connection()->sMembers($key);
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Remove member from the set
     *
     * @param string $key key
     * @param mixed $member set member
     * @return int count of removed elements
     *
     * @throws RedisConnectionException exception on connection to redis instance
     */
    public function srem(string $key, $member)
    {
        try {
            return $this->connection()->sRem($key);
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }

    /**
     * Create difference set
     *
     * @param string $destination key for result set
     * @param array $sources source keys
     * @return int size of result set
     *
     * @throws RedisConnectionException exception on connection to redis instance
     */
    public function sdiffstore(string $destination, array $sources)
    {
        try {
            return call_user_func_array([
                $this->connection(),
                'sDiffStore',
            ], array_merge([$destination], $sources));
        } catch (\RedisException $ex) {
            throw new RedisConnectionException();
        }
    }
}
