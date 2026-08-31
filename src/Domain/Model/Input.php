<?php

declare(strict_types=1);

namespace Pollora\Abilities\Domain\Model;

/**
 * Typed, defensive reader over the raw input an ability receives.
 *
 * The Abilities API validates input against the declared schema before the
 * execute callback runs, but it does not guarantee a shape: an ability whose
 * every property is optional can legitimately be invoked with `null`. Reading
 * through this wrapper means an ability body never repeats the same
 * `is_array()` / `?? default` / cast dance, and never has to guess whether a
 * missing key means "absent" or "empty".
 *
 * Accessors coerce rather than throw. A model that sends `"12"` where an integer
 * was asked for should get a working call, not an error it cannot act on.
 *
 * Instances are immutable.
 */
final readonly class Input
{
    /**
     * @param  array<string, mixed>  $values  Normalised input values, keyed by property name.
     */
    private function __construct(private array $values) {}

    /**
     * Wrap whatever the Abilities API handed the callback.
     *
     * @param  mixed  $raw  Raw input. Anything that is not an array becomes an empty set,
     *                      which is what lets an all-optional ability be called with `null`.
     * @return self The wrapped input.
     */
    public static function wrap(mixed $raw): self
    {
        /** @var array<string, mixed> $values */
        $values = is_array($raw) ? $raw : [];

        return new self($values);
    }

    /**
     * Whether a key is present at all, even holding an empty or false value.
     *
     * Use this where "explicitly set to zero, false or empty" is a different
     * instruction from "not mentioned" — a menu order, a parent identifier.
     *
     * @param  string  $key  Property name.
     * @return bool True when the key exists and is not null.
     */
    public function has(string $key): bool
    {
        return isset($this->values[$key]);
    }

    /**
     * Whether a key was supplied with a meaningful value.
     *
     * Deliberately stricter than {@see self::has()}: callers routinely send empty
     * strings and empty arrays for properties they mean to leave alone, and
     * treating those as present produces empty search terms and cleared
     * taxonomies.
     *
     * @param  string  $key  Property name.
     * @return bool True when the value is set and is neither an empty string nor an empty array.
     */
    public function filled(string $key): bool
    {
        return isset($this->values[$key])
            && $this->values[$key] !== ''
            && $this->values[$key] !== [];
    }

    /**
     * Read a string.
     *
     * @param  string  $key  Property name.
     * @param  string  $default  Value returned when the property is absent, empty or not scalar.
     * @return string The string value.
     */
    public function string(string $key, string $default = ''): string
    {
        if (! $this->filled($key)) {
            return $default;
        }

        $value = $this->values[$key];

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Read an integer, optionally clamped.
     *
     * @param  string  $key  Property name.
     * @param  int  $default  Value returned when the property is absent or not numeric.
     * @param  int|null  $min  Lower bound applied after coercion, or null to leave it unbounded.
     * @param  int|null  $max  Upper bound applied after coercion, or null to leave it unbounded.
     *                         Worth setting on anything that sizes a query: a model asking for
     *                         100000 rows should quietly get the ceiling rather than time out.
     * @return int The integer value, within bounds when given.
     */
    public function integer(string $key, int $default = 0, ?int $min = null, ?int $max = null): int
    {
        $value = $this->has($key) && is_numeric($this->values[$key])
            ? (int) $this->values[$key]
            : $default;

        if ($min !== null) {
            $value = max($min, $value);
        }

        if ($max !== null) {
            $value = min($max, $value);
        }

        return $value;
    }

    /**
     * Read a float, optionally clamped.
     *
     * @param  string  $key  Property name.
     * @param  float  $default  Value returned when the property is absent or not numeric.
     * @param  float|null  $min  Lower bound applied after coercion, or null to leave it unbounded.
     * @param  float|null  $max  Upper bound applied after coercion, or null to leave it unbounded.
     * @return float The float value, within bounds when given.
     */
    public function float(string $key, float $default = 0.0, ?float $min = null, ?float $max = null): float
    {
        $value = $this->has($key) && is_numeric($this->values[$key])
            ? (float) $this->values[$key]
            : $default;

        if ($min !== null) {
            $value = max($min, $value);
        }

        if ($max !== null) {
            $value = min($max, $value);
        }

        return $value;
    }

    /**
     * Read a non-negative object identifier.
     *
     * Identifiers are never negative and zero reliably means "none", which makes
     * this a distinct concept from a plain integer rather than a convenience.
     *
     * @param  string  $key  Property name.
     * @return int<0, max> The identifier, or 0 when absent or invalid.
     */
    public function id(string $key): int
    {
        if (! $this->has($key) || ! is_numeric($this->values[$key])) {
            return 0;
        }

        return max(0, (int) $this->values[$key]);
    }

    /**
     * Read a boolean.
     *
     * Accepts the JSON booleans a well-behaved client sends, and the `"true"` /
     * `"1"` / `"yes"` strings that leak through less careful ones.
     *
     * @param  string  $key  Property name.
     * @param  bool  $default  Value returned when the property is absent or uninterpretable.
     * @return bool The boolean value.
     */
    public function boolean(string $key, bool $default = false): bool
    {
        if (! $this->has($key)) {
            return $default;
        }

        return filter_var($this->values[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * Read a list of strings, dropping anything that is not scalar or is blank.
     *
     * @param  string  $key  Property name.
     * @return list<string> The cleaned list, empty when the property is absent or not an array.
     */
    public function stringList(string $key): array
    {
        if (! $this->filled($key) || ! is_array($this->values[$key])) {
            return [];
        }

        $strings = array_map(
            static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '',
            $this->values[$key],
        );

        return array_values(array_filter($strings, static fn (string $item): bool => $item !== ''));
    }

    /**
     * Read a list of non-negative identifiers, dropping anything non-numeric.
     *
     * @param  string  $key  Property name.
     * @return list<int<0, max>> The cleaned list, empty when the property is absent or not an array.
     */
    public function idList(string $key): array
    {
        if (! $this->filled($key) || ! is_array($this->values[$key])) {
            return [];
        }

        $ids = [];

        foreach ($this->values[$key] as $item) {
            if (is_numeric($item)) {
                $ids[] = max(0, (int) $item);
            }
        }

        return $ids;
    }

    /**
     * Read a free-form associative array.
     *
     * @param  string  $key  Property name.
     * @return array<string, mixed> The map, empty when the property is absent or not an array.
     */
    public function map(string $key): array
    {
        if (! $this->filled($key) || ! is_array($this->values[$key])) {
            return [];
        }

        /** @var array<string, mixed> $map */
        $map = $this->values[$key];

        return $map;
    }

    /**
     * Expose the underlying values, for the rare ability that needs them whole.
     *
     * @return array<string, mixed> The normalised input.
     */
    public function all(): array
    {
        return $this->values;
    }
}
