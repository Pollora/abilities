<?php

declare(strict_types=1);

namespace Pollora\Abilities\Domain\Schema;

/**
 * Fluent builder for the JSON Schema object that describes an ability's input.
 *
 * This schema is the only documentation a language model gets about the ability,
 * so the descriptions are not decoration — they *are* the interface. The builder
 * exists to make writing them terse enough that nobody is tempted to skip them,
 * and to keep the shape consistent across every ability in a project.
 *
 * The Model Context Protocol requires the top-level input schema of a tool to be
 * of type `object`, so that is the only root this builder produces.
 *
 * Every method returns `$this`, so properties chain:
 *
 *     $schema
 *         ->string('title', 'Title of the post to create.', required: true)
 *         ->enum('status', 'Publication status.', ['draft', 'publish'], default: 'draft')
 *         ->integer('parent', 'Identifier of the parent page, or 0 for none.', minimum: 0);
 */
final class SchemaBuilder
{
    /**
     * Declared properties, keyed by property name, in declaration order.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $properties = [];

    /**
     * Names of the properties the caller must supply.
     *
     * @var list<string>
     */
    private array $required = [];

    /**
     * Declare a string property.
     *
     * @param  string  $name  Property name, as the caller will send it.
     * @param  string  $description  What the value means, written for a model that has
     *                               never seen this site.
     * @param  bool  $required  Whether the caller must supply the property.
     * @param  string|null  $default  Value assumed when the property is omitted, or null for none.
     * @param  string|null  $format  JSON Schema `format` annotation such as `uri` or `date-time`,
     *                               or null to leave the string unconstrained.
     * @return $this The builder, for chaining.
     */
    public function string(
        string $name,
        string $description,
        bool $required = false,
        ?string $default = null,
        ?string $format = null,
    ): self {
        $property = ['type' => 'string'];

        if ($format !== null) {
            $property['format'] = $format;
        }

        return $this->add($name, $property, $description, $required, $default);
    }

    /**
     * Declare a string property restricted to a closed set of values.
     *
     * Prefer this over a free string wherever the accepted values are known: a
     * model that can see the options picks one, where a model given a free string
     * invents a plausible-looking value that fails downstream.
     *
     * @param  string  $name  Property name.
     * @param  string  $description  What the value selects.
     * @param  array<array-key, string>  $values  The accepted values. Reindexed on the way in:
     *                                            a set built by filtering another array would
     *                                            otherwise keep its keys and encode to a JSON
     *                                            object where the schema needs an array.
     * @param  bool  $required  Whether the caller must supply the property.
     * @param  string|null  $default  Value assumed when the property is omitted, or null for none.
     * @return $this The builder, for chaining.
     */
    public function enum(
        string $name,
        string $description,
        array $values,
        bool $required = false,
        ?string $default = null,
    ): self {
        return $this->add(
            $name,
            ['type' => 'string', 'enum' => array_values($values)],
            $description,
            $required,
            $default,
        );
    }

    /**
     * Declare an integer property.
     *
     * @param  string  $name  Property name.
     * @param  string  $description  What the value means.
     * @param  bool  $required  Whether the caller must supply the property.
     * @param  int|null  $default  Value assumed when the property is omitted, or null for none.
     * @param  int|null  $minimum  Smallest accepted value, or null for unbounded.
     * @param  int|null  $maximum  Largest accepted value, or null for unbounded. Worth setting on
     *                             anything that sizes a query, so a model cannot ask for every
     *                             row in the table.
     * @return $this The builder, for chaining.
     */
    public function integer(
        string $name,
        string $description,
        bool $required = false,
        ?int $default = null,
        ?int $minimum = null,
        ?int $maximum = null,
    ): self {
        $property = ['type' => 'integer'];

        if ($minimum !== null) {
            $property['minimum'] = $minimum;
        }

        if ($maximum !== null) {
            $property['maximum'] = $maximum;
        }

        return $this->add($name, $property, $description, $required, $default);
    }

    /**
     * Declare a floating-point property.
     *
     * @param  string  $name  Property name.
     * @param  string  $description  What the value means.
     * @param  bool  $required  Whether the caller must supply the property.
     * @param  float|null  $default  Value assumed when the property is omitted, or null for none.
     * @param  float|null  $minimum  Smallest accepted value, or null for unbounded.
     * @param  float|null  $maximum  Largest accepted value, or null for unbounded.
     * @return $this The builder, for chaining.
     */
    public function number(
        string $name,
        string $description,
        bool $required = false,
        ?float $default = null,
        ?float $minimum = null,
        ?float $maximum = null,
    ): self {
        $property = ['type' => 'number'];

        if ($minimum !== null) {
            $property['minimum'] = $minimum;
        }

        if ($maximum !== null) {
            $property['maximum'] = $maximum;
        }

        return $this->add($name, $property, $description, $required, $default);
    }

