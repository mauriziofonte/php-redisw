<?php

declare(strict_types=1);

namespace Mfonte\Redisw;

use Mfonte\Redisw\Exception\RedisConnectionException;
use Mfonte\Redisw\Exception\RedisException as BaseRedisException;
use Mfonte\Redisw\Exception\RedisImpossibleValueException;
use Mfonte\Redisw\Exception\RedisKeyNotFoundException;
use Mfonte\Redisw\Exception\RedisNotConfiguredException;
use Mfonte\Redisw\Exception\RedisScriptExecutionException;
use Mfonte\Redisw\Exception\RedisTriesOverConnectException;
use Mfonte\Redisw\Interfaces\RedisInterface;

/**
 * Redis wrapper around the phpredis extension.
 *
 * @author Maurizio Fonte <fonte.maurizio@gmail.com>
 */
final class RedisClient implements RedisInterface
{
    /**
     * Default cache TTL, in seconds, used when no "cache_ttl" is given to {@see RedisClient::instance()}.
     */
    public const DEFAULT_CACHE_TTL_SECONDS = 60;

    /**
     * Lower and upper bounds (milliseconds) of the random jitter added to every TTL, to avoid cache stampedes.
     */
    private const TTL_JITTER_MIN_MS = 10000;
    private const TTL_JITTER_MAX_MS = 99000;

    /**
     * Number of keys fetched per SCAN / MGET round trip in {@see RedisClient::allKeys()} and {@see RedisClient::allValues()}.
     */
    private const SCAN_BATCH_SIZE = 1000;

    /**
     * Sentinels used to safely store values that phpredis can't round-trip (null, false, true).
     */
    private const NULL_SENTINEL = '##REDIS__NULL__VAR##';
    private const FALSE_SENTINEL = '##REDIS__FALSE__VAR##';
    private const TRUE_SENTINEL = '##REDIS__TRUE__VAR##';

    /**
     * Supported serializers, mapped to the name of the matching \Redis constant.
     */
    private const SERIALIZERS = [
        'none' => 'SERIALIZER_NONE',
        'php' => 'SERIALIZER_PHP',
        'igbinary' => 'SERIALIZER_IGBINARY',
        'json' => 'SERIALIZER_JSON',
        'msgpack' => 'SERIALIZER_MSGPACK',
    ];

    /**
     * Supported compression algorithms, mapped to the name of the matching \Redis constant.
     */
    private const COMPRESSIONS = [
        'none' => 'COMPRESSION_NONE',
        'lzf' => 'COMPRESSION_LZF',
        'zstd' => 'COMPRESSION_ZSTD',
        'lz4' => 'COMPRESSION_LZ4',
    ];

    /**
     * Configuration keys accepted by {@see RedisClient::instance()}.
     */
    private const CONFIG_KEYS = [
        'host',
        'port',
        'connect_timeout',
        'connect_tries',
        'read_timeout',
        'persistent',
        'db_index',
        'cache_ttl',
        'cache_ttl_jitter',
        'auth',
        'auth_password',
        'username',
        'ssl',
        'ssl_context',
        'key_prefix',
        'compression',
        'serializer',
        'force_revalidate',
    ];

    private bool $isConnected = false;

    private string $host = '';

    private int $port = -1;

    private float $connectTimeout = 0.0;

    private float $readTimeout = 0.0;

    private int $connectTries = 1;

    private bool $persistent = false;

    private ?int $dbIndex = null;

    /**
     * Cache TTL in seconds. 0 means "no expiration".
     */
    private int $cacheTtlSeconds = 0;

    private bool $cacheTtlJitter = true;

    private string $auth = '';

    private string $username = '';

    /**
     * @var array<string, mixed> SSL stream context options (see https://www.php.net/manual/en/context.ssl.php)
     */
    private array $sslContext = [];

    private bool $ssl = false;

    private string $keyPrefix = '';

    private ?string $compression = null;

    private ?string $serializer = null;

    private ?\Redis $phpredis = null;

    private int $hits = 0;

    private int $misses = 0;

    /**
     * If true, all calls to has(), exists() and allKeys() behave as if the key space were empty.
     */
    private bool $forceRevalidate = false;

