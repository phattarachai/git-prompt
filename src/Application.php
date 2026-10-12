<?php

namespace Phattarachai\GitPrompt;

use Composer\InstalledVersions;
use Phattarachai\GitPrompt\Actions\Cleanup;
use Phattarachai\GitPrompt\Actions\Commit;
use Phattarachai\GitPrompt\Actions\FileChanges;
use Phattarachai\GitPrompt\Actions\NewBranch;
use Phattarachai\GitPrompt\Actions\Palette;
use Phattarachai\GitPrompt\Actions\Push;
use Phattarachai\GitPrompt\Actions\Stash;
use Phattarachai\GitPrompt\Actions\Status;
use Phattarachai\GitPrompt\Actions\Update;
use Phattarachai\GitPrompt\Prompts\Cancelled;
use Phattarachai\GitPrompt\Prompts\Escape;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

class Application
{
    /**
     * @var array<string, array{0: class-string<Actions\Action>, 1: string}>
     */
    private const array COMMANDS = [
        'b' => [Palette::class, 'Branches only'],
        's' => [Status::class, 'Status'],
        'up' => [Update::class, 'Update Project (fetch + pull)'],
        'c' => [Commit::class, 'Commit'],
        'p' => [Push::class, 'Push'],
        'st' => [Stash::class, 'Stash'],
        'n' => [NewBranch::class, 'New branch'],
        'd' => [Cleanup::class, 'Delete many branches (local and remote)'],
        'v' => [FileChanges::class, 'View changes — pick a file, read its diff'],
        'w' => [Palette::class, 'Worktrees — what each one changed, and files edited in more than one'],
    ];

    /**
     * @param  list<string>  $arguments  argv without the script name
     */
    public function run(array $arguments, string $cwd): int
    {
        $command = $arguments[0] ?? '';

        if (in_array($command, ['-h', '--help', 'help'], true)) {
            $this->help();

            return 0;
        }

        if (in_array($command, ['-V', '--version'], true)) {
            echo 'git-prompt '.self::version().PHP_EOL;

            return 0;
        }

        if ($command !== '' && ! isset(self::COMMANDS[$command])) {
            $this->help();

            return 1;
        }

        $git = new Git($cwd);

        if (! $git->isRepository()) {
            error('Not inside a git repository.');

            return 1;
        }

        $console = new Console($git);

        $palette = new Palette($git, $console);

        Escape::install();

        if ($command === 'w' && count($git->worktreeList()) < 2) {
            info('This repository has only one worktree — `g s` shows its changes.');

            return 0;
        }

        if (in_array($command, ['', 'b', 'w'], true)) {
            return $palette(branchesOnly: $command === 'b', startTab: $command === 'w' ? Palette::WORKTREES_TAB : 0) ? 0 : 1;
        }

        $palette->header();

        try {
            $action = new (self::COMMANDS[$command][0])($git, $console);

            return ($action instanceof FileChanges ? $action->current() : $action()) ? 0 : 1;
        } catch (Cancelled) {
            return 0;
        }
    }

    private function help(): void
    {
        $lines = [
            'git-prompt '.self::version().' — git through a search box.',
            '',
            'Usage:',
            '  g          Search branches and actions',
        ];

        foreach (self::COMMANDS as $name => [, $description]) {
            $lines[] = sprintf('  g %-8s %s', $name, $description);
        }

        array_push(
            $lines,
            '',
            'Config (git config --global …):',
            '  gitprompt.protected   Pinned on top, in this order, and need their name typed back (default: main master develop)',
            '  gitprompt.update      rebase | merge — how a diverged branch updates (default: ask)',
            '  gitprompt.sort        recent | name — order after the protected branches (default: recent)',
            '',
            'Keys: type to filter every list · Enter pick · Esc back · Ctrl+C quit',
        );

        echo implode(PHP_EOL, $lines).PHP_EOL;
    }

    private static function version(): string
    {
        $package = 'phattarachai/git-prompt';

        if (! InstalledVersions::isInstalled($package) || InstalledVersions::getRootPackage()['name'] === $package) {
            return 'dev';
        }

        return (string) InstalledVersions::getPrettyVersion($package);
    }
}
