# Changelog

## [Unreleased]

## [1.0.2] - 2026-11-19

### Security

- Ability names and category slugs are validated with the `D` modifier, so `$`
  can no longer tolerate a trailing line break. Defence in depth: values were
  already trimmed before validation.
