<?php

declare(strict_types=1);

namespace Mfonte\Redisw\Test\Integration;

use Mfonte\Redisw\RedisClient;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need a running Redis server.
 *
 * The server is configured through the REDIS_HOST, REDIS_PORT, REDIS_DB and REDIS_DB_ALT
 * environment variables. Only the REDIS_DB and REDIS_DB_ALT databases are ever flushed:
 * the test suite never calls FLUSHALL.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected RedisClient $client;

    protected static function host(): string
    {
        $host = getenv('REDIS_HOST');

        return is_string($host) && $host !== '' ? $host : '127.0.0.1';
    }

    protected static function port(): int
    {
        $port = getenv('REDIS_PORT');

        return is_string($port) && is_numeric($port) ? (int) $port : 6379;
    }

    protected static function db(): int
    {
        $db = getenv('REDIS_DB');

        return is_string($db) && is_numeric($db) ? (int) $db : 15;
    }

    protected static function altDb(): int
    {
        $db = getenv('REDIS_DB_ALT');

        return is_string($db) && is_numeric($db) ? (int) $db : 14;
    }

    /**
     * @param array<string, mixed> $config
     */
    protected static function makeClient(array $config = []): RedisClient
    {
        return RedisClient::instance($config + [
            'host' => self::host(),
            'port' => self::port(),
            'connect_timeout' => 1,
            'db_index' => self::db(),
            'serializer' => 'php',
        ]);
    }

    protected function setUp(): void
    {
        if (! extension_loaded('redis')) {
            self::markTestSkipped('The redis extension is not loaded.');
        }

        $probe = new \Redis();

        try {
            $probe->connect(self::host(), self::port(), 1.0);
            $probe->close();
        } catch (\RedisException $e) {
            self::markTestSkipped(sprintf('No Redis server reachable at %s:%d: %s', self::host(), self::port(), $e->getMessage()));
        }

        $this->client = self::makeClient();
        $this->client->flushDb();
        $this->client->flushDb(self::altDb());
    }

    protected function tearDown(): void
    {
        if (isset($this->client)) {
            $this->client->setKeyPrefix('');
            $this->client->flushDb(self::db());
            $this->client->flushDb(self::altDb());
            $this->client->disconnect();
        }
    }
}
