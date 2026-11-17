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

/**
 * Run a callback with every PHP warning and notice raised as an exception.
 *
 * Reading an input must never emit one: a warning on a production request is
 * log noise at best and a visible error at worst.
 *
 * @param  Closure(): mixed  $callback  The code to run.
 * @return mixed What the callback returned.
 */
function withoutPhpWarnings(Closure $callback): mixed
{
    set_error_handler(static function (int $severity, string $message): never {
        throw new ErrorException($message, 0, $severity);
    });

    try {
        return $callback();
    } finally {
        restore_error_handler();
    }
}

describe('Input exactness', function (): void {
    it('returns the default for an empty string and for an absent key, without a warning', function (): void {
        $input = Input::wrap(['empty' => '']);

        expect(withoutPhpWarnings(fn (): string => $input->string('empty', 'fallback')))->toBe('fallback')
            ->and(withoutPhpWarnings(fn (): string => $input->string('absent', 'fallback')))->toBe('fallback');
    });

    it('exposes the values it was given, whole', function (): void {
        expect(Input::wrap(['title' => 'Hello', 'limit' => 3])->all())->toBe(['title' => 'Hello', 'limit' => 3]);
    });

    it('falls back for a non-numeric float rather than coercing it to zero', function (): void {
        expect(Input::wrap(['score' => 'high'])->float('score', 0.5))->toBe(0.5)
            ->and(Input::wrap(['score' => []])->float('score', 0.5))->toBe(0.5);
    });

    it('applies a float lower bound only when one is given', function (): void {
        $input = Input::wrap(['score' => -5.0]);

        expect($input->float('score', min: 1.0))->toBe(1.0)
            ->and($input->float('score'))->toBe(-5.0)
            ->and($input->float('score', min: -5.0))->toBe(-5.0)
            ->and($input->float('score', min: -9.0))->toBe(-5.0);
    });

    it('applies an integer lower bound only when one is given', function (): void {
        $input = Input::wrap(['limit' => -5]);

        expect($input->integer('limit', min: 1))->toBe(1)
            ->and($input->integer('limit'))->toBe(-5)
            ->and($input->integer('limit', min: -5))->toBe(-5)
            ->and($input->integer('limit', min: -9))->toBe(-5);
    });

    it('stringifies numeric and boolean list items instead of dropping or rejecting them', function (): void {
        expect(Input::wrap(['tags' => [12, 1.5, true, ' a ']])->stringList('tags'))->toBe(['12', '1.5', '1', 'a']);
    });

    it('reindexes a string list that had gaps', function (): void {
        expect(Input::wrap(['tags' => [3 => 'a', 7 => '', 9 => 'b']])->stringList('tags'))->toBe(['a', 'b']);
    });

    it('returns an empty list for a non-array without a warning', function (mixed $raw): void {
        $input = Input::wrap(['ids' => $raw]);

        expect(withoutPhpWarnings(fn (): array => $input->idList('ids')))->toBe([])
            ->and(withoutPhpWarnings(fn (): array => $input->stringList('ids')))->toBe([])
            ->and(withoutPhpWarnings(fn (): array => $input->map('ids')))->toBe([]);
    })->with([['news'], [''], [null], [42], [true]]);

    it('returns an empty list for an absent key without a warning', function (): void {
        $input = Input::wrap([]);

        expect(withoutPhpWarnings(fn (): array => $input->idList('ids')))->toBe([])
            ->and(withoutPhpWarnings(fn (): array => $input->stringList('ids')))->toBe([]);
    });
});
