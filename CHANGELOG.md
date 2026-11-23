# Changelog

## [Unreleased]

### Added

- An integration suite (`tests/Integration`, `composer test:integration`) that registers abilities and categories with the Abilities API of a real WordPress and runs them. It is kept out of the default test run and out of the Composer archive.

## [1.0.2] - 2026-11-19

### Security

- Ability names and category slugs are validated with the `D` modifier, so `$`
  can no longer tolerate a trailing line break. Defence in depth: values were
  already trimmed before validation.