    /**
     * Declare a boolean property.
     *
     * @param  string  $name  Property name.
     * @param  string  $description  What the flag turns on.
     * @param  bool  $required  Whether the caller must supply the property.
     * @param  bool|null  $default  Value assumed when the property is omitted, or null for none.
     * @return $this The builder, for chaining.
     */
    public function boolean(
        string $name,
        string $description,
        bool $required = false,
        ?bool $default = null,
    ): self {
        return $this->add($name, ['type' => 'boolean'], $description, $required, $default);
    }

    /**
     * Declare an array property whose elements are all of one type.
     *
     * @param  string  $name  Property name.
     * @param  string  $description  What the collection holds.
     * @param  array<string, mixed>  $items  Schema every element must satisfy, for example
     *                                       `['type' => 'string']`.
     * @param  bool  $required  Whether the caller must supply the property.
     * @return $this The builder, for chaining.
     */
    public function list(
        string $name,
        string $description,
        array $items = ['type' => 'string'],
        bool $required = false,
    ): self {
        return $this->add($name, ['type' => 'array', 'items' => $items], $description, $required);
    }

    /**
     * Declare a nested object property, described by a callback on a fresh builder.
     *
     * @param  string  $name  Property name.
     * @param  string  $description  What the object represents.
     * @param  callable(self): void  $definition  Receives a nested builder to declare the
     *                                            object's own properties on.
     * @param  bool  $required  Whether the caller must supply the property.
     * @return $this The builder, for chaining.
     */
    public function object(
        string $name,
        string $description,
        callable $definition,
        bool $required = false,
    ): self {
        $nested = new self;
        $definition($nested);

        return $this->add($name, $nested->toArray(), $description, $required);
    }

    /**
     * Declare a free-form map whose keys are not known ahead of time.
     *
     * Useful for taxonomy filters, meta values and anything else keyed on names
     * the site owns rather than the schema.
     *
     * @param  string  $name  Property name.
     * @param  string  $description  What the keys and values mean.
     * @param  array<string, mixed>  $values  Schema every value must satisfy.
     * @param  bool  $required  Whether the caller must supply the property.
     * @return $this The builder, for chaining.
     */
    public function map(
        string $name,
        string $description,
        array $values = [],
        bool $required = false,
    ): self {
        $property = ['type' => 'object'];

        if ($values !== []) {
            $property['additionalProperties'] = $values;
        }

        return $this->add($name, $property, $description, $required);
    }

    /**
     * Declare a property from a schema fragment this builder cannot express.
     *
     * The escape hatch, for `oneOf`, `$ref` and anything else the typed methods
     * above do not cover. Prefer them where they apply — they keep descriptions
     * mandatory, which is the whole point of the builder.
     *
     * @param  string  $name  Property name.
     * @param  array<string, mixed>  $schema  The complete property schema, used as given.
     * @param  bool  $required  Whether the caller must supply the property.
     * @return $this The builder, for chaining.
     */
    public function raw(string $name, array $schema, bool $required = false): self
    {
        $this->properties[$name] = $schema;

        if ($required && ! in_array($name, $this->required, true)) {
            $this->required[] = $name;
        }

        return $this;
    }

    /**
     * Whether any property has been declared.
     *
     * @return bool True when the builder is still empty.
     */
    public function isEmpty(): bool
    {
        return $this->properties === [];
    }

    /**
     * Render the JSON Schema object.
     *
     * An ability that takes no input renders `additionalProperties: false` rather
     * than an empty `properties`, because an empty PHP array encodes to `[]` and
     * would advertise a JSON array where the specification calls for an object.
     *
     * @return array<string, mixed> The object schema.
     */
    public function toArray(): array
    {
        $schema = ['type' => 'object'];

        if ($this->properties === []) {
            $schema['additionalProperties'] = false;

            return $schema;
        }

        $schema['properties'] = $this->properties;

        if ($this->required !== []) {
            $schema['required'] = $this->required;
        }

        return $schema;
    }

    /**
     * Record a property and, when asked, mark it required.
     *
     * @param  string  $name  Property name.
     * @param  array<string, mixed>  $property  The type-specific part of the schema.
     * @param  string  $description  What the value means.
     * @param  bool  $required  Whether the caller must supply the property.
     * @param  mixed  $default  Value assumed when the property is omitted; null omits the key.
     * @return $this The builder, for chaining.
     */
    private function add(
        string $name,
        array $property,
        string $description,
        bool $required,
        mixed $default = null,
    ): self {
        $property['description'] = $description;

        if ($default !== null) {
            $property['default'] = $default;
        }

        return $this->raw($name, $property, $required);
    }
}
