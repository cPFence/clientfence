# Contributing to ClientFence

Thanks for helping. By taking part you agree to follow the
[Code of Conduct](CODE_OF_CONDUCT.md).

## Issues

- **Bugs:** open a [GitHub issue](https://github.com/cPFence/clientfence/issues) with the
  ClientFence version, what you did, what you expected and what happened instead.
  Leave out passwords, keys and customer data.
- **Ideas:** open an issue that describes the problem first, then the change you suggest.
- **Security issues:** never in a public issue. Follow [SECURITY.md](.github/SECURITY.md).

## Pull requests

1. Open or find an issue first for anything larger than a small fix.
2. Keep each pull request to one change, with tests for new behaviour.
3. Run the checks below; they must pass.
4. Describe what changed and why. Pull requests are merged by a maintainer after review.

Contributions are accepted under the [Apache License 2.0](LICENSE).

## Checks

The test image has every tool and runs the same checks as CI:

```bash
docker build --target test -t clientfence-test .
docker run --rm clientfence-test ./src/vendor/bin/phpstan analyse --configuration phpstan.neon --memory-limit=1G --no-progress
docker run --rm clientfence-test ./src/vendor/bin/pest --test-directory ../tests --configuration phpunit.xml.dist --ci
```

Format PHP with `composer cs:fix` (PSR-12).
