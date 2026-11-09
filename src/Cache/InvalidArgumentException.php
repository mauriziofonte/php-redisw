<?php

declare(strict_types=1);

namespace Mfonte\Redisw\Cache;

use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgumentException;

/**
 * Thrown by {@see SimpleCache} when a key or a TTL is not valid according to PSR-16.
 */
final class InvalidArgumentException extends \InvalidArgumentException implements PsrInvalidArgumentException
{
}