    /**
     * Static factory method.
     *
     * @param array<string, mixed> $config Accepted keys: host, port, connect_timeout, connect_tries, read_timeout,
     *                                     persistent, db_index, cache_ttl, cache_ttl_jitter, auth (alias: auth_password),
     *                                     username, ssl, ssl_context, key_prefix, compression, serializer, force_revalidate
     *
     * @throws \InvalidArgumentException if "host" or "port" are missing, or if an unknown/invalid option is given
     */
    public static function instance(array $config): self
    {
        if (! array_key_exists('host', $config) || ! array_key_exists('port', $config)) {
            throw new \InvalidArgumentException('The "host" and "port" configuration keys are required.');
        }

        $unknown = array_diff(array_keys($config), self::CONFIG_KEYS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown configuration key(s): %s. Accepted keys are: %s.',
                implode(', ', $unknown),
                implode(', ', self::CONFIG_KEYS)
            ));
        }

        $client = new self();
        $client->setHost($config['host'])->setPort($config['port']);

        if (array_key_exists('connect_timeout', $config)) {
            $client->setConnectTimeout($config['connect_timeout']);
        }
        if (array_key_exists('connect_tries', $config)) {
            $client->setConnectTries($config['connect_tries']);
        }
        if (array_key_exists('read_timeout', $config)) {
            $client->setReadTimeout($config['read_timeout']);
        }
        if (array_key_exists('persistent', $config)) {
            $client->setPersistent($config['persistent']);
        }
        if (array_key_exists('db_index', $config)) {
            $client->setDbIndex($config['db_index']);
        }
        if (array_key_exists('cache_ttl_jitter', $config)) {
            $client->setCacheTTLJitter($config['cache_ttl_jitter']);
        }
        $client->setCacheTTL($config['cache_ttl'] ?? self::DEFAULT_CACHE_TTL_SECONDS);
        if (array_key_exists('auth', $config)) {
            $client->setAuthPassword($config['auth']);
        } elseif (array_key_exists('auth_password', $config)) {
            $client->setAuthPassword($config['auth_password']);
        }
        if (array_key_exists('username', $config)) {
            $client->setUsername($config['username']);
        }
        if (array_key_exists('ssl', $config)) {
            $client->setSsl($config['ssl']);
        }
        if (array_key_exists('ssl_context', $config)) {
            $client->setSslContext($config['ssl_context']);
        }
        if (array_key_exists('key_prefix', $config)) {
            $client->setKeyPrefix($config['key_prefix']);
        }
        if (array_key_exists('compression', $config)) {
            $client->setCompression($config['compression']);
        }
        if (array_key_exists('serializer', $config)) {
            $client->setSerializer($config['serializer']);
        }
        if (array_key_exists('force_revalidate', $config)) {
            $client->setForceRevalidate($config['force_revalidate']);
        }

        return $client;
    }

    /**
     * Lazily returns the connected phpredis instance.
     *
     * @throws RedisNotConfiguredException if host or port are not configured
     * @throws RedisConnectionException    if the connection could not be established
     */
    private function connection(): \Redis
    {
        if ($this->phpredis === null) {
            if (! $this->isConfigured()) {
                throw new RedisNotConfiguredException('Redis host and port must be configured before connecting.');
            }

            $this->reconnect();
        }

        /** @var \Redis */
        return $this->phpredis;
    }

    private function isConfigured(): bool
    {
        return $this->host !== '' && $this->port >= 0 && $this->port <= 65535;
    }

    /**
     * (Re)connects to the Redis instance, retrying up to "connect_tries" times.
     *
     * @throws RedisTriesOverConnectException if the connection could not be established within the allowed tries
     * @throws RedisConnectionException       if the connection was established but could not be configured
     */
    private function reconnect(): void
    {
        $this->phpredis = null;
        $this->isConnected = false;

        if ($this->username !== '' && $this->auth === '') {
            throw new RedisNotConfiguredException('A password ("auth") is required when a username is configured.');
        }

        $host = $this->ssl ? 'tls://' . $this->host : $this->host;
        $lastError = null;

        for ($attempt = 1; $attempt <= $this->connectTries; $attempt++) {
            $redis = new \Redis();

            try {
                if (! $this->open($redis, $host)) {
                    throw new RedisConnectionException(sprintf('Could not connect to %s:%d.', $host, $this->port));
                }

                if ($this->auth !== '') {
                    $credentials = $this->username !== '' ? [$this->username, $this->auth] : $this->auth;
                    if (! $redis->auth($credentials)) {
                        throw new RedisConnectionException('Redis authentication failed.');
                    }
                }

                if ($redis->ping('pong') !== 'pong') {
                    throw new RedisConnectionException('Redis ping test failed.');
                }

                $this->phpredis = $redis;
                $this->applyOptions();
                $this->isConnected = true;

                return;
            } catch (\RedisException | RedisConnectionException $e) {
                $lastError = $e;
                $this->phpredis = null;

                try {
                    $redis->close();
                } catch (\Throwable) {
                    // no-op: we are already handling a failure
                }
            }
        }

        throw new RedisTriesOverConnectException(
            sprintf(
                'Redis connection to %s:%d failed after %d attempt(s): %s',
                $host,
                $this->port,
                $this->connectTries,
                $lastError !== null ? $lastError->getMessage() : 'unknown error'
            ),
            0,
            $lastError
        );
    }

    /**
     * Opens the TCP/TLS/unix socket connection.
     */
    private function open(\Redis $redis, string $host): bool
    {
        if ($this->ssl && $this->sslContext !== []) {
            $context = ['stream' => $this->sslContext];

            return $this->persistent
                ? $redis->pconnect($host, $this->port, $this->connectTimeout, null, 0, $this->readTimeout, $context)
                : $redis->connect($host, $this->port, $this->connectTimeout, null, 0, $this->readTimeout, $context);
        }

        return $this->persistent
            ? $redis->pconnect($host, $this->port, $this->connectTimeout, null, 0, $this->readTimeout)
            : $redis->connect($host, $this->port, $this->connectTimeout, null, 0, $this->readTimeout);
    }

    /**
     * Applies db index, key prefix, serializer and compression to the live connection, if any.
     *
     * @throws RedisConnectionException if the configured database can't be selected
     */
    private function applyOptions(): void
    {
        if ($this->phpredis === null) {
            return;
        }

        try {
            if ($this->dbIndex !== null && ! $this->phpredis->select($this->dbIndex)) {
                throw new RedisConnectionException(sprintf('Could not select database %d.', $this->dbIndex));
            }

            $this->phpredis->setOption(\Redis::OPT_PREFIX, $this->keyPrefix);
            $this->phpredis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
            $this->phpredis->setOption(\Redis::OPT_SERIALIZER, self::redisConstant(self::SERIALIZERS[$this->serializer ?? 'none']));
            $this->phpredis->setOption(\Redis::OPT_COMPRESSION, self::redisConstant(self::COMPRESSIONS[$this->compression ?? 'none']));
        } catch (\RedisException $e) {
            throw new RedisConnectionException('Could not configure the Redis connection: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Drops the current connection (if any), so that the next command reconnects with the new parameters.
     */
    private function dropConnection(): void
    {
        if ($this->phpredis !== null) {
            $this->disconnect();
        }
    }

    private static function redisConstant(string $name): int
    {
        $fqcn = '\Redis::' . $name;
        if (! defined($fqcn)) {
            throw new \InvalidArgumentException(sprintf('\Redis::%s is not supported by your version of phpredis.', $name));
        }

        $value = constant($fqcn);

        return is_int($value) ? $value : throw new \InvalidArgumentException(sprintf('\\Redis::%s is not an integer constant.', $name));
    }

    /**
     * Encodes a value so that null, false and true survive a round trip through Redis.
     * Literal FALSE, in particular, is what phpredis returns for a missing key.
     */
    private function encode(mixed $value): mixed
    {
        return match (true) {
            $value === null => self::NULL_SENTINEL,
            $value === false => self::FALSE_SENTINEL,
            $value === true => self::TRUE_SENTINEL,
            default => $value,
        };
    }

    /**
     * Decodes a value previously encoded by {@see RedisClient::encode()}.
     */
    private function decode(mixed $value): mixed
    {
        return match ($value) {
            self::NULL_SENTINEL => null,
            self::FALSE_SENTINEL => false,
            self::TRUE_SENTINEL => true,
            default => $value,
        };
    }

    /**
     * Effective TTL, in milliseconds, to apply to a write. 0 means "no expiration".
     * The jitter is computed per write, so that keys written together don't expire together.
     */
    private function effectiveTtlMs(): int
    {
        if ($this->cacheTtlSeconds === 0) {
            return 0;
        }

        $jitter = $this->cacheTtlJitter ? random_int(self::TTL_JITTER_MIN_MS, self::TTL_JITTER_MAX_MS) : 0;

        return $this->cacheTtlSeconds * 1000 + $jitter;
    }

    /**
     * TTL, in milliseconds, for a write: the explicit $ttl (seconds, no jitter) or the configured cache TTL.
     *
     * @throws \InvalidArgumentException if $ttl is negative
     */
    private function ttlMs(?int $ttl): int
    {
        if ($ttl === null) {
            return $this->effectiveTtlMs();
        }

        if ($ttl < 0) {
            throw new \InvalidArgumentException('Invalid ttl value. Ttl must be a non-negative number of seconds.');
        }

        return $ttl * 1000;
    }

    /**
     * Runs $callback against $dbIndex (if given), restoring the previously selected database afterwards.
     *
     * @template T
     *
     * @param callable(\Redis): T $callback
     *
     * @return T
     */
    private function onDatabase(?int $dbIndex, callable $callback): mixed
    {
        $redis = $this->connection();

        if ($dbIndex === null) {
            return $callback($redis);
        }

        $activeDbIndex = self::toInt($redis->getDbNum());
        if (! $redis->select($dbIndex)) {
            throw new RedisConnectionException(sprintf('Could not select database %d.', $dbIndex));
        }

        try {
            return $callback($redis);
        } finally {
            $redis->select($activeDbIndex);
        }
    }

    /**
     * Normalizes a phpredis integer reply (phpredis may also return false, or itself when in MULTI mode).
     */
    private static function toInt(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }

    private static function wrapException(\RedisException $e): BaseRedisException
    {
        return new BaseRedisException($e->getMessage(), (int) $e->getCode(), $e);
    }

    /*
     * configuration
     */

    /**
     * Whether a connection to Redis is currently established.
     */
    public function getIsConnected(): bool
    {
        return $this->isConnected;
    }

    /**
     * @param mixed $connectTimeout connection timeout in seconds (numeric, >= 0). 0 means "no timeout".
     *
     * @throws \InvalidArgumentException
     */
    public function setConnectTimeout(mixed $connectTimeout): self
    {
        if (! is_numeric($connectTimeout) || (float) $connectTimeout < 0) {
            throw new \InvalidArgumentException('Invalid connect timeout value. Connect timeout value must be a positive float.');
        }

        $this->connectTimeout = (float) $connectTimeout;
        $this->dropConnection();

        return $this;
    }

    public function getConnectTimeout(): float
    {
        return $this->connectTimeout;
    }

    /**
     * @param mixed $readTimeout read timeout in seconds (numeric, >= 0). 0 means "use the default_socket_timeout ini setting".
     *
     * @throws \InvalidArgumentException
     */
    public function setReadTimeout(mixed $readTimeout): self
    {
        if (! is_numeric($readTimeout) || (float) $readTimeout < 0) {
            throw new \InvalidArgumentException('Invalid read timeout value. Read timeout value must be a positive float.');
        }

        $this->readTimeout = (float) $readTimeout;
        $this->dropConnection();

        return $this;
    }

    public function getReadTimeout(): float
    {
        return $this->readTimeout;
    }

    /**
     * @param mixed $connectTries number of connection attempts (integer, >= 1)
     *
     * @throws \InvalidArgumentException
     */
    public function setConnectTries(mixed $connectTries): self
    {
        if (! is_numeric($connectTries) || (int) $connectTries < 1) {
            throw new \InvalidArgumentException('Invalid connect tries value. Connect tries value must be a positive integer.');
        }

        $this->connectTries = (int) $connectTries;

        return $this;
    }

    public function getConnectTries(): int
    {
        return $this->connectTries;
    }

    /**
     * @param mixed $host hostname, IP address or unix socket path (non-empty string)
     *
     * @throws \InvalidArgumentException
     */
    public function setHost(mixed $host): self
    {
        if (! is_string($host) || $host === '') {
            throw new \InvalidArgumentException('Invalid host value. Host value must be a non empty string.');
        }

        $this->host = $host;
        $this->dropConnection();

        return $this;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * @param mixed $port TCP port (integer between 0 and 65535; use 0 for unix sockets)
     *
     * @throws \InvalidArgumentException
     */
    public function setPort(mixed $port): self
    {
        if (! is_numeric($port) || (int) $port < 0 || (int) $port > 65535) {
            throw new \InvalidArgumentException('Invalid port value. Port value must be a number between 0 and 65535.');
        }

        $this->port = (int) $port;
        $this->dropConnection();

        return $this;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function setSsl(mixed $ssl): self
    {
        if (! is_bool($ssl)) {
            throw new \InvalidArgumentException('Invalid ssl value. Ssl value must be a true/false boolean.');
        }

        $this->ssl = $ssl;
        $this->dropConnection();

        return $this;
    }

    public function getSsl(): bool
    {
        return $this->ssl;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function setAuthPassword(mixed $auth): self
    {
        if (! is_string($auth) || $auth === '') {
            throw new \InvalidArgumentException('Invalid authentication. Auth password must be a non empty string.');
        }

        $this->auth = $auth;
        $this->dropConnection();

        return $this;
    }

    public function getAuth(): string
    {
        return $this->auth;
    }

    /**
     * Sets the ACL username (Redis >= 6). Requires a password, see {@see RedisClient::setAuthPassword()}.
     *
     * @throws \InvalidArgumentException
     */
    public function setUsername(mixed $username): self
    {
        if (! is_string($username) || $username === '') {
            throw new \InvalidArgumentException('Invalid username. Username must be a non empty string.');
        }

        $this->username = $username;
        $this->dropConnection();

        return $this;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * Sets the SSL stream context options used when "ssl" is enabled,
     * e.g. ['cafile' => '/path/ca.pem', 'verify_peer_name' => true, 'local_cert' => '/path/client.pem'].
     *
     * @see https://www.php.net/manual/en/context.ssl.php
     *
     * @param mixed $sslContext array of SSL context options, indexed by option name
     *
     * @throws \InvalidArgumentException
     */
    public function setSslContext(mixed $sslContext): self
    {
        if (! is_array($sslContext)) {
            throw new \InvalidArgumentException('Invalid ssl context. Ssl context must be an array of SSL context options.');
        }

        $context = [];
        foreach ($sslContext as $option => $value) {
            if (! is_string($option)) {
                throw new \InvalidArgumentException('Invalid ssl context. Ssl context options must be indexed by option name.');
            }
            $context[$option] = $value;
        }

        $this->sslContext = $context;
        $this->dropConnection();

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSslContext(): array
    {
        return $this->sslContext;
    }

    /**
     * @param mixed $persistent boolean: if true, pconnect() is used instead of connect()
     *
     * @throws \InvalidArgumentException
     */
    public function setPersistent(mixed $persistent): self
    {
        if (! is_bool($persistent)) {
            throw new \InvalidArgumentException('Invalid persistent value. Persistent value must be a true/false boolean.');
        }

        $this->persistent = $persistent;
        $this->dropConnection();

        return $this;
    }

    public function getPersistent(): bool
    {
        return $this->persistent;
    }

    /**
     * Selects the database index. If already connected, the database is switched immediately.
     *
     * @param mixed $dbIndex a non-negative integer (the upper bound depends on the "databases" Redis setting)
     *
     * @throws \InvalidArgumentException
     * @throws RedisConnectionException  if already connected and the database can't be selected
     */
    public function setDbIndex(mixed $dbIndex): self
    {
        if (! is_numeric($dbIndex) || (int) $dbIndex < 0) {
            throw new \InvalidArgumentException('Invalid database index. Database index must be a non-negative integer.');
        }

        $this->dbIndex = (int) $dbIndex;
        $this->applyOptions();

        return $this;
    }

    /**
     * @return int|null the configured database index, or null if none was configured (Redis defaults to 0)
     */
    public function getDbIndex(): ?int
    {
        return $this->dbIndex;
    }

    /**
     * Sets the cache TTL applied to every write (set, mset, setnx, msetnx, getset).
     *
     * @param mixed $ttl TTL in seconds (integer, >= 0). 0 means "no expiration".
     *
     * @throws \InvalidArgumentException
     */
    public function setCacheTTL(mixed $ttl): self
    {
        if (! is_numeric($ttl) || (int) $ttl < 0) {
            throw new \InvalidArgumentException('Invalid cache ttl value. Ttl must be a positive integer representing the number of seconds of cache lifetime.');
        }

        $this->cacheTtlSeconds = (int) $ttl;

        return $this;
    }

    /**
     * @return int the configured cache TTL, in seconds (0 means "no expiration"), without jitter
     */
    public function getCacheTTL(): int
    {
        return $this->cacheTtlSeconds;
    }

    /**
     * Enables or disables the random 10-99 seconds jitter added to every TTL to avoid cache stampedes.
     *
     * @throws \InvalidArgumentException
     */
    public function setCacheTTLJitter(mixed $jitter): self
    {
        if (! is_bool($jitter)) {
            throw new \InvalidArgumentException('Invalid cache ttl jitter value. It must be a true/false boolean.');
        }

        $this->cacheTtlJitter = $jitter;

        return $this;
    }

    public function getCacheTTLJitter(): bool
    {
        return $this->cacheTtlJitter;
    }

    /**
     * Sets the compression algorithm. Valid values are: none, lzf, zstd, lz4.
     *
     * @throws \InvalidArgumentException if the value is invalid or not supported by the installed phpredis
     */
    public function setCompression(mixed $compression): self
    {
        if (! is_string($compression) || ! array_key_exists($compression, self::COMPRESSIONS)) {
            throw new \InvalidArgumentException('Invalid compression value. Valid values are: ' . implode(', ', array_keys(self::COMPRESSIONS)));
        }

        self::redisConstant(self::COMPRESSIONS[$compression]);

        $this->compression = $compression;
        $this->applyOptions();

        return $this;
    }

    public function getCompression(): ?string
    {
        return $this->compression;
    }

    /**
     * Sets the key prefix. The prefix is normalized to a lowercase slug made of [a-z0-9-].
     */
    public function setKeyPrefix(mixed $keyPrefix): self
    {
        if (! is_string($keyPrefix)) {
            throw new \InvalidArgumentException('Invalid key prefix value. Key prefix must be a string.');
        }

        $keyPrefix = (string) preg_replace('/[^a-z0-9\-]/', '-', strtolower($keyPrefix));
        $keyPrefix = trim((string) preg_replace('/\-+/', '-', $keyPrefix), '-');

        $this->keyPrefix = $keyPrefix;
        $this->applyOptions();

        return $this;
    }

    public function getKeyPrefix(): string
    {
        return $this->keyPrefix;
    }

    /**
     * Sets the serializer. Valid values are: none, php, igbinary, json, msgpack.
     *
     * @throws \InvalidArgumentException if the value is invalid or not supported by the installed phpredis
     */
    public function setSerializer(mixed $serializer): self
    {
        if (! is_string($serializer) || ! array_key_exists($serializer, self::SERIALIZERS)) {
            throw new \InvalidArgumentException('Invalid serializer value. Valid values are: ' . implode(', ', array_keys(self::SERIALIZERS)));
        }

        self::redisConstant(self::SERIALIZERS[$serializer]);

        $this->serializer = $serializer;
        $this->applyOptions();

        return $this;
    }

    public function getSerializer(): ?string
    {
        return $this->serializer;
    }

    /**
     * @param mixed $forceRevalidate boolean: if true, has(), exists() and allKeys() behave as if the key space were empty
     *
     * @throws \InvalidArgumentException
     */
    public function setForceRevalidate(mixed $forceRevalidate): self
    {
        if (! is_bool($forceRevalidate)) {
            throw new \InvalidArgumentException('Invalid force revalidate value. It must be a true/false boolean.');
        }

        $this->forceRevalidate = $forceRevalidate;

        return $this;
    }

    public function getForceRevalidate(): bool
    {
        return $this->forceRevalidate;
    }

    /**
     * Number of keys found by has() / exists().
     */
    public function getHits(): int
    {
        return $this->hits;
    }

    /**
     * Number of keys not found by has() / exists().
     */
    public function getMisses(): int
    {
        return $this->misses;
    }

    /*
     * custom redis methods
     */

    /**
     * Gets all keys (without the configured key prefix) and their values.
     * Keys holding non-string values (hashes, sets, ...) are returned with a false value.
     *
     * @return array<string, mixed>
     *
     * @throws BaseRedisException
     */
    public function allValues(): array
    {
        $result = [];
        foreach (array_chunk($this->allKeys(), self::SCAN_BATCH_SIZE) as $keys) {
            $result += $this->mget($keys);
        }

        return $result;
    }

    /**
     * Gets all keys of the current database (without the configured key prefix), using non-blocking SCAN.
     *
     * @return list<string>
     *
     * @throws BaseRedisException
     */
    public function allKeys(): array
    {
        if ($this->forceRevalidate) {
            return [];
        }

        return $this->scanKeys();
    }

    /**
     * Deletes all the keys of the configured key prefix, or of the whole current database when no prefix is configured.
     * Unlike {@see RedisClient::flushDb()}, keys belonging to other prefixes are preserved.
     *
     * @return int number of deleted keys
     *
     * @throws BaseRedisException
     */
    public function deleteAll(): int
    {
        $deleted = 0;
        foreach (array_chunk($this->scanKeys(), self::SCAN_BATCH_SIZE) as $keys) {
            $deleted += $this->delete($keys);
        }

        return $deleted;
    }

    /**
     * @return list<string> all keys of the current database matching the prefix, without the prefix
     *
     * @throws BaseRedisException
     */
    private function scanKeys(): array
    {
        try {
            $redis = $this->connection();
            $prefixLength = strlen($this->keyPrefix);
            $pattern = $prefixLength > 0 ? $this->keyPrefix . '*' : '*';
            $keys = [];
            $iterator = null;

            while (($batch = $redis->scan($iterator, $pattern, self::SCAN_BATCH_SIZE)) !== false) {
                foreach ($batch as $key) {
                    $keys[(string) substr((string) $key, $prefixLength)] = true;
                }
            }

            return array_map('strval', array_keys($keys));
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }
    }

    /**
     * Gets the number of keys of the current database, or of $dbIndex if given.
     *
     * @throws RedisConnectionException
     * @throws BaseRedisException
     */
    public function dbSize(?int $dbIndex = null): int
    {
        try {
            return $this->onDatabase($dbIndex, static fn (\Redis $redis): int => self::toInt($redis->dbSize()));
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }
    }

    /**
     * Flushes all databases asynchronously.
     *
     * @throws BaseRedisException
     */
    public function flushAllAsync(): void
    {
        try {
            $this->connection()->rawCommand('FLUSHALL', 'ASYNC');
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }
    }

    /**
     * Flushes all databases.
     *
     * @throws BaseRedisException
     */
    public function flushAll(): void
    {
        try {
            $this->connection()->flushAll();
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }
    }

    /**
     * Flushes the current database, or $dbIndex if given.
     *
     * @throws RedisConnectionException
     * @throws BaseRedisException
     */
    public function flushDb(?int $dbIndex = null): void
    {
        try {
            $this->onDatabase($dbIndex, static fn (\Redis $redis) => $redis->flushDB());
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }
    }

    /**
     * Closes the connection, if any. Never throws, and never opens a connection.
     */
    public function disconnect(): void
    {
        if ($this->phpredis !== null) {
            try {
                $this->phpredis->close();
            } catch (\Throwable) {
                // no-op: do not throw exceptions on disconnect
            }
        }

        $this->phpredis = null;
        $this->isConnected = false;
    }

    /**
     * Alias of {@see RedisClient::exists()}.
     *
     * @throws BaseRedisException
     */
    public function has(string $key): bool
    {
        return $this->exists($key);
    }

    /**
     * Calls a public method of this client, returning null instead of throwing if the call fails.
     *
     * @param string $command public method name, e.g. "get"
     * @param mixed  ...$args method arguments
     *
     * @throws \InvalidArgumentException if $command is not a public method of this class
     */
    public function wrap(string $command, mixed ...$args): mixed
    {
        if (! method_exists($this, $command)
            || ! (new \ReflectionMethod($this, $command))->isPublic()
            || strcasecmp($command, 'wrap') === 0
            || strcasecmp($command, 'instance') === 0
        ) {
            throw new \InvalidArgumentException(sprintf('Method %s does not exist or is not callable through wrap().', $command));
        }

        try {
            return $this->{$command}(...$args);
        } catch (\Exception) {
            return null;
        }
    }

    /*
     * phpredis interface implementation
     */

    /**
     * @throws RedisImpossibleValueException if the key holds a non-integer value
     * @throws BaseRedisException
     */
    public function incr(string $key, int $value = 1): int
    {
        try {
            $result = ($value === 1)
                ? $this->connection()->incr($key)
                : $this->connection()->incrBy($key, $value);
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }

        if (! is_int($result)) {
            throw new RedisImpossibleValueException(sprintf('Could not increment key "%s".', $key));
        }

        return $result;
    }

    /**
     * @throws RedisImpossibleValueException if the key holds a non-integer value
     * @throws BaseRedisException
     */
    public function decr(string $key, int $value = 1): int
    {
        try {
            $result = ($value === 1)
                ? $this->connection()->decr($key)
                : $this->connection()->decrBy($key, $value);
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }

        if (! is_int($result)) {
            throw new RedisImpossibleValueException(sprintf('Could not decrement key "%s".', $key));
        }

        return $result;
    }

    /**
     * @throws RedisImpossibleValueException
     * @throws BaseRedisException
     */
    public function append(string $key, string|int|float $value): int
    {
        try {
            $result = $this->connection()->append($key, (string) $value);
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }

        if (! is_int($result)) {
            throw new RedisImpossibleValueException(sprintf('Could not append to key "%s".', $key));
        }

        return $result;
    }

    /**
     * @throws RedisKeyNotFoundException when the key does not exist
     * @throws BaseRedisException
     */
    public function get(string $key): mixed
    {
        try {
            $result = $this->connection()->get($key);
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }

        if ($result === false) {
            throw new RedisKeyNotFoundException(sprintf('Key "%s" not found.', $key));
        }

        return $this->decode($result);
    }

    /**
     * Missing keys are returned with the $default value (false, unless specified).
     * Stored false values are distinguishable from missing keys when a $default is given.
     *
     * @param array<int, string> $keys
     * @param mixed              $default value returned for missing keys
     *
     * @return array<string, mixed>
     *
     * @throws RedisImpossibleValueException
     * @throws BaseRedisException
     */
    public function mget(array $keys, mixed $default = false): array
    {
        if ($keys === []) {
            return [];
        }

        $keys = array_map('strval', array_values($keys));

        try {
            $result = $this->connection()->mGet($keys);
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }

        if (! is_array($result)) {
            throw new RedisImpossibleValueException('MGET failed.');
        }

        return array_combine($keys, array_map(
            fn (mixed $value): mixed => $value === false ? $default : $this->decode($value),
            $result
        ));
    }

    /**
     * @param int|null $ttl TTL in seconds, overriding the configured cache TTL (no jitter is added).
     *                      0 means "no expiration", null means "use the configured cache TTL".
     *
     * @throws RedisImpossibleValueException
     * @throws BaseRedisException
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        try {
            $ttl = $this->ttlMs($ttl);
            $result = ($ttl > 0)
                ? $this->connection()->psetex($key, $ttl, $this->encode($value))
                : $this->connection()->set($key, $this->encode($value));
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }

        if ($result === false) {
            throw new RedisImpossibleValueException(sprintf('Could not set key "%s".', $key));
        }

        return true;
    }

    /**
     * Sets multiple keys atomically (MULTI/EXEC when a TTL applies, MSET otherwise).
     *
     * @param array<string, mixed> $values
     * @param int|null             $ttl    TTL in seconds, overriding the configured cache TTL (no jitter is added).
     *                                     0 means "no expiration", null means "use the configured cache TTL".
     *
     * @throws BaseRedisException
     */
    public function mset(array $values, ?int $ttl = null): bool
    {
        if ($values === []) {
            return true;
        }

        $this->ttlMs($ttl); // validates $ttl

        try {
            $redis = $this->connection();

            if ($ttl === 0 || ($ttl === null && $this->cacheTtlSeconds === 0)) {
                return (bool) $redis->mset(array_map([$this, 'encode'], $values));
            }

            $redis->multi();
            foreach ($values as $key => $value) {
                $redis->psetex((string) $key, $this->ttlMs($ttl), $this->encode($value));
            }
            $results = $redis->exec();

            return is_array($results) && ! in_array(false, $results, true);
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }
    }

    /**
     * @param int|null $ttl TTL in seconds, overriding the configured cache TTL (no jitter is added).
     *                      0 means "no expiration", null means "use the configured cache TTL".
     *
     * @return bool true if the key was set, false if it already existed
     *
     * @throws BaseRedisException
     */
    public function setnx(string $key, mixed $value, ?int $ttl = null): bool
    {
        try {
            $ttl = $this->ttlMs($ttl);

            return (bool) (($ttl > 0)
                ? $this->connection()->set($key, $this->encode($value), ['nx', 'px' => $ttl])
                : $this->connection()->setnx($key, $this->encode($value)));
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }
    }

    /**
     * Sets multiple keys only if none of them exists (all-or-nothing, like MSETNX), honoring the configured TTL.
     *
     * @param array<string, mixed> $values
     *
     * @return bool true if all keys were set, false if at least one key already existed
     *
     * @throws BaseRedisException
     */
    public function msetnx(array $values): bool
    {
        if ($values === []) {
            return true;
        }

        try {
            $redis = $this->connection();

            if ($this->cacheTtlSeconds === 0) {
                return (bool) $redis->msetnx(array_map([$this, 'encode'], $values));
            }

            // optimistic locking: abort if any of the keys is created/modified by someone else meanwhile
            $keys = array_map('strval', array_keys($values));
            $redis->watch($keys);
            if (self::toInt($redis->exists($keys)) > 0) {
                $redis->unwatch();

                return false;
            }

            $redis->multi();
            foreach ($values as $key => $value) {
                $redis->psetex((string) $key, $this->effectiveTtlMs(), $this->encode($value));
            }
            $results = $redis->exec();

            return is_array($results) && ! in_array(false, $results, true);
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }
    }

    /**
     * Atomically sets a new value and returns the previous one. The configured TTL is applied to the key.
     *
     * @return mixed the previous value, or false if the key did not exist
     *
     * @throws BaseRedisException
     */
    public function getset(string $key, mixed $value): mixed
    {
        try {
            $redis = $this->connection();
            $ttl = $this->effectiveTtlMs();

            if ($ttl === 0) {
                $previous = $redis->getSet($key, $this->encode($value));
            } else {
                $redis->multi();
                $redis->getSet($key, $this->encode($value));
                $redis->pexpire($key, $ttl);
                $results = $redis->exec();
                $previous = is_array($results) ? $results[0] : false;
            }
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }

        return $previous === false ? false : $this->decode($previous);
    }

    /**
     * @param string|array<int, string> $keys
     *
     * @return int number of deleted keys
     *
     * @throws RedisImpossibleValueException
     * @throws BaseRedisException
     */
    public function delete(string|array $keys): int
    {
        if ($keys === []) {
            return 0;
        }

        try {
            $result = $this->connection()->del($keys);
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }

        if (! is_int($result)) {
            throw new RedisImpossibleValueException('DEL failed.');
        }

        return $result;
    }

    /**
     * Checks if a key exists, updating the hits/misses counters.
     * Always returns false when force_revalidate is enabled.
     *
     * @throws BaseRedisException
     */
    public function exists(string $key): bool
    {
        try {
            $exists = ! $this->forceRevalidate && self::toInt($this->connection()->exists($key)) > 0;
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }

        if ($exists) {
            $this->hits++;
        } else {
            $this->misses++;
        }

        return $exists;
    }

    /**
     * @return bool false if the source key does not exist
     *
     * @throws BaseRedisException
     */
    public function rename(string $source, string $destination): bool
    {
        try {
            return (bool) $this->connection()->rename($source, $destination);
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }
    }

    /**
     * @return bool false if the source key does not exist, or the destination key already exists
     *
     * @throws BaseRedisException
     */
    public function renamenx(string $source, string $destination): bool
    {
        try {
            return (bool) $this->connection()->renameNx($source, $destination);
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }
    }

    /**
     * @throws RedisImpossibleValueException
     * @throws BaseRedisException
     */
    public function strlen(string $key): int
    {
        try {
            $result = $this->connection()->strlen($key);
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }

        if (! is_int($result)) {
            throw new RedisImpossibleValueException(sprintf('Could not get the length of key "%s".', $key));
        }

        return $result;
    }

    /**
     * @param int $timeout TTL in milliseconds
     *
     * @return bool false if the key does not exist
     *
     * @throws BaseRedisException
     */
    public function expire(string $key, int $timeout): bool
    {
        try {
            return (bool) $this->connection()->pexpire($key, $timeout);
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }
    }

    /**
     * @param int $timestamp unix timestamp (seconds) at which the key expires
     *
     * @return bool false if the key does not exist
     *
     * @throws BaseRedisException
     */
    public function expireat(string $key, int $timestamp): bool
    {
        try {
            return (bool) $this->connection()->expireAt($key, $timestamp);
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }
    }

    /**
     * @return int|false TTL in milliseconds, or false if the key has no TTL or does not exist
     *
     * @throws BaseRedisException
     */
    public function ttl(string $key): int|false
    {
        try {
            $result = $this->connection()->pttl($key);
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }

        return (is_int($result) && $result >= 0) ? $result : false;
    }

    /**
     * @return bool false if the key has no TTL or does not exist
     *
     * @throws BaseRedisException
     */
    public function persist(string $key): bool
    {
        try {
            return (bool) $this->connection()->persist($key);
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }
    }

    /**
     * @throws RedisImpossibleValueException
     * @throws BaseRedisException
     */
    public function getbit(string $key, int $offset): int
    {
        try {
            $result = $this->connection()->getBit($key, $offset);
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }

        if (! is_int($result)) {
            throw new RedisImpossibleValueException(sprintf('Could not get bit %d of key "%s".', $offset, $key));
        }

        return $result;
    }

    /**
     * @param int $value bit value; any non-zero value is treated as 1
     *
     * @return int the previous bit value
     *
     * @throws RedisImpossibleValueException
     * @throws BaseRedisException
     */
    public function setbit(string $key, int $offset, int $value): int
    {
        try {
            $result = $this->connection()->setBit($key, $offset, $value !== 0);
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }

        if (! is_int($result)) {
            throw new RedisImpossibleValueException(sprintf('Could not set bit %d of key "%s".', $offset, $key));
        }

        return $result;
    }

    /**
     * @param array<int, mixed> $arguments script arguments: the first $numKeys are KEYS, the rest are ARGV
     * @param int|null          $numKeys   number of arguments to pass as KEYS. Defaults to all of them.
     *
     * @throws RedisScriptExecutionException when the script fails
     * @throws BaseRedisException
     */
    public function evaluate(string $code, array $arguments = [], ?int $numKeys = null): mixed
    {
        return $this->runScript('eval', $code, $arguments, $numKeys);
    }

    /**
     * @param array<int, mixed> $arguments script arguments: the first $numKeys are KEYS, the rest are ARGV
     * @param int|null          $numKeys   number of arguments to pass as KEYS. Defaults to all of them.
     *
     * @throws RedisScriptExecutionException when the script fails
     * @throws BaseRedisException
     */
    public function evalSha(string $sha, array $arguments = [], ?int $numKeys = null): mixed
    {
        return $this->runScript('evalSha', $sha, $arguments, $numKeys);
    }

    /**
     * @param 'eval'|'evalSha'   $method
     * @param array<int, mixed>  $arguments
     */
    private function runScript(string $method, string $script, array $arguments, ?int $numKeys): mixed
    {
        $arguments = array_values($arguments);
        $numKeys ??= count($arguments);

        if ($numKeys < 0 || $numKeys > count($arguments)) {
            throw new \InvalidArgumentException('$numKeys must be between 0 and the number of arguments.');
        }

        try {
            $redis = $this->connection();
            $redis->clearLastError();
            $result = $redis->{$method}($script, $arguments, $numKeys);
            $lastError = $redis->getLastError();
            $redis->clearLastError();
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }

        if ($lastError !== null) {
            throw new RedisScriptExecutionException($lastError);
        }

        return $result;
    }

    /**
     * @return int number of members added to the set
     *
     * @throws RedisImpossibleValueException
     * @throws BaseRedisException
     */
    public function sadd(string $key, mixed $member): int
    {
        try {
            $result = $this->connection()->sAdd($key, $member);
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }

        if (! is_int($result)) {
            throw new RedisImpossibleValueException(sprintf('Could not add a member to set "%s".', $key));
        }

        return $result;
    }

    /**
     * @return mixed the popped member, or false if the set is empty
     *
     * @throws BaseRedisException
     */
    public function spop(string $key): mixed
    {
        try {
            return $this->connection()->sPop($key);
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }
    }

    /**
     * @return mixed a random member, or false if the set is empty
     *
     * @throws BaseRedisException
     */
    public function srandmember(string $key): mixed
    {
        try {
            return $this->connection()->sRandMember($key);
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }
    }

    /**
     * @throws BaseRedisException
     */
    public function scard(string $key): int
    {
        try {
            return self::toInt($this->connection()->sCard($key));
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }
    }

    /**
     * @throws BaseRedisException
     */
    public function sismember(string $key, mixed $member): bool
    {
        try {
            return (bool) $this->connection()->sIsMember($key, $member);
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }
    }

    /**
     * @deprecated since 2.0, use {@see RedisClient::sismember()} instead
     *
     * @throws BaseRedisException
     */
    public function sismembers(string $key, mixed $member): bool
    {
        return $this->sismember($key, $member);
    }

    /**
     * @return array<int, mixed>
     *
     * @throws BaseRedisException
     */
    public function smembers(string $key): array
    {
        try {
            $result = $this->connection()->sMembers($key);
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }

        return is_array($result) ? $result : [];
    }

    /**
     * @return int number of members removed from the set
     *
     * @throws BaseRedisException
     */
    public function srem(string $key, mixed $member): int
    {
        try {
            return self::toInt($this->connection()->sRem($key, $member));
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }
    }

    /**
     * @param array<int, string> $sources
     *
     * @return int size of the resulting set
     *
     * @throws RedisImpossibleValueException
     * @throws BaseRedisException
     */
    public function sdiffstore(string $destination, array $sources): int
    {
        return $this->setStore('sDiffStore', $destination, $sources);
    }

    /**
     * @param array<int, string> $sources
     *
     * @return int size of the resulting set
     *
     * @throws RedisImpossibleValueException
     * @throws BaseRedisException
     */
    public function sinterstore(string $destination, array $sources): int
    {
        return $this->setStore('sInterStore', $destination, $sources);
    }

    /**
     * @param array<int, string> $sources
     *
     * @return int size of the resulting set
     *
     * @throws RedisImpossibleValueException
     * @throws BaseRedisException
     */
    public function sunionstore(string $destination, array $sources): int
    {
        return $this->setStore('sUnionStore', $destination, $sources);
    }

    /**
     * @param 'sDiffStore'|'sInterStore'|'sUnionStore' $method
     * @param array<int, string>                         $sources
     */
    private function setStore(string $method, string $destination, array $sources): int
    {
        if ($sources === []) {
            throw new \InvalidArgumentException('At least one source key is required.');
        }

        try {
            $result = $this->connection()->{$method}($destination, ...array_map('strval', array_values($sources)));
        } catch (\RedisException $e) {
            throw self::wrapException($e);
        }

        if (! is_int($result)) {
            throw new RedisImpossibleValueException(sprintf('%s failed.', strtoupper($method)));
        }

        return $result;
    }
}
