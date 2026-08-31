<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Abilities\Adapter\Out\WordPress\WordPressAbilityCategoryRegistrar;
use Pollora\Abilities\Adapter\Out\WordPress\WordPressAbilityRegistrar;
use Pollora\Abilities\Domain\Model\AbilityCategory;
use Pollora\Abilities\Domain\Model\Behaviour;

describe('WordPressAbilityRegistrar', function (): void {
    beforeEach(function (): void {
        Functions\when('wp_has_ability')->justReturn(false);
    });

    it('hands WordPress the declared metadata', function (): void {
        $call = captureAbilityRegistration(anAbility('acme/create-post'));

        expect($call['registered'])->toBeTrue()
            ->and($call['name'])->toBe('acme/create-post')
            ->and($call['arguments']['label'])->toBe('List posts')
            ->and($call['arguments']['description'])->toBe('Returns the most recent posts.')
            ->and($call['arguments']['category'])->toBe('acme-content')
            ->and($call['arguments']['input_schema'])
            ->toBe(['type' => 'object', 'additionalProperties' => false]);
    });

    it('wraps the raw input before the body sees it', function (): void {
        // The Abilities API passes its validated input straight through, so an
        // all-optional ability can legitimately be invoked with null.
        $execute = captureAbilityRegistration(anAbilityReturningInput())['arguments']['execute_callback'];

        expect($execute(['title' => 'Hello']))->toBe('Hello')
            ->and($execute(null))->toBe('')
            ->and($execute())->toBe('');
    });

    it('wraps the raw input for the permission check too', function (): void {
        // Same input as the body, which is what allows per-object checks —
        // `edit_post` on the identifier being edited, not a blanket `edit_posts`.
        $permission = captureAbilityRegistration(anAbilityCheckingInput())['arguments']['permission_callback'];

        expect($permission(['id' => 12]))->toBeTrue()
            ->and($permission(null))->toBeFalse();
    });

    it('publishes the behaviour annotations', function (Behaviour $behaviour, array $expected): void {
        $call = captureAbilityRegistration(anAbility('acme/thing', $behaviour));

        expect($call['arguments']['meta']['annotations'])->toBe($expected);
    })->with([
        [Behaviour::Reads, ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        [Behaviour::Creates, ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
        [Behaviour::Deletes, ['readonly' => false, 'destructive' => true, 'idempotent' => true]],
    ]);

    it('exposes abilities through the REST controllers by default', function (): void {
        // Which is how an ability is tested without an MCP client.
        $call = captureAbilityRegistration(anAbility());

        expect($call['arguments']['meta']['show_in_rest'])->toBeTrue();
    });

    it('merges caller metadata but keeps ownership of the annotations', function (): void {
        // An ability declaring its own annotations would otherwise publish hints
        // contradicting its own behaviour.
        $call = captureAbilityRegistration(anAbility(
            'acme/get-posts',
            Behaviour::Reads,
            ['mcp' => ['public' => true], 'annotations' => ['readonly' => false]],
        ));

        expect($call['arguments']['meta']['mcp'])->toBe(['public' => true])
            ->and($call['arguments']['meta']['annotations']['readonly'])->toBeTrue();
    });

    it('lets caller metadata override show_in_rest', function (): void {
        $call = captureAbilityRegistration(anAbility(meta: ['show_in_rest' => false]));

        expect($call['arguments']['meta']['show_in_rest'])->toBeFalse();
    });

    it('omits the output schema when none was declared', function (): void {
        $call = captureAbilityRegistration(anAbility());

        expect($call['arguments'])->not->toHaveKey('output_schema');
    });

    it('passes the output schema through when one was declared', function (): void {
        $call = captureAbilityRegistration(anAbility(outputSchema: ['type' => 'object']));

        expect($call['arguments']['output_schema'])->toBe(['type' => 'object']);
    });

    it('refuses to register a name that is already taken', function (): void {
        // A second registration raises _doing_it_wrong(), which on a site running
        // with WP_DEBUG on lands in the REST response body.
        Functions\when('wp_has_ability')->justReturn(true);
        Functions\when('wp_register_ability')->justReturn(new stdClass);

        expect((new WordPressAbilityRegistrar)->register(anAbility()))->toBeFalse();
    });

    it('reports failure when WordPress refuses the registration', function (): void {
        Functions\when('wp_register_ability')->justReturn(null);

        expect((new WordPressAbilityRegistrar)->register(anAbility()))->toBeFalse();
    });

    it('reports whether a name is taken', function (): void {
        Functions\when('wp_has_ability')->justReturn(true);

        expect((new WordPressAbilityRegistrar)->exists('acme/get-posts'))->toBeTrue();
    });

    // The "WordPress older than 6.9" path — where wp_register_ability() does not
    // exist at all — is not covered here on purpose. Brain Monkey defines a
    // stubbed function process-wide and cannot undefine it, so a test claiming to
    // exercise that branch would only ever be skipped.
});

describe('WordPressAbilityCategoryRegistrar', function (): void {
    beforeEach(function (): void {
        Functions\when('wp_has_ability_category')->justReturn(false);
    });

    it('hands WordPress the slug and arguments', function (): void {
        $call = captureCategoryRegistration(
            AbilityCategory::make('acme-content', 'Editorial', 'Posts and pages.')
        );

        expect($call['registered'])->toBeTrue()
            ->and($call['slug'])->toBe('acme-content')
            ->and($call['arguments'])->toBe(['label' => 'Editorial', 'description' => 'Posts and pages.']);
    });

    it('refuses a slug that is already taken', function (): void {
        Functions\when('wp_has_ability_category')->justReturn(true);
        Functions\when('wp_register_ability_category')->justReturn(new stdClass);

        expect((new WordPressAbilityCategoryRegistrar)->register(AbilityCategory::make('acme-content')))
            ->toBeFalse();
    });

    it('reports failure when WordPress refuses the registration', function (): void {
        Functions\when('wp_register_ability_category')->justReturn(null);

        expect((new WordPressAbilityCategoryRegistrar)->register(AbilityCategory::make('acme-content')))
            ->toBeFalse();
    });

    it('reports whether a slug is taken', function (): void {
        Functions\when('wp_has_ability_category')->justReturn(true);

        expect((new WordPressAbilityCategoryRegistrar)->exists('acme-content'))->toBeTrue();
    });
});
