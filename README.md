# PHP Redis Wrapper around the phpredis extension

Just another Redis Wrapper, around the [https://github.com/phpredis/phpredis](https://github.com/phpredis/phpredis) extension.

[![Latest Stable Version](https://poser.pugx.org/mfonte/redisw/v/stable)](https://packagist.org/packages/mfonte/redisw)
[![Total Downloads](https://poser.pugx.org/mfonte/redisw/downloads)](https://packagist.org/packages/mfonte/redisw)

## Installation

Simple enough.

`composer require mfonte/redisw`

Required environment:

1. PHP **>= 8.1**
2. `ext-redis` module
3. *optional* (but recommended) : *igbinary* support for better serialization

### Basic Usage

Usage is simple enough with a nice, expressive API:

```php
<?php
use Mfonte\Redisw\RedisClient as Redisw;

try {
    $client = Redisw::instance([
        'host' => '127.0.0.1', 
        'port' => 6379, 
        'connect_timeout' => 1, // optional
        'connect_tries' => 10, // optional
        'persistent' => true, // optional
        'db_index' => 1, // optional
        'cache_ttl' => 60, // optional. Defaults to 60 sec.
        'auth_password' => '', // optional
        'ssl' => '', // optional
        'key_prefix' => 'some_prefix' // optional
    ]);

    // set a key
    $client->set('somekey', ['value', 'value', 'value']);

    // get a key
    $value = $client->get('somekey');
}

```

### Testing

Simply run `composer install` over this module's installation directory.

Then, run `composer test` to run all the tests.

### Thank you's

A big thank you goes to [https://github.com/alxmsl](https://github.com/alxmsl) for his base implementation on [https://github.com/alxmsl/Redis](https://github.com/alxmsl/Redis). This package heavily relies on his work.

Another big thank you goes to [https://github.com/ukko](https://github.com/ukko) for his phpredis extension autocomplete on [https://github.com/ukko/phpredis-phpdoc](https://github.com/ukko/phpredis-phpdoc). This package was easier to be written thanks to his work.
