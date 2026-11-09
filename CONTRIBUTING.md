# Contributing

Contributions are welcome! Please open an issue first for anything larger than a small fix, so that we can agree on the approach.

## Development setup

You need PHP >= 8.1 with the `redis` extension, Composer, and a Redis server for the integration tests.

```bash
git clone https://github.com/mauriziofonte/php-redisw.git
cd php-redisw
composer install

# start a throwaway Redis server, if you don't have one
docker run --rm -d -p 6379:6379 redis:7-alpine
```

## Running the checks

```bash
composer test      # PHPUnit (unit + integration suites)
composer analyse   # PHPStan, level max
composer cs        # PHP-CS-Fixer, dry run (use `composer fix` to apply)
composer check     # all of the above
```

The integration tests connect to `127.0.0.1:6379` and use databases **15** and **14**, which are flushed before and after each test. They never call `FLUSHALL`. Override the defaults with the `REDIS_HOST`, `REDIS_PORT`, `REDIS_DB` and `REDIS_DB_ALT` environment variables. If no Redis server is reachable, the integration tests are skipped.

## Pull requests

- Add tests for every bug fix and new feature.
- Keep the public API backward compatible, unless the change is planned for a new major version.
- Add an entry to the `Unreleased` section of [CHANGELOG.md](CHANGELOG.md).
- Use [Conventional Commits](https://www.conventionalcommits.org/) for commit messages (`fix: ...`, `feat: ...`, `docs: ...`).
