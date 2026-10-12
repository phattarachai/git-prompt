<?php

namespace Phattarachai\GitPrompt;

use Phattarachai\GitPrompt\Prompts\Output;
use Symfony\Component\Console\Formatter\OutputFormatter;

use function Laravel\Prompts\error;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

/**
 * Runs git commands in front of the user: every command is echoed before it runs,
 * so the tool teaches the git it is doing instead of hiding it.
 */
class Console
{
    private const int MAX_OUTPUT_LINES = 15;

    public function __construct(public readonly Git $git) {}

    /**
     * @param  list<string>  $arguments
     */
    public function run(array $arguments, ?string $spinner = null): Result
    {
        $this->line('<fg=gray>  → '.OutputFormatter::escape(Git::describe($arguments)).'</>');

        $result = $spinner === null
            ? $this->git->run($arguments)
            : spin(fn () => $this->git->run($arguments), $spinner);

        $this->echoOutput($result->allOutput());

        return $result;
    }

    /**
     * Run a chain of commands, stopping at the first failure.
     *
     * @param  list<list<string>>  $commands
     */
    public function runAll(array $commands): Result
    {
        $result = null;

        foreach ($commands as $arguments) {
            $result = $this->run($arguments);

            if ($result->failed()) {
                return $result;
            }
        }

        return $result ?? new Result('', 0, '', '');
    }

    public function line(string $message = ''): void
    {
        Output::writeln($message);
    }

    /**
     * Protected branches need their name typed back before anything touches them.
     */
    public function confirmTyped(string $branch, string $action): bool
    {
        $typed = text(
            label: "{$action} — type '{$branch}' to confirm",
            hint: 'Leave empty to cancel.',
        );

        if ($typed === $branch) {
            return true;
        }

        error('Cancelled — the name did not match.');

        return false;
    }

    private function echoOutput(string $output): void
    {
        if ($output === '') {
            return;
        }

        $lines = explode("\n", $output);
        $hidden = count($lines) - self::MAX_OUTPUT_LINES;

        foreach (array_slice($lines, 0, self::MAX_OUTPUT_LINES) as $line) {
            $this->line('<fg=gray>    '.OutputFormatter::escape(rtrim($line)).'</>');
        }

        if ($hidden > 0) {
            $this->line("<fg=gray>    … {$hidden} more lines</>");
        }
    }
}
