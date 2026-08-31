<?php

declare(strict_types=1);

namespace Pollora\Abilities\Adapter\Out\WordPress;

use Pollora\Abilities\Domain\Model\AbilityCategory;
use Pollora\Abilities\Port\Out\AbilityCategoryRegistrarPort;

/**
 * Publishes ability categories through the WordPress Abilities API.
 *
 * Separate from {@see WordPressAbilityRegistrar} because WordPress initialises
 * categories on an earlier hook, and an ability naming a category that does not
 * exist yet fails to register.
 *
 * Available since WordPress 6.9. On an older install every call here is a no-op
 * returning false.
 */
final class WordPressAbilityCategoryRegistrar implements AbilityCategoryRegistrarPort
{
    /**
     * {@inheritDoc}
     */
    public function register(AbilityCategory $category): bool
    {
        if (! function_exists('wp_register_ability_category')) {
            return false;
        }

        // Registering a slug twice raises _doing_it_wrong(); see the note in
        // WordPressAbilityRegistrar::register() for why that matters here.
        if ($this->exists($category->slug)) {
            return false;
        }

        return \wp_register_ability_category($category->slug, $category->toArray()) !== null;
    }

    /**
     * {@inheritDoc}
     */
    public function exists(string $slug): bool
    {
        return function_exists('wp_has_ability_category') && \wp_has_ability_category($slug);
    }
}
