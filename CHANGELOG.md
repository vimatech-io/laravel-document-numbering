# Changelog

All notable changes to `vimatech/laravel-document-numbering` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- `NumberingManager` now reads its configuration from the config repository on
  demand instead of capturing a snapshot at construction, making it a fully
  stateless singleton that is safe under FrankenPHP/Octane worker mode.

## [1.0.0] - 2026-06-24

### Added

- Initial release.
- `NumberingManager` with `for($scope, $type)->next()` and `->peek()` for
  atomic, concurrency-safe sequential allocation using a DB transaction and
  `lockForUpdate()` row lock.
- Configurable per-type patterns with the `{YYYY}`, `{YY}`, `{MM}` and
  `{seq:n}` tokens, compiled by `Support\PatternCompiler`.
- Period resets: `yearly`, `monthly` and `never` via the `ResetPolicy` enum.
- Per-type `gap_free` policy: allocate inside the caller's transaction so a
  rollback releases the number, or fast-sequential mode that favours throughput.
- `Concerns\HasDocumentNumber` trait that assigns the number on `creating`,
  wrapping the first save of gap-free types in a transaction.
- `Events\NumberAllocated` event and `Exceptions\SequenceLocked`,
  `InvalidPattern` and `UnknownDocumentType` exceptions.
- `Numbering` facade.
- Database migration for the `document_number_sequences` table with a unique
  index on `(scope, type, period_key)`.

[Unreleased]: https://github.com/vimatech-io/laravel-document-numbering/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/vimatech-io/laravel-document-numbering/releases/tag/v1.0.0
