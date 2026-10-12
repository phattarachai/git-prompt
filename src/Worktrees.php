<?php

namespace Phattarachai\GitPrompt;

/**
 * What every worktree of the repository is doing, and which files more than one of
 * them touches — the files that will conflict when their branches meet.
 */
final class Worktrees
{
    public function __construct(private readonly Git $git) {}

    /**
     * The current worktree first, then the busiest, clean ones last.
     *
     * @return list<Worktree>
     */
    public function all(): array
    {
        $current = realpath($this->git->topLevel()) ?: $this->git->topLevel();
        $base = $this->git->defaultBase();
        $mainPath = null;
        $worktrees = [];

        foreach ($this->git->worktreeList() as $entry) {
            $mainPath ??= $entry['path'];
            $worktrees[] = $this->inspect($entry, $mainPath, $current, $base);
        }

        usort($worktrees, fn (Worktree $a, Worktree $b) => [! $a->isCurrent, $a->isClean() && $a->ahead === 0, -count($a->uncommitted), -$a->ahead]
            <=> [! $b->isCurrent, $b->isClean() && $b->ahead === 0, -count($b->uncommitted), -$b->ahead]);

        return $worktrees;
    }

    /**
     * Files touched in more than one worktree.
     *
     * @param  list<Worktree>  $worktrees
     * @return array<string, array<string, 'committed'|'uncommitted'>> path => [worktree name => how]
     */
    public static function overlaps(array $worktrees): array
    {
        $byFile = [];

        foreach ($worktrees as $worktree) {
            foreach ($worktree->touchedFiles() as $path => $how) {
                $byFile[$path][$worktree->name] = $how;
            }
        }

        $overlaps = array_filter($byFile, fn (array $names) => count($names) > 1);
        uksort($overlaps, fn (string $a, string $b) => [count($overlaps[$b]), $a] <=> [count($overlaps[$a]), $b]);

        return $overlaps;
    }

    /**
     * @param  array{path: string, branch: ?string, locked: bool, prunable: bool}  $entry
     */
    private function inspect(array $entry, string $mainPath, string $current, ?string $base): Worktree
    {
        $path = $entry['path'];
        $isMain = $path === $mainPath;
        $name = basename($path);
        $isCurrent = (realpath($path) ?: $path) === $current;

        if ($entry['prunable'] || ! is_dir($path)) {
            return new Worktree($path, $name, $entry['branch'], $isMain, $isCurrent, $entry['locked'], isMissing: true);
        }

        $git = new Git($path);
        $compare = $base !== null && $entry['branch'] !== null && ! $this->git->isProtected($entry['branch']);

        return new Worktree(
            path: $path,
            name: $name,
            branch: $entry['branch'],
            isMain: $isMain,
            isCurrent: $isCurrent,
            isLocked: $entry['locked'],
            uncommitted: $git->status(),
            committed: $compare ? $git->committedChanges($base) : [],
            ahead: $compare ? $git->countCommitsBetween($base, 'HEAD') : 0,
            behind: $compare ? $git->countCommitsBetween('HEAD', $base) : 0,
        );
    }
}
