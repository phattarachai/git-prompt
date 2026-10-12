<?php

namespace Phattarachai\GitPrompt;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Opens a folder or file in the user's editor and hands the terminal to full-screen tools.
 */
final class Editor
{
    /**
     * `git config --global gitprompt.editor phpstorm`, else the first of phpstorm, pstorm, code, cursor found.
     */
    public static function command(Git $git): ?string
    {
        $configured = $git->value(['config', '--get', 'gitprompt.editor']);

        if ($configured !== '') {
            return $configured;
        }

        $finder = new ExecutableFinder;

        foreach (['phpstorm', 'pstorm', 'code', 'cursor'] as $candidate) {
            if ($finder->find($candidate) !== null) {
                return $candidate;
            }
        }

        return null;
    }

    public static function open(string $editor, string $path): void
    {
        Process::fromShellCommandline($editor.' '.escapeshellarg($path).' >/dev/null 2>&1 &')->run();
    }

    public static function has(string $program): bool
    {
        return (new ExecutableFinder)->find($program) !== null;
    }

    /**
     * Run a program on the real terminal (a pager, lazygit) and wait for it to exit.
     * Without a terminal (tests, pipes) the output is returned instead.
     *
     * @param  list<string>  $command
     */
    public static function interactive(array $command, string $cwd): ?string
    {
        $process = new Process($command, $cwd, timeout: null);

        if (self::$interactive ??= (Process::isTtySupported() && stream_isatty(STDOUT))) {
            $process->setTty(true)->run();

            return null;
        }

        $process->run();

        return $process->getOutput();
    }

    public static ?bool $interactive = null;
}
