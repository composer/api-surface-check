<?php

declare(strict_types=1);

namespace Composer\ApiSurfaceCheck\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Full-pipeline integration tests: drives scripts/detect.sh against synthetic
 * two-commit git repos and asserts the rendered comment body. Covers the
 * scenarios that previously caused false positives or regressions.
 */
final class DetectTest extends TestCase
{
    private string $repoDir;

    protected function setUp(): void
    {
        $this->repoDir = sys_get_temp_dir() . '/api-surface-detect-' . bin2hex(random_bytes(4));
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

    /**
     * @param array<string,string> $files Map of relative path => contents.
     */
    private function commit(array $files, string $message): void
    {
        foreach ($files as $relative => $contents) {
            $full = $this->repoDir . '/' . $relative;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0o755, true);
            }
            file_put_contents($full, $contents);
        }
        $this->shell('git add -A');
        $this->shell(sprintf('git -c user.email=t@t -c user.name=t commit -q -m %s', escapeshellarg($message)));
    }

    /**
     * @param array<string,string> $extraEnv
     */
    private function runDetect(array $extraEnv = []): string
    {
        $scriptDir = realpath(__DIR__ . '/../scripts');
        $env = array_merge([
            'BASE_REF' => 'HEAD~1',
            'PATHS_GLOB' => 'src/**/*.php',
            'SOURCE_ROOTS' => 'src',
            'OUTPUT_DIR' => 'result',
        ], $extraEnv);

        $envString = '';
        foreach ($env as $k => $v) {
            $envString .= sprintf('%s=%s ', $k, escapeshellarg($v));
        }

        $this->shell($envString . 'bash ' . escapeshellarg($scriptDir . '/detect.sh'));

        $body = $this->repoDir . '/result/comment-body.txt';
        return is_file($body) ? file_get_contents($body) : '';
    }

    private function shell(string $cmd): string
    {
        $current = getcwd();
        chdir($this->repoDir);
        try {
            $lines = [];
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

    public function testNoSourceChangesProducesEmptyBody(): void
    {
        $this->commit(['src/Foo.php' => "<?php\nnamespace L;\nclass Foo {}\n"], 'base');
        $this->commit(['docs/README.md' => "doc\n"], 'unrelated');

        $this->assertSame('', $this->runDetect());
    }

    public function testNewClassWithPublicMethodIsReported(): void
    {
        $this->commit(['src/Existing.php' => "<?php\nnamespace L;\nclass Existing {}\n"], 'base');
        $this->commit([
            'src/Existing.php' => "<?php\nnamespace L;\nclass Existing {}\n",
            'src/Fresh.php' => "<?php\nnamespace L;\nclass Fresh {\n    public function go(int \$x): bool { return true; }\n}\n",
        ], 'add Fresh');

        $body = $this->runDetect();
        $this->assertStringContainsString('### New API Surface', $body);
        $this->assertStringContainsString('class L\\Fresh', $body);
        $this->assertStringContainsString('L\\Fresh::go', $body);
        $this->assertStringContainsString('public function go(int $x): bool', $body);
    }

    public function testInterfaceImplementationIsNotReportedOnImplementer(): void
    {
        // Mirrors composer/composer PR #12803: a class newly implements an
        // existing interface and adds the implementation methods. The interface
        // is unchanged, so the methods are not "new API surface" — they're
        // just an existing contract being satisfied on another class.
        $this->commit([
            'src/Iface.php' => "<?php\nnamespace L;\ninterface Iface { public function action(): bool; }\n",
            'src/Repo.php' => "<?php\nnamespace L;\nclass Repo { public function existing(): void {} }\n",
        ], 'base');
        $this->commit([
            'src/Iface.php' => "<?php\nnamespace L;\ninterface Iface { public function action(): bool; }\n",
            'src/Repo.php' => "<?php\nnamespace L;\nclass Repo implements Iface {\n    public function existing(): void {}\n    public function action(): bool { return true; }\n}\n",
        ], 'implement Iface on Repo');

        $body = $this->runDetect();
        $this->assertStringNotContainsString('### New API Surface', $body, 'Interface implementations should not be reported as new API surface.');
        $this->assertStringNotContainsString('L\\Repo::action', $body);
    }

    public function testRemovedPublicMethodIsReported(): void
    {
        $this->commit([
            'src/Foo.php' => "<?php\nnamespace L;\nclass Foo {\n    public function keep(): void {}\n    public function remove(): int { return 1; }\n}\n",
        ], 'base');
        $this->commit([
            'src/Foo.php' => "<?php\nnamespace L;\nclass Foo {\n    public function keep(): void {}\n}\n",
        ], 'remove method');

        $body = $this->runDetect();
        $this->assertStringContainsString('### Removed API Surface', $body);
        $this->assertStringContainsString('L\\Foo::remove', $body);
    }

    public function testNewPublicPropertyIsReportedUnderProperties(): void
    {
        $this->commit(['src/Foo.php' => "<?php\nnamespace L;\nclass Foo {}\n"], 'base');
        $this->commit([
            'src/Foo.php' => "<?php\nnamespace L;\nclass Foo {\n    public readonly int \$count;\n}\n",
        ], 'add property');

        $body = $this->runDetect();
        $this->assertStringContainsString('#### Properties', $body);
        $this->assertStringContainsString('L\\Foo::count', $body);
        $this->assertStringContainsString('public readonly int $count', $body);
    }

    public function testParentMethodOverrideIsNotReportedOnChild(): void
    {
        $this->commit([
            'src/Parent_.php' => "<?php\nnamespace L;\nclass Parent_ { public function shared(): void {} }\n",
            'src/Child_.php' => "<?php\nnamespace L;\nclass Child_ extends Parent_ {}\n",
        ], 'base');
        $this->commit([
            'src/Parent_.php' => "<?php\nnamespace L;\nclass Parent_ { public function shared(): void {} }\n",
            'src/Child_.php' => "<?php\nnamespace L;\nclass Child_ extends Parent_ { public function shared(): void {} }\n",
        ], 'override on child');

        $body = $this->runDetect();
        $this->assertSame('', $body, 'Method overrides on child classes should not appear as new API.');
    }

    public function testInternalAnnotationOnExistingMethodRemovesItFromSurface(): void
    {
        $this->commit([
            'src/Foo.php' => "<?php\nnamespace L;\nclass Foo {\n    public function exposed(): void {}\n}\n",
        ], 'base');
        $this->commit([
            'src/Foo.php' => "<?php\nnamespace L;\nclass Foo {\n    /** @internal */\n    public function exposed(): void {}\n}\n",
        ], 'mark internal');

        $body = $this->runDetect();
        $this->assertStringContainsString('### Removed API Surface', $body);
        $this->assertStringContainsString('L\\Foo::exposed', $body);
    }

    public function testInternalSymbolStaysHiddenWithDefaultFilter(): void
    {
        $this->commit(['src/Foo.php' => "<?php\nnamespace L;\nclass Foo {}\n"], 'base');
        $this->commit([
            'src/Foo.php' => "<?php\nnamespace L;\nclass Foo {\n    /** @internal */\n    public function impl(): void {}\n}\n",
        ], 'add internal method');

        $this->assertSame('', $this->runDetect(), '@internal additions should not surface by default.');
    }

    public function testIncludeInternalFlagShowsInternalAdditions(): void
    {
        $this->commit(['src/Foo.php' => "<?php\nnamespace L;\nclass Foo {}\n"], 'base');
        $this->commit([
            'src/Foo.php' => "<?php\nnamespace L;\nclass Foo {\n    /** @internal */\n    public function impl(): void {}\n}\n",
        ], 'add internal method');

        $body = $this->runDetect(['INCLUDE_INTERNAL' => 'true']);
        $this->assertStringContainsString('L\\Foo::impl', $body);
    }

    public function testModifiedSignatureFlag(): void
    {
        $this->commit([
            'src/Foo.php' => "<?php\nnamespace L;\nclass Foo {\n    public function mut(int \$x): void {}\n}\n",
        ], 'base');
        $this->commit([
            'src/Foo.php' => "<?php\nnamespace L;\nclass Foo {\n    public function mut(int \$x, int \$y): void {}\n}\n",
        ], 'add param');

        // Default → reported under Modified.
        $body = $this->runDetect();
        $this->assertStringContainsString('### Modified API Surface', $body);
        $this->assertStringContainsString('L\\Foo::mut', $body);
        $this->assertStringContainsString("```diff", $body);
        $this->assertStringContainsString('- public function mut(int $x): void', $body);
        $this->assertStringContainsString('+ public function mut(int $x, int $y): void', $body);

        // Explicit opt-out → empty body.
        $this->assertSame('', $this->runDetect(['SHOW_MODIFIED' => 'false']));
    }

    public function testVendorParentMethodIsNotReportedWhenVendorPathIsProvided(): void
    {
        // Pretend vendor/ is composer-installed: it lives outside SOURCE_ROOTS
        // and outside PATHS_GLOB, so it's never analyzed — only used to
        // resolve parent class declarations through better-reflection.
        $this->commit([
            'vendor/Acme/Lib/Base.php' => "<?php\nnamespace Acme\\Lib;\nclass Base {\n    public function vendorMethod(): void {}\n}\n",
            'src/Foo.php' => "<?php\nnamespace L;\nuse Acme\\Lib\\Base;\nclass Foo extends Base {}\n",
        ], 'base');
        $this->commit([
            'src/Foo.php' => "<?php\nnamespace L;\nuse Acme\\Lib\\Base;\nclass Foo extends Base {\n    public function vendorMethod(): void {}\n}\n",
        ], 'override vendor method on Foo');

        $vendorPath = $this->repoDir . '/vendor';

        // Without VENDOR_PATH, better-reflection can't see Acme\Lib\Base and
        // the override looks like a brand-new method on Foo.
        $bodyWithout = $this->runDetect();
        $this->assertStringContainsString('L\\Foo::vendorMethod', $bodyWithout, 'Sanity: without VENDOR_PATH the override should be (incorrectly) flagged.');

        // With VENDOR_PATH, the introduction point of vendorMethod is Acme\Lib\Base
        // (which lives outside the analyzed paths), so Foo::vendorMethod is skipped.
        $bodyWith = $this->runDetect(['VENDOR_PATH' => $vendorPath]);
        $this->assertSame('', $bodyWith, 'With VENDOR_PATH set, overriding a vendor method should not appear as new API.');
    }

    public function testTopLevelAndNestedFilesAreBothMatched(): void
    {
        $this->commit([
            'src/TopLevel.php' => "<?php\nnamespace L;\nclass TopLevel {}\n",
            'src/Sub/Nested.php' => "<?php\nnamespace L\\Sub;\nclass Nested {}\n",
        ], 'base');
        $this->commit([
            'src/TopLevel.php' => "<?php\nnamespace L;\nclass TopLevel { public function a(): void {} }\n",
            'src/Sub/Nested.php' => "<?php\nnamespace L\\Sub;\nclass Nested { public function b(): void {} }\n",
        ], 'add methods');

        $body = $this->runDetect();
        $this->assertStringContainsString('L\\TopLevel::a', $body);
        $this->assertStringContainsString('L\\Sub\\Nested::b', $body);
    }

    public function testRenamedClassIsReportedAsRemovedAndAdded(): void
    {
        // Mirrors composer/composer PR #12919: a file and its class are renamed
        // (Version.php/class Version -> VersionRenamed.php/class VersionRenamed).
        // The bodies are identical, so git classifies this as a single rename (R)
        // entry. detect.sh must decompose it (via --no-renames) into a delete +
        // add; otherwise --diff-filter=AMD drops the R entry and neither the old
        // nor the new class reaches the snapshotter.
        $classBody = "{\n    public function major(): int { return 1; }\n    public function minor(): int { return 2; }\n    public function patch(): int { return 3; }\n}\n";
        $this->commit([
            'src/Platform/Version.php' => "<?php\nnamespace L\\Platform;\nclass Version " . $classBody,
        ], 'base');

        // The commit() helper only writes files; remove the old one ourselves so
        // `git add -A` stages the deletion alongside the new file.
        unlink($this->repoDir . '/src/Platform/Version.php');
        $this->commit([
            'src/Platform/VersionRenamed.php' => "<?php\nnamespace L\\Platform;\nclass VersionRenamed " . $classBody,
        ], 'rename Version to VersionRenamed');

        $rendered = $this->runDetect();

        $this->assertStringContainsString('### New API Surface', $rendered);
        $this->assertStringContainsString('### Removed API Surface', $rendered);

        // New is rendered before Removed; split so we can assert membership.
        $removedAt = strpos($rendered, '### Removed API Surface');
        $newSection = substr($rendered, 0, $removedAt);
        $removedSection = substr($rendered, $removedAt);

        $this->assertStringContainsString('L\\Platform\\VersionRenamed', $newSection, 'The renamed-to class should be reported as new API surface.');
        // Backtick-bounded so it does not also match `L\Platform\VersionRenamed`.
        $this->assertStringContainsString('`L\\Platform\\Version`', $removedSection, 'The renamed-from class should be reported as removed API surface.');
    }
}
