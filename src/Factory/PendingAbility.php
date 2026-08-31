<?php

declare(strict_types=1);

namespace Pollora\Abilities\Factory;

use Closure;
use Pollora\Abilities\Application\Service\RegisterAbilityService;
use Pollora\Abilities\Domain\Contracts\AbilityHandler;
use Pollora\Abilities\Domain\Exception\InvalidAbilityException;
use Pollora\Abilities\Domain\Model\Ability;
use Pollora\Abilities\Domain\Model\Behaviour;
use Pollora\Abilities\Domain\Model\Input;
use Pollora\Abilities\Domain\Schema\SchemaBuilder;

/**
 * An ability under construction, configured by chaining and queued on completion.
 *
 * Returned by {@see AbilityFactory::define()}. Nothing is queued until a body is
 * supplied — through {@see self::using()} or {@see self::handledBy()} — so an
 * incomplete chain registers nothing rather than registering something broken.
 *
 *     Ability::define('acme/get-posts')
 *         ->label('List posts')
 *         ->description('Returns the most recent posts, newest first.')
 *         ->category('acme-content')
 *         ->input(fn (SchemaBuilder $schema) => $schema
 *             ->integer('limit', 'How many posts to return.', default: 10, minimum: 1, maximum: 100))
 *         ->can(fn (Input $input): bool => current_user_can('edit_posts'))
 *         ->reads()
 *         ->using(fn (Input $input): array => get_posts(['numberposts' => $input->integer('limit', 10)]));
 */
final class PendingAbility
{
    /**
     * Short human-readable title. Defaults to a title-cased slug.
     */
    private string $label = '';

    /**
     * What the ability does, written for a model deciding whether to call it.
     */
    private string $description = '';

    /**
     * Slug of the category the ability is filed under.
     */
    private string $category = '';

    /**
     * Builder holding the declared input properties.
     */
    private SchemaBuilder $schema;

    /**
     * JSON Schema of the returned value, or null to leave the output undescribed.
     *
     * @var array<string, mixed>|null
     */
    private ?array $outputSchema = null;

    /**
     * Permission check. Defaults to refusing, so an ability that forgets to
     * declare one is inert rather than open.
     *
     * @var Closure(Input): mixed
     */
    private Closure $permission;

    /**
     * What the ability does to the site.
     */
    private Behaviour $behaviour = Behaviour::Reads;

    /**
     * Extra metadata merged into the registration `meta` array.
     *
     * @var array<string, mixed>
     */
    private array $meta = [];

    /**
     * @param  string  $name  Fully-qualified ability name, `namespace/slug`.
     * @param  RegisterAbilityService  $service  Service the finished ability is queued on.
     */
    public function __construct(
        private readonly string $name,
        private readonly RegisterAbilityService $service,
    ) {
        $this->schema = new SchemaBuilder;
        $this->permission = static fn (Input $input): bool => false;
    }

    /**
     * Set the short human-readable title surfaced to clients.
     *
     * @param  string  $label  The title.
     * @return $this The builder, for chaining.
     */
    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Set what the ability does.
     *
     * This is the tool description a language model reads to decide whether to
     * call the ability, so write it for a reader who has never seen this site.
     *
     * @param  string  $description  The description.
     * @return $this The builder, for chaining.
     */
    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * File the ability under a category.
     *
     * The category must be registered separately, through
     * {@see AbilityFactory::category()}. Naming one that does not exist makes the
     * ability fail to register.
     *
     * @param  string  $slug  The category slug.
     * @return $this The builder, for chaining.
     */
    public function category(string $slug): self
    {
        $this->category = $slug;

        return $this;
    }

    /**
     * Declare the input the ability accepts.
     *
     * @param  callable(SchemaBuilder): void  $definition  Receives the builder to declare
     *                                                     properties on.
     * @return $this The builder, for chaining.
     */
    public function input(callable $definition): self
    {
        $definition($this->schema);

        return $this;
    }

