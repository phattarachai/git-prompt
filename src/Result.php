<?php

namespace Phattarachai\GitPrompt;

final readonly class Result
{
    public function __construct(
        public string $command,
        public int $exitCode,
        public string $output,
        public string $errorOutput,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }

    /**
     * Stdout and stderr together — git writes progress and most messages to stderr.
     */
    public function allOutput(): string
    {
        return trim($this->output."\n".$this->errorOutput);
    }

    public function mentions(string ...$needles): bool
    {
        $haystack = strtolower($this->allOutput());

        foreach ($needles as $needle) {
            if (str_contains($haystack, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
