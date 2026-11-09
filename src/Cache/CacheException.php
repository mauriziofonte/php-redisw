<?php

declare(strict_types=1);

namespace Mfonte\Redisw\Cache;

use Mfonte\Redisw\Exception\RedisException;
use Psr\SimpleCache\CacheException as PsrCacheException;

/**
 * Thrown by {@see SimpleCache} when the underlying Redis command fails.
 */
final class CacheException extends RedisException implements PsrCacheException
{
}
