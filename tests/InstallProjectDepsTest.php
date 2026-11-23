<?php

declare(strict_types=1);

namespace Composer\ApiSurfaceCheck\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Drives scripts/install-project-deps.sh against PR-controlled composer.json
 * files and asserts composer never writes outside the isolated install dir.
 * Uses a local path repository so no network access is needed.
 */
final class InstallProjectDepsTest extends TestCase
{
    private string $baseDir;
    private string $projectDir;
    private string $installDir;
    private string $victimDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/api-surface-install-' . bin2hex(random_bytes(4));
        $this->projectDir = $this->baseDir . '/project';
        $this->installDir = $this->baseDir . '/install';
        $this->victimDir = $this->baseDir . '/victim';

        mkdir($this->projectDir, 0o755, true);
        mkdir($this->victimDir . '/composer', 0o755, true);
        file_put_contents($this->victimDir . '/composer/autoload_files.php', 'original');

        mkdir($this->baseDir . '/pkg/src', 0o755, true);
        file_put_contents($this->baseDir . '/pkg/composer.json', json_encode([
            'name' => 'acme/lib',
            'version' => '1.0.0',
            'autoload' => ['psr-4' => ['Acme\\Lib\\' => 'src/']],
        ]));
        file_put_contents(
            $this->baseDir . '/pkg/src/Base.php',
            "<?php\nnamespace Acme\\Lib;\nclass Base {\n    public function vendorMethod(): void {}\n}\n",
        );

        $this->shell($this->projectDir, 'git init -q');
        $this->shell($this->projectDir, 'git -c user.email=t@t -c user.name=t commit -q --allow-empty -m bootstrap');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->baseDir)) {
            $this->rrmdir($this->baseDir);
        }
    }

    public function testInstallsIntoIsolatedDirAndResolvesVendorParents(): void
    {
        $this->writeComposerJson();
        $this->commit(['src/Foo.php' => "<?php\nnamespace L;\nuse Acme\\Lib\\Base;\nclass Foo extends Base {}\n"], 'base');
        $this->commit(['src/Foo.php' => "<?php\nnamespace L;\nuse Acme\\Lib\\Base;\nclass Foo extends Base {\n    public function vendorMethod(): void {}\n}\n"], 'override');

        $projectPath = $this->install();
        $this->assertSame(realpath($this->installDir), $projectPath);
        $this->assertFileExists($this->installDir . '/vendor/composer/installed.json');
        $this->assertDirectoryDoesNotExist($this->projectDir . '/vendor');

        $env = sprintf(
            'BASE_REF=HEAD~1 OUTPUT_DIR=result COMPOSER_PROJECT_PATH=%s bash %s',
            escapeshellarg($projectPath),
            escapeshellarg(realpath(__DIR__ . '/../scripts/detect.sh')),
        );
        $this->shell($this->projectDir, $env);
        $this->assertSame('', file_get_contents($this->projectDir . '/result/comment-body.txt'));
    }

    public function testAbsoluteVendorAndBinDirAreIgnored(): void
    {
        $this->writeComposerJson([
            'vendor-dir' => $this->victimDir,
            'bin-dir' => $this->victimDir . '/bin',
            'cache-dir' => $this->victimDir . '/cache',
        ]);

        $this->install();

        $this->assertVictimUntouched();
        $this->assertFileExists($this->installDir . '/vendor/acme/lib/composer.json');
    }

    public function testOnlyInstallRelevantConfigIsKept(): void
    {
        $this->writeComposerJson([
            'vendor-dir' => $this->victimDir,
            'platform' => ['php' => '8.4.0'],
            'policy' => ['abandoned' => 'report'],
        ]);

        $this->install();

        $copied = json_decode(file_get_contents($this->installDir . '/composer.json'), true);
        $this->assertSame(['platform' => ['php' => '8.4.0'], 'policy' => ['abandoned' => 'report']], $copied['config']);
        $this->assertArrayNotHasKey('autoload', $copied);
        $this->assertArrayNotHasKey('scripts', $copied);
    }

    public function testCommittedVendorSymlinkIsNotFollowed(): void
    {
        $this->writeComposerJson();
        symlink($this->victimDir, $this->projectDir . '/vendor');

        $this->install();

        $this->assertVictimUntouched();
        $this->assertFileExists($this->installDir . '/vendor/acme/lib/composer.json');
    }

    public function testSymlinkedComposerJsonIsSkipped(): void
    {
        $this->writeComposerJson();
        rename($this->projectDir . '/composer.json', $this->baseDir . '/elsewhere.json');
        symlink($this->baseDir . '/elsewhere.json', $this->projectDir . '/composer.json');

        $this->assertNull($this->install());
        $this->assertDirectoryDoesNotExist($this->installDir);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function writeComposerJson(array $config = []): void
    {
        $json = [
            'repositories' => [
                ['type' => 'path', 'url' => $this->baseDir . '/pkg', 'options' => ['symlink' => false]],
                ['packagist.org' => false],
            ],
            'require' => ['acme/lib' => '*'],
            'autoload' => ['files' => ['src/Evil.php']],
            'scripts' => ['post-install-cmd' => 'touch ' . $this->victimDir . '/pwned'],
        ];
        if ($config !== []) {
            $json['config'] = $config;
        }
        file_put_contents($this->projectDir . '/composer.json', json_encode($json, JSON_UNESCAPED_SLASHES));
    }

    private function install(): ?string
    {
        $outputFile = $this->baseDir . '/github-output';
        @unlink($outputFile);
        $this->shell($this->projectDir, sprintf(
            'INSTALL_DIR=%s GITHUB_OUTPUT=%s COMPOSER_NO_AUDIT=1 bash %s',
            escapeshellarg($this->installDir),
            escapeshellarg($outputFile),
            escapeshellarg(realpath(__DIR__ . '/../scripts/install-project-deps.sh')),
        ));

        if (!is_file($outputFile) || !preg_match('{^project-path=(.+)$}m', file_get_contents($outputFile), $m)) {
            return null;
        }

        return $m[1];
    }

    private function assertVictimUntouched(): void
    {
        $this->assertSame('original', file_get_contents($this->victimDir . '/composer/autoload_files.php'));
        $this->assertSame(['.', '..', 'composer'], scandir($this->victimDir));
    }

    /**
     * @param array<string,string> $files
     */
    private function commit(array $files, string $message): void
    {
        foreach ($files as $relative => $contents) {
            $full = $this->projectDir . '/' . $relative;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0o755, true);
            }
            file_put_contents($full, $contents);
        }
        $this->shell($this->projectDir, 'git add -A');
        $this->shell($this->projectDir, sprintf('git -c user.email=t@t -c user.name=t commit -q -m %s', escapeshellarg($message)));
    }

    private function shell(string $cwd, string $cmd): string
    {
        $current = getcwd();
        chdir($cwd);
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
}
