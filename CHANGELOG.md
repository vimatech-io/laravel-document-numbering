# Changelog

All notable changes to `vimatech/laravel-document-numbering` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-09-04

### Added

- `hasEverAllocated()` on the sequence (`Numbering::for($scope, $type)->hasEverAllocated()`, or `NumberingManager::hasEverAllocated($scope, $type)`): whether the sequence has ever consumed a number, across **all** periods. Use it to gate a change to a numbering setting on whether the sequence is already engaged. It is a read and consumes nothing.
- **The two read methods are bounded differently, and deliberately so: `peek()` reports the current period only, `hasEverAllocated()` reports every period.** `peek()` therefore cannot answer "is this sequence engaged?" — under a yearly reset a sequence used all through last year reports the fresh `…-00001` of the new year on 1 January, so code that gated a settings lock on `peek()` reopened that decision at every period boundary, silently.

### Changed

- Documented that `next()`, `peek()` and `hasEverAllocated()` each resolve the type's configuration first, so all three can throw `InvalidPattern` as well as `UnknownDocumentType`. The README previously named only `UnknownDocumentType`. Both extend `NumberingException`, which is the only type worth catching and only as a last-resort guard.
- Corrected the README's account of what a settings lock guards: it listed "a starting value" among the numbering settings, and this package has no starting-value setting. The configurable values are `pattern`, `reset` and `gap_free` per type, plus `table`, `connection` and `lock_attempts`.
- Qualified the "database-portable" claim in the README. The package writes no engine-specific SQL, but the automated suite runs on SQLite only — where `lockForUpdate()` compiles to an empty string, so the serialisation the concurrency test observes comes from SQLite's database-wide write lock, not from a row lock. The `SELECT ... FOR UPDATE` path MySQL and PostgreSQL take is not covered by any test in the repository. No behaviour changed; the README now says which guarantee is verified and which is designed-for.

## [1.0.2] - 2026-09-01

### Fixed

- Allocation refuses to continue when the sequence row cannot be read back after being created, instead of treating the missing value as zero. The row is created with `insertOrIgnore`, which swallows every failure and not only a duplicate key, so a numbering table carrying a column the package does not populate silently produced no row; the read then returned null, null was coerced to zero, and the next document number re-issued one that was already in use. It now throws `SequenceUnreadable` and the surrounding transaction rolls back, so no number is consumed. `peek()` is unchanged: an absent row there legitimately means nothing has been allocated yet.

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

[Unreleased]: https://github.com/vimatech-io/laravel-document-numbering/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/vimatech-io/laravel-document-numbering/compare/v1.0.2...v1.1.0
[1.0.2]: https://github.com/vimatech-io/laravel-document-numbering/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/vimatech-io/laravel-document-numbering/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/vimatech-io/laravel-document-numbering/releases/tag/v1.0.0
