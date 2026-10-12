<?php

namespace Phattarachai\GitPrompt\Actions;

use Phattarachai\GitPrompt\Prompts\Menu;
use Phattarachai\GitPrompt\Result;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

/**
 * What happens after a merge, rebase or update stops on conflicts, and how to finish it.
 */
class Conflicts extends Action
{
    /**
     * Report a failed merge/rebase. Offers to abort when it stopped on conflicts.
     */
    public function handle(Result $result, string $what): bool
    {
        $operation = $this->git->operationInProgress();

        if ($operation === null || $this->git->conflictedFiles() === []) {
            error("{$what} failed.");

            return false;
        }

        warning("{$what} stopped on conflicts.");
        $this->list();

        $choice = Menu::choose(
            label: 'What now?',
            options: [
                'resolve' => 'Resolve them in my editor — then run `g` and pick Continue',
                'abort' => "Abort the {$operation} — put everything back",
            ],
        );

        if ($choice === 'abort') {
            $this->abort();
        }

        return false;
    }

    public function list(): void
    {
        foreach ($this->git->conflictedFiles() as $file) {
            $this->console->line("  <fg=red>✕</> {$file}");
        }
    }

    public function continue(): bool
    {
        $operation = $this->git->operationInProgress();

        if ($operation === null) {
            info('Nothing in progress.');

            return true;
        }

        $conflicted = $this->git->conflictedFiles();
        $unresolved = array_values(array_filter($conflicted, $this->hasConflictMarkers(...)));

        if ($unresolved !== []) {
            error('These files still have conflict markers (<<<<<<< / >>>>>>>):');

            foreach ($unresolved as $file) {
                $this->console->line("  <fg=red>✕</> {$file}");
            }

            return false;
        }

        if ($conflicted !== [] && $this->console->run(['add', '--', ...$conflicted])->failed()) {
            error('Could not stage the resolved files.');

            return false;
        }

        $result = $this->console->run(match ($operation) {
            'merge' => ['commit', '--no-edit'],
            default => [$operation, '--continue'],
        });

        if ($result->successful() && $this->git->operationInProgress() === null) {
            info(ucfirst($operation).' finished.');

            return true;
        }

        return $this->handle($result, ucfirst($operation));
    }

    public function abort(): bool
    {
        $operation = $this->git->operationInProgress();

        if ($operation === null) {
            info('Nothing in progress.');

            return true;
        }

        if ($this->console->run([$operation, '--abort'])->failed()) {
            error("Could not abort the {$operation}.");

            return false;
        }

        info(ucfirst($operation).' aborted — back to where you started.');

        return true;
    }

    private function hasConflictMarkers(string $file): bool
    {
        $contents = @file_get_contents($this->git->topLevel().'/'.$file);

        return $contents !== false && preg_match('/^(<{7}|>{7})( |$)/m', $contents) === 1;
    }
}
