<?php

declare(strict_types=1);

namespace Composer\ApiSurfaceCheck\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Drives scripts/post-comment.sh with a stub `gh` that logs its calls and
 * returns canned existing comments.
 */
final class PostCommentTest extends TestCase
{
    private const string MARKER = '<!-- api-surface-bot -->';
    private const string SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private string $dir;
    private string $artifactDir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/api-surface-post-' . bin2hex(random_bytes(4));
        $this->artifactDir = $this->dir . '/artifact';
        mkdir($this->artifactDir, 0o755, true);
        mkdir($this->dir . '/bin');

        // Stub gh: GET of the comments list prints comments.json, every call is logged.
        file_put_contents($this->dir . '/bin/gh', <<<'SH'
            #!/usr/bin/env bash
            printf '%s\n' "$*" >> "$STUB_DIR/gh.log"
            for arg in "$@"; do
                case "$arg" in body=*) printf '%s' "${arg#body=}" > "$STUB_DIR/posted-body.txt";; esac
            done
            if [[ "$1 $2" == api\ repos/*/comments && "$*" != *" -X "* && "$*" != *" -f "* ]]; then
                cat "$STUB_DIR/comments.json"
            fi
            SH);
        chmod($this->dir . '/bin/gh', 0o755);
        $this->setExistingComments([]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testPostsNewComment(): void
    {
        $this->writeArtifact(self::MARKER . "\n## API Surface Changes\n\nfoo");

        $this->assertStringContainsString('Posted API surface comment.', $this->postComment());
        $this->assertSame(self::MARKER . "\n## API Surface Changes\n\nfoo", $this->postedBody());
        $this->assertStringContainsString('repos/acme/repo/issues/12/comments -f body=', $this->ghLog());
    }

    public function testUpdatesOwnCommentButNotOthers(): void
    {
        $this->setExistingComments([
            ['id' => 1, 'user' => ['login' => 'mallory'], 'body' => self::MARKER . ' fake'],
            ['id' => 2, 'user' => ['login' => 'github-actions[bot]'], 'body' => self::MARKER . ' old'],
        ]);
        $this->writeArtifact(self::MARKER . ' new');

        $this->assertStringContainsString('Updated API surface comment.', $this->postComment());
        $this->assertStringContainsString('-X PATCH repos/acme/repo/issues/comments/2', $this->ghLog());
        $this->assertStringNotContainsString('comments/1', $this->ghLog());
    }

    public function testEmptyBodyDeletesOwnComment(): void
    {
        $this->setExistingComments([
            ['id' => 1, 'user' => ['login' => 'mallory'], 'body' => self::MARKER],
            ['id' => 2, 'user' => ['login' => 'github-actions[bot]'], 'body' => self::MARKER . ' old'],
        ]);
        $this->writeArtifact('');

        $this->assertStringContainsString('Deleted stale API surface comment.', $this->postComment());
        $this->assertStringContainsString('-X DELETE repos/acme/repo/issues/comments/2', $this->ghLog());
        $this->assertStringNotContainsString('comments/1', $this->ghLog());
    }

    public function testSkipsWhenArtifactIsForAnotherPr(): void
    {
        $this->writeArtifact(self::MARKER . ' x', pr: 13);

        $this->assertStringContainsString("Artifact is for PR '13'", $this->postComment());
        $this->assertFileDoesNotExist($this->dir . '/gh.log');
    }

    public function testSkipsWhenArtifactIsForAnotherCommit(): void
    {
        $this->writeArtifact(self::MARKER . ' x', sha: str_repeat('b', 40));

        $this->assertStringContainsString('Artifact is for commit', $this->postComment());
        $this->assertFileDoesNotExist($this->dir . '/gh.log');
    }

    public function testSkipsWhenPrHeadMoved(): void
    {
        $this->writeArtifact(self::MARKER . ' x');

        $this->assertStringContainsString('PR head moved', $this->postComment(['PR_HEAD_SHA' => str_repeat('c', 40)]));
        $this->assertFileDoesNotExist($this->dir . '/gh.log');
    }

    public function testSkipsBodyWithoutMarker(): void
    {
        $this->writeArtifact('arbitrary text');

        $this->assertStringContainsString('does not start with the comment marker', $this->postComment());
        $this->assertFileDoesNotExist($this->dir . '/gh.log');
    }

    public function testSkipsMalformedMetadata(): void
    {
        $this->writeArtifact(self::MARKER . ' x');
        file_put_contents($this->artifactDir . '/metadata.json', 'not json');

        $this->assertStringContainsString("Artifact is for PR ''", $this->postComment());
        $this->assertFileDoesNotExist($this->dir . '/gh.log');
    }

    public function testSkipsMissingArtifactFiles(): void
    {
        $this->assertStringContainsString('missing comment-body.txt or metadata.json', $this->postComment());
        $this->assertFileDoesNotExist($this->dir . '/gh.log');
    }

    public function testTruncatesOversizedBody(): void
    {
        $this->writeArtifact(self::MARKER . "\n" . str_repeat("- `Foo\\Bar::baz()`\n", 5000));

        $this->postComment();
        $posted = $this->postedBody();
        $this->assertLessThan(65536, mb_strlen($posted));
        $this->assertStringStartsWith(self::MARKER, $posted);
        $this->assertStringEndsWith('_Output truncated, see the analysis job summary for the full list._', $posted);
    }

    private function writeArtifact(string $body, int $pr = 12, string $sha = self::SHA): void
    {
        file_put_contents($this->artifactDir . '/comment-body.txt', $body);
        file_put_contents($this->artifactDir . '/metadata.json', json_encode(['version' => 1, 'pr-number' => $pr, 'head-sha' => $sha]));
    }

    /**
     * @param list<array<string, mixed>> $comments
     */
    private function setExistingComments(array $comments): void
    {
        file_put_contents($this->dir . '/comments.json', json_encode($comments));
    }

    /**
     * @param array<string, string> $env
     */
    private function postComment(array $env = []): string
    {
        $env = array_merge([
            'PATH' => $this->dir . '/bin:' . getenv('PATH'),
            'STUB_DIR' => $this->dir,
            'ARTIFACT_DIR' => $this->artifactDir,
            'GITHUB_REPOSITORY' => 'acme/repo',
            'GH_TOKEN' => 'fake',
            'PR_NUMBER' => '12',
            'PR_HEAD_SHA' => self::SHA,
            'RUN_HEAD_SHA' => self::SHA,
        ], $env);

        $cmd = '';
        foreach ($env as $k => $v) {
            $cmd .= sprintf('%s=%s ', $k, escapeshellarg($v));
        }
        $cmd .= 'bash ' . escapeshellarg(realpath(__DIR__ . '/../scripts/post-comment.sh')) . ' 2>&1';

        exec($cmd, $lines, $status);
        $output = implode("\n", $lines);
        $this->assertSame(0, $status, $output);

        return $output;
    }

    private function ghLog(): string
    {
        return (string) @file_get_contents($this->dir . '/gh.log');
    }

    private function postedBody(): string
    {
        return (string) file_get_contents($this->dir . '/posted-body.txt');
    }
}
