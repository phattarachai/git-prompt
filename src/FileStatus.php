<?php

namespace Phattarachai\GitPrompt;

final readonly class FileStatus
{
    public function __construct(
        public string $index,
        public string $worktree,
        public string $path,
        public ?string $originalPath = null,
    ) {}

    public function isUntracked(): bool
    {
        return $this->index === '?' && $this->worktree === '?';
    }

    public function isStaged(): bool
    {
        return ! in_array($this->index, [' ', '?', '!'], true) && ! $this->isConflicted();
    }

    public function isConflicted(): bool
    {
        return $this->index === 'U' || $this->worktree === 'U'
            || ($this->index === 'A' && $this->worktree === 'A')
            || ($this->index === 'D' && $this->worktree === 'D');
    }

    public function code(): string
    {
        return $this->index.$this->worktree;
    }

    public function label(): string
    {
        return match (true) {
            $this->isUntracked() => 'new',
            $this->isConflicted() => 'conflict',
            in_array('D', [$this->index, $this->worktree], true) => 'deleted',
            $this->index === 'R' => 'renamed',
            $this->index === 'A' => 'added',
            default => 'modified',
        };
    }

    /**
     * Every path a commit of this file touches — a rename touches both sides.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return $this->originalPath === null ? [$this->path] : [$this->path, $this->originalPath];
    }

    public function display(): string
    {
        return $this->originalPath === null ? $this->path : "{$this->originalPath} → {$this->path}";
    }
}
