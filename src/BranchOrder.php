<?php

namespace Phattarachai\GitPrompt;

/**
 * Protected branches first, in the order they are configured (develop, uat, main…),
 * then the other local branches, then remote-only ones — each group by recency or name.
 */
final class BranchOrder
{
    /**
     * @param  list<Branch>  $branches  as {@see Git::branches()} returns them (newest first)
     * @param  list<string>  $pinned
     * @return list<Branch>
     */
    public static function sort(array $branches, array $pinned, string $sort): array
    {
        $top = [];

        foreach ($pinned as $name) {
            foreach ($branches as $branch) {
                if ($branch->name === $name) {
                    $top[] = $branch;

                    break;
                }
            }
        }

        $rest = array_filter($branches, fn (Branch $branch) => ! in_array($branch, $top, true));
        $local = array_values(array_filter($rest, fn (Branch $branch) => ! $branch->isRemote()));
        $remote = array_values(array_filter($rest, fn (Branch $branch) => $branch->isRemote()));

        if ($sort === 'name') {
            $byName = fn (Branch $a, Branch $b) => strnatcasecmp($a->ref(), $b->ref());
            usort($local, $byName);
            usort($remote, $byName);
        }

        return [...$top, ...$local, ...$remote];
    }
}
