<?php

declare(strict_types=1);

use Pollora\Abilities\Domain\Schema\SchemaBuilder;

describe('SchemaBuilder', function (): void {
    it('renders an object schema', function (): void {
        $schema = (new SchemaBuilder)
            ->string('title', 'The post title.')
            ->toArray();

        expect($schema['type'])->toBe('object')
            ->and($schema['properties']['title'])->toBe([
                'type' => 'string',
                'description' => 'The post title.',
            ]);
    });

    it('closes an empty schema rather than rendering an empty properties array', function (): void {
        // An empty PHP array encodes to `[]`, so `properties` would advertise a
        // JSON array where the specification calls for an object.
        $schema = (new SchemaBuilder)->toArray();

        expect($schema)->toBe(['type' => 'object', 'additionalProperties' => false])
            ->and($schema)->not->toHaveKey('properties');
    });

    it('reports whether anything has been declared', function (): void {
        $schema = new SchemaBuilder;

        expect($schema->isEmpty())->toBeTrue();

        $schema->boolean('draft', 'Whether to keep it as a draft.');

        expect($schema->isEmpty())->toBeFalse();
    });

    it('collects required property names in declaration order', function (): void {
        $schema = (new SchemaBuilder)
            ->string('title', 'The title.', required: true)
            ->string('slug', 'The slug.')
            ->integer('parent', 'The parent identifier.', required: true)
            ->toArray();

        expect($schema['required'])->toBe(['title', 'parent']);
    });

    it('omits the required key entirely when nothing is required', function (): void {
        $schema = (new SchemaBuilder)->string('title', 'The title.')->toArray();

        expect($schema)->not->toHaveKey('required');
    });

    it('does not list a property twice when it is redeclared', function (): void {
        $schema = (new SchemaBuilder)
            ->string('title', 'First.', required: true)
            ->string('title', 'Second.', required: true)
            ->toArray();

        expect($schema['required'])->toBe(['title'])
            ->and($schema['properties']['title']['description'])->toBe('Second.');
    });

    describe('property types', function (): void {
        it('builds a bounded integer', function (): void {
            $schema = (new SchemaBuilder)
                ->integer('limit', 'How many to return.', default: 10, minimum: 1, maximum: 100)
                ->toArray();

            expect($schema['properties']['limit'])->toBe([
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => 100,
                'description' => 'How many to return.',
                'default' => 10,
            ]);
        });

        it('builds a bounded number', function (): void {
            $schema = (new SchemaBuilder)
                ->number('score', 'Relevance score.', minimum: 0.0, maximum: 1.0)
                ->toArray();

            expect($schema['properties']['score']['type'])->toBe('number')
                ->and($schema['properties']['score']['maximum'])->toBe(1.0);
        });

        it('builds an enum', function (): void {
            $schema = (new SchemaBuilder)
                ->enum('status', 'Publication status.', ['draft', 'publish'], default: 'draft')
                ->toArray();

            expect($schema['properties']['status'])->toBe([
                'type' => 'string',
                'enum' => ['draft', 'publish'],
                'description' => 'Publication status.',
                'default' => 'draft',
            ]);
        });

        it('builds a string carrying a format annotation', function (): void {
            $schema = (new SchemaBuilder)
                ->string('url', 'Source URL.', format: 'uri')
                ->toArray();

            expect($schema['properties']['url']['format'])->toBe('uri');
        });

        it('builds a list', function (): void {
            $schema = (new SchemaBuilder)
                ->list('tags', 'Tag slugs to attach.')
                ->toArray();

            expect($schema['properties']['tags'])->toBe([
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Tag slugs to attach.',
            ]);
        });

        it('builds a free-form map', function (): void {
            $schema = (new SchemaBuilder)
                ->map('terms', 'Taxonomy slug to term slugs.', ['type' => 'array'])
                ->toArray();

            expect($schema['properties']['terms']['additionalProperties'])->toBe(['type' => 'array']);
        });

        it('omits additionalProperties on a map with no value schema', function (): void {
            $schema = (new SchemaBuilder)->map('meta', 'Arbitrary metadata.')->toArray();

            expect($schema['properties']['meta'])->toBe([
                'type' => 'object',
                'description' => 'Arbitrary metadata.',
            ]);
        });

        it('builds a nested object from its own builder', function (): void {
            $schema = (new SchemaBuilder)
                ->object('author', 'The post author.', function (SchemaBuilder $author): void {
                    $author->integer('id', 'User identifier.', required: true);
                })
                ->toArray();

            expect($schema['properties']['author'])->toBe([
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'User identifier.'],
                ],
                'required' => ['id'],
                'description' => 'The post author.',
            ]);
        });

        it('passes a raw fragment through untouched', function (): void {
            $fragment = ['oneOf' => [['type' => 'string'], ['type' => 'integer']]];

            $schema = (new SchemaBuilder)->raw('id', $fragment, required: true)->toArray();

            expect($schema['properties']['id'])->toBe($fragment)
                ->and($schema['required'])->toBe(['id']);
        });
    });

    it('omits a null default rather than declaring one', function (): void {
        $schema = (new SchemaBuilder)->string('title', 'The title.')->toArray();

        expect($schema['properties']['title'])->not->toHaveKey('default');
    });
});
