<?php

declare(strict_types=1);

use Pollora\Abilities\Adapter\Out\WordPress\WordPressAbilityCategoryRegistrar;
use Pollora\Abilities\Adapter\Out\WordPress\WordPressAbilityRegistrar;
use Pollora\Abilities\Application\Service\RegisterAbilityService;
use Pollora\Abilities\Domain\Model\Ability;
use Pollora\Abilities\Domain\Model\AbilityCategory;
use Pollora\Abilities\Domain\Model\Input;
use Pollora\Abilities\Domain\Schema\SchemaBuilder;
use Pollora\Abilities\Factory\AbilityFactory;

/**
 * The package against the Abilities API of a real WordPress (6.9 and later): what `wp_register_ability()` accepts,
 * what WordPress does with the input, the permission and the annotations, and what its REST controllers answer.
 *
 * The unit suite stubs the functions, so it cannot tell that WordPress refuses an argument or a registration made
 * outside its hooks.
 */
beforeEach(function (): void {
    $GLOBALS['wpint'] = [
        // WP_Hook objects are changed in place: the snapshot needs copies of them.
        'hooks' => array_map(static fn (WP_Hook $hook): WP_Hook => clone $hook, $GLOBALS['wp_filter']),
        'server' => $GLOBALS['wp_rest_server'] ?? null,
        'abilities' => [],
        'categories' => [],
        'users' => [],
        'notices' => [],
    ];

    // The cheapest bcrypt cost: a test user does not need a strong hash, and the default one takes a second.
    add_filter('wp_hash_password_options', static fn (array $options): array => ['cost' => 4] + $options);
    // WP_DEBUG turns a _doing_it_wrong() into a warning: the tests read them instead.
    add_filter('doing_it_wrong_trigger_error', '__return_false');
    add_action('doing_it_wrong_run', static function (string $function, string $message): void {
        $GLOBALS['wpint']['notices'][] = $function.': '.$message;
    }, 10, 2);
});

afterEach(function (): void {
    require_once ABSPATH.'wp-admin/includes/user.php';

    wp_set_current_user(0);

    foreach ($GLOBALS['wpint']['abilities'] as $name) {
        if (wp_has_ability($name)) {
            wp_unregister_ability($name);
        }
    }

    foreach ($GLOBALS['wpint']['categories'] as $slug) {
        if (wp_has_ability_category($slug)) {
            wp_unregister_ability_category($slug);
        }
    }

    foreach ($GLOBALS['wpint']['users'] as $id) {
        wp_delete_user($id);
    }

    $GLOBALS['wp_filter'] = array_map(static fn (WP_Hook $hook): WP_Hook => clone $hook, $GLOBALS['wpint']['hooks']);
    $GLOBALS['wp_rest_server'] = $GLOBALS['wpint']['server'];
});

/**
 * A factory over a fresh service wired to the WordPress registrars, which publishes on WordPress's own hooks.
 */
function wpintAbilities(): AbilityFactory
{
    $service = new RegisterAbilityService(new WordPressAbilityRegistrar, new WordPressAbilityCategoryRegistrar);

    // Firing the hooks again would run WordPress's own registrations a second time: only this suite listens.
    remove_all_actions('wp_abilities_api_categories_init');
    remove_all_actions('wp_abilities_api_init');
    add_action('wp_abilities_api_categories_init', static function () use ($service): void {
        $service->flushCategories();
    });
    add_action('wp_abilities_api_init', static function () use ($service): void {
        $service->flushAbilities();
    });

    return new AbilityFactory($service);
}

/**
 * Makes WordPress ask for the categories, then the abilities, as it does when it builds its registries.
 * Names are remembered to be unregistered after the test.
 *
 * @param  list<string>  $abilities
 * @param  list<string>  $categories
 */
function wpintPublish(array $abilities, array $categories = ['wpint-content']): void
{
    $GLOBALS['wpint']['abilities'] = [...$GLOBALS['wpint']['abilities'], ...$abilities];
    $GLOBALS['wpint']['categories'] = [...$GLOBALS['wpint']['categories'], ...$categories];

    do_action('wp_abilities_api_categories_init');
    do_action('wp_abilities_api_init');
}

function wpintUser(string $role, string $login): int
{
    $id = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login.'@example.com', 'role' => $role]);

    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }

    $GLOBALS['wpint']['users'][] = $id;

    return $id;
}

/**
 * The ability WordPress holds under a name, which the test expects to be registered.
 */
function wpintAbility(string $name): WP_Ability
{
    $ability = wp_get_ability($name);

    if (! $ability instanceof WP_Ability) {
        throw new RuntimeException(sprintf('WordPress holds no ability named %s.', $name));
    }

    return $ability;
}

