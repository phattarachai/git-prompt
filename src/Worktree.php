<?php

namespace Phattarachai\GitPrompt;

final readonly class Worktree
{
    /**
     * @param  list<FileStatus>  $uncommitted
     * @param  list<array{status: string, path: string}>  $committed  changes since the base branch
     */
    public function __construct(
        public string $path,
        public string $name,
        public ?string $branch,
        public bool $isMain,
        public bool $isCurrent,
        public bool $isLocked,
        public array $uncommitted = [],
        public array $committed = [],
        public int $ahead = 0,
        public int $behind = 0,
        public bool $isMissing = false,
    ) {}

    public function isClean(): bool
    {
        return $this->uncommitted === [];
    }

    /**
     * Every file this worktree's branch touches, committed or not.
     *
     * @return array<string, 'committed'|'uncommitted'>
     */
    public function touchedFiles(): array
    {
        $files = [];

        foreach ($this->committed as $change) {
            $files[$change['path']] = 'committed';
        }

        foreach ($this->uncommitted as $file) {
            foreach ($file->paths() as $path) {
                $files[$path] = 'uncommitted';
            }
        }

        return $files;
    }

    /**
     * "36 changed · 2 new · ↑3 · ↓2", or "clean".
     */
    public function summary(): string
    {
        if ($this->isMissing) {
            return 'folder missing — prune it';
        }

        $new = count(array_filter($this->uncommitted, fn (FileStatus $file) => $file->isUntracked()));
        $changed = count($this->uncommitted) - $new;

        $parts = array_filter([
            $changed > 0 ? "{$changed} changed" : null,
            $new > 0 ? "{$new} new" : null,
            $this->ahead > 0 ? "↑{$this->ahead}" : null,
            $this->behind > 0 ? "↓{$this->behind}" : null,
        ]);

        return $parts === [] ? 'clean' : implode(' · ', $parts);
    }
}
