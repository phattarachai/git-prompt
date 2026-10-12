<?php

namespace Phattarachai\GitPrompt\Actions;

use Phattarachai\GitPrompt\Worktree;
use Phattarachai\GitPrompt\Worktrees;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;

/**
 * Files edited in more than one worktree — likely conflicts once the branches meet.
 */
class Overlaps extends Action
{
    /**
     * @param  list<Worktree>  $worktrees
     */
    public function __invoke(array $worktrees): bool
    {
        $overlaps = Worktrees::overlaps($worktrees);

        if ($overlaps === []) {
            info('No file is edited in more than one worktree.');

            return true;
        }

        table(
            headers: ['File', 'Worktrees'],
            rows: array_map(
                fn (string $path, array $names) => [$path, implode(', ', array_map(
                    fn (string $name, string $how) => $how === 'committed' ? "{$name} (committed)" : $name,
                    array_keys($names),
                    $names,
                ))],
                array_keys($overlaps),
                $overlaps,
            ),
        );

        $this->console->line('<fg=gray>  (committed) = already committed on that branch; otherwise still being edited.</>');

        return true;
    }
}
