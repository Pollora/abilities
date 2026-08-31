<?php

declare(strict_types=1);

namespace Pollora\Abilities\Domain\Model;

/**
 * Behaviour hints attached to an ability and published under `meta.annotations`.
 *
 * Prefer building these through {@see Behaviour::annotations()} rather than by
 * hand: the constructor accepts combinations that do not describe anything real,
 * such as an ability that is both read-only and destructive.
 *
 * Instances are immutable.
 */
final readonly class Annotations
{
    /**
     * @param  bool  $readonly  Whether the ability leaves the site unchanged.
     * @param  bool  $destructive  Whether the ability may remove or overwrite existing data,
     *                             as opposed to only adding to it.
     * @param  bool  $idempotent  Whether repeating the call with identical arguments has no
     *                            further effect beyond the first.
     */
    public function __construct(
        public bool $readonly = false,
        public bool $destructive = false,
        public bool $idempotent = false,
    ) {}

    /**
     * Render in the shape the Abilities API expects under `meta.annotations`.
     *
     * @return array{readonly: bool, destructive: bool, idempotent: bool} The annotation map.
     */
    public function toArray(): array
    {
        return [
            'readonly' => $this->readonly,
            'destructive' => $this->destructive,
            'idempotent' => $this->idempotent,
        ];
    }
}
