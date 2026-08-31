<?php

declare(strict_types=1);

namespace Pollora\Abilities\Domain\Exception;

use InvalidArgumentException;

/**
 * Thrown when an ability or a category is described in a way WordPress will refuse.
 *
 * Registration failures in the Abilities API surface as a `_doing_it_wrong()`
 * notice and a silently missing ability, which on a site running with `WP_DEBUG`
 * on lands in the REST response body. Failing loudly at declaration time — while
 * the developer is looking at the code that caused it — is the better trade.
 */
final class InvalidAbilityException extends InvalidArgumentException
{
    /**
     * The ability or category name is not in `namespace/slug` or `slug` form.
     *
     * @param  string  $name  The rejected name.
     * @param  string  $expected  Human-readable description of the accepted form.
     * @return self The exception to throw.
     */
    public static function malformedName(string $name, string $expected): self
    {
        return new self(sprintf(
            'Ability name "%s" is malformed: %s.',
            $name,
            $expected,
        ));
    }

    /**
     * A property required to describe the ability was left empty.
     *
     * @param  string  $name  Ability name the property belongs to.
     * @param  string  $property  Name of the missing property.
     * @return self The exception to throw.
     */
    public static function missingProperty(string $name, string $property): self
    {
        return new self(sprintf(
            'Ability "%s" cannot be registered without a %s.',
            $name,
            $property,
        ));
    }

    /**
     * The input schema is not a JSON Schema object, which the specification requires.
     *
     * @param  string  $name  Ability name the schema belongs to.
     * @param  string  $type  The type that was declared instead.
     * @return self The exception to throw.
     */
    public static function nonObjectSchema(string $name, string $type): self
    {
        return new self(sprintf(
            'Ability "%s" declares an input schema of type "%s"; the top-level schema must be an object.',
            $name,
            $type,
        ));
    }
}
