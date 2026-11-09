<?php

declare(strict_types=1);

namespace Mfonte\Redisw\Test\Integration;

use Mfonte\Redisw\Exception\RedisException;
use Mfonte\Redisw\Exception\RedisKeyNotFoundException;
use Mfonte\Redisw\Exception\RedisScriptExecutionException;
use Mfonte\Redisw\Exception\RedisTriesOverConnectException;
use Mfonte\Redisw\RedisClient;
use PHPUnit\Framework\Attributes\DataProvider;

final class RedisClientTest extends IntegrationTestCase
{
    public function testConnectsLazily(): void
    {
        $client = self::makeClient();
        self::assertFalse($client->getIsConnected());

        $client->set('test', 'test');
        self::assertTrue($client->getIsConnected());

        $client->disconnect();
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function values(): iterable
    {
        yield 'string' => ['string'];
        yield 'empty string' => [''];
        yield 'int' => [42];
        yield 'zero' => [0];
        yield 'float' => [1.5];
        yield 'array' => [['a' => 1, 'b' => [1, 2, 3]]];
        yield 'null' => [null];
        yield 'false' => [false];
        yield 'true' => [true];
    }

    #[DataProvider('values')]
    public function testValuesRoundTrip(mixed $value): void
    {
        self::assertTrue($this->client->set('key', $value));
        self::assertSame($value, $this->client->get('key'));
    }

    public function testGetMissingKeyThrows(): void
    {
        $this->expectException(RedisKeyNotFoundException::class);

        $this->client->get('missing');
    }

    public function testMgetReturnsFalseForMissingKeys(): void
    {
        $this->client->set('a', 1);
        $this->client->set('b', null);

        self::assertSame(['a' => 1, 'b' => null, 'c' => false], $this->client->mget(['a', 'b', 'c']));
        self::assertSame([], $this->client->mget([]));

        $this->client->set('d', false);
        self::assertSame(['d' => false, 'e' => 'default'], $this->client->mget(['d', 'e'], 'default'));
    }

    public function testCacheTtlIsApplied(): void
    {
        $this->client->setCacheTTL(60)->setCacheTTLJitter(false);
        $this->client->set('key', 'value');

        $ttl = $this->client->ttl('key');
        self::assertIsInt($ttl);
        self::assertGreaterThan(59000, $ttl);
        self::assertLessThanOrEqual(60000, $ttl);
    }

    public function testCacheTtlJitterIsBounded(): void
    {
        $this->client->setCacheTTL(60)->setCacheTTLJitter(true);
        $this->client->set('key', 'value');

        $ttl = $this->client->ttl('key');
        self::assertIsInt($ttl);
        self::assertGreaterThan(60000 + 9000, $ttl);
        self::assertLessThanOrEqual(60000 + 99000, $ttl);
    }

    public function testZeroCacheTtlMeansNoExpiration(): void
    {
        $this->client->setCacheTTL(0);
        $this->client->set('key', 'value');

        self::assertFalse($this->client->ttl('key'));
    }

    public function testTtlOfMissingKeyIsFalse(): void
    {
        self::assertFalse($this->client->ttl('missing'));
    }

    public function testIncrAndDecr(): void
    {
        self::assertSame(1, $this->client->incr('counter'));
        self::assertSame(6, $this->client->incr('counter', 5));
        self::assertSame(4, $this->client->incr('counter', -2));
        self::assertSame(4, $this->client->incr('counter', 0));
        self::assertSame(3, $this->client->decr('counter'));
        self::assertSame(0, $this->client->decr('counter', 3));
        self::assertSame(2, $this->client->decr('counter', -2));
    }

    public function testIncrOnNonIntegerValueThrows(): void
    {
        $this->client->set('key', 'not a number');

        $this->expectException(RedisException::class);

        $this->client->incr('key');
    }

    public function testAppendAndStrlen(): void
    {
        $this->client->setSerializer('none');

        self::assertSame(3, $this->client->append('key', 'abc'));
        self::assertSame(5, $this->client->append('key', 12));
        self::assertSame(5, $this->client->strlen('key'));
        self::assertSame('abc12', $this->client->get('key'));
    }

    public function testExistsHasAndCounters(): void
    {
        $this->client->set('key', 'value');

        self::assertTrue($this->client->exists('key'));
        self::assertTrue($this->client->has('key'));
        self::assertFalse($this->client->has('missing'));
        self::assertSame(2, $this->client->getHits());
        self::assertSame(1, $this->client->getMisses());
    }

    public function testForceRevalidate(): void
    {
        $this->client->set('key', 'value');
        $this->client->setForceRevalidate(true);

        self::assertFalse($this->client->has('key'));
        self::assertSame([], $this->client->allKeys());
        self::assertSame('value', $this->client->get('key'));
    }

    public function testDelete(): void
    {
        $this->client->set('a', 1);
        $this->client->set('b', 2);
        $this->client->set('c', 3);

        self::assertSame(1, $this->client->delete('a'));
        self::assertSame(2, $this->client->delete(['b', 'c', 'missing']));
        self::assertSame(0, $this->client->delete([]));
    }

    public function testMsetAppliesTtl(): void
    {
        self::assertTrue($this->client->mset(['a' => 1, 'b' => null]));

        self::assertSame(['a' => 1, 'b' => null], $this->client->mget(['a', 'b']));
        self::assertIsInt($this->client->ttl('a'));
        self::assertIsInt($this->client->ttl('b'));
    }

    public function testMsetWithoutTtl(): void
    {
        $this->client->setCacheTTL(0);

        self::assertTrue($this->client->mset(['a' => 1, 'b' => true]));
        self::assertSame(['a' => 1, 'b' => true], $this->client->mget(['a', 'b']));
        self::assertFalse($this->client->ttl('a'));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function ttls(): iterable
    {
        yield 'with ttl' => [60];
        yield 'without ttl' => [0];
    }

    #[DataProvider('ttls')]
    public function testMsetnxIsAllOrNothing(int $ttl): void
    {
        $this->client->setCacheTTL($ttl);
        $this->client->set('existing', 'value');

        self::assertFalse($this->client->msetnx(['existing' => 'other', 'new' => 'value']));
        self::assertFalse($this->client->has('new'));
        self::assertSame('value', $this->client->get('existing'));

        self::assertTrue($this->client->msetnx(['new1' => 1, 'new2' => 2]));
        self::assertSame(['new1' => 1, 'new2' => 2], $this->client->mget(['new1', 'new2']));
        self::assertSame($ttl > 0, is_int($this->client->ttl('new1')));
    }

    #[DataProvider('ttls')]
    public function testSetnx(int $ttl): void
    {
        $this->client->setCacheTTL($ttl);

        self::assertTrue($this->client->setnx('key', 'first'));
        self::assertFalse($this->client->setnx('key', 'second'));
        self::assertSame('first', $this->client->get('key'));
        self::assertSame($ttl > 0, is_int($this->client->ttl('key')));
    }

    #[DataProvider('ttls')]
    public function testGetset(int $ttl): void
    {
        $this->client->setCacheTTL($ttl);

        self::assertFalse($this->client->getset('key', null));
        self::assertNull($this->client->getset('key', ['a']));
        self::assertSame(['a'], $this->client->get('key'));
        self::assertSame($ttl > 0, is_int($this->client->ttl('key')));
    }

    public function testRenameAndRenamenx(): void
    {
        $this->client->set('a', 1);
        $this->client->set('b', 2);

        self::assertTrue($this->client->rename('a', 'c'));
        self::assertFalse($this->client->has('a'));
        self::assertSame(1, $this->client->get('c'));
        self::assertFalse($this->client->renamenx('c', 'b'));
        self::assertTrue($this->client->renamenx('c', 'd'));
    }

    public function testExpireExpireatAndPersist(): void
    {
        $this->client->set('key', 'value');

        self::assertTrue($this->client->expire('key', 5000));
        $ttl = $this->client->ttl('key');
        self::assertIsInt($ttl);
        self::assertLessThanOrEqual(5000, $ttl);

        self::assertTrue($this->client->persist('key'));
        self::assertFalse($this->client->ttl('key'));

        self::assertTrue($this->client->expireat('key', time() + 100));
        self::assertIsInt($this->client->ttl('key'));

        self::assertFalse($this->client->expire('missing', 1000));
    }

    public function testBits(): void
    {
        self::assertSame(0, $this->client->setbit('bits', 7, 1));
        self::assertSame(1, $this->client->getbit('bits', 7));
        self::assertSame(1, $this->client->setbit('bits', 7, 0));
        self::assertSame(0, $this->client->getbit('bits', 7));
    }

    public function testEvaluate(): void
    {
        self::assertSame(1, $this->client->evaluate('return 1'));
        self::assertSame(['k', 'v'], $this->client->evaluate('return {KEYS[1], ARGV[1]}', ['k', 'v'], 1));
        self::assertSame(['k', 'v'], $this->client->evaluate('return {KEYS[1], KEYS[2]}', ['k', 'v']));
    }

    public function testEvalSha(): void
    {
        $script = 'return ARGV[1]';
        $this->client->evaluate($script, ['warmup'], 0);

        self::assertSame('hello', $this->client->evalSha(sha1($script), ['hello'], 0));
    }

    public function testFailingScriptThrowsAndDoesNotLeakTheError(): void
    {
        try {
            $this->client->evaluate("return redis.call('NOT_A_COMMAND')");
            self::fail('RedisScriptExecutionException not thrown');
        } catch (RedisScriptExecutionException) {
            // expected
        }

        self::assertSame(1, $this->client->evaluate('return 1'));
    }

    public function testSets(): void
    {
        self::assertSame(1, $this->client->sadd('s1', 'a'));
        self::assertSame(1, $this->client->sadd('s1', 'b'));
        self::assertSame(0, $this->client->sadd('s1', 'b'));
        self::assertSame(1, $this->client->sadd('s2', 'b'));
        self::assertSame(1, $this->client->sadd('s2', 'c'));

        self::assertSame(2, $this->client->scard('s1'));
        self::assertTrue($this->client->sismember('s1', 'a'));
        self::assertFalse($this->client->sismember('s1', 'c'));

        $members = $this->client->smembers('s1');
        sort($members);
        self::assertSame(['a', 'b'], $members);
        self::assertContains($this->client->srandmember('s1'), ['a', 'b']);

        self::assertSame(3, $this->client->sunionstore('union', ['s1', 's2']));
        self::assertSame(1, $this->client->sinterstore('inter', ['s1', 's2']));
        self::assertSame(1, $this->client->sdiffstore('diff', ['s1', 's2']));

        self::assertSame(1, $this->client->srem('s1', 'a'));
        self::assertSame(0, $this->client->srem('s1', 'a'));
        self::assertSame(['b'], $this->client->smembers('s1'));
        self::assertSame('b', $this->client->spop('s1'));
        self::assertSame(0, $this->client->scard('s1'));
    }

    public function testAllKeysAndAllValues(): void
    {
        $values = ['a' => 1, 'b' => 'two', 'c' => null];
        $this->client->mset($values);

        $keys = $this->client->allKeys();
        sort($keys);
        self::assertSame(['a', 'b', 'c'], $keys);

        $all = $this->client->allValues();
        ksort($all);
        self::assertSame($values, $all);
    }

    public function testKeyPrefix(): void
    {
        $this->client->set('outside', 1);
        $this->client->setKeyPrefix('app');
        $this->client->set('inside', 2);

        self::assertSame(['inside'], $this->client->allKeys());
        self::assertSame(['inside' => 2], $this->client->allValues());
        self::assertFalse($this->client->has('outside'));

        $this->client->setKeyPrefix('');

        self::assertTrue($this->client->has('appinside'));
        self::assertTrue($this->client->has('outside'));
    }

    public function testDbIndexCanBeChangedWhileConnected(): void
    {
        $this->client->set('key', 'value');

        $this->client->setDbIndex(self::altDb());
        self::assertSame(self::altDb(), $this->client->getDbIndex());
        self::assertFalse($this->client->has('key'));

        $this->client->setDbIndex(self::db());
        self::assertTrue($this->client->has('key'));
    }

    public function testDbSizeAndFlushDb(): void
    {
        $this->client->set('a', 1);
        $this->client->set('b', 2);
        $this->client->setDbIndex(self::altDb());
        $this->client->set('c', 3);
        $this->client->setDbIndex(self::db());

        self::assertSame(2, $this->client->dbSize());
        self::assertSame(1, $this->client->dbSize(self::altDb()));

        $this->client->flushDb(self::altDb());

        self::assertSame(0, $this->client->dbSize(self::altDb()));
        self::assertSame(2, $this->client->dbSize(), 'flushing another database must not touch the current one');

        $this->client->flushDb();

        self::assertSame(0, $this->client->dbSize());
        self::assertSame([], $this->client->allKeys());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function compressions(): iterable
    {
        yield 'none' => ['none'];
        yield 'lzf' => ['lzf'];
        yield 'zstd' => ['zstd'];
        yield 'lz4' => ['lz4'];
    }

    #[DataProvider('compressions')]
    public function testCompression(string $compression): void
    {
        try {
            $this->client->setCompression($compression);
        } catch (\InvalidArgumentException $e) {
            self::markTestSkipped($e->getMessage());
        }

        $value = str_repeat('compressible ', 100);
        $this->client->set('key', $value);

        self::assertSame($compression, $this->client->getCompression());
        self::assertSame($value, $this->client->get('key'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function serializers(): iterable
    {
        yield 'php' => ['php'];
        yield 'json' => ['json'];
        yield 'igbinary' => ['igbinary'];
        yield 'msgpack' => ['msgpack'];
    }

    #[DataProvider('serializers')]
    public function testSerializer(string $serializer): void
    {
        try {
            $this->client->setSerializer($serializer);
        } catch (\InvalidArgumentException $e) {
            self::markTestSkipped($e->getMessage());
        }

        $this->client->set('key', ['a' => 1, 'b' => 'two']);

        self::assertSame($serializer, $this->client->getSerializer());
        self::assertSame(['a' => 1, 'b' => 'two'], $this->client->get('key'));
    }

    public function testWrapSwallowsExceptions(): void
    {
        self::assertNull($this->client->wrap('get', 'missing'));
        self::assertTrue($this->client->wrap('set', 'key', [1, 2]));
        self::assertSame([1, 2], $this->client->wrap('get', 'key'));
    }

    public function testDisconnectAndReconnect(): void
    {
        $this->client->set('key', 'value');
        $this->client->disconnect();

        self::assertFalse($this->client->getIsConnected());

        $this->client->disconnect();

        self::assertFalse($this->client->getIsConnected());
        self::assertSame('value', $this->client->get('key'));
        self::assertTrue($this->client->getIsConnected());
    }

    public function testAclUsernameAuthentication(): void
    {
        $admin = new \Redis();
        $admin->connect(self::host(), self::port(), 1.0);

        $info = $admin->info('server');
        $version = is_array($info) && is_string($info['redis_version'] ?? null) ? $info['redis_version'] : '0';
        if (version_compare($version, '6.0.0', '<')) {
            self::markTestSkipped('ACL users require Redis >= 6.0.');
        }

        $admin->rawCommand('ACL', 'SETUSER', 'redisw-test', 'on', '>s3cret', '~*', '+@all');

        try {
            $client = self::makeClient(['username' => 'redisw-test', 'auth' => 's3cret']);
            self::assertTrue($client->set('acl', 'ok'));
            self::assertSame('ok', $client->get('acl'));
            $client->disconnect();

            $wrong = self::makeClient(['username' => 'redisw-test', 'auth' => 'wrong']);
            $this->expectException(RedisTriesOverConnectException::class);
            $wrong->get('acl');
        } finally {
            $admin->rawCommand('ACL', 'DELUSER', 'redisw-test');
            $admin->close();
        }
    }

    public function testReadTimeoutIsAccepted(): void
    {
        $client = self::makeClient(['read_timeout' => 2.5]);

        self::assertTrue($client->set('key', 'value'));
        self::assertSame('value', $client->get('key'));
        $client->disconnect();
    }

    public function testExplicitTtlOverridesTheDefault(): void
    {
        $this->client->setCacheTTL(60);

        $this->client->set('ten', 'value', 10);
        $ttl = $this->client->ttl('ten');
        self::assertIsInt($ttl);
        self::assertLessThanOrEqual(10000, $ttl);

        $this->client->set('forever', 'value', 0);
        self::assertFalse($this->client->ttl('forever'));

        $this->client->mset(['m1' => 1, 'm2' => 2], 10);
        $ttl = $this->client->ttl('m2');
        self::assertIsInt($ttl);
        self::assertLessThanOrEqual(10000, $ttl);

        $this->client->mset(['m3' => 3], 0);
        self::assertFalse($this->client->ttl('m3'));

        self::assertTrue($this->client->setnx('nx', 'value', 10));
        $ttl = $this->client->ttl('nx');
        self::assertIsInt($ttl);
        self::assertLessThanOrEqual(10000, $ttl);
    }

    public function testNegativeExplicitTtlIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->client->set('key', 'value', -1);
    }

    public function testDeleteAllOnlyDeletesPrefixedKeys(): void
    {
        $this->client->set('outside', 1);
        $this->client->setKeyPrefix('app');
        $this->client->mset(['a' => 1, 'b' => 2]);
        $this->client->setForceRevalidate(true);

        self::assertSame(2, $this->client->deleteAll(), 'deleteAll() must ignore force_revalidate');

        $this->client->setForceRevalidate(false)->setKeyPrefix('');
        self::assertSame(['outside'], $this->client->allKeys());
    }

    public function testConnectionRetriesAreExhausted(): void
    {
        $client = RedisClient::instance([
            'host' => self::host(),
            'port' => 1,
            'connect_tries' => 3,
            'connect_timeout' => 0.2,
        ]);

        $this->expectException(RedisTriesOverConnectException::class);
        $this->expectExceptionMessage('after 3 attempt(s)');

        $client->get('key');
    }
}
