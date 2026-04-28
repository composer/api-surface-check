<?php

declare(strict_types=1);

namespace Composer\ApiSurfaceCheck\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Drives scripts/preflight.sh against synthetic two-commit git repos and
 * asserts the verdict.
 */
final class PreflightTest extends TestCase
{
    private string $repoDir;

    protected function setUp(): void
    {
        $this->repoDir = sys_get_temp_dir() . '/api-surface-preflight-' . bin2hex(random_bytes(4));
        mkdir($this->repoDir, 0o755, true);
        $this->shell('git init -q');
        $this->shell('git -c user.email=t@t -c user.name=t commit -q --allow-empty -m bootstrap');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->repoDir)) {
            $this->rrmdir($this->repoDir);
        }
    }

    private function commit(string $relativePath, string $contents, string $message): void
    {
        $full = $this->repoDir . '/' . $relativePath;
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0o755, true);
        }
        file_put_contents($full, $contents);
        $this->shell('git add -A');
        $this->shell(sprintf('git -c user.email=t@t -c user.name=t commit -q -m %s', escapeshellarg($message)));
    }

    private function verdict(string $pathsGlob = 'src/**/*.php'): string
    {
        $script = realpath(__DIR__ . '/../scripts/preflight.sh');
        $output = $this->repoDir . '/preflight.txt';

        $cmd = sprintf(
            'BASE_REF=HEAD~1 PATHS_GLOB=%s OUTPUT_FILE=%s bash %s 2>&1',
            escapeshellarg($pathsGlob),
            escapeshellarg($output),
            escapeshellarg($script),
        );
        $this->shell($cmd);

        return trim(file_get_contents($output));
    }

    private function shell(string $cmd): string
    {
        $current = getcwd();
        chdir($this->repoDir);
        try {
            $output = '';
            $status = 0;
            exec($cmd . ' 2>&1', $lines, $status);
            $output = implode("\n", $lines);
            if ($status !== 0) {
                $this->fail("Command failed (exit {$status}): {$cmd}\n{$output}");
            }
            return $output;
        } finally {
            chdir($current);
        }
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testNoChangesProducesFalse(): void
    {
        $this->shell('git -c user.email=t@t -c user.name=t commit -q --allow-empty -m noop');
        $this->assertSame('false', $this->verdict());
    }

    public function testNewClassProducesTrue(): void
    {
        $this->commit('src/Foo.php', "<?php\nclass Foo {}\n", 'add foo');
        $this->assertSame('true', $this->verdict());
    }

    public function testNewMethodProducesTrue(): void
    {
        $this->commit('src/Foo.php', "<?php\nclass Foo { public function a() {} }\n", 'base');
        $this->commit('src/Foo.php', "<?php\nclass Foo {\n    public function a() {}\n    public function b() {}\n}\n", 'add b');
        $this->assertSame('true', $this->verdict());
    }

    public function testInternalAnnotationAddedProducesTrue(): void
    {
        $this->commit('src/Foo.php', "<?php\nclass Foo {\n    public function a() {}\n}\n", 'base');
        $this->commit('src/Foo.php', "<?php\nclass Foo {\n    /** @internal */\n    public function a() {}\n}\n", 'mark internal');
        $this->assertSame('true', $this->verdict());
    }

    public function testExtendsChangedProducesTrue(): void
    {
        $this->commit('src/Foo.php', "<?php\nclass Foo {}\n", 'base');
        $this->commit('src/Foo.php', "<?php\nclass Foo extends Bar {}\n", 'add extends');
        $this->assertSame('true', $this->verdict());
    }

    public function testImplementsAddedProducesTrue(): void
    {
        $this->commit('src/Foo.php', "<?php\nclass Foo {}\n", 'base');
        $this->commit('src/Foo.php', "<?php\nclass Foo implements Bar {}\n", 'add implements');
        $this->assertSame('true', $this->verdict());
    }

    public function testPropertyAddedProducesTrue(): void
    {
        $this->commit('src/Foo.php', "<?php\nclass Foo {}\n", 'base');
        $this->commit('src/Foo.php', "<?php\nclass Foo {\n    public string \$bar;\n}\n", 'add property');
        $this->assertSame('true', $this->verdict());
    }

    public function testMethodBodyOnlyChangeProducesFalse(): void
    {
        $this->commit(
            'src/Foo.php',
            "<?php\nclass Foo {\n    public function compute() {\n        return 1 + 1;\n    }\n}\n",
            'base',
        );
        $this->commit(
            'src/Foo.php',
            "<?php\nclass Foo {\n    public function compute() {\n        return 1 + 2;\n    }\n}\n",
            'tweak body',
        );
        $this->assertSame('false', $this->verdict());
    }

    public function testCommentOnlyChangeProducesFalse(): void
    {
        $this->commit(
            'src/Foo.php',
            "<?php\nclass Foo {\n    // old comment\n    \$x = 1;\n}\n",
            'base',
        );
        $this->commit(
            'src/Foo.php',
            "<?php\nclass Foo {\n    // new comment text only\n    \$x = 1;\n}\n",
            'comment',
        );
        $this->assertSame('false', $this->verdict());
    }

    public function testWhitespaceOnlyChangeProducesFalse(): void
    {
        $this->commit('src/Foo.php', "<?php\nclass Foo {\n    \$x = 1;\n}\n", 'base');
        $this->commit('src/Foo.php', "<?php\nclass Foo {\n    \$x   =   1;\n}\n", 'whitespace');
        $this->assertSame('false', $this->verdict());
    }

    public function testChangesOutsideScopeProducesFalse(): void
    {
        $this->commit('docs/README.md', "old\n", 'base');
        $this->commit('docs/README.md', "new\n", 'docs');
        $this->assertSame('false', $this->verdict());
    }
}
