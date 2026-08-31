<?php

declare(strict_types=1);

namespace Pollora\Abilities\Domain\Model;

/**
 * What an ability does to the site, expressed as one of four shapes.
 *
 * The WordPress Abilities API carries behaviour hints through `meta.annotations`,
 * and consumers such as the MCP Adapter turn them into the `readOnlyHint`,
 * `destructiveHint` and `idempotentHint` tool annotations. A client uses those to
 * decide how much ceremony an invocation deserves: a read may run unattended, a
 * delete should be confirmed with the user first.
 *
 * Getting the three booleans wrong is worse than omitting them, so this enum
 * encodes the combinations that actually occur instead of leaving every ability
 * to reason them out. {@see Annotations} is the resulting value object.
 *
 * The hints are advisory and are never enforced by WordPress. The permission
 * callback is what protects the site.
 */
enum Behaviour: string
{
    /** Reads without changing anything. Safe to repeat, safe to run unattended. */
    case Reads = 'reads';

    /** Adds something new on every call. Two calls create two records. */
    case Creates = 'creates';

    /** Overwrites part of an existing record. Repeatable, but the previous value is gone. */
    case Updates = 'updates';

    /** Removes a record. */
    case Deletes = 'deletes';

    /**
     * Whether the ability leaves the site unchanged.
     *
     * @return bool True only for {@see self::Reads}.
     */
    public function isReadOnly(): bool
    {
        return $this === self::Reads;
    }

    /**
     * Behaviour hints in the shape the Abilities API expects.
     *
     * @return Annotations The annotations matching this behaviour.
     */
    public function annotations(): Annotations
    {
        return match ($this) {
            self::Reads => new Annotations(readonly: true, destructive: false, idempotent: true),
            self::Creates => new Annotations(readonly: false, destructive: false, idempotent: false),
            self::Updates => new Annotations(readonly: false, destructive: true, idempotent: true),
            self::Deletes => new Annotations(readonly: false, destructive: true, idempotent: true),
        };
    }
}
