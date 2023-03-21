<?php

namespace Mfonte\Redisw\Test\Unit;

class RedisClientTest extends TestCase
{
    public function testConnection()
    {
        $this->baseClient->set('test', 'test');
        $this->assertEquals(true, $this->baseClient->getIsConnected());
    }

    public function testSetDbIndex()
    {
        $this->baseClient->setDbIndex(1);
        $this->baseClient->set('test', 'test');

        $this->assertEquals(1, $this->baseClient->getDbIndex());
    }

    public function testSetKeyPrefix()
    {
        $this->baseClient->setKeyPrefix('keyprefix');
        $this->baseClient->set('test', 'test');

        $this->assertEquals('keyprefix', $this->baseClient->getKeyPrefix());
    }

    public function testSetKeyPrefixWithDbIndex()
    {
        $this->baseClient->setDbIndex(1);
        $this->baseClient->setKeyPrefix('keyprefix');
        $this->baseClient->set('test', 'test');

        $this->assertEquals('keyprefix', $this->baseClient->getKeyPrefix());
        $this->assertEquals(1, $this->baseClient->getDbIndex());
    }

    public function testSetLzfCompression()
    {
        $this->baseClient->setCompression('lzf');
        $this->baseClient->set('test', 'test');

        $this->assertEquals('lzf', $this->baseClient->getCompression());
        $this->assertEquals('test', $this->baseClient->get('test'));
    }

    public function testSetZstdCompression()
    {
        $this->baseClient->setCompression('zstd');
        $this->baseClient->set('test', 'test');

        $this->assertEquals('zstd', $this->baseClient->getCompression());
        $this->assertEquals('test', $this->baseClient->get('test'));
    }

    public function testSetLz4Compression()
    {
        $this->baseClient->setCompression('lz4');
        $this->baseClient->set('test', 'test');

        $this->assertEquals('lz4', $this->baseClient->getCompression());
        $this->assertEquals('test', $this->baseClient->get('test'));
    }

    public function testSetMultipleKeys()
    {
        $arr = self::getRandomArray();

        // set keys
        foreach ($arr as $key => $value) {
            $this->baseClient->set($key, $value);
        }

        // assert keys
        foreach ($arr as $key => $value) {
            $this->assertEquals($value, $this->baseClient->get($key));
        }

        // assert that mget returns the same values
        $this->assertEquals($arr, $this->baseClient->mget(array_keys($arr)));
    }

    public function testGetAllValues()
    {
        $arr = self::getRandomArray();

        // set keys
        foreach ($arr as $key => $value) {
            $this->baseClient->set($key, $value);
        }

        // assert that getAllKeys returns the same keys
        $this->assertEquals($arr, $this->baseClient->allValues());
    }
}
