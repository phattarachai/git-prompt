<?php

namespace Phattarachai\GitPrompt\Tests;

use Phattarachai\GitPrompt\Console;
use Phattarachai\GitPrompt\Git;
use RuntimeException;
use Symfony\Component\Process\Process;

final class TestRepo
{
    /** @var list<string> */
    private static array $directories = [];

    public readonly Git $git;

    public readonly Console $console;

    private function __construct(public readonly string $path, public readonly string $origin)
    {
        $this->git = new Git($path);
        $this->console = new Console($this->git);
    }

    public static function create(): self
    {
        $root = sys_get_temp_dir().'/git-prompt-'.bin2hex(random_bytes(6));
        mkdir($root);
        self::$directories[] = $root;

        self::exec($root, ['init', '--quiet', '--bare', '--initial-branch=main', 'origin.git']);
        self::exec($root, ['clone', '--quiet', 'origin.git', 'work']);

        $repo = new self(realpath("{$root}/work") ?: "{$root}/work", "{$root}/origin.git");
        $repo->git('config', 'user.name', 'Test');
        $repo->git('config', 'user.email', 'test@example.com');
        $repo->git('switch', '--quiet', '-c', 'main');
        $repo->commit('README.md', "hello\n", 'Initial commit');
        $repo->git('push', '--quiet', '-u', 'origin', 'main');

        return $repo;
    }

    /**
     * A second clone of the same origin — "a teammate" who pushes commits.
     */
    public function teammate(): self
    {
        $path = dirname($this->path).'/teammate-'.bin2hex(random_bytes(3));
        self::exec(dirname($this->path), ['clone', '--quiet', 'origin.git', basename($path)]);

        $teammate = new self($path, $this->origin);
        $teammate->git('config', 'user.name', 'Teammate');
        $teammate->git('config', 'user.email', 'mate@example.com');

        return $teammate;
    }

    public function git(string ...$arguments): string
    {
        return self::exec($this->path, $arguments);
    }

    public function write(string $file, string $contents): self
    {
        if (! is_dir($directory = dirname("{$this->path}/{$file}"))) {
            mkdir($directory, recursive: true);
        }

        file_put_contents("{$this->path}/{$file}", $contents);

        return $this;
    }

    public function read(string $file): string
    {
        return (string) file_get_contents("{$this->path}/{$file}");
    }

    public function commit(string $file, string $contents, string $message): self
    {
        $this->write($file, $contents);
        $this->git('add', $file);
        $this->git('commit', '--quiet', '-m', $message);

        return $this;
    }

    public function current(): string
    {
        return $this->git('symbolic-ref', '--short', 'HEAD');
    }

    public static function cleanUp(): void
    {
        foreach (self::$directories as $directory) {
            (new Process(['rm', '-rf', $directory]))->run();
        }

        self::$directories = [];
    }

    /**
     * @param  list<string>  $arguments
     */
    private static function exec(string $cwd, array $arguments): string
    {
        $process = new Process(['git', ...$arguments], $cwd);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('git '.implode(' ', $arguments).' failed: '.$process->getErrorOutput());
        }

        return trim($process->getOutput());
    }
}
