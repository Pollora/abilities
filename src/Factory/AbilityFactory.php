<?php

declare(strict_types=1);

namespace Pollora\Abilities\Factory;

use Pollora\Abilities\Application\Service\RegisterAbilityService;
use Pollora\Abilities\Domain\Contracts\AbilityHandler;
use Pollora\Abilities\Domain\Exception\InvalidAbilityException;
use Pollora\Abilities\Domain\Model\Ability;
use Pollora\Abilities\Domain\Model\AbilityCategory;
use Pollora\Abilities\Domain\Model\Behaviour;

/**
 * Entry point for declaring abilities and the categories they are filed under.
 *
 * This is what a Laravel facade resolves to. Everything it returns is queued on
 * {@see RegisterAbilityService} and published later, on the platform hook that is
 * early enough to matter and late enough to work.
 */
final class AbilityFactory
{
    /**
     * @param  RegisterAbilityService  $service  Service declarations are queued on.
     */
    public function __construct(private readonly RegisterAbilityService $service) {}

    /**
     * Begin declaring an ability.
     *
     * @param  string  $name  Fully-qualified ability name, `namespace/slug`.
     * @return PendingAbility The builder to chain on. Nothing is queued until a body
     *                        is supplied.
     */
    public function define(string $name): PendingAbility
    {
        return new PendingAbility($name, $this->service);
    }

    /**
     * Declare a category abilities can be filed under.
     *
     * Category slugs are global to the install and WordPress core already claims
     * several of them, so prefix the slug with something the project owns.
     *
     * @param  string  $slug  Globally unique slug, lowercase with dashes.
     * @param  string  $label  Human-readable name. Defaults to a title-cased slug.
     * @param  string  $description  What belongs in the category.
     * @return AbilityCategory The queued category.
     *
     * @throws InvalidAbilityException When the slug is empty or malformed.
     */
    public function category(string $slug, string $label = '', string $description = ''): AbilityCategory
    {
        $category = AbilityCategory::make($slug, $label, $description);

        $this->service->queueCategory($category);

        return $category;
    }

    /**
     * Declare a category only if that slug has not been declared yet.
     *
     * Use this where the category is a prerequisite rather than the point — an
     * ability guaranteeing the category it files under exists. An explicit
     * {@see self::category()} always wins, whichever ran first.
     *
     * @param  string  $slug  Globally unique slug, lowercase with dashes.
     * @param  string  $label  Human-readable name. Defaults to a title-cased slug.
     * @param  string  $description  What belongs in the category.
     * @return bool True when the category was queued, false when the slug was already declared.
     *
     * @throws InvalidAbilityException When the slug is empty or malformed.
     */
    public function ensureCategory(string $slug, string $label = '', string $description = ''): bool
    {
        return $this->service->ensureCategory(AbilityCategory::make($slug, $label, $description));
    }

    /**
     * Declare an ability implemented by a handler object, in one call.
     *
     * The long form is {@see self::define()} chained into
     * {@see PendingAbility::handledBy()}; this is the shape the `#[Ability]`
     * attribute discovery uses, where the metadata comes from the attribute and
     * everything else from the handler.
     *
     * @param  string  $name  Fully-qualified ability name, `namespace/slug`.
     * @param  AbilityHandler  $handler  The handler implementing the ability.
     * @param  string  $category  Slug of the category the ability is filed under.
     * @param  string  $label  Short human-readable title. Defaults to a title-cased slug.
     * @param  string  $description  What the ability does.
     * @param  Behaviour  $behaviour  What the ability does to the site.
     * @return Ability The queued ability.
     *
     * @throws InvalidAbilityException When the declaration is incomplete or malformed.
     */
    public function handle(
        string $name,
        AbilityHandler $handler,
        string $category,
        string $label = '',
        string $description = '',
        Behaviour $behaviour = Behaviour::Reads,
    ): Ability {
        return $this->define($name)
            ->label($label)
            ->description($description)
            ->category($category)
            ->behaviour($behaviour)
            ->handledBy($handler);
    }

    /**
     * The service declarations are queued on.
     *
     * Exposed so a host can flush the queues on its own hooks, and so a consumer
     * can ask what ended up registered.
     *
     * @return RegisterAbilityService The registration service.
     */
    public function service(): RegisterAbilityService
    {
        return $this->service;
    }
}
