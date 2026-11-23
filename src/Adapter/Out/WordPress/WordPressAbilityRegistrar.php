<?php

declare(strict_types=1);

namespace Pollora\Abilities\Adapter\Out\WordPress;

use Pollora\Abilities\Domain\Model\Ability;
use Pollora\Abilities\Domain\Model\Behaviour;
use Pollora\Abilities\Domain\Model\Input;
use Pollora\Abilities\Port\Out\AbilityRegistrarPort;

/**
 * Publishes abilities through the WordPress Abilities API.
 *
 * The only class in this package that calls `wp_register_ability()`, which is
 * what keeps the domain free of registration boilerplate and lets the same
 * declarations be tested, or published elsewhere, without WordPress loaded.
 *
 * Available since WordPress 6.9. On an older install the functions are absent and
 * every call here is a no-op returning false — the abilities simply do not
 * appear, which is the correct outcome for a platform that has nowhere to put
 * them.
 */
final class WordPressAbilityRegistrar implements AbilityRegistrarPort
{
    /**
     * {@inheritDoc}
     */
    public function register(Ability $ability): bool
    {
        if (! function_exists('wp_register_ability')) {
            return false;
        }

        // A second registration of the same name raises _doing_it_wrong(), which
        // on a site running with WP_DEBUG on lands in the REST response body and
        // breaks whatever was reading it.
        if ($this->exists($ability->name)) {
            return false;
        }

        $execute = $ability->execute;
        $permission = $ability->permission;

        $arguments = [
            'label' => $ability->label,
            'description' => $ability->description,
            'category' => $ability->category,
            'input_schema' => $this->inputSchema($ability),
            // The Abilities API hands the raw, schema-validated input straight
            // through. Wrapping it here means no ability body has to defend
            // against a null or a numeric string on its own.
            'execute_callback' => static fn (mixed $input = null): mixed => $execute(Input::wrap($input)),
            // The permission callback receives the same input as the execute
            // callback, which is what allows per-object checks — `edit_post` on
            // the identifier being edited, rather than a blanket `edit_posts`.
            'permission_callback' => static fn (mixed $input = null): mixed => $permission(Input::wrap($input)),
            'meta' => $this->meta($ability),
        ];

        if ($ability->outputSchema !== null) {
            $arguments['output_schema'] = $ability->outputSchema;
        }

        return \wp_register_ability($ability->name, $arguments) !== null; // @phpstan-ignore argument.type (name validated by Ability::create())
    }

    /**
     * {@inheritDoc}
     */
    public function exists(string $name): bool
    {
        return function_exists('wp_has_ability') && \wp_has_ability($name);
    }

    /**
     * The input schema as it is published to WordPress.
     *
     * A call that carries no input reaches WordPress as `null` (a REST call without
     * an `input` parameter, or `execute()` without an argument), and WordPress
     * validates that `null` against the schema: an object schema refuses it with
     * "input is not of type object". So an ability that declares no property, or
     * only optional ones, could never be run without passing an empty object.
     * Declaring `default: []` — what the core abilities do — makes WordPress
     * substitute an empty input before validating it. It is added only when no
     * property is required: a required property keeps being reported as missing.
     * A default the schema already carries is left alone.
     *
     * @param  Ability  $ability  The ability being registered.
     * @return array<string, mixed> The schema to publish.
     */
    private function inputSchema(Ability $ability): array
    {
        $schema = $ability->inputSchema;

        if (! array_key_exists('default', $schema) && ($schema['required'] ?? []) === []) {
            $schema['default'] = [];
        }

        return $schema;
    }

    /**
     * Build the registration `meta` array.
     *
     * Caller-supplied metadata comes first so the keys this package owns cannot
     * be overwritten by accident — an ability that declared its own `annotations`
     * would otherwise publish behaviour hints contradicting its own
     * {@see Behaviour}.
     *
     * `show_in_rest` exposes the ability through the core abilities REST
     * controllers, which is how one is tested without an MCP client.
     *
     * @param  Ability  $ability  The ability being registered.
     * @return array<string, mixed> The metadata to publish.
     */
    private function meta(Ability $ability): array
    {
        return [
            'show_in_rest' => true,
            ...$ability->meta,
            'annotations' => $ability->annotations->toArray(),
        ];
    }
}
