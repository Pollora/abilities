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
            'input_schema' => $ability->inputSchema,
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

        return \wp_register_ability($ability->name, $arguments) !== null;
    }

    /**
     * {@inheritDoc}
     */
    public function exists(string $name): bool
    {
        return function_exists('wp_has_ability') && \wp_has_ability($name);
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