    /**
     * Describe the value the ability returns.
     *
     * Optional. Worth declaring where the shape is stable, because it lets a
     * client validate what it got instead of trusting it.
     *
     * @param  callable(SchemaBuilder): void  $definition  Receives a builder to declare the
     *                                                     output's properties on.
     * @return $this The builder, for chaining.
     */
    public function output(callable $definition): self
    {
        $builder = new SchemaBuilder;
        $definition($builder);

        $this->outputSchema = $builder->toArray();

        return $this;
    }

    /**
     * Set the permission check.
     *
     * @param  Closure(Input): mixed  $permission  Returns true to allow, false to refuse, or a
     *                                             `WP_Error` carrying the reason for the refusal.
     * @return $this The builder, for chaining.
     */
    public function can(Closure $permission): self
    {
        $this->permission = $permission;

        return $this;
    }

    /**
     * Mark the ability as reading only. This is the default.
     *
     * @return $this The builder, for chaining.
     */
    public function reads(): self
    {
        return $this->behaviour(Behaviour::Reads);
    }

    /**
     * Mark the ability as adding something new on each call.
     *
     * @return $this The builder, for chaining.
     */
    public function creates(): self
    {
        return $this->behaviour(Behaviour::Creates);
    }

    /**
     * Mark the ability as overwriting part of an existing record.
     *
     * @return $this The builder, for chaining.
     */
    public function updates(): self
    {
        return $this->behaviour(Behaviour::Updates);
    }

    /**
     * Mark the ability as removing a record.
     *
     * @return $this The builder, for chaining.
     */
    public function deletes(): self
    {
        return $this->behaviour(Behaviour::Deletes);
    }

    /**
     * Set what the ability does to the site.
     *
     * @param  Behaviour  $behaviour  The behaviour.
     * @return $this The builder, for chaining.
     */
    public function behaviour(Behaviour $behaviour): self
    {
        $this->behaviour = $behaviour;

        return $this;
    }

    /**
     * Merge extra metadata into the registration `meta` array.
     *
     * The escape hatch for consumers this package does not know about. Keys
     * this package sets itself — `annotations` — are overwritten by it.
     *
     * @param  array<string, mixed>  $meta  Metadata to merge.
     * @return $this The builder, for chaining.
     */
    public function meta(array $meta): self
    {
        $this->meta = [...$this->meta, ...$meta];

        return $this;
    }

    /**
     * Supply the ability body and queue the finished ability.
     *
     * @param  Closure(Input): mixed  $execute  Receives the wrapped input, returns any
     *                                          JSON-serialisable value or a `WP_Error`.
     * @return Ability The queued ability.
     *
     * @throws InvalidAbilityException When the declaration is incomplete or malformed.
     */
    public function using(Closure $execute): Ability
    {
        $ability = Ability::create(
            name: $this->name,
            label: $this->label !== '' ? $this->label : $this->defaultLabel(),
            description: $this->description,
            category: $this->category,
            inputSchema: $this->schema->toArray(),
            execute: $execute,
            permission: $this->permission,
            behaviour: $this->behaviour,
            outputSchema: $this->outputSchema,
            meta: $this->meta,
        );

        $this->service->queue($ability);

        return $ability;
    }

    /**
     * Take the schema, permission check and body from a handler object.
     *
     * Anything already set by chaining is kept; only the three pieces the handler
     * owns are taken from it. This is what the `#[Ability]` attribute discovery
     * uses, and it is equally usable by hand.
     *
     * @param  AbilityHandler  $handler  The handler implementing the ability.
     * @return Ability The queued ability.
     *
     * @throws InvalidAbilityException When the declaration is incomplete or malformed.
     */
    public function handledBy(AbilityHandler $handler): Ability
    {
        $handler->schema($this->schema);

        return $this
            ->can(static fn (Input $input): mixed => $handler->authorize($input))
            ->using(static fn (Input $input): mixed => $handler->handle($input));
    }

    /**
     * Title-cased slug, used when no label was set.
     *
     * @return string The derived label.
     */
    private function defaultLabel(): string
    {
        $slug = substr($this->name, (int) strpos($this->name, '/') + 1);

        return ucfirst(str_replace('-', ' ', $slug));
    }
}
