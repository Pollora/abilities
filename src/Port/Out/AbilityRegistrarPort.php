<?php

declare(strict_types=1);

namespace Pollora\Abilities\Port\Out;

use Pollora\Abilities\Domain\Model\Ability;

/**
 * Outbound port for publishing an ability to the host platform.
 *
 * The WordPress implementation calls `wp_register_ability()`. Substituting a
 * different one is how the domain stays testable without WordPress loaded, and
 * how a project could publish the same declarations somewhere else entirely.
 */
interface AbilityRegistrarPort
{
    /**
     * Publish an ability.
     *
     * Implementations must be idempotent: registering a name that already exists
     * is a no-op returning false, not an error. A double registration in the
     * Abilities API raises `_doing_it_wrong()`, which on a debugging site lands
     * in the REST response body and breaks whatever was reading it.
     *
     * @param  Ability  $ability  The ability to publish.
     * @return bool True when the ability was newly registered, false when it already existed
     *              or the platform refused it.
     */
    public function register(Ability $ability): bool;

    /**
     * Whether an ability of this name is already published.
     *
     * @param  string  $name  Fully-qualified ability name, `namespace/slug`.
     * @return bool True when the name is taken.
     */
    public function exists(string $name): bool;
}
