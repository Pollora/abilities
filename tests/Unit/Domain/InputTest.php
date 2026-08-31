<?php

declare(strict_types=1);

use Pollora\Abilities\Domain\Model\Input;

describe('Input', function (): void {
    it('treats anything that is not an array as an empty set', function (mixed $raw): void {
        expect(Input::wrap($raw)->all())->toBe([]);
    })->with([[null], ['string'], [42], [true]]);

    describe('presence', function (): void {
        it('distinguishes a present empty value from an absent one', function (): void {
            $input = Input::wrap(['empty' => '', 'list' => [], 'zero' => 0]);

            expect($input->has('empty'))->toBeTrue()
                ->and($input->filled('empty'))->toBeFalse()
                ->and($input->has('list'))->toBeTrue()
                ->and($input->filled('list'))->toBeFalse()
                // Zero is a value someone meant to send, not an omission.
                ->and($input->has('zero'))->toBeTrue()
                ->and($input->filled('zero'))->toBeTrue()
                ->and($input->has('absent'))->toBeFalse()
                ->and($input->filled('absent'))->toBeFalse();
        });

        it('treats an explicit null as absent', function (): void {
            $input = Input::wrap(['nothing' => null]);

            expect($input->has('nothing'))->toBeFalse()
                ->and($input->filled('nothing'))->toBeFalse();
        });
    });

    describe('string', function (): void {
        it('coerces scalars and falls back for everything else', function (): void {
            $input = Input::wrap(['text' => 'hello', 'number' => 12, 'array' => ['a']]);

            expect($input->string('text'))->toBe('hello')
                ->and($input->string('number'))->toBe('12')
                ->and($input->string('array', 'fallback'))->toBe('fallback')
                ->and($input->string('absent', 'fallback'))->toBe('fallback');
        });
    });

    describe('integer', function (): void {
        it('coerces numeric strings, which is what a model actually sends', function (): void {
            expect(Input::wrap(['limit' => '12'])->integer('limit'))->toBe(12);
        });

        it('falls back when the value is not numeric', function (): void {
            expect(Input::wrap(['limit' => 'many'])->integer('limit', 5))->toBe(5);
        });

        it('clamps to the given bounds', function (): void {
            $input = Input::wrap(['limit' => 100000]);

            expect($input->integer('limit', max: 100))->toBe(100)
                ->and(Input::wrap(['limit' => -5])->integer('limit', min: 1))->toBe(1);
        });

        it('clamps the default too, so an out-of-range default cannot slip through', function (): void {
            expect(Input::wrap([])->integer('limit', default: 500, max: 100))->toBe(100);
        });
    });

    describe('float', function (): void {
        it('coerces and clamps', function (): void {
            expect(Input::wrap(['score' => '1.5'])->float('score'))->toBe(1.5)
                ->and(Input::wrap(['score' => 9.9])->float('score', max: 1.0))->toBe(1.0)
                ->and(Input::wrap([])->float('score', 0.25))->toBe(0.25);
        });
    });

    describe('id', function (): void {
        it('returns zero for anything that is not a usable identifier', function (mixed $raw): void {
            expect(Input::wrap(['id' => $raw])->id('id'))->toBe(0);
            // Each row is wrapped, so the array case arrives as one argument
            // rather than being spread into the closure.
        })->with([['not-a-number'], [null], [[1]]]);

        it('never returns a negative identifier', function (): void {
            expect(Input::wrap(['id' => -12])->id('id'))->toBe(0);
        });

        it('coerces numeric strings', function (): void {
            expect(Input::wrap(['id' => '42'])->id('id'))->toBe(42);
        });
    });

    describe('boolean', function (): void {
        it('accepts the string forms that leak through careless clients', function (mixed $raw): void {
            expect(Input::wrap(['flag' => $raw])->boolean('flag'))->toBeTrue();
        })->with([[true], ['true'], ['1'], [1], ['yes'], ['on']]);

        it('reads falsy forms as false', function (mixed $raw): void {
            expect(Input::wrap(['flag' => $raw])->boolean('flag', true))->toBeFalse();
        })->with([[false], ['false'], ['0'], [0], ['no'], ['off']]);

        it('falls back when the value is uninterpretable', function (): void {
            expect(Input::wrap(['flag' => 'perhaps'])->boolean('flag', true))->toBeTrue();
        });
    });

    describe('stringList', function (): void {
        it('drops blanks and non-scalars, and trims what is left', function (): void {
            $input = Input::wrap(['tags' => ['  news ', '', 'sport', ['nested'], '   ']]);

            expect($input->stringList('tags'))->toBe(['news', 'sport']);
        });

        it('returns an empty list when the property is absent or not an array', function (): void {
            expect(Input::wrap([])->stringList('tags'))->toBe([])
                ->and(Input::wrap(['tags' => 'news'])->stringList('tags'))->toBe([]);
        });
    });

    describe('idList', function (): void {
        it('keeps numerics, drops the rest and floors at zero', function (): void {
            $input = Input::wrap(['ids' => [1, '2', 'three', -4, null]]);

            expect($input->idList('ids'))->toBe([1, 2, 0]);
        });
    });

    describe('map', function (): void {
        it('returns the array as given', function (): void {
            $terms = ['category' => ['news'], 'post_tag' => ['sport']];

            expect(Input::wrap(['terms' => $terms])->map('terms'))->toBe($terms);
        });

        it('returns an empty map when the property is absent or not an array', function (): void {
            expect(Input::wrap([])->map('terms'))->toBe([])
                ->and(Input::wrap(['terms' => 'news'])->map('terms'))->toBe([]);
        });
    });
});
