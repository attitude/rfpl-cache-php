# Respond First Process Later Cache

> PHP caching drop-in class

Features:

1. only GET requests are cached
1. always serves previously cached version if exists
1. request is passed down to the application only if TTL expires
1. if processing occurs, it is never sent to output if cached response was sent before

This method is experimental and might not be suitable for all applications due to how the caching mechanism works.

## Installation using Composer

1. Create `composer.json`:
```
{
    "name": "yourproject",
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:attitude/rfpl-cache-php.git"
        }
    ],
    "require": {
        "attitude/rfpl-cache-php": "dev-master"
    }
}
```
2. run `$ composer install`

## Usage

1. Require using composer autoload: `require_once 'vendor/autoload.php';` or directly `require_once 'src/Cache.php';`
2. Insert before any processing:
```php
$cache = new \RFPL\Cache();
try { $cache->serve(); } catch (\Exception $e) {}
```

## Options

Constructor accepts array of arguments:

- `path`: relative or absolute path, where to store cache; default value is `'cache'`;
- `ttl`: time to live in seconds specifies how long cache is considered *fresh*; default is 5 minutes, `300` seconds

Example:

```php
$cache = new \RFPL\Cache([
    'path' => 'cache/html',
    'ttl' => 120
]);
```

`$cache->serve()` method has optional filter argument. By passing a callable it's possible to filter response, e.g. to add timestamp to response.

A script by [@martin_adamko](https://twitter.com/martin_adamko)
