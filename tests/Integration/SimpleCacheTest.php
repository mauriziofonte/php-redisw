<?php

declare(strict_types=1);

namespace Mfonte\Redisw\Test\Integration;

use Mfonte\Redisw\Cache\InvalidArgumentException;
use Mfonte\Redisw\Cache\SimpleCache;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgumentException;

final class SimpleCacheTest extends IntegrationTestCase
{
    private SimpleCache $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = new SimpleCache($this->client);
    }

    public function testGetReturnsDefaultForMissingKeys(): void
    {
        self::assertNull($this->cache->get('missing'));
        self::assertSame('default', $this->cache->get('missing', 'default'));
    }

    public function testSetAndGet(): void
    {
        self::assertTrue($this->cache->set('key', ['a' => 1]));
        self::assertSame(['a' => 1], $this->cache->get('key', 'default'));

        self::assertTrue($this->cache->set('false', false));
        self::assertFalse($this->cache->get('false', 'default'));

        self::assertTrue($this->cache->set('null', null));
        self::assertNull($this->cache->get('null', 'default'));
        self::assertTrue($this->cache->has('null'));
    }

    public function testNullTtlUsesTheClientDefault(): void
    {
        $this->client->setCacheTTL(60)->setCacheTTLJitter(false);
        $this->cache->set('key', 'value');

        $ttl = $this->client->ttl('key');
        self::assertIsInt($ttl);
        self::assertGreaterThan(59000, $ttl);
        self::assertLessThanOrEqual(60000, $ttl);
    }

    public function testExplicitTtlIsAppliedExactly(): void
    {
        $this->cache->set('int', 'value', 10);
        $this->cache->set('interval', 'value', new \DateInterval('PT20S'));

        $ttl = $this->client->ttl('int');
        self::assertIsInt($ttl);
        self::assertGreaterThan(9000, $ttl);
        self::assertLessThanOrEqual(10000, $ttl);

        $ttl = $this->client->ttl('interval');
        self::assertIsInt($ttl);
        self::assertGreaterThan(19000, $ttl);
        self::assertLessThanOrEqual(20000, $ttl);
    }

    public function testNonPositiveTtlDeletesTheItem(): void
    {
        $this->cache->set('key', 'value');

        self::assertTrue($this->cache->set('key', 'other', 0));
        self::assertFalse($this->cache->has('key'));

        $this->cache->setMultiple(['a' => 1, 'b' => 2]);

        self::assertTrue($this->cache->setMultiple(['a' => 3, 'b' => 4], -1));
        self::assertFalse($this->cache->has('a'));
        self::assertFalse($this->cache->has('b'));
    }

    public function testDelete(): void
    {
        $this->cache->set('key', 'value');

        self::assertTrue($this->cache->delete('key'));
        self::assertTrue($this->cache->delete('missing'));
        self::assertFalse($this->cache->has('key'));
    }

    public function testMultiple(): void
    {
        self::assertTrue($this->cache->setMultiple(['a' => 1, 'b' => false, 7 => 'seven']));

        self::assertSame(
            ['a' => 1, 'b' => false, '7' => 'seven', 'c' => 'default'],
            $this->cache->getMultiple(['a', 'b', '7', 'c'], 'default')
        );

        self::assertTrue($this->cache->deleteMultiple(['a', 'b']));
        self::assertSame(['a' => null, 'b' => null], $this->cache->getMultiple(['a', 'b']));
        self::assertSame([], $this->cache->getMultiple([]));
    }

    public function testMultipleAcceptsGenerators(): void
    {
        $values = (static function (): \Generator {
            yield 'a' => 1;
            yield 'b' => 2;
        })();
        $keys = (static function (): \Generator {
            yield 'a';
            yield 'b';
        })();

        self::assertTrue($this->cache->setMultiple($values));
        self::assertSame(['a' => 1, 'b' => 2], $this->cache->getMultiple($keys));
    }

    public function testClearWithoutPrefixFlushesTheCurrentDatabase(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);

        self::assertTrue($this->cache->clear());
        self::assertSame(0, $this->client->dbSize());
    }

    public function testClearWithPrefixOnlyDeletesPrefixedKeys(): void
    {
        $this->client->set('outside', 1);
        $this->client->setKeyPrefix('app');
        $this->cache->set('inside', 2);

        self::assertTrue($this->cache->clear());
        self::assertFalse($this->cache->has('inside'));

        $this->client->setKeyPrefix('');
        self::assertTrue($this->client->has('outside'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        foreach (str_split('{}()/\\@:') as $char) {
            yield "reserved {$char}" => ["key{$char}"];
        }
    }

    #[DataProvider('invalidKeys')]
    public function testInvalidKeysAreRejected(string $key): void
    {
        $this->expectException(PsrInvalidArgumentException::class);

        $this->cache->get($key);
    }

    public function testInvalidKeysInIterablesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cache->getMultiple(['valid', 'in:valid']);
    }

    public function testNonStringKeysInIterablesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cache->deleteMultiple([new \stdClass()]);
    }
}
