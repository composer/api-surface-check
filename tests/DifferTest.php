<?php

declare(strict_types=1);

namespace Composer\ApiSurfaceCheck\Tests;

use Composer\ApiSurfaceCheck\Differ;
use PHPUnit\Framework\TestCase;

final class DifferTest extends TestCase
{
    private static function record(
        string $type,
        string $fqcn,
        ?string $member = null,
        string $visibility = 'public',
        bool $internal = false,
        string $signature = '',
        string $file = 'src/X.php',
        int $line = 1,
        ?string $hash = null,
    ): array {
        return [
            'type' => $type,
            'fqcn' => $fqcn,
            'member' => $member,
            'visibility' => $visibility,
            'internal' => $internal,
            'file' => $file,
            'line' => $line,
            'signature' => $signature ?: ($member === null ? "{$type} {$fqcn}" : "public function {$member}()"),
            'signature_hash' => $hash ?? hash('sha256', $signature ?: "{$type}:{$fqcn}::{$member}"),
        ];
    }

    public function testEmptySnapshotsProduceEmptyBody(): void
    {
        $body = (new Differ())->diff([], []);
        $this->assertSame('', $body);
    }

    public function testIdenticalSnapshotsProduceEmptyBody(): void
    {
        $records = [self::record('class', 'Foo')];
        $body = (new Differ())->diff($records, $records);
        $this->assertSame('', $body);
    }

    public function testAddedClassIsReported(): void
    {
        $body = (new Differ())->diff(
            [self::record('class', 'Foo\\Bar')],
            [],
        );

        $this->assertStringContainsString('### New API Surface', $body);
        $this->assertStringContainsString('#### Classes', $body);
        $this->assertStringContainsString('Foo\\Bar', $body);
        $this->assertStringNotContainsString('Removed', $body);
    }

    public function testAddedMethodIsReportedUnderMethods(): void
    {
        $head = [
            self::record('class', 'Foo\\Bar'),
            self::record('method', 'Foo\\Bar', 'newMethod', signature: 'public function newMethod(): void'),
        ];
        $base = [self::record('class', 'Foo\\Bar')];

        $body = (new Differ())->diff($head, $base);

        $this->assertStringContainsString('#### Methods', $body);
        $this->assertStringContainsString('Foo\\Bar::newMethod', $body);
        $this->assertStringContainsString('public function newMethod(): void', $body);
    }

    public function testRemovedSymbolReportedByDefault(): void
    {
        $body = (new Differ())->diff(
            [],
            [self::record('method', 'Foo\\Bar', 'gone', signature: 'public function gone(): void')],
        );

        $this->assertStringContainsString('### Removed API Surface', $body);
        $this->assertStringContainsString('Foo\\Bar::gone', $body);
    }

    public function testRemovedSymbolHiddenWhenShowRemovedFalse(): void
    {
        $differ = new Differ(['show-removed' => false]);
        $body = $differ->diff(
            [],
            [self::record('method', 'Foo\\Bar', 'gone')],
        );

        $this->assertSame('', $body);
    }

    public function testModifiedSymbolReportedOnlyWhenEnabled(): void
    {
        $head = [self::record('method', 'Foo\\Bar', 'mut', signature: 'public function mut(int $x): void', hash: 'h2')];
        $base = [self::record('method', 'Foo\\Bar', 'mut', signature: 'public function mut(): void', hash: 'h1')];

        $bodyOff = (new Differ())->diff($head, $base);
        $this->assertSame('', $bodyOff);

        $bodyOn = (new Differ(['show-modified' => true]))->diff($head, $base);
        $this->assertStringContainsString('### Modified API Surface', $bodyOn);
        $this->assertStringContainsString("```diff", $bodyOn);
        $this->assertStringContainsString('- public function mut(): void', $bodyOn);
        $this->assertStringContainsString('+ public function mut(int $x): void', $bodyOn);
    }

    public function testInternalSymbolFilteredByDefault(): void
    {
        $body = (new Differ())->diff(
            [self::record('method', 'Foo\\Bar', 'secret', internal: true)],
            [],
        );

        $this->assertSame('', $body);
    }

    public function testInternalSymbolIncludedWhenFlagSet(): void
    {
        $body = (new Differ(['include-internal' => true]))->diff(
            [self::record('method', 'Foo\\Bar', 'secret', internal: true, signature: 'public function secret(): void')],
            [],
        );

        $this->assertStringContainsString('Foo\\Bar::secret', $body);
    }

    public function testPrivateVisibilityFilteredByDefault(): void
    {
        $body = (new Differ())->diff(
            [self::record('method', 'Foo\\Bar', 'priv', visibility: 'private')],
            [],
        );

        $this->assertSame('', $body);
    }

