# Changelog

## [Unreleased]

## [1.0.3] - 2026-11-23

### Fixed

- An ability that declares no `input()`, or only optional properties, can now be run without any input (#30). WordPress validates the `null` of a call without input against the object schema and refused it with "input is not of type object"; the schema is now published with `default: []`, as the core abilities do. It is left out when a property is required, so a missing required property is still reported, and a default the schema already declares is kept. **BREAKING**: the input schema published to WordPress, and so listed by REST and MCP, now carries `"default": []` for such abilities.

### Documentation

- The README says that WordPress runs an ability by the method derived from its hints, and that `->updates()` is run by `DELETE` like `->deletes()` (#30): this is the Abilities API mapping of destructive and idempotent abilities, and the hint is kept because an update overwrites data. The integration suite asserts it.

### Added

- An integration suite (`tests/Integration`, `composer test:integration`) that registers abilities and categories with the Abilities API of a real WordPress and runs them. It is kept out of the default test run and out of the Composer archive.

## [1.0.2] - 2026-11-19

### Security

- Ability names and category slugs are validated with the `D` modifier, so `$`
  can no longer tolerate a trailing line break. Defence in depth: values were
  already trimmed before validation.
