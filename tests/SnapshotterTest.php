<?php

declare(strict_types=1);

namespace Composer\ApiSurfaceCheck\Tests;

use Composer\ApiSurfaceCheck\Snapshotter;
use PHPUnit\Framework\TestCase;

final class SnapshotterTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/fixtures';

    private function snapshot(string ...$relativeFixtures): array
    {
        $cwd = getcwd();
        chdir(self::FIXTURES_DIR);
        try {
            $snapshotter = new Snapshotter(['.']);
            return $snapshotter->snapshot($relativeFixtures);
        } finally {
            chdir($cwd);
        }
    }

    private function findRecord(array $records, string $key): ?array
    {
        foreach ($records as $r) {
            $rk = $r['type'] . ':' . $r['fqcn'] . ($r['member'] !== null ? '::' . $r['member'] : '');
            if ($rk === $key) {
                return $r;
            }
        }
        return null;
    }

    private function recordKeys(array $records): array
    {
        $keys = [];
        foreach ($records as $r) {
            $keys[] = $r['type'] . ':' . $r['fqcn'] . ($r['member'] !== null ? '::' . $r['member'] : '');
        }
        return $keys;
    }

    public function testSimpleClassEmitsClassMethodConstant(): void
    {
        $records = $this->snapshot('SimpleClass.php');
        $keys = $this->recordKeys($records);

        $fqcn = 'Composer\\ApiSurfaceCheck\\Tests\\Fixtures\\SimpleClass';
        $this->assertContains("class:{$fqcn}", $keys);
        $this->assertContains("constant:{$fqcn}::VERSION", $keys);
        $this->assertContains("method:{$fqcn}::publicMethod", $keys);
        $this->assertContains("method:{$fqcn}::protectedMethod", $keys);
        $this->assertContains("method:{$fqcn}::privateMethod", $keys);
    }

    public function testMethodSignatureContainsTypesAndDefaults(): void
    {
        $records = $this->snapshot('SimpleClass.php');
        $rec = $this->findRecord($records, 'method:Composer\\ApiSurfaceCheck\\Tests\\Fixtures\\SimpleClass::publicMethod');

        $this->assertNotNull($rec);
        $this->assertSame('public', $rec['visibility']);
        $this->assertStringContainsString('int $count = 5', $rec['signature']);
        $this->assertStringContainsString(': string', $rec['signature']);
    }

    public function testVisibilityIsCorrectlyDetected(): void
    {
        $records = $this->snapshot('SimpleClass.php');
        $base = 'method:Composer\\ApiSurfaceCheck\\Tests\\Fixtures\\SimpleClass::';

        $this->assertSame('public', $this->findRecord($records, $base . 'publicMethod')['visibility']);
        $this->assertSame('protected', $this->findRecord($records, $base . 'protectedMethod')['visibility']);
        $this->assertSame('private', $this->findRecord($records, $base . 'privateMethod')['visibility']);
    }

    public function testInterfaceMethodNotDuplicatedOnImplementer(): void
    {
        $records = $this->snapshot('InterfaceImpl.php');
        $keys = $this->recordKeys($records);
        $contract = 'Composer\\ApiSurfaceCheck\\Tests\\Fixtures\\Contract';
        $impl = 'Composer\\ApiSurfaceCheck\\Tests\\Fixtures\\Implementer';

        $this->assertContains("interface:{$contract}", $keys);
        $this->assertContains("method:{$contract}::fulfill", $keys);
        $this->assertContains("constant:{$contract}::STATUS_OK", $keys);

        $this->assertContains("class:{$impl}", $keys);
        $this->assertContains("method:{$impl}::classOnly", $keys);

        $this->assertNotContains("method:{$impl}::fulfill", $keys, 'Interface implementations must not be reported on the implementer.');
    }

    public function testParentMethodNotDuplicatedOnChild(): void
    {
        $records = $this->snapshot('Hierarchy.php');
        $keys = $this->recordKeys($records);
        $parent = 'Composer\\ApiSurfaceCheck\\Tests\\Fixtures\\ParentClass';
        $child = 'Composer\\ApiSurfaceCheck\\Tests\\Fixtures\\ChildClass';

        $this->assertContains("method:{$parent}::inherited", $keys);
        $this->assertContains("constant:{$parent}::SHARED", $keys);
        $this->assertContains("method:{$child}::childOnly", $keys);

        $this->assertNotContains("method:{$child}::inherited", $keys, 'Overrides must not be re-reported on the child class.');
    }

    public function testInternalClassMarksAllMembersInternal(): void
    {
        $records = $this->snapshot('InternalDoc.php');
        $fqcn = 'Composer\\ApiSurfaceCheck\\Tests\\Fixtures\\WhollyInternal';

        $classRec = $this->findRecord($records, "class:{$fqcn}");
        $methodRec = $this->findRecord($records, "method:{$fqcn}::whatever");

        $this->assertNotNull($classRec);
        $this->assertTrue($classRec['internal']);
        $this->assertNotNull($methodRec);
        $this->assertTrue($methodRec['internal'], 'Members of an @internal class inherit the flag.');
    }

    public function testPartiallyInternalClassFlagsOnlyMarkedMembers(): void
    {
        $records = $this->snapshot('InternalDoc.php');
        $fqcn = 'Composer\\ApiSurfaceCheck\\Tests\\Fixtures\\PartiallyInternal';

        $classRec = $this->findRecord($records, "class:{$fqcn}");
        $publicRec = $this->findRecord($records, "method:{$fqcn}::publicApi");
        $internalMethodRec = $this->findRecord($records, "method:{$fqcn}::internalMethod");
        $internalConstRec = $this->findRecord($records, "constant:{$fqcn}::INTERNAL_CONST");

        $this->assertFalse($classRec['internal']);
        $this->assertFalse($publicRec['internal']);
        $this->assertTrue($internalMethodRec['internal']);
        $this->assertTrue($internalConstRec['internal']);
    }

    public function testAnonymousClassesAreSkipped(): void
    {
        $records = $this->snapshot('Anonymous.php');

        foreach ($records as $r) {
            $this->assertNotEmpty($r['fqcn']);
            $this->assertStringNotContainsString('@anonymous', $r['fqcn']);
        }

        $this->assertNotNull($this->findRecord($records, 'class:Composer\\ApiSurfaceCheck\\Tests\\Fixtures\\Holder'));
    }

    public function testTraitAndEnumKindDetection(): void
    {
        $records = $this->snapshot('TraitAndEnum.php');
        $traitFqcn = 'Composer\\ApiSurfaceCheck\\Tests\\Fixtures\\Stringable_';
        $enumFqcn = 'Composer\\ApiSurfaceCheck\\Tests\\Fixtures\\Status';

        $traitRec = $this->findRecord($records, "trait:{$traitFqcn}");
        $enumRec = $this->findRecord($records, "enum:{$enumFqcn}");

        $this->assertNotNull($traitRec);
        $this->assertSame('trait', $traitRec['type']);

        $this->assertNotNull($enumRec);
        $this->assertSame('enum', $enumRec['type']);

        $this->assertNotNull($this->findRecord($records, "method:{$traitFqcn}::asString"));
        $this->assertNotNull($this->findRecord($records, "method:{$enumFqcn}::label"));
    }

    public function testFilesOutsideRequestedListAreNotIncluded(): void
    {
        $records = $this->snapshot('SimpleClass.php');
        $keys = $this->recordKeys($records);

        $this->assertNotContains('class:Composer\\ApiSurfaceCheck\\Tests\\Fixtures\\Holder', $keys);
        $this->assertNotContains('class:Composer\\ApiSurfaceCheck\\Tests\\Fixtures\\Implementer', $keys);
    }

    public function testSignatureHashIsStable(): void
    {
        $first = $this->snapshot('SimpleClass.php');
        $second = $this->snapshot('SimpleClass.php');

        $key = 'method:Composer\\ApiSurfaceCheck\\Tests\\Fixtures\\SimpleClass::publicMethod';
        $this->assertSame(
            $this->findRecord($first, $key)['signature_hash'],
            $this->findRecord($second, $key)['signature_hash'],
        );
    }

    public function testPropertiesAreEmittedWithCorrectVisibility(): void
    {
        $records = $this->snapshot('Properties.php');
        $base = 'property:Composer\\ApiSurfaceCheck\\Tests\\Fixtures\\WithProperties::';

        $public = $this->findRecord($records, $base . 'publicTyped');
        $protected = $this->findRecord($records, $base . 'protectedNullable');
        $private = $this->findRecord($records, $base . 'privateProp');

        $this->assertNotNull($public);
        $this->assertSame('property', $public['type']);
        $this->assertSame('public', $public['visibility']);

        $this->assertNotNull($protected);
        $this->assertSame('protected', $protected['visibility']);

        $this->assertNotNull($private);
        $this->assertSame('private', $private['visibility']);
    }

    public function testPropertySignatureIncludesTypeStaticAndReadonly(): void
    {
        $records = $this->snapshot('Properties.php');
        $base = 'property:Composer\\ApiSurfaceCheck\\Tests\\Fixtures\\WithProperties::';

        $readonly = $this->findRecord($records, $base . 'readonlyValue');
        $static = $this->findRecord($records, $base . 'staticArr');
        $union = $this->findRecord($records, $base . 'unionTyped');
        $withDefault = $this->findRecord($records, $base . 'publicWithDefault');
        $nullable = $this->findRecord($records, $base . 'protectedNullable');

        $this->assertStringContainsString('readonly', $readonly['signature']);
        $this->assertStringContainsString('int', $readonly['signature']);

        $this->assertStringContainsString('static', $static['signature']);
        $this->assertStringContainsString('array', $static['signature']);

        $this->assertStringContainsString('string|int', $union['signature']);

        $this->assertStringContainsString("= 'default'", $withDefault['signature']);

        // Better-reflection emits nullable property types as `T|null`.
        $this->assertMatchesRegularExpression('/(\?float|float\|null|null\|float)/', $nullable['signature']);
    }

    public function testInternalPropertyIsFlagged(): void
    {
        $records = $this->snapshot('Properties.php');
        $internal = $this->findRecord($records, 'property:Composer\\ApiSurfaceCheck\\Tests\\Fixtures\\WithProperties::internalProp');
        $public = $this->findRecord($records, 'property:Composer\\ApiSurfaceCheck\\Tests\\Fixtures\\WithProperties::publicTyped');

        $this->assertTrue($internal['internal']);
        $this->assertFalse($public['internal']);
    }

    public function testPropertyOverrideOnChildIsSkipped(): void
    {
        $records = $this->snapshot('PropertiesHierarchy.php');
        $keys = $this->recordKeys($records);

        $parent = 'Composer\\ApiSurfaceCheck\\Tests\\Fixtures\\PropParent';
        $child = 'Composer\\ApiSurfaceCheck\\Tests\\Fixtures\\PropChild';

        $this->assertContains("property:{$parent}::shared", $keys);
        $this->assertContains("property:{$child}::childOnly", $keys);
        $this->assertNotContains("property:{$child}::shared", $keys, 'Property overrides must not be re-reported on the child class.');
    }
}
