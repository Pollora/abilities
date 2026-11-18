# Changelog

## [Unreleased]

### Security

- Ability names and category slugs are validated with the `D` modifier, so `$`
  can no longer tolerate a trailing line break. Defence in depth: values were
  already trimmed before validation.
