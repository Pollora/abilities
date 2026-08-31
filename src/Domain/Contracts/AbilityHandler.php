<?php

declare(strict_types=1);

namespace Pollora\Abilities\Domain\Contracts;

use Pollora\Abilities\Domain\Model\Input;
use Pollora\Abilities\Domain\Schema\SchemaBuilder;

/**
 * A class that implements one ability.
 *
 * This is the form to reach for in application code. Everything an ability needs
 * lives in one class with a typed signature — the schema it accepts, the check
 * that guards it, and the body that runs — which makes it straightforward to
 * unit-test without registering anything.
 *
 * In a Pollora project, decorate the class with `#[Ability]` and the discovery
 * system registers it. Everything the attribute does not carry comes from here.
 *
 *     #[Ability('acme/create-post', category: 'acme-content', behaviour: Behaviour::Creates)]
 *     final class CreatePost implements AbilityHandler
 *     {
 *         public function schema(SchemaBuilder $schema): void
 *         {
 *             $schema->string('title', 'Title of the post to create.', required: true);
 *         }
 *
 *         public function authorize(Input $input): bool
 *         {
 *             return current_user_can('edit_posts');
 *         }
 *
 *         public function handle(Input $input): array
 *         {
 *             return ['id' => wp_insert_post(['post_title' => $input->string('title')])];
 *         }
 *     }
 */
interface AbilityHandler
{
    /**
     * Declare the input this ability accepts.
     *
     * Called once at registration, so it may consult registered post types and
     * taxonomies — but not the current user, who is not resolved that early.
     *
     * @param  SchemaBuilder  $schema  Builder to declare properties on. Leave it untouched for
     *                                 an ability that takes no input.
     */
    public function schema(SchemaBuilder $schema): void;

    /**
     * Decide whether the current user may run this ability.
     *
     * Receives the same input as {@see self::handle()}, which is what allows
     * per-object checks — `edit_post` on the identifier being edited, rather than
     * a blanket `edit_posts`.
     *
     * Return a `WP_Error` instead of `false` to explain the refusal; a client that
     * knows *why* it was refused can act on it, where a bare denial leaves the
     * model guessing.
     *
     * @param  Input  $input  The validated, wrapped input.
     * @return mixed True to allow, false to refuse, or a `WP_Error` carrying the reason.
     */
    public function authorize(Input $input): mixed;

    /**
     * Run the ability.
     *
     * Only reached once {@see self::authorize()} has allowed it.
     *
     * @param  Input  $input  The validated, wrapped input.
     * @return mixed Any JSON-serialisable value, or a `WP_Error` to fail the call.
     */
    public function handle(Input $input): mixed;
}
