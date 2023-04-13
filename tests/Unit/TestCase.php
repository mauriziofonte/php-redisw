<?php

namespace Mfonte\Redisw\Test\Unit;

use Mfonte\Redisw\RedisClient;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase
{
    protected RedisClient $baseClient;

    protected function setUp(): void
    {
        $this->baseClient = RedisClient::instance([
            'host' => '127.0.0.1',
            'port' => 6379,
            'connect_timeout' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $this->baseClient->flushAll();
        $this->baseClient->disconnect();
    }

    /**
     * Get a random array of 16 keys with random values
     *
     * @return array
     */
    protected static function getRandomArray() : array
    {
        $randomArray = [];
        for ($i = 0; $i < 16; $i++) {
            $randomArray["key_{$i}"] = self::getRandomValue();
        }

        return $randomArray;
    }

    /**
     * Get a random value
     *
     * @return mixed
     */
    protected static function getRandomValue()
    {
        $randomValue = null;
        switch (rand(0, 4)) {
            case 0:
                $randomValue = 'string';
                break;
            case 1:
                $randomValue = rand(0, 100);
                break;
            case 2:
                // pick a random number from 1 to 10
                $randKeys = mt_rand(1, 10);
                // create an array of random keys
                $randomValue = array_map(function () {
                    return rand(1, 100);
                }, array_fill(0, $randKeys, null));
                break;
            case 3:
                $randomValue = true;
                break;
            case 4:
                $randomValue = null;
                break;
            case 5:
        }
        
        return $randomValue;
    }
}
