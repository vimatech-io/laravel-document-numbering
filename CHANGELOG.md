# Changelog

All notable changes to `vimatech/laravel-document-numbering` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.1] - 2026-06-26

### Changed

- Add `.gitattributes` (`export-ignore`) to slim the distribution archive.
- Add Packagist version and downloads badges to the README.

## [1.0.0] - 2026-06-26

### Added

- Initial release.
- `NumberingManager` with `for($scope, $type)->next()` and `->peek()` for
  atomic, concurrency-safe sequential allocation using a DB transaction and
  `lockForUpdate()` row lock. The manager is stateless (config read on demand,
  connection resolved per call), so it is safe as a long-lived singleton under
  FrankenPHP/Octane worker mode.
- Configurable per-type patterns with the `{YYYY}`, `{YY}`, `{MM}` and
  `{seq:n}` tokens, compiled by `Support\PatternCompiler`.
- Period resets: `yearly`, `monthly` and `never` via the `ResetPolicy` enum.
- Per-type `gap_free` policy: allocate inside the caller's transaction so a
  rollback releases the number, or fast-sequential mode that favours throughput.
- `Concerns\HasDocumentNumber` trait that assigns the number on `creating`,
  wrapping the first save of gap-free types in a transaction. A configured scope
  column must be set before saving, otherwise a `LogicException` is thrown rather
  than silently using the global scope.
- `numbering.lock_attempts` config option to tune how many times the allocation
  transaction is retried on a deadlock / locked error.
- `Events\NumberAllocated` event and `Exceptions\SequenceLocked`,
  `InvalidPattern` and `UnknownDocumentType` exceptions.
- `Numbering` facade.
- Database migration for the `document_number_sequences` table with a unique
  index on `(scope, type, period_key)`.

[Unreleased]: https://github.com/vimatech-io/laravel-document-numbering/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/vimatech-io/laravel-document-numbering/releases/tag/v1.0.0
