<?php

declare(strict_types=1);

namespace Pollora\Abilities\Domain\Model;

use Closure;
use Pollora\Abilities\Domain\Exception\InvalidAbilityException;

/**
 * A single ability, described independently of how it will be registered.
 *
 * This is the domain's central value object. Nothing on it knows about
 * WordPress: the adapter decides what `wp_register_ability()` is handed, which
 * is what lets the same declaration be registered under a different namespace,
 * inspected in a test, or published over something other than the Abilities API.
 *
 * Build one through the fluent factory rather than this constructor — validation
 * lives in {@see self::create()} and running it at declaration time is the point.
 *
 * Instances are immutable.
 */
final readonly class Ability
{
    /**
     * @param  string  $name  Fully-qualified ability name, `namespace/slug`.
     * @param  string  $label  Short human-readable title, surfaced to clients.
     * @param  string  $description  What the ability does, written for a model deciding
     *                               whether to call it. This is the tool description.
     * @param  string  $category  Slug of the category the ability is filed under.
     * @param  array<string, mixed>  $inputSchema  JSON Schema of the accepted input. Always an
     *                                             object schema.
     * @param  array<string, mixed>|null  $outputSchema  JSON Schema of the returned value, or null
     *                                                   to leave the output undescribed.
     * @param  Closure(Input): mixed  $execute  Ability body. Receives wrapped input and returns any
     *                                          JSON-serialisable value.
     * @param  Closure(Input): mixed  $permission  Permission check run before `$execute`, on the same
     *                                             wrapped input. Returns a boolean, or a falsy value
     *                                             carrying an explanation such as a `WP_Error`.
     * @param  Annotations  $annotations  Behaviour hints published to clients.
     * @param  array<string, mixed>  $meta  Extra metadata merged into the registration `meta` array,
     *                                      for consumers this package does not know about.
     */
    private function __construct(
        public string $name,
        public string $label,
        public string $description,
        public string $category,
        public array $inputSchema,
        public ?array $outputSchema,
        public Closure $execute,
        public Closure $permission,
        public Annotations $annotations,
        public array $meta,
    ) {}

    /**
     * Describe an ability, validating everything WordPress would otherwise refuse silently.
     *
     * @param  string  $name  Fully-qualified ability name, `namespace/slug`.
     * @param  string  $label  Short human-readable title.
     * @param  string  $description  What the ability does.
     * @param  string  $category  Slug of the category the ability is filed under.
     * @param  array<string, mixed>  $inputSchema  JSON Schema of the accepted input.
     * @param  Closure(Input): mixed  $execute  Ability body.
     * @param  Closure(Input): mixed  $permission  Permission check.
     * @param  Behaviour  $behaviour  What the ability does to the site.
     * @param  array<string, mixed>|null  $outputSchema  JSON Schema of the returned value, or null.
     * @param  array<string, mixed>  $meta  Extra registration metadata.
     * @return self The validated ability.
     *
     * @throws InvalidAbilityException When the name is malformed, a required property is
     *                                 empty, or the input schema is not an object schema.
     */
    public static function create(
        string $name,
        string $label,
        string $description,
        string $category,
        array $inputSchema,
        Closure $execute,
        Closure $permission,
        Behaviour $behaviour = Behaviour::Reads,
        ?array $outputSchema = null,
        array $meta = [],
    ): self {
        $name = trim($name);

        // WordPress requires `namespace/slug`; a bare slug registers nothing and
        // reports it through _doing_it_wrong(), which is easy to miss.
        if (preg_match('#^[a-z0-9]+(?:-[a-z0-9]+)*/[a-z0-9]+(?:-[a-z0-9]+)*$#', $name) !== 1) {
            throw InvalidAbilityException::malformedName(
                $name,
                'expected "namespace/slug", lowercase alphanumerics separated by single dashes',
            );
        }

        if (trim($label) === '') {
            throw InvalidAbilityException::missingProperty($name, 'label');
        }

        // The description is what a model reads to decide whether to call the
        // ability. An unlabelled tool is a tool that never gets used correctly.
        if (trim($description) === '') {
            throw InvalidAbilityException::missingProperty($name, 'description');
        }

        if (trim($category) === '') {
            throw InvalidAbilityException::missingProperty($name, 'category');
        }

        $declaredType = $inputSchema['type'] ?? null;

        if ($declaredType !== 'object') {
            throw InvalidAbilityException::nonObjectSchema(
                $name,
                is_string($declaredType) ? $declaredType : 'undeclared',
            );
        }

        return new self(
            $name,
            $label,
            $description,
            $category,
            $inputSchema,
            $outputSchema,
            $execute,
            $permission,
            $behaviour->annotations(),
            $meta,
        );
    }

    /**
     * The namespace part of the ability name.
     *
     * @return string Everything before the slash.
     */
    public function namespace(): string
    {
        return substr($this->name, 0, (int) strpos($this->name, '/'));
    }

    /**
     * The slug part of the ability name.
     *
     * @return string Everything after the slash.
     */
    public function slug(): string
    {
        return substr($this->name, (int) strpos($this->name, '/') + 1);
    }

    /**
     * Whether the ability leaves the site unchanged.
     *
     * @return bool True when the ability only reads.
     */
    public function isReadOnly(): bool
    {
        return $this->annotations->readonly;
    }
}