it('publishes a category and an ability that WordPress then knows, with the schema and annotations declared', function (): void {
    $factory = wpintAbilities();
    $factory->category('wpint-content', 'Integration content', 'Abilities of the integration suite.');
    $factory->define('wpint/echo')
        ->label('Echo')
        ->description('Returns the title it was given.')
        ->category('wpint-content')
        ->input(static function (SchemaBuilder $schema): void {
            $schema->string('title', 'Title to return.', required: true);
        })
        ->output(static function (SchemaBuilder $schema): void {
            $schema->string('title', 'The same title.');
        })
        ->can(static fn (): bool => true)
        ->reads()
        ->using(static fn (Input $input): array => ['title' => $input->string('title')]);

    wpintPublish(['wpint/echo']);

    $ability = wpintAbility('wpint/echo');

    expect(wp_has_ability_category('wpint-content'))->toBeTrue()
        ->and($ability)->toBeInstanceOf(WP_Ability::class)
        ->and($ability->get_label())->toBe('Echo')
        ->and($ability->get_description())->toBe('Returns the title it was given.')
        ->and($ability->get_category())->toBe('wpint-content')
        ->and($ability->get_input_schema()['required'])->toBe(['title'])
        ->and($ability->get_input_schema()['properties']['title']['type'])->toBe('string')
        ->and($ability->get_output_schema())->not->toBe([])
        ->and($ability->get_meta_item('annotations'))->toBe(['readonly' => true, 'destructive' => false, 'idempotent' => true])
        ->and($ability->get_meta_item('show_in_rest'))->toBeTrue()
        ->and($GLOBALS['wpint']['notices'])->toBe([]);
});

it('runs the body with the input WordPress validated, and WordPress refuses an input the schema forbids', function (): void {
    $factory = wpintAbilities();
    $factory->category('wpint-content', 'Integration content', 'Abilities of the integration suite.');
    $factory->define('wpint/echo')
        ->label('Echo')
        ->description('Returns the title it was given.')
        ->category('wpint-content')
        ->input(static function (SchemaBuilder $schema): void {
            $schema
                ->string('title', 'Title to return.', required: true)
                ->integer('limit', 'How many times.', minimum: 1);
        })
        ->can(static fn (): bool => true)
        ->using(static fn (Input $input): array => ['title' => $input->string('title'), 'limit' => $input->integer('limit', 5)]);

    wpintPublish(['wpint/echo']);
    $ability = wpintAbility('wpint/echo');

    expect($ability->execute(['title' => 'Hello', 'limit' => '3']))->toBe(['title' => 'Hello', 'limit' => 3]);

    $missing = $ability->execute([]);
    $wrongType = $ability->execute(['title' => ['not', 'a string']]);

    expect($missing)->toBeInstanceOf(WP_Error::class)
        ->and($missing->get_error_code())->toBe('ability_invalid_input')
        ->and($wrongType)->toBeInstanceOf(WP_Error::class);
});

it('asks the permission with the input of the call, so a check can be made on the object', function (): void {
    $owner = wpintUser('author', 'wpint_owner');
    $stranger = wpintUser('author', 'wpint_stranger');
    $postId = wp_insert_post(['post_title' => 'Mine', 'post_status' => 'publish', 'post_author' => $owner]);

    $factory = wpintAbilities();
    $factory->category('wpint-content', 'Integration content', 'Abilities of the integration suite.');
    $factory->define('wpint/rename')
        ->label('Rename')
        ->description('Renames a post.')
        ->category('wpint-content')
        ->input(static function (SchemaBuilder $schema): void {
            $schema->integer('post_id', 'Post to rename.', required: true, minimum: 1);
        })
        ->can(static fn (Input $input): bool => current_user_can('edit_post', $input->id('post_id')))
        ->updates()
        ->using(static fn (Input $input): array => ['renamed' => $input->id('post_id')]);
    // No permission declared: the ability must be inert.
    $factory->define('wpint/inert')
        ->label('Inert')
        ->description('Declares no permission.')
        ->category('wpint-content')
        ->using(static fn (): array => ['ran' => true]);

    wpintPublish(['wpint/rename', 'wpint/inert']);

    try {
        wp_set_current_user($owner);
        $mine = wpintAbility('wpint/rename')->execute(['post_id' => $postId]);

        wp_set_current_user($stranger);
        $theirs = wpintAbility('wpint/rename')->execute(['post_id' => $postId]);

        wp_set_current_user(1);
        $inert = wpintAbility('wpint/inert')->execute([]);

        expect($mine)->toBe(['renamed' => $postId])
            ->and($theirs)->toBeInstanceOf(WP_Error::class)
            ->and($theirs->get_error_code())->toBe('ability_invalid_permissions')
            ->and($inert)->toBeInstanceOf(WP_Error::class)
            ->and($inert->get_error_code())->toBe('ability_invalid_permissions');
    } finally {
        wp_delete_post($postId, true);
    }
});

