<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Abilities\Adapter\Out\WordPress\WordPressAbilityCategoryRegistrar;
use Pollora\Abilities\Adapter\Out\WordPress\WordPressAbilityRegistrar;
use Pollora\Abilities\Domain\Model\Ability;
use Pollora\Abilities\Domain\Model\AbilityCategory;
use Pollora\Abilities\Domain\Model\Behaviour;
use Pollora\Abilities\Domain\Model\Input;
use Pollora\Abilities\Port\Out\AbilityCategoryRegistrarPort;
use Pollora\Abilities\Port\Out\AbilityRegistrarPort;

/**
 * An in-memory ability registrar, so the domain can be exercised without WordPress.
 */
final class RecordingAbilityRegistrar implements AbilityRegistrarPort
{
    /**
     * Names registered so far, in order.
     *
     * @var list<string>
     */
    public array $registered = [];

    /**
     * @param  bool  $accepts  Whether the fake platform accepts registrations at all,
     *                         standing in for a WordPress that refuses one.
     */
    public function __construct(private readonly bool $accepts = true) {}

    /**
     * {@inheritDoc}
     */
    public function register(Ability $ability): bool
    {
        if (! $this->accepts || $this->exists($ability->name)) {
            return false;
        }

        $this->registered[] = $ability->name;

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function exists(string $name): bool
    {
        return in_array($name, $this->registered, true);
    }
}

/**
 * An in-memory category registrar.
 */
final class RecordingCategoryRegistrar implements AbilityCategoryRegistrarPort
{
    /**
     * Slugs registered so far, in order.
     *
     * @var list<string>
     */
    public array $registered = [];

    /**
     * {@inheritDoc}
     */
    public function register(AbilityCategory $category): bool
    {
        if ($this->exists($category->slug)) {
            return false;
        }

        $this->registered[] = $category->slug;

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function exists(string $slug): bool
    {
        return in_array($slug, $this->registered, true);
    }
}

/**
 * A valid ability, for tests that care about everything except its body.
 *
 * @param  string  $name  Fully-qualified ability name.
 * @param  Behaviour  $behaviour  What the ability does to the site.
 * @param  array<string, mixed>  $meta  Extra registration metadata.
 * @param  array<string, mixed>|null  $outputSchema  Output schema, or null for none.
 * @return Ability The ability.
 */
function anAbility(
    string $name = 'acme/get-posts',
    Behaviour $behaviour = Behaviour::Reads,
    array $meta = [],
    ?array $outputSchema = null,
): Ability {
    return Ability::create(
        name: $name,
        label: 'List posts',
        description: 'Returns the most recent posts.',
        category: 'acme-content',
        inputSchema: ['type' => 'object', 'additionalProperties' => false],
        execute: static fn (Input $input): array => [],
        permission: static fn (Input $input): bool => true,
        behaviour: $behaviour,
        outputSchema: $outputSchema,
        meta: $meta,
    );
}

/**
 * An ability whose body echoes back a property, to observe input wrapping.
 *
 * @return Ability The ability.
 */
function anAbilityReturningInput(): Ability
{
    return Ability::create(
        name: 'acme/echo',
        label: 'Echo',
        description: 'Returns the title it was given.',
        category: 'acme-content',
        inputSchema: ['type' => 'object'],
        execute: static fn (Input $input): string => $input->string('title'),
        permission: static fn (Input $input): bool => true,
    );
}

/**
 * An ability whose permission check reads the input, to observe per-object checks.
 *
 * @return Ability The ability.
 */
function anAbilityCheckingInput(): Ability
{
    return Ability::create(
        name: 'acme/guarded',
        label: 'Guarded',
        description: 'Allowed only when an identifier is supplied.',
        category: 'acme-content',
        inputSchema: ['type' => 'object'],
        execute: static fn (Input $input): array => [],
        permission: static fn (Input $input): bool => $input->id('id') > 0,
    );
}

/**
 * Register an ability against a stubbed Abilities API and return what it was handed.
 *
 * Every adapter assertion needs the same stub, so it lives here rather than
 * being rebuilt in each test.
 *
 * @param  Ability  $ability  The ability to register.
 * @return array{registered: bool, name: string, arguments: array<string, mixed>} What
 *                                                                                `wp_register_ability()` received, and whether the adapter reported success.
 */
function captureAbilityRegistration(Ability $ability): array
{
    $name = '';
    $arguments = [];

    Functions\when('wp_register_ability')->alias(
        function (string $registeredName, array $registeredArguments) use (&$name, &$arguments): object {
            $name = $registeredName;
            $arguments = $registeredArguments;

            return new stdClass;
        }
    );

    $registered = (new WordPressAbilityRegistrar)->register($ability);

    return ['registered' => $registered, 'name' => $name, 'arguments' => $arguments];
}

/**
 * Register a category against a stubbed Abilities API and return what it was handed.
 *
 * @param  AbilityCategory  $category  The category to register.
 * @return array{registered: bool, slug: string, arguments: array<string, mixed>} What
 *                                                                                `wp_register_ability_category()` received, and whether the adapter reported success.
 */
function captureCategoryRegistration(AbilityCategory $category): array
{
    $slug = '';
    $arguments = [];

    Functions\when('wp_register_ability_category')->alias(
        function (string $registeredSlug, array $registeredArguments) use (&$slug, &$arguments): object {
            $slug = $registeredSlug;
            $arguments = $registeredArguments;

            return new stdClass;
        }
    );

    $registered = (new WordPressAbilityCategoryRegistrar)->register($category);

    return ['registered' => $registered, 'slug' => $slug, 'arguments' => $arguments];
}
