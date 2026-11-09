<?php

declare(strict_types=1);

namespace Mfonte\Redisw\Test\Unit;

use Mfonte\Redisw\Exception\RedisNotConfiguredException;
use Mfonte\Redisw\RedisClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Configuration tests: none of these open a connection to Redis.
 */
final class ConfigurationTest extends TestCase
{
    public function testInstanceRequiresHostAndPort(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RedisClient::instance(['host' => '127.0.0.1']);
    }

    public function testInstanceRejectsUnknownKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('serialiser');

        RedisClient::instance(['host' => '127.0.0.1', 'port' => 6379, 'serialiser' => 'php']);
    }

    public function testDefaults(): void
    {
        $client = RedisClient::instance(['host' => '127.0.0.1', 'port' => 6379]);

        self::assertSame('127.0.0.1', $client->getHost());
        self::assertSame(6379, $client->getPort());
        self::assertSame(RedisClient::DEFAULT_CACHE_TTL_SECONDS, $client->getCacheTTL());
        self::assertTrue($client->getCacheTTLJitter());
        self::assertNull($client->getDbIndex());
        self::assertNull($client->getSerializer());
        self::assertNull($client->getCompression());
        self::assertSame('', $client->getKeyPrefix());
        self::assertSame('', $client->getAuth());
        self::assertSame('', $client->getUsername());
        self::assertSame([], $client->getSslContext());
        self::assertSame(0.0, $client->getReadTimeout());
        self::assertSame(1, $client->getConnectTries());
        self::assertSame(0.0, $client->getConnectTimeout());
        self::assertFalse($client->getSsl());
        self::assertFalse($client->getPersistent());
        self::assertFalse($client->getForceRevalidate());
        self::assertFalse($client->getIsConnected());
    }

    public function testFullConfiguration(): void
    {
        $client = RedisClient::instance([
            'host' => 'redis.example.com',
            'port' => '6380',
            'connect_timeout' => '1.5',
            'connect_tries' => 3,
            'read_timeout' => 2,
            'persistent' => true,
            'db_index' => 20,
            'cache_ttl' => 0,
            'cache_ttl_jitter' => false,
            'auth' => 'secret',
            'username' => 'app',
            'ssl' => true,
            'ssl_context' => ['verify_peer_name' => false],
            'key_prefix' => 'My App:',
            'compression' => 'none',
            'serializer' => 'php',
            'force_revalidate' => true,
        ]);

        self::assertSame(6380, $client->getPort());
        self::assertSame(1.5, $client->getConnectTimeout());
        self::assertSame(3, $client->getConnectTries());
        self::assertTrue($client->getPersistent());
        self::assertSame(20, $client->getDbIndex());
        self::assertSame(0, $client->getCacheTTL());
        self::assertFalse($client->getCacheTTLJitter());
        self::assertSame('secret', $client->getAuth());
        self::assertSame('app', $client->getUsername());
        self::assertSame(2.0, $client->getReadTimeout());
        self::assertSame(['verify_peer_name' => false], $client->getSslContext());
        self::assertTrue($client->getSsl());
        self::assertSame('my-app', $client->getKeyPrefix());
        self::assertSame('none', $client->getCompression());
        self::assertSame('php', $client->getSerializer());
        self::assertTrue($client->getForceRevalidate());
        self::assertFalse($client->getIsConnected(), 'Configuring the client must not open a connection');
    }

    public function testAuthPasswordAlias(): void
    {
        $client = RedisClient::instance(['host' => '127.0.0.1', 'port' => 6379, 'auth_password' => 'secret']);

        self::assertSame('secret', $client->getAuth());
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidValues(): iterable
    {
        yield 'empty host' => ['host', ''];
        yield 'non-string host' => ['host', 123];
        yield 'negative port' => ['port', -1];
        yield 'too big port' => ['port', 65536];
        yield 'non-numeric port' => ['port', 'abc'];
        yield 'negative timeout' => ['connect_timeout', -1];
        yield 'zero tries' => ['connect_tries', 0];
        yield 'non-bool persistent' => ['persistent', 'yes'];
        yield 'negative db index' => ['db_index', -1];
        yield 'negative ttl' => ['cache_ttl', -5];
        yield 'non-bool jitter' => ['cache_ttl_jitter', 1];
        yield 'empty auth' => ['auth', ''];
        yield 'non-bool ssl' => ['ssl', ''];
        yield 'non-array ssl context' => ['ssl_context', 'cafile'];
        yield 'list ssl context' => ['ssl_context', ['/path/ca.pem']];
        yield 'empty username' => ['username', ''];
        yield 'negative read timeout' => ['read_timeout', -1];
        yield 'non-string key prefix' => ['key_prefix', []];
        yield 'unknown compression' => ['compression', 'gzip'];
        yield 'unknown serializer' => ['serializer', 'ibginary'];
        yield 'non-bool force revalidate' => ['force_revalidate', 'true'];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValuesAreRejected(string $key, mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RedisClient::instance([$key => $value] + ['host' => '127.0.0.1', 'port' => 6379]);
    }

    public function testKeyPrefixIsSlugged(): void
    {
        $client = RedisClient::instance(['host' => '127.0.0.1', 'port' => 6379]);

        self::assertSame('my-app-v2', $client->setKeyPrefix('  My App :: v2!! ')->getKeyPrefix());
        self::assertSame('', $client->setKeyPrefix('')->getKeyPrefix());
    }

    public function testUsernameRequiresAPassword(): void
    {
        $client = RedisClient::instance(['host' => '127.0.0.1', 'port' => 6379, 'username' => 'app']);

        $this->expectException(RedisNotConfiguredException::class);

        $client->get('key');
    }

    public function testNotConfiguredClientThrows(): void
    {
        $this->expectException(RedisNotConfiguredException::class);

        (new RedisClient())->get('key');
    }

    public function testWrapRejectsNonPublicMethods(): void
    {
        $client = RedisClient::instance(['host' => '127.0.0.1', 'port' => 6379]);

        $this->expectException(\InvalidArgumentException::class);

        $client->wrap('reconnect');
    }

    public function testWrapRejectsUnknownMethods(): void
    {
        $client = RedisClient::instance(['host' => '127.0.0.1', 'port' => 6379]);

        $this->expectException(\InvalidArgumentException::class);

        $client->wrap('doesNotExist');
    }

    public function testDisconnectDoesNotConnect(): void
    {
        $client = RedisClient::instance(['host' => '127.0.0.1', 'port' => 6379]);
        $client->disconnect();

        self::assertFalse($client->getIsConnected());
    }
}