it('does not register a name or a category twice, which WordPress would report as doing it wrong', function (): void {
    $abilities = new WordPressAbilityRegistrar;
    $categories = new WordPressAbilityCategoryRegistrar;
    remove_all_actions('wp_abilities_api_categories_init');
    remove_all_actions('wp_abilities_api_init');
    $ability = Ability::create(
        name: 'wpint/twice',
        label: 'Twice',
        description: 'Declared two times.',
        category: 'wpint-content',
        inputSchema: ['type' => 'object', 'additionalProperties' => false],
        execute: static fn (): array => [],
        permission: static fn (): bool => true,
    );
    $category = AbilityCategory::make('wpint-content', 'Integration content', 'Abilities of the integration suite.');
    $results = [];

    add_action('wp_abilities_api_categories_init', static function () use ($categories, $category, &$results): void {
        $results['category'] = [$categories->register($category), $categories->register($category)];
    });
    add_action('wp_abilities_api_init', static function () use ($abilities, $ability, &$results): void {
        $results['ability'] = [$abilities->register($ability), $abilities->register($ability)];
    });
    wpintPublish(['wpint/twice']);

    expect($results['category'])->toBe([true, false])
        ->and($results['ability'])->toBe([true, false])
        ->and($abilities->exists('wpint/twice'))->toBeTrue()
        ->and($categories->exists('wpint-content'))->toBeTrue()
        ->and($GLOBALS['wpint']['notices'])->toBe([]);
});

it('leaves an ability out when its category does not exist, until the category is ensured', function (): void {
    $factory = wpintAbilities();
    $factory->define('wpint/orphan')
        ->label('Orphan')
        ->description('Names a category nobody declared.')
        ->category('wpint-missing')
        ->can(static fn (): bool => true)
        ->using(static fn (): array => []);

    wpintPublish(['wpint/orphan'], ['wpint-missing']);

    expect(wp_has_ability('wpint/orphan'))->toBeFalse()
        ->and($factory->service()->registered())->toBe([])
        ->and($GLOBALS['wpint']['notices'])->not->toBe([]);

    // The category the ability names, declared as the fallback the package offers, lets a later flush publish it.
    $GLOBALS['wpint']['notices'] = [];
    $factory->define('wpint/fixed')
        ->label('Fixed')
        ->description('Ensures its category.')
        ->category('wpint-missing')
        ->can(static fn (): bool => true)
        ->using(static fn (): array => []);
    $factory->ensureCategory('wpint-missing', 'Missing', 'A fallback category.');
    wpintPublish(['wpint/fixed'], ['wpint-missing']);

    expect(wp_has_ability('wpint/fixed'))->toBeTrue()
        ->and($GLOBALS['wpint']['notices'])->toBe([]);
});

it('is run by the verb WordPress derives from the annotations the behaviour publishes', function (string $behaviour, string $allowed, string $refused): void {
    $factory = wpintAbilities();
    $factory->category('wpint-content', 'Integration content', 'Abilities of the integration suite.');
    $factory->define('wpint/verb')
        ->label('Verb')
        ->description('Runs through REST.')
        ->category('wpint-content')
        ->can(static fn (): bool => true)
        ->{$behaviour}()
        ->using(static fn (): array => ['ran' => true]);
    wpintPublish(['wpint/verb']);

    wp_set_current_user(1);
    $GLOBALS['wp_rest_server'] = null;
    // An empty object as input: WordPress rejects the null a call without input would carry, for an object schema.
    $run = static function (string $method): WP_REST_Response {
        $request = new WP_REST_Request($method, '/wp-abilities/v1/abilities/wpint/verb/run');

        if ($method === 'POST') {
            $request->set_header('content-type', 'application/json');
            $request->set_body('{"input":{}}');
        } else {
            $request->set_query_params(['input' => []]);
        }

        return rest_do_request($request);
    };

    expect($run($allowed)->get_status())->toBe(200)
        ->and($run($allowed)->get_data())->toBe(['ran' => true])
        ->and($run($refused)->get_status())->toBe(405);
})->with([
    'reads' => ['reads', 'GET', 'POST'],
    'creates' => ['creates', 'POST', 'GET'],
    'deletes' => ['deletes', 'DELETE', 'POST'],
]);

it('lists the abilities in the REST API of WordPress, with the annotations of the behaviour', function (): void {
    $factory = wpintAbilities();
    $factory->category('wpint-content', 'Integration content', 'Abilities of the integration suite.');
    $factory->define('wpint/listed')
        ->label('Listed')
        ->description('Shown by the abilities endpoint.')
        ->category('wpint-content')
        ->can(static fn (): bool => true)
        ->using(static fn (): array => []);
    wpintPublish(['wpint/listed']);

    wp_set_current_user(1);
    $GLOBALS['wp_rest_server'] = null;
    $response = rest_do_request(new WP_REST_Request('GET', '/wp-abilities/v1/abilities/wpint/listed'));

    expect($response->get_status())->toBe(200)
        ->and($response->get_data()['name'])->toBe('wpint/listed')
        ->and($response->get_data()['meta']['annotations'])->toBe(['readonly' => true, 'destructive' => false, 'idempotent' => true]);
});
