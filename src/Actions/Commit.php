<?php

namespace Phattarachai\GitPrompt\Actions;

use Phattarachai\GitPrompt\FileStatus;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\text;

class Commit extends Action
{
    public function __invoke(): bool
    {
        $files = $this->git->status();

        if ($files === []) {
            info('Nothing to commit — the working tree is clean.');

            return true;
        }

        if (array_filter($files, fn (FileStatus $file) => $file->isConflicted()) !== []) {
            error('Resolve the conflicts first — run `g` and pick Continue.');

            return false;
        }

        $byPath = [];

        foreach ($files as $file) {
            $byPath[$file->path] = $file;
        }

        $staged = array_keys(array_filter($byPath, fn (FileStatus $file) => $file->isStaged()));

        $selected = multiselect(
            label: 'Files to commit',
            options: array_map(fn (FileStatus $file) => sprintf('%-8s %s', $file->label(), $file->display()), $byPath),
            default: $staged !== [] ? $staged : array_keys($byPath),
            scroll: 15,
            required: 'Pick at least one file.',
            hint: 'Space toggles a file, Enter confirms.',
        );

        $message = text(label: 'Commit message', required: true);

        $paths = array_merge(...array_map(fn (string $path) => $byPath[$path]->paths(), $selected));

        $result = $this->console->runAll([
            ['add', '--all', '--', ...$paths],
            ['commit', '-m', $message, '--', ...$paths],
        ]);

        if ($result->failed()) {
            error('Commit failed — a hook may have rejected it.');

            return false;
        }

        info('Committed '.count($selected).' file(s).');

        if (confirm('Push now?', default: false)) {
            return $this->make(Push::class)();
        }

        return true;
    }
}
