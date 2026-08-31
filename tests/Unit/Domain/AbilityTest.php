<?php

declare(strict_types=1);

use Pollora\Abilities\Domain\Exception\InvalidAbilityException;
use Pollora\Abilities\Domain\Model\Ability;
use Pollora\Abilities\Domain\Model\AbilityCategory;
use Pollora\Abilities\Domain\Model\Annotations;
use Pollora\Abilities\Domain\Model\Behaviour;
use Pollora\Abilities\Domain\Model\Input;

/**
 * Build a valid ability, overriding only what a test cares about.
 *
 * @param  array<string, mixed>  $overrides  Named arguments to replace.
 */
function makeAbility(array $overrides = []): Ability
{
    return Ability::create(...[
        'name' => 'acme/get-posts',
        'label' => 'List posts',
        'description' => 'Returns the most recent posts, newest first.',
        'category' => 'acme-content',
        'inputSchema' => ['type' => 'object', 'additionalProperties' => false],
        'execute' => static fn (Input $input): array => [],
        'permission' => static fn (Input $input): bool => true,
        ...$overrides,
    ]);
}

describe('Ability', function (): void {
    it('splits the name into namespace and slug', function (): void {
        $ability = makeAbility(['name' => 'acme-plugin/create-post']);

        expect($ability->namespace())->toBe('acme-plugin')
            ->and($ability->slug())->toBe('create-post');
    });

    describe('validation', function (): void {
        it('refuses a name without a namespace', function (): void {
            // A bare slug registers nothing and reports it through
            // _doing_it_wrong(), which is easy to miss.
            makeAbility(['name' => 'get-posts']);
        })->throws(InvalidAbilityException::class, 'malformed');

        it('refuses a malformed name', function (string $name): void {
            makeAbility(['name' => $name]);
        })->with([
            'Acme/GetPosts',
            'acme//get-posts',
            'acme/get_posts',
            'acme/get-posts/extra',
            'acme /get-posts',
            '',
        ])->throws(InvalidAbilityException::class);

        it('accepts a well-formed name', function (string $name): void {
            expect(makeAbility(['name' => $name])->name)->toBe($name);
        })->with(['acme/get-posts', 'a/b', 'acme-plugin/set-post-terms', 'wp2/get-posts']);

        it('refuses an empty label', function (): void {
            makeAbility(['label' => '  ']);
        })->throws(InvalidAbilityException::class, 'label');

        it('refuses an empty description', function (): void {
            // The description is what a model reads to decide whether to call
            // the ability; without it the tool never gets used correctly.
            makeAbility(['description' => '']);
        })->throws(InvalidAbilityException::class, 'description');

        it('refuses an empty category', function (): void {
            makeAbility(['category' => '']);
        })->throws(InvalidAbilityException::class, 'category');

        it('refuses an input schema that is not an object', function (): void {
            makeAbility(['inputSchema' => ['type' => 'array']]);
        })->throws(InvalidAbilityException::class, 'must be an object');

        it('refuses an input schema with no declared type', function (): void {
            makeAbility(['inputSchema' => ['properties' => []]]);
        })->throws(InvalidAbilityException::class, 'undeclared');

        it('trims surrounding whitespace from the name', function (): void {
            expect(makeAbility(['name' => '  acme/get-posts  '])->name)->toBe('acme/get-posts');
        });
    });

    describe('behaviour', function (): void {
        it('defaults to reading', function (): void {
            expect(makeAbility()->isReadOnly())->toBeTrue();
        });

        it('derives annotations from the behaviour', function (Behaviour $behaviour, array $expected): void {
            expect(makeAbility(['behaviour' => $behaviour])->annotations->toArray())->toBe($expected);
        })->with([
            [Behaviour::Reads, ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
            [Behaviour::Creates, ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
            [Behaviour::Updates, ['readonly' => false, 'destructive' => true, 'idempotent' => true]],
            [Behaviour::Deletes, ['readonly' => false, 'destructive' => true, 'idempotent' => true]],
        ]);
    });

    it('keeps the output schema optional', function (): void {
        expect(makeAbility()->outputSchema)->toBeNull()
            ->and(makeAbility(['outputSchema' => ['type' => 'object']])->outputSchema)
            ->toBe(['type' => 'object']);
    });
});

describe('Behaviour', function (): void {
    it('reports read-only only for reads', function (): void {
        expect(Behaviour::Reads->isReadOnly())->toBeTrue()
            ->and(Behaviour::Creates->isReadOnly())->toBeFalse()
            ->and(Behaviour::Updates->isReadOnly())->toBeFalse()
            ->and(Behaviour::Deletes->isReadOnly())->toBeFalse();
    });
});

describe('Annotations', function (): void {
    it('renders the three hints', function (): void {
        expect((new Annotations(readonly: true, destructive: false, idempotent: true))->toArray())
            ->toBe(['readonly' => true, 'destructive' => false, 'idempotent' => true]);
    });

    it('defaults every hint to false', function (): void {
        expect((new Annotations)->toArray())
            ->toBe(['readonly' => false, 'destructive' => false, 'idempotent' => false]);
    });
});

describe('AbilityCategory', function (): void {
    it('derives a label from the slug when none is given', function (): void {
        expect(AbilityCategory::make('acme-content')->label)->toBe('Acme Content');
    });

    it('keeps an explicit label', function (): void {
        expect(AbilityCategory::make('acme-content', 'Editorial')->label)->toBe('Editorial');
    });

    it('renders the registration arguments', function (): void {
        expect(AbilityCategory::make('acme-content', 'Editorial', 'Posts and pages.')->toArray())
            ->toBe(['label' => 'Editorial', 'description' => 'Posts and pages.']);
    });

    it('refuses an empty slug', function (): void {
        AbilityCategory::make('   ');
    })->throws(InvalidAbilityException::class, 'slug');

    it('refuses a malformed slug', function (string $slug): void {
        AbilityCategory::make($slug);
    })->with(['Acme Content', 'acme_content', 'acme--content', '-acme', 'acme-'])
        ->throws(InvalidAbilityException::class, 'malformed');
});

describe('AbilityCategory description', function (): void {
    it('derives a description from the label when none is given', function (): void {
        // WordPress rejects a category with a blank description, and does it by
        // returning null rather than raising — an empty one would vanish silently.
        expect(AbilityCategory::make('acme-content')->description)->toBe('Acme Content abilities.')
            ->and(AbilityCategory::make('acme-content', 'Editorial')->description)
            ->toBe('Editorial abilities.');
    });

    it('never renders a blank description', function (string $slug, string $label, string $description): void {
        expect(AbilityCategory::make($slug, $label, $description)->toArray()['description'])->not->toBe('');
    })->with([
        ['acme-content', '', ''],
        ['acme-content', 'Editorial', ''],
        ['acme-content', '', '   '],
    ]);

    it('keeps an explicit description', function (): void {
        expect(AbilityCategory::make('acme-content', 'Editorial', 'Posts and pages.')->description)
            ->toBe('Posts and pages.');
    });
});
