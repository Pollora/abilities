<?php

declare(strict_types=1);

use Pollora\Abilities\Application\Service\RegisterAbilityService;
use Pollora\Abilities\Domain\Model\AbilityCategory;
use Pollora\Abilities\Domain\Model\Behaviour;

describe('RegisterAbilityService', function (): void {
    beforeEach(function (): void {
        $this->abilities = new RecordingAbilityRegistrar;
        $this->categories = new RecordingCategoryRegistrar;
        $this->service = new RegisterAbilityService($this->abilities, $this->categories);
    });

    it('does not publish anything until it is flushed', function (): void {
        // Declaration happens long before WordPress has an abilities registry;
        // publishing eagerly would register nothing at all.
        $this->service->queue(anAbility());

        expect($this->abilities->registered)->toBe([])
            ->and($this->service->pending())->toHaveCount(1);
    });

    it('publishes queued abilities on flush and empties the queue', function (): void {
        $this->service->queue(anAbility('acme/get-posts'));
        $this->service->queue(anAbility('acme/create-post'));

        expect($this->service->flushAbilities())->toBe(['acme/get-posts', 'acme/create-post'])
            ->and($this->abilities->registered)->toBe(['acme/get-posts', 'acme/create-post'])
            ->and($this->service->pending())->toBe([]);
    });

    it('publishes in declaration order', function (): void {
        foreach (['acme/c', 'acme/a', 'acme/b'] as $name) {
            $this->service->queue(anAbility($name));
        }

        expect($this->service->flushAbilities())->toBe(['acme/c', 'acme/a', 'acme/b']);
    });

    it('does not republish on a second flush', function (): void {
        $this->service->queue(anAbility());
        $this->service->flushAbilities();

        expect($this->service->flushAbilities())->toBe([])
            ->and($this->abilities->registered)->toHaveCount(1);
    });

    it('reports only what the platform accepted', function (): void {
        $refusing = new RecordingAbilityRegistrar(accepts: false);
        $service = new RegisterAbilityService($refusing, $this->categories);
        $service->queue(anAbility());

        expect($service->flushAbilities())->toBe([])
            ->and($service->registered())->toBe([]);
    });

    it('accumulates what it registered across flushes', function (): void {
        $this->service->queue(anAbility('acme/one'));
        $this->service->flushAbilities();
        $this->service->queue(anAbility('acme/two'));
        $this->service->flushAbilities();

        expect($this->service->registered())->toBe(['acme/one', 'acme/two']);
    });

    describe('categories', function (): void {
        it('publishes queued categories on flush', function (): void {
            $this->service->queueCategory(AbilityCategory::make('acme-content'));

            expect($this->service->flushCategories())->toBe(['acme-content'])
                ->and($this->categories->registered)->toBe(['acme-content'])
                ->and($this->service->registeredCategories())->toBe(['acme-content']);
        });

        it('collapses a slug declared twice into one registration', function (): void {
            // Several unrelated abilities declaring the category they need must
            // not produce a double registration.
            $this->service->queueCategory(AbilityCategory::make('acme-content', 'First'));
            $this->service->queueCategory(AbilityCategory::make('acme-content', 'Second'));

            expect($this->service->pendingCategories())->toHaveCount(1)
                ->and($this->service->pendingCategories()[0]->label)->toBe('Second')
                ->and($this->service->flushCategories())->toBe(['acme-content']);
        });

        it('keeps the two queues independent', function (): void {
            $this->service->queue(anAbility());
            $this->service->queueCategory(AbilityCategory::make('acme-content'));

            $this->service->flushCategories();

            expect($this->categories->registered)->toBe(['acme-content'])
                ->and($this->abilities->registered)->toBe([])
                ->and($this->service->pending())->toHaveCount(1);
        });
    });
});

describe('RegisterAbilityService::ensureCategory', function (): void {
    beforeEach(function (): void {
        $this->abilities = new RecordingAbilityRegistrar;
        $this->categories = new RecordingCategoryRegistrar;
        $this->service = new RegisterAbilityService($this->abilities, $this->categories);
    });

    it('queues a category nobody declared', function (): void {
        expect($this->service->ensureCategory(AbilityCategory::make('acme-content')))->toBeTrue()
            ->and($this->service->pendingCategories())->toHaveCount(1);
    });

    it('leaves an explicit declaration alone', function (): void {
        // An explicit label must not be displaced by a derived one.
        $this->service->queueCategory(AbilityCategory::make('acme-content', 'Editorial'));

        expect($this->service->ensureCategory(AbilityCategory::make('acme-content')))->toBeFalse()
            ->and($this->service->pendingCategories()[0]->label)->toBe('Editorial');
    });

    it('is overwritten by a later explicit declaration', function (): void {
        // Explicit wins whichever ran first, so declaration order does not matter.
        $this->service->ensureCategory(AbilityCategory::make('acme-content'));
        $this->service->queueCategory(AbilityCategory::make('acme-content', 'Editorial'));

        expect($this->service->pendingCategories())->toHaveCount(1)
            ->and($this->service->pendingCategories()[0]->label)->toBe('Editorial');
    });

    it('does not requeue a category it already published', function (): void {
        $this->service->ensureCategory(AbilityCategory::make('acme-content'));
        $this->service->flushCategories();

        expect($this->service->ensureCategory(AbilityCategory::make('acme-content')))->toBeFalse()
            ->and($this->service->pendingCategories())->toBe([]);
    });

    it('does not requeue a category another plugin already owns', function (): void {
        // Reusing a category somebody else registered is legitimate; registering
        // it a second time is what raises _doing_it_wrong().
        $this->categories->registered[] = 'acme-content';

        expect($this->service->ensureCategory(AbilityCategory::make('acme-content')))->toBeFalse();
    });
});

describe('RegisterAbilityService duplicate handling', function (): void {
    beforeEach(function (): void {
        $this->abilities = new RecordingAbilityRegistrar;
        $this->categories = new RecordingCategoryRegistrar;
        $this->service = new RegisterAbilityService($this->abilities, $this->categories);
    });

    it('collapses a name queued twice', function (): void {
        // A host may run its discovery once per scan location; the platform
        // refuses the repeats anyway, so a queue of dead entries helps nobody.
        expect($this->service->queue(anAbility('acme/get-posts')))->toBeTrue()
            ->and($this->service->queue(anAbility('acme/get-posts')))->toBeFalse()
            ->and($this->service->pending())->toHaveCount(1);
    });

    it('keeps the first declaration of a repeated name', function (): void {
        // The first is what the platform would have accepted, so the queue and
        // the outcome agree.
        $this->service->queue(anAbility('acme/thing', Behaviour::Reads));
        $this->service->queue(anAbility('acme/thing', Behaviour::Deletes));

        expect($this->service->pending()[0]->isReadOnly())->toBeTrue();
    });

    it('still publishes distinct names', function (): void {
        $this->service->queue(anAbility('acme/one'));
        $this->service->queue(anAbility('acme/two'));

        expect($this->service->flushAbilities())->toBe(['acme/one', 'acme/two']);
    });
});
