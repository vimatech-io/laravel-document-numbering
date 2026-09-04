# Contributing

Thank you for considering contributing to Laravel Document Numbering!

## Development Setup

```bash
git clone https://github.com/vimatech-io/laravel-document-numbering.git
cd laravel-document-numbering
composer install
```

## Running Tests

```bash
composer test
```

The suite runs on SQLite, where `lockForUpdate()` compiles to an empty string,
so the row lock in `NumberingManager` is not covered.

Adding a second connection that holds the row does not cover it either: the
`insertOrIgnore` preceding the locking read takes a conflicting lock first, so
the allocation times out on the insert and the test passes unchanged with
`lockForUpdate()` removed. A test that covers the lock has to reach the locking
read.

## Code Style

```bash
composer format
```

## Static Analysis

```bash
composer analyse
```

## Pull Requests

- Fork the repo, create a feature branch
- Add tests for new features
- Ensure all tests pass and PHPStan is clean
- Submit a PR against `main`

## Security Vulnerabilities

Please report security issues to hello@adelzemzemi.dev directly.
