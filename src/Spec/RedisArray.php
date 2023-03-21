<?php

namespace Mfonte\Redisw\Spec;

/**
 * @mixin \Redis
 */
class RedisArray
{
    /**
     * Constructor
     *
     * @param string|array $hosts Name of the redis array from redis.ini or array of hosts to construct the array with
     * @param array        $opts  Array of options
     *
     * @link    https://github.com/nicolasff/phpredis/blob/master/arrays.markdown
     */
    public function __construct($hosts, array $opts = null)
    {
    }

    /**
     * @return array list of hosts for the selected array
     */
    public function _hosts()
    {
    }

    /**
     * @return string the name of the function used to extract key parts during consistent hashing
     */
    public function _function()
    {
    }

    /**
     * @param string $key The key for which you want to lookup the host
     *
     * @return  string  the host to be used for a certain key
     */
    public function _target($key)
    {
    }

    /**
     * Use this function when a new node is added and keys need to be rehashed.
     */
    public function _rehash()
    {
    }
}
