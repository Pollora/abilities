<?php

declare(strict_types=1);

use Pollora\Abilities\Application\Service\RegisterAbilityService;
use Pollora\Abilities\Domain\Contracts\AbilityHandler;
use Pollora\Abilities\Domain\Exception\InvalidAbilityException;
use Pollora\Abilities\Domain\Model\Behaviour;
use Pollora\Abilities\Domain\Model\Input;
use Pollora\Abilities\Domain\Schema\SchemaBuilder;
use Pollora\Abilities\Factory\AbilityFactory;

/**
 * A handler that records what it was asked, so the wiring can be observed.
 */
final class SpyHandler implements AbilityHandler
{
    public bool $schemaCalled = false;

    public ?Input $authorized = null;

    public ?Input $handled = null;

    public function __construct(public bool $allows = true) {}

    public function schema(SchemaBuilder $schema): void
    {
        $this->schemaCalled = true;
        $schema->string('title', 'The post title.', required: true);
    }

    public function authorize(Input $input): mixed
    {
        $this->authorized = $input;

        return $this->allows;
    }

    public function handle(Input $input): mixed
    {
        $this->handled = $input;

        return ['ok' => true];
    }
}

describe('AbilityFactory', function (): void {
    beforeEach(function (): void {
        $this->abilities = new RecordingAbilityRegistrar;
        $this->categories = new RecordingCategoryRegistrar;
        $this->service = new RegisterAbilityService($this->abilities, $this->categories);
        $this->factory = new AbilityFactory($this->service);
    });

    it('queues nothing until a body is supplied', function (): void {
        // An abandoned chain must register nothing rather than something broken.
        $this->factory->define('acme/get-posts')
            ->label('List posts')
            ->description('Returns posts.')
            ->category('acme-content');

        expect($this->service->pending())->toBe([]);
    });

    it('queues the ability once a body is supplied', function (): void {
        $ability = $this->factory->define('acme/get-posts')
            ->label('List posts')
            ->description('Returns the most recent posts.')
            ->category('acme-content')
            ->using(static fn (Input $input): array => []);

        expect($this->service->pending())->toBe([$ability])
            ->and($ability->name)->toBe('acme/get-posts');
    });

    it('derives a label from the slug when none is given', function (): void {
        $ability = $this->factory->define('acme/set-post-terms')
            ->description('Assigns terms.')
            ->category('acme-content')
            ->using(static fn (Input $input): array => []);

        expect($ability->label)->toBe('Set post terms');
    });

    it('refuses to build without a description', function (): void {
        $this->factory->define('acme/get-posts')
            ->category('acme-content')
            ->using(static fn (Input $input): array => []);
    })->throws(InvalidAbilityException::class, 'description');

    it('refuses without a category, rather than registering an orphan', function (): void {
        // Naming a category that does not exist makes WordPress drop the
        // ability, so an omitted one has to fail here instead.
        $this->factory->define('acme/get-posts')
            ->description('Returns posts.')
            ->using(static fn (Input $input): array => []);
    })->throws(InvalidAbilityException::class, 'category');

    it('refuses everything by default, so a forgotten check is inert not open', function (): void {
        $ability = $this->factory->define('acme/get-posts')
            ->description('Returns posts.')
            ->category('acme-content')
            ->using(static fn (Input $input): array => []);

        expect(($ability->permission)(Input::wrap([])))->toBeFalse();
    });

    it('builds the input schema through the builder', function (): void {
        $ability = $this->factory->define('acme/get-posts')
            ->description('Returns posts.')
            ->category('acme-content')
            ->input(fn (SchemaBuilder $schema) => $schema->integer('limit', 'How many.', maximum: 100))
            ->using(static fn (Input $input): array => []);

        expect($ability->inputSchema['properties']['limit']['maximum'])->toBe(100);
    });

    it('builds an optional output schema', function (): void {
        $ability = $this->factory->define('acme/get-posts')
            ->description('Returns posts.')
            ->category('acme-content')
            ->output(fn (SchemaBuilder $schema) => $schema->list('posts', 'The matched posts.'))
            ->using(static fn (Input $input): array => []);

        expect($ability->outputSchema['properties']['posts']['type'])->toBe('array');
    });

    describe('behaviour shorthands', function (): void {
        it('maps each shorthand onto its behaviour', function (string $method, bool $readOnly): void {
            $ability = $this->factory->define('acme/thing')
                ->description('Does a thing.')
                ->category('acme-content')
                ->{$method}()
                ->using(static fn (Input $input): array => []);

            expect($ability->isReadOnly())->toBe($readOnly);
        })->with([
            ['reads', true],
            ['creates', false],
            ['updates', false],
            ['deletes', false],
        ]);

        it('defaults to reading', function (): void {
            $ability = $this->factory->define('acme/thing')
                ->description('Does a thing.')
                ->category('acme-content')
                ->using(static fn (Input $input): array => []);

            expect($ability->isReadOnly())->toBeTrue();
        });
    });

    describe('meta', function (): void {
        it('merges caller metadata', function (): void {
            $ability = $this->factory->define('acme/thing')
                ->description('Does a thing.')
                ->category('acme-content')
                ->meta(['mcp' => ['public' => true]])
                ->meta(['show_in_rest' => false])
                ->using(static fn (Input $input): array => []);

            expect($ability->meta)->toBe(['mcp' => ['public' => true], 'show_in_rest' => false]);
        });
    });

    describe('categories', function (): void {
        it('queues a category', function (): void {
            $category = $this->factory->category('acme-content', 'Editorial', 'Posts and pages.');

            expect($this->service->pendingCategories())->toBe([$category])
                ->and($category->label)->toBe('Editorial');
        });
    });

    describe('handlers', function (): void {
        it('takes schema, permission and body from the handler', function (): void {
            $handler = new SpyHandler;

            $ability = $this->factory->handle(
                name: 'acme/create-post',
                handler: $handler,
                category: 'acme-content',
                description: 'Creates a post.',
                behaviour: Behaviour::Creates,
            );

            expect($handler->schemaCalled)->toBeTrue()
                ->and($ability->inputSchema['required'])->toBe(['title'])
                ->and($ability->isReadOnly())->toBeFalse();

            $input = Input::wrap(['title' => 'Hello']);

            expect(($ability->permission)($input))->toBeTrue()
                ->and(($ability->execute)($input))->toBe(['ok' => true])
                ->and($handler->authorized?->string('title'))->toBe('Hello')
                ->and($handler->handled?->string('title'))->toBe('Hello');
        });

        it('lets a handler refuse', function (): void {
            $ability = $this->factory->handle(
                name: 'acme/create-post',
                handler: new SpyHandler(allows: false),
                category: 'acme-content',
                description: 'Creates a post.',
            );

            expect(($ability->permission)(Input::wrap([])))->toBeFalse();
        });

        it('keeps what was already chained when attaching a handler', function (): void {
            $ability = $this->factory->define('acme/create-post')
                ->label('Custom label')
                ->description('Creates a post.')
                ->category('acme-content')
                ->creates()
                ->handledBy(new SpyHandler);

            expect($ability->label)->toBe('Custom label')
                ->and($ability->isReadOnly())->toBeFalse();
        });
    });

    it('exposes the service so a host can flush it', function (): void {
        expect($this->factory->service())->toBe($this->service);
    });
});
