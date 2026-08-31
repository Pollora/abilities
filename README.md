# Pollora Abilities

A Laravel-flavoured API over the [WordPress Abilities API](https://make.wordpress.org/core/2025/11/10/abilities-api-in-wordpress-6-9/),
introduced in WordPress 6.9.

An *ability* is a unit of functionality a site publishes in a machine-readable
form — inputs, outputs, permissions, behaviour — so that AI agents and automation
tools can discover and invoke it. This package is how you declare one without
writing registration boilerplate, and without scattering `wp_register_ability()`
calls through your codebase.

It knows nothing about MCP. MCP is one *consumer* of abilities, served by the
[MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin; abilities
registered here are published to it, and to the core abilities REST controllers,
without this package taking part.

---

## Requirements

| | |
|---|---|
| PHP | 8.3 or later |
| WordPress | 6.9 or later, for the Abilities API in core |

On an older WordPress the package is inert: declarations are accepted and simply
never published, because there is nowhere to put them.

---

## Install

```bash
composer require pollora/abilities
```

In a [Pollora](https://github.com/Pollora/framework) project the framework
already requires it and wires everything up — see **In a Pollora project** below.
Everything above that section is framework-agnostic.

---

## Declaring an ability

```php
use Pollora\Abilities\Adapter\Out\WordPress\WordPressAbilityCategoryRegistrar;
use Pollora\Abilities\Adapter\Out\WordPress\WordPressAbilityRegistrar;
use Pollora\Abilities\Application\Service\RegisterAbilityService;
use Pollora\Abilities\Domain\Model\Input;
use Pollora\Abilities\Domain\Schema\SchemaBuilder;
use Pollora\Abilities\Factory\AbilityFactory;

$service = new RegisterAbilityService(
    new WordPressAbilityRegistrar,
    new WordPressAbilityCategoryRegistrar,
);

$abilities = new AbilityFactory($service);

$abilities->category('acme-content', 'Editorial', 'Posts and pages.');

$abilities->define('acme/get-posts')
    ->description('Returns the most recent posts, newest first.')
    ->category('acme-content')
    ->input(fn (SchemaBuilder $schema) => $schema
        ->integer('limit', 'How many posts to return.', default: 10, minimum: 1, maximum: 100))
    ->can(fn (Input $input): bool => current_user_can('edit_posts'))
    ->using(fn (Input $input): array => array_map(
        static fn (WP_Post $post): array => ['id' => $post->ID, 'title' => $post->post_title],
        get_posts(['numberposts' => $input->integer('limit', 10)]),
    ));
```

Declarations are queued, not published. Flush them on the two hooks WordPress
accepts them on — categories first, because an ability naming a category that
does not exist yet fails to register:

```php
add_action('wp_abilities_api_categories_init', $service->flushCategories(...));
add_action('wp_abilities_api_init', $service->flushAbilities(...));
```

That two-phase split is not ceremony. Abilities are declared wherever it is
natural to write them, which is almost always before WordPress has initialised
its registry; registering early registers nothing, and registering late misses
the request.

### Handler classes

For anything past a couple of lines, implement `AbilityHandler`. Everything the
ability needs lives in one class with a typed signature, which makes it
straightforward to unit-test without registering anything:

```php
use Pollora\Abilities\Domain\Contracts\AbilityHandler;
use Pollora\Abilities\Domain\Model\Input;
use Pollora\Abilities\Domain\Schema\SchemaBuilder;

final class CreatePost implements AbilityHandler
{
    public function schema(SchemaBuilder $schema): void
    {
        $schema->string('title', 'Title of the post to create.', required: true);
        $schema->enum('status', 'Publication status.', ['draft', 'publish'], default: 'draft');
    }

    public function authorize(Input $input): mixed
    {
        return current_user_can('edit_posts')
            ?: new WP_Error('forbidden', 'You cannot create posts.', ['status' => 403]);
    }

    public function handle(Input $input): mixed
    {
        return ['id' => wp_insert_post([
            'post_title'  => $input->string('title'),
            'post_status' => $input->string('status', 'draft'),
        ])];
    }
}
```

```php
use Pollora\Abilities\Domain\Model\Behaviour;

$abilities->handle(
    name: 'acme/create-post',
    handler: new CreatePost,
    category: 'acme-content',
    description: 'Creates a post from a title and a status.',
    behaviour: Behaviour::Creates,
);
```

---

## Behaviour

Every ability declares what it does to the site. WordPress publishes this under
`meta.annotations`, and consumers turn it into the `readOnlyHint`,
`destructiveHint` and `idempotentHint` tool annotations a client uses to decide
how much ceremony an invocation deserves.

| Declaration | `readonly` | `destructive` | `idempotent` | Means |
|---|---|---|---|---|
| `->reads()` *(default)* | ✓ | | ✓ | Changes nothing. Safe to run unattended. |
| `->creates()` | | | | Adds something on every call. Two calls, two records. |
| `->updates()` | | ✓ | ✓ | Overwrites part of a record. The previous value is gone. |
| `->deletes()` | | ✓ | ✓ | Removes a record. |

Getting these wrong is worse than omitting them, which is why they are declared
as one of four shapes rather than three loose booleans.

**They are advisory.** WordPress does not enforce them. The permission callback
is what protects the site — and it defaults to refusing, so an ability that
forgets to declare one is inert rather than open.

---

## Input

`Input` is a defensive reader over what the ability was handed. WordPress
validates against the declared schema before the body runs, but it does not
guarantee a shape — an ability whose every property is optional can legitimately
be invoked with `null`.

```php
$input->string('title');                              // '' when absent
$input->integer('limit', default: 10, max: 100);      // coerces "12", clamps 100000 → 100
$input->id('post_id');                                // 0 when absent or invalid, never negative
$input->boolean('draft');                             // accepts true, "true", "1", "yes", "on"
$input->stringList('tags');                           // trims, drops blanks and non-scalars
$input->idList('post_ids');
$input->map('terms');                                 // free-form associative array
```

Accessors coerce rather than throw: a model that sends `"12"` where an integer
was asked for should get a working call, not an error it cannot act on.

`has()` and `filled()` are distinct on purpose. `has()` is true for a present
empty value, `filled()` is not — because callers routinely send empty strings for
properties they mean to leave alone, and treating those as present produces empty
search terms and cleared taxonomies. Use `has()` where "explicitly zero" differs
from "not mentioned".

---

## Schema

The input schema is the only documentation a language model gets about your
ability, so descriptions are not decoration — they are the interface. Every
`SchemaBuilder` method takes one, and there is no overload that omits it.

```php
$schema
    ->string('title', 'Title of the post.', required: true)
    ->string('url', 'Source URL.', format: 'uri')
    ->enum('status', 'Publication status.', ['draft', 'publish'], default: 'draft')
    ->integer('limit', 'How many to return.', default: 10, minimum: 1, maximum: 100)
    ->number('score', 'Relevance threshold.', minimum: 0.0, maximum: 1.0)
    ->boolean('sticky', 'Whether to pin the post.')
    ->list('tags', 'Tag slugs to attach.')
    ->map('terms', 'Taxonomy slug to term slugs.', ['type' => 'array'])
    ->object('author', 'The post author.', fn (SchemaBuilder $author) => $author
        ->integer('id', 'User identifier.', required: true))
    ->raw('id', ['oneOf' => [['type' => 'string'], ['type' => 'integer']]]);
```

Prefer `enum()` over a free string wherever the accepted values are known: a
model that can see the options picks one, where a model given a free string
invents a plausible value that fails downstream. Set `maximum` on anything that
sizes a query, so a model cannot ask for every row in the table.

`output()` is available and optional. Declare it where the shape is stable — it
lets a client validate what it got instead of trusting it.

---

## In a Pollora project

The framework requires this package, binds it, and registers the flush hooks. You
get a facade and an attribute; nothing else needs wiring.

```php
use Pollora\Support\Facades\Ability;

Ability::category('acme-content', 'Editorial', 'Posts and pages.');

Ability::define('acme/get-posts')
    ->description('Returns the most recent posts, newest first.')
    ->category('acme-content')
    ->can(fn (Input $input): bool => current_user_can('edit_posts'))
    ->using(fn (Input $input): array => …);
```

Or, discovered automatically from anywhere the discovery engine scans — `app/`,
a theme, a module:

```php
use Pollora\Attributes\Ability;
use Pollora\Abilities\Domain\Contracts\AbilityHandler;
use Pollora\Abilities\Domain\Model\Behaviour;

#[Ability(
    name: 'acme/create-post',
    description: 'Creates a post from a title and a status.',
    category: 'acme-content',
    behaviour: Behaviour::Creates,
)]
final class CreatePost implements AbilityHandler { … }
```

The attribute carries what the ability *is*; the handler carries what it *does*.
The category is declared for you if nobody declared it — a slug with no category
would otherwise make the ability vanish without a word — and an explicit
`Ability::category()` always wins, whichever ran first.

---

## Things worth knowing

**Names are `namespace/slug`.** A bare slug registers nothing, and WordPress
reports it through `_doing_it_wrong()` where it is easy to miss. This package
refuses it at declaration time instead, along with an empty label, an empty
description, and an input schema that is not an object.

**Category slugs are global to the install,** and core already claims several.
Prefix yours with something the project owns. Registering a slug twice raises
`_doing_it_wrong()`, which on a site running with `WP_DEBUG` on lands in the REST
response body and breaks whatever was reading it — so both registrars check
first and both are idempotent.

**Category descriptions cannot be blank.** WordPress rejects such a category by
returning `null` rather than raising, so one would vanish silently; a description
is derived from the label when you leave it out.

**Queueing the same ability name twice keeps the first.** A host that runs its
discovery once per scan location would otherwise build a queue of near-identical
entries, all but one of them dead on arrival.

---

## Architecture

Hexagonal, with no framework dependency. The domain never touches WordPress.

```
src/
├── Domain/
│   ├── Model/          Ability, AbilityCategory, Annotations, Behaviour, Input
│   ├── Schema/         SchemaBuilder
│   ├── Contracts/      AbilityHandler
│   └── Exception/      InvalidAbilityException
├── Application/
│   └── Service/        RegisterAbilityService — the declaration queues
├── Port/Out/           AbilityRegistrarPort, AbilityCategoryRegistrarPort
├── Adapter/Out/
│   └── WordPress/      The only classes that call wp_register_ability*()
└── Factory/            AbilityFactory, PendingAbility — the fluent API
```

Substituting the ports is how the domain stays testable without WordPress
loaded, and how a project could publish the same declarations somewhere else.

---

## Development

```bash
composer test          # pest, phpstan (level 8), pint --test
composer test:unit
composer analyse
composer lint
```

---

## Licence

MIT.
