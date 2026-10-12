<?php

namespace Phattarachai\GitPrompt\Actions;

use Phattarachai\GitPrompt\FileStatus;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class Status extends Action
{
    public function __invoke(): bool
    {
        if (($operation = $this->git->operationInProgress()) !== null) {
            warning(ucfirst($operation).' in progress — run `g` and pick Continue or Abort.');
        }

        $files = $this->git->status();

        if ($files === []) {
            info('Working tree clean.');

            return true;
        }

        table(
            headers: ['', 'Change', 'File'],
            rows: array_map(fn (FileStatus $file) => [
                $file->isStaged() ? '●' : '',
                $file->label(),
                $file->display(),
            ], $files),
        );

        $this->console->line('<fg=gray>  ● = staged</>');

        return true;
    }
}