    public function testPrivateVisibilityIncludedWhenRequested(): void
    {
        $body = (new Differ(['visibility' => ['public', 'protected', 'private']]))->diff(
            [self::record('method', 'Foo\\Bar', 'priv', visibility: 'private', signature: 'private function priv(): void')],
            [],
        );

        $this->assertStringContainsString('Foo\\Bar::priv', $body);
    }

    public function testTypesFilterRestrictsKinds(): void
    {
        $head = [
            self::record('class', 'Foo\\NewClass'),
            self::record('method', 'Foo\\NewClass', 'm', signature: 'public function m(): void'),
            self::record('constant', 'Foo\\NewClass', 'C', signature: 'public const C = 1'),
        ];

        $body = (new Differ(['types' => ['method']]))->diff($head, []);

        $this->assertStringContainsString('#### Methods', $body);
        $this->assertStringNotContainsString('#### Classes', $body);
        $this->assertStringNotContainsString('#### Constants', $body);
    }

    public function testCommentMarkerAndHeadingAreCustomizable(): void
    {
        $body = (new Differ([
            'comment-marker' => '<!-- custom-bot -->',
            'heading' => '## Custom Heading',
        ]))->diff(
            [self::record('class', 'Foo\\Bar')],
            [],
        );

        $this->assertStringStartsWith('<!-- custom-bot -->', $body);
        $this->assertStringContainsString('## Custom Heading', $body);
    }

    public function testRecordKeyDistinguishesByTypeFqcnAndMember(): void
    {
        $a = self::record('method', 'Foo', 'bar');
        $b = self::record('constant', 'Foo', 'bar');

        $this->assertNotSame(Differ::recordKey($a), Differ::recordKey($b));
    }

    public function testPropertyKindHasItsOwnSection(): void
    {
        $body = (new Differ())->diff(
            [self::record('property', 'Foo\\Bar', 'newProp', signature: 'public string $newProp')],
            [],
        );

        $this->assertStringContainsString('#### Properties', $body);
        $this->assertStringContainsString('Foo\\Bar::newProp', $body);
        $this->assertStringContainsString('public string $newProp', $body);
    }

    public function testRepoAndPrNumberLinkifySymbolHeader(): void
    {
        $rec = self::record('method', 'Foo\\Bar', 'newMethod', signature: 'public function newMethod(): void', file: 'src/Foo/Bar.php', line: 42);
        $differ = new Differ([
            'repo' => 'acme/proj',
            'pr-number' => 7,
        ]);
        $body = $differ->diff([self::record('class', 'Foo\\Bar'), $rec], [self::record('class', 'Foo\\Bar')]);

        $expectedHash = hash('sha256', 'src/Foo/Bar.php');
        $expectedUrl = "https://github.com/acme/proj/pull/7/files#diff-{$expectedHash}R42";
        $this->assertStringContainsString("[`Foo\\Bar::newMethod`]({$expectedUrl})", $body);
        // No trailing path when linkified.
        $this->assertStringNotContainsString('in `src/Foo/Bar.php:42`', $body);
    }

    public function testRemovedRecordsLinkUseLeftSideAnchor(): void
    {
        $differ = new Differ([
            'repo' => 'acme/proj',
            'pr-number' => 7,
        ]);
        $body = $differ->diff(
            [],
            [self::record('method', 'Foo\\Bar', 'gone', signature: 'public function gone(): void', file: 'src/Foo/Bar.php', line: 11)],
        );

        $expectedHash = hash('sha256', 'src/Foo/Bar.php');
        $this->assertStringContainsString("#diff-{$expectedHash}L11", $body);
    }

    public function testLongSignatureModificationUsesUnifiedDiff(): void
    {
        $longWas = "public function foo(\n    int \$a,\n    int \$b,\n    int \$c,\n    int \$d,\n    int \$e,\n): void";
        $longNow = "public function foo(\n    int \$a,\n    int \$b,\n    int \$c,\n    int \$d,\n    int \$e,\n    int \$f,\n): void";

        $head = [self::recordWithSource('method', 'Foo\\Bar', 'foo', $longNow, 'h2')];
        $base = [self::recordWithSource('method', 'Foo\\Bar', 'foo', $longWas, 'h1')];

        $body = (new Differ(['show-modified' => true]))->diff($head, $base);

        // Unified diff format includes @@ hunk markers.
        $this->assertStringContainsString('@@', $body);
        $this->assertStringContainsString('+    int $f,', $body);
    }

    /** @return array<string,mixed> */
    private static function recordWithSource(string $type, string $fqcn, string $member, string $source, string $hash): array
    {
        $r = self::record($type, $fqcn, $member, signature: $source, hash: $hash);
        $r['signature_source'] = $source;
        return $r;
    }
}
