<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Definitions\DefinitionRefused;
use App\Models\Admin;
use App\Models\ContentDefinition;

/**
 * The life of a published definition: on offer (active), withdrawn from the offer, and archived. A publisher moves it; the move changes what the next pack is made
 * of and nothing before that. A definition is withdrawn and not deleted, because the cars and the parts of the players are made from it; only one that nothing
 * uses, in the content or among the players, can be archived.
 */
final class DefinitionLifecycle
{
    public function __construct(private readonly DefinitionUsage $usage, private readonly AdminAudit $audit) {}

    /** @throws DefinitionRefused not_publisher, unpublished or wrong_status */
    public function withdraw(ContentDefinition $definition, Admin $admin): ContentDefinition
    {
        return $this->move($definition, $admin, [ContentDefinition::STATUS_ACTIVE], ContentDefinition::STATUS_WITHDRAWN);
    }

    /** Puts a withdrawn or archived definition back on offer. @throws DefinitionRefused not_publisher, unpublished or wrong_status */
    public function restore(ContentDefinition $definition, Admin $admin): ContentDefinition
    {
        return $this->move($definition, $admin, [ContentDefinition::STATUS_WITHDRAWN, ContentDefinition::STATUS_ARCHIVED], ContentDefinition::STATUS_ACTIVE);
    }

    /**
     * Takes a definition out of the packs. It has to be withdrawn first (a definition that is on offer is not archived in one step), and nothing may use it.
     *
     * @throws DefinitionRefused not_publisher, unpublished, wrong_status, in_use or referenced
     */
    public function archive(ContentDefinition $definition, Admin $admin): ContentDefinition
    {
        $this->guard($definition, $admin);

        if ($definition->status === ContentDefinition::STATUS_WITHDRAWN) {
            $instances = $this->usage->instances($definition);

            if ($instances !== []) {
                throw new DefinitionRefused('in_use', ['slug' => $definition->slug, 'what' => $this->describe($instances)]);
            }

            $places = $this->usage->references($definition);

            if ($places !== []) {
                throw new DefinitionRefused('referenced', ['slug' => $definition->slug, 'where' => implode(', ', $places)]);
            }
        }

        return $this->move($definition, $admin, [ContentDefinition::STATUS_WITHDRAWN], ContentDefinition::STATUS_ARCHIVED);
    }

    /**
     * @param  list<string>  $from
     */
    private function move(ContentDefinition $definition, Admin $admin, array $from, string $to): ContentDefinition
    {
        $this->guard($definition, $admin);

        if (! in_array($definition->status, $from, true)) {
            throw new DefinitionRefused('wrong_status', ['slug' => $definition->slug, 'status' => $definition->status, 'to' => $to]);
        }

        $before = $definition->status;
        $definition->forceFill(['status' => $to])->save();
        $this->audit->record('definition_status_changed', $admin, "{$definition->kind}:{$definition->slug}", ['from' => $before, 'to' => $to]);

        return $definition;
    }

    private function guard(ContentDefinition $definition, Admin $admin): void
    {
        if (! $admin->isPublisher()) {
            throw new DefinitionRefused('not_publisher');
        }

        if ($definition->versions()->doesntExist()) {
            throw new DefinitionRefused('unpublished', ['slug' => $definition->slug]);
        }
    }

    /**
     * @param  array<string, int>  $instances
     */
    private function describe(array $instances): string
    {
        return implode(', ', array_map(fn (string $what, int $count): string => "{$count} {$what}", array_keys($instances), $instances));
    }
}
