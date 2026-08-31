<?php

declare(strict_types=1);

namespace Pollora\Abilities\Port\Out;

use Pollora\Abilities\Domain\Model\AbilityCategory;

/**
 * Outbound port for publishing an ability category to the host platform.
 *
 * The WordPress implementation calls `wp_register_ability_category()`. Categories
 * are registered on an earlier hook than abilities, which is why they get a port
 * of their own rather than riding along with {@see AbilityRegistrarPort}.
 */
interface AbilityCategoryRegistrarPort
{
    /**
     * Publish a category.
     *
     * Implementations must be idempotent, for the same reason as
     * {@see AbilityRegistrarPort::register()}.
     *
     * @param  AbilityCategory  $category  The category to publish.
     * @return bool True when the category was newly registered, false when it already existed
     *              or the platform refused it.
     */
    public function register(AbilityCategory $category): bool;

    /**
     * Whether a category of this slug is already published.
     *
     * @param  string  $slug  The category slug.
     * @return bool True when the slug is taken.
     */
    public function exists(string $slug): bool;
}
