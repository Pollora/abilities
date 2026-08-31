<?php

declare(strict_types=1);

namespace Pollora\Abilities\Domain\Model;

use Pollora\Abilities\Domain\Exception\InvalidAbilityException;

/**
 * A category abilities are filed under.
 *
 * Category slugs are global to the install, and WordPress core already claims
 * several of them. A collision is not benign: the second registration is refused
 * and every ability pointing at the category fails to register with it. Namespace
 * the slug with something the project owns.
 *
 * Instances are immutable.
 */
final readonly class AbilityCategory
{
    /**
     * @param  string  $slug  Globally unique slug, lowercase with dashes.
     * @param  string  $label  Human-readable name shown wherever categories are listed.
     * @param  string  $description  What belongs in the category.
     */
    private function __construct(
        public string $slug,
        public string $label,
        public string $description,
    ) {}

    /**
     * Describe a category.
     *
     * @param  string  $slug  Globally unique slug, lowercase with dashes.
     * @param  string  $label  Human-readable name. Falls back to a title-cased slug when empty.
     * @param  string  $description  What belongs in the category. Falls back to one derived from
     *                               the label when empty: WordPress rejects a category with a
     *                               blank description, and does it by returning null rather than
     *                               raising, so an empty one would vanish without a word.
     * @return self The category.
     *
     * @throws InvalidAbilityException When the slug is empty or malformed.
     */
    public static function make(string $slug, string $label = '', string $description = ''): self
    {
        $slug = trim($slug);

        if ($slug === '') {
            throw InvalidAbilityException::missingProperty('(category)', 'slug');
        }

        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            throw InvalidAbilityException::malformedName(
                $slug,
                'a category slug must be lowercase alphanumerics separated by single dashes',
            );
        }

        $label = trim($label) !== '' ? $label : ucwords(str_replace('-', ' ', $slug));
        $description = trim($description) !== '' ? $description : sprintf('%s abilities.', $label);

        return new self($slug, $label, $description);
    }

    /**
     * Render in the shape `wp_register_ability_category()` expects.
     *
     * @return array{label: string, description: string} The category arguments.
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'description' => $this->description,
        ];
    }
}
