<?php

declare(strict_types=1);

namespace Pollora\Abilities\Application\Service;

use Pollora\Abilities\Domain\Model\Ability;
use Pollora\Abilities\Domain\Model\AbilityCategory;
use Pollora\Abilities\Port\Out\AbilityCategoryRegistrarPort;
use Pollora\Abilities\Port\Out\AbilityRegistrarPort;

/**
 * Collects declared abilities and publishes them when the platform is ready.
 *
 * Declaration and registration are deliberately two phases. Abilities are
 * declared wherever it is natural to write them — a service provider, a
 * discovered handler class, a theme's bootstrap — which is almost always before
 * WordPress has initialised its abilities registry. Publishing early registers
 * nothing; publishing late misses the request. So declarations queue here, and
 * the host flushes them on the right hook.
 *
 * Categories flush separately because WordPress initialises them on an earlier
 * hook, and an ability naming a category that does not exist yet fails to
 * register.
 */
final class RegisterAbilityService
{
    /**
     * Abilities declared but not yet published, keyed by name to preserve
     * declaration order while collapsing repeats.
     *
     * @var array<string, Ability>
     */
    private array $pendingAbilities = [];

    /**
     * Categories declared but not yet published, keyed by slug so a slug declared
     * twice does not attempt two registrations.
     *
     * @var array<string, AbilityCategory>
     */
    private array $pendingCategories = [];

    /**
     * Names of the abilities published during this request.
     *
     * @var list<string>
     */
    private array $registeredAbilities = [];

    /**
     * Slugs of the categories published during this request.
     *
     * @var list<string>
     */
    private array $registeredCategories = [];

    /**
     * @param  AbilityRegistrarPort  $abilityRegistrar  Publishes abilities to the host platform.
     * @param  AbilityCategoryRegistrarPort  $categoryRegistrar  Publishes categories to the host platform.
     */
    public function __construct(
        private readonly AbilityRegistrarPort $abilityRegistrar,
        private readonly AbilityCategoryRegistrarPort $categoryRegistrar,
    ) {}

    /**
     * Queue an ability for publication.
     *
     * Queueing a name twice keeps the first declaration and discards the rest.
     * The platform refuses a duplicate registration anyway, so the first is what
     * would have won; collapsing here means a host that runs its discovery more
     * than once — several scan locations, a re-applied cache — does not build a
     * queue of near-identical entries that all but one of which is dead.
     *
     * @param  Ability  $ability  The ability to publish on the next flush.
     * @return bool True when the ability was queued, false when the name was already declared.
     */
    public function queue(Ability $ability): bool
    {
        if (isset($this->pendingAbilities[$ability->name])) {
            return false;
        }

        $this->pendingAbilities[$ability->name] = $ability;

        return true;
    }

    /**
     * Queue a category for publication.
     *
     * Declaring the same slug twice keeps the last description, which is what
     * makes it safe for several unrelated abilities to declare the category they
     * need without coordinating.
     *
     * @param  AbilityCategory  $category  The category to publish on the next flush.
     */
    public function queueCategory(AbilityCategory $category): void
    {
        $this->pendingCategories[$category->slug] = $category;
    }

    /**
     * Queue a category only if that slug has not been declared yet.
     *
     * This is the fallback an ability uses to guarantee its own category exists.
     * An ability naming a category nobody declared fails to register, silently —
     * no error, no log entry, the tool simply never appears — so filling the gap
     * beats leaving it.
     *
     * An explicit {@see self::queueCategory()} always wins, whichever ran first:
     * called before, this method leaves it alone; called after, it overwrites the
     * fallback. That way a derived label never displaces a chosen one.
     *
     * @param  AbilityCategory  $category  The fallback category.
     * @return bool True when the category was queued, false when the slug was already declared.
     */
    public function ensureCategory(AbilityCategory $category): bool
    {
        if (isset($this->pendingCategories[$category->slug])) {
            return false;
        }

        // Already published this request — by us, or by another plugin whose
        // category we are deliberately reusing.
        if (in_array($category->slug, $this->registeredCategories, true)) {
            return false;
        }

        if ($this->categoryRegistrar->exists($category->slug)) {
            return false;
        }

        $this->pendingCategories[$category->slug] = $category;

        return true;
    }

    /**
     * Publish every queued category and empty the queue.
     *
     * Call this on the platform's category initialisation hook, before
     * {@see self::flushAbilities()}.
     *
     * @return list<string> Slugs of the categories newly registered by this call.
     */
    public function flushCategories(): array
    {
        $categories = $this->pendingCategories;
        $this->pendingCategories = [];

        $registered = [];

        foreach ($categories as $category) {
            if ($this->categoryRegistrar->register($category)) {
                $registered[] = $category->slug;
                $this->registeredCategories[] = $category->slug;
            }
        }

        return $registered;
    }

    /**
     * Publish every queued ability and empty the queue.
     *
     * Call this on the platform's ability initialisation hook.
     *
     * @return list<string> Names of the abilities newly registered by this call.
     */
    public function flushAbilities(): array
    {
        $abilities = $this->pendingAbilities;
        $this->pendingAbilities = [];

        $registered = [];

        foreach ($abilities as $ability) {
            if ($this->abilityRegistrar->register($ability)) {
                $registered[] = $ability->name;
                $this->registeredAbilities[] = $ability->name;
            }
        }

        return $registered;
    }

    /**
     * Abilities declared but not yet published.
     *
     * @return list<Ability> The pending queue, in declaration order.
     */
    public function pending(): array
    {
        return array_values($this->pendingAbilities);
    }

    /**
     * Categories declared but not yet published.
     *
     * @return list<AbilityCategory> The pending categories.
     */
    public function pendingCategories(): array
    {
        return array_values($this->pendingCategories);
    }

    /**
     * Abilities published during this request.
     *
     * Useful to a consumer that has to hand an exact list downstream — an MCP
     * server declaration, a settings screen reporting what is live — without
     * rediscovering it.
     *
     * @return list<string> Fully-qualified ability names.
     */
    public function registered(): array
    {
        return $this->registeredAbilities;
    }

    /**
     * Categories published during this request.
     *
     * @return list<string> Category slugs.
     */
    public function registeredCategories(): array
    {
        return $this->registeredCategories;
    }
}
