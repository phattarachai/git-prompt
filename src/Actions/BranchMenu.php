<?php

namespace Phattarachai\GitPrompt\Actions;

use Phattarachai\GitPrompt\Branch;
use Phattarachai\GitPrompt\Prompts\Menu;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\text;

/**
 * Everything you can do with one branch — the submenu of the PHPStorm popup.
 */
class BranchMenu extends Action
{
    public function __invoke(Branch $branch): bool
    {
        $options = $this->options($branch, $this->git->currentBranch());

        $choice = Menu::choose($branch->ref(), $options);

        $integrate = $this->make(Integrate::class);

        return match ($choice) {
            'checkout' => $this->make(Checkout::class)($branch),
            'checkout-update' => $this->make(Checkout::class)($branch) && $this->make(Update::class)(),
            'checkout-rebase' => $integrate->checkoutAndRebase($branch),
            'update' => $this->make(Update::class)(),
            'push' => $this->make(Push::class)(),
            'new' => $this->make(NewBranch::class)($branch),
            'compare' => $this->compare($branch),
            'merge' => $integrate->merge($branch),
            'rebase' => $integrate->rebaseOnto($branch),
            'rename' => $this->rename($branch),
            'delete' => $this->delete($branch),
            default => true,
        };
    }

    /**
     * @return array<string, string>
     */
    private function options(Branch $branch, ?string $current): array
    {
        $ref = $branch->ref();
        $other = ! $branch->isCurrent && $current !== null;

        return array_filter([
            'checkout' => $branch->isCurrent ? null : 'Checkout',
            'checkout-update' => $other ? 'Checkout and Update' : null,
            'checkout-rebase' => $other ? "Checkout and Rebase onto '{$current}'" : null,
            'update' => $branch->isCurrent ? 'Update' : null,
            'push' => $branch->isCurrent ? 'Push…' : null,
            'new' => "New Branch from '{$ref}'…",
            'compare' => $other ? "Compare with '{$current}'" : null,
            'merge' => $other ? "Merge '{$ref}' into '{$current}'" : null,
            'rebase' => $other ? "Rebase '{$current}' onto '{$ref}'" : null,
            'rename' => $branch->isRemote() ? null : 'Rename…',
            'delete' => $branch->isCurrent ? null : 'Delete…',
        ]);
    }

    private function compare(Branch $branch): bool
    {
        $current = (string) $this->git->currentBranch();
        $incoming = $this->git->commitsBetween($current, $branch->ref());
        $outgoing = $this->git->commitsBetween($branch->ref(), $current);

        note("In '{$branch->ref()}' but not in '{$current}' (".count($incoming).')');
        $this->printCommits($incoming);

        note("In '{$current}' but not in '{$branch->ref()}' (".count($outgoing).')');
        $this->printCommits($outgoing);

        return true;
    }

    /**
     * @param  list<string>  $commits
     */
    private function printCommits(array $commits): void
    {
        foreach ($commits === [] ? ['—'] : $commits as $commit) {
            $this->console->line("  {$commit}");
        }
    }

    private function rename(Branch $branch): bool
    {
        $name = text(
            label: "Rename '{$branch->name}' to",
            default: $branch->name,
            required: true,
            validate: fn (string $value) => $this->git->isValidBranchName($value) ? null : 'Not a valid branch name.',
        );

        if ($name === $branch->name) {
            return true;
        }

        if ($this->console->run(['branch', '--move', $branch->name, $name])->failed()) {
            error('Rename failed.');

            return false;
        }

        info("Renamed to '{$name}'.");

        if ($branch->upstream !== null) {
            $this->console->line("  The remote branch '{$branch->upstream}' keeps its old name.");
        }

        return true;
    }

    private function delete(Branch $branch): bool
    {
        if ($this->git->isProtected($branch->name) && ! $this->console->confirmTyped($branch->name, "Delete protected '{$branch->ref()}'")) {
            return false;
        }

        if ($branch->isRemote()) {
            return confirm("Delete '{$branch->ref()}' on the remote? Everyone loses it.", default: false)
                && $this->deleteRemote((string) $branch->remote, $branch->name);
        }

        if (! confirm("Delete local branch '{$branch->name}'?")) {
            return false;
        }

        $result = $this->console->run(['branch', '--delete', $branch->name]);

        if ($result->failed() && $result->mentions('not fully merged')) {
            if (! confirm("'{$branch->name}' has commits that are not merged anywhere. Delete anyway?", default: false)) {
                return false;
            }

            $result = $this->console->run(['branch', '--delete', '--force', $branch->name]);
        }

        if ($result->failed()) {
            error("Could not delete '{$branch->name}'.");

            return false;
        }

        info("Deleted '{$branch->name}'.");

        if ($branch->upstream !== null && ! $branch->upstreamGone && confirm("Also delete '{$branch->upstream}' on the remote?", default: false)) {
            [$remote, $name] = explode('/', $branch->upstream, 2);

            return $this->deleteRemote($remote, $name);
        }

        return true;
    }

    private function deleteRemote(string $remote, string $name): bool
    {
        if ($this->console->run(['push', $remote, '--delete', $name], 'Deleting…')->failed()) {
            error("Could not delete '{$remote}/{$name}'.");

            return false;
        }

        info("Deleted '{$remote}/{$name}'.");

        return true;
    }
}
