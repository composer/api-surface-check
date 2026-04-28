<?php

declare(strict_types=1);

namespace Composer\ApiSurfaceCheck;

use PhpParser\Node\Expr;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use Roave\BetterReflection\BetterReflection;
use Roave\BetterReflection\Reflection\ReflectionClass;
use Roave\BetterReflection\Reflection\ReflectionClassConstant;
use Roave\BetterReflection\Reflection\ReflectionEnum;
use Roave\BetterReflection\Reflection\ReflectionIntersectionType;
use Roave\BetterReflection\Reflection\ReflectionMethod;
use Roave\BetterReflection\Reflection\ReflectionNamedType;
use Roave\BetterReflection\Reflection\ReflectionParameter;
use Roave\BetterReflection\Reflection\ReflectionProperty;
use Roave\BetterReflection\Reflection\ReflectionType;
use Roave\BetterReflection\Reflection\ReflectionUnionType;
use Roave\BetterReflection\Reflector\DefaultReflector;
use Roave\BetterReflection\Reflector\Reflector;
use Roave\BetterReflection\SourceLocator\Type\AggregateSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\DirectoriesSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\PhpInternalSourceLocator;

/**
 * Builds an array of API-surface symbol records for a set of files.
 *
 * Each record represents a class/interface/trait/enum, a method, or a constant
 * whose introduction point in its hierarchy is the class itself (i.e. the
 * member is not inherited from a parent or already declared on an implemented
 * interface).
 */
final class Snapshotter
{
    private PrettyPrinter $prettyPrinter;

    /**
     * @param list<string> $sourceRoots Directories used to resolve parent classes / interfaces.
     */
    public function __construct(private array $sourceRoots)
    {
        $this->prettyPrinter = new PrettyPrinter();
    }

    /**
     * @param list<string> $files     File paths (relative to cwd) to include in the snapshot.
     * @return list<array<string,mixed>> Records sorted by file/line.
     */
    public function snapshot(array $files): array
    {
        $targetFiles = [];
        foreach ($files as $relative) {
            $abs = realpath($relative);
            if ($abs !== false) {
                $targetFiles[$abs] = $relative;
            }
        }

        if (empty($targetFiles)) {
            return [];
        }

        $reflector = $this->buildReflector();
        $records = [];

        foreach ($reflector->reflectAllClasses() as $class) {
            $fileName = $class->getFileName();
            if ($fileName === null) {
                continue;
            }
            $abs = realpath($fileName);
            if ($abs === false || !isset($targetFiles[$abs])) {
                continue;
            }

            if ($class->isAnonymous()) {
                continue;
            }

            foreach ($this->collectFromClass($class, $targetFiles[$abs]) as $record) {
                $records[] = $record;
            }
        }

        usort($records, static function (array $a, array $b): int {
            return [$a['file'], $a['line'], $a['type'], $a['fqcn'], $a['member'] ?? '']
                <=> [$b['file'], $b['line'], $b['type'], $b['fqcn'], $b['member'] ?? ''];
        });

        return $records;
    }

    private function buildReflector(): Reflector
    {
        $br = new BetterReflection();
        $astLocator = $br->astLocator();
        $stubber = $br->sourceStubber();

        $locators = [];
        foreach ($this->sourceRoots as $root) {
            if (is_dir($root)) {
                $locators[] = new DirectoriesSourceLocator([$root], $astLocator);
            }
        }
        $locators[] = new PhpInternalSourceLocator($astLocator, $stubber);

        return new DefaultReflector(new AggregateSourceLocator($locators));
    }

    /**
     * @return iterable<array<string,mixed>>
     */
    private function collectFromClass(ReflectionClass $class, string $relativeFile): iterable
    {
        $classInternal = self::isInternalDoc($class->getDocComment());
        $kind = self::classKind($class);

        $classSignature = sprintf(
            '%s %s%s%s',
            $kind,
            $class->getName(),
            self::formatExtends($class),
            self::formatImplements($class),
        );

        yield [
            'type' => $kind,
            'fqcn' => $class->getName(),
            'member' => null,
            'visibility' => 'public',
            'internal' => $classInternal,
            'file' => $relativeFile,
            'line' => $class->getStartLine(),
            'signature' => $classSignature,
            'signature_hash' => hash('sha256', $classSignature),
        ];

        foreach ($class->getImmediateMethods() as $method) {
            $name = $method->getName();
            if (!self::isMethodIntroducedHere($class, $name)) {
                continue;
            }
            $signature = $this->formatMethodSignature($method);
            yield [
                'type' => 'method',
                'fqcn' => $class->getName(),
                'member' => $name,
                'visibility' => self::methodVisibility($method),
                'internal' => $classInternal || self::isInternalDoc($method->getDocComment()),
                'file' => $relativeFile,
                'line' => $method->getStartLine(),
                'signature' => $signature,
                'signature_hash' => hash('sha256', $signature),
            ];
        }

        foreach ($class->getImmediateConstants() as $constant) {
            $name = $constant->getName();
            if (!self::isConstantIntroducedHere($class, $name)) {
                continue;
            }
            $signature = $this->formatConstantSignature($constant);
            yield [
                'type' => 'constant',
                'fqcn' => $class->getName(),
                'member' => $name,
                'visibility' => self::constantVisibility($constant),
                'internal' => $classInternal || self::isInternalDoc($constant->getDocComment()),
                'file' => $relativeFile,
                'line' => $constant->getStartLine(),
                'signature' => $signature,
                'signature_hash' => hash('sha256', $signature),
            ];
        }

        foreach ($class->getImmediateProperties() as $property) {
            $name = $property->getName();
            if (!self::isPropertyIntroducedHere($class, $name)) {
                continue;
            }
            $signature = $this->formatPropertySignature($property);
            yield [
                'type' => 'property',
                'fqcn' => $class->getName(),
                'member' => $name,
                'visibility' => self::propertyVisibility($property),
                'internal' => $classInternal || self::isInternalDoc($property->getDocComment()),
                'file' => $relativeFile,
                'line' => $property->getStartLine(),
                'signature' => $signature,
                'signature_hash' => hash('sha256', $signature),
            ];
        }
    }

    public static function isInternalDoc(?string $doc): bool
    {
        if ($doc === null) {
            return false;
        }
        return preg_match('/@(internal|private)\b/', $doc) === 1;
    }

    public static function classKind(ReflectionClass $class): string
    {
        if ($class->isInterface()) {
            return 'interface';
        }
        if ($class->isTrait()) {
            return 'trait';
        }
        if ($class instanceof ReflectionEnum || $class->isEnum()) {
            return 'enum';
        }
        return 'class';
    }

    private static function formatExtends(ReflectionClass $class): string
    {
        $parentName = $class->getParentClassName();
        if ($parentName !== null) {
            return ' extends ' . $parentName;
        }
        return '';
    }

    private static function formatImplements(ReflectionClass $class): string
    {
        $names = $class->getInterfaceClassNames();
        if (empty($names)) {
            return '';
        }
        sort($names);
        return ' implements ' . implode(', ', $names);
    }

    /**
     * Resolve a member's introduction-point status. If a parent or interface
     * can't be resolved (e.g. lives in a vendor package that wasn't installed),
     * we fall back to "introduced here" — a conservative choice that may
     * over-report rather than under-report API additions.
     */
    public static function isMethodIntroducedHere(ReflectionClass $class, string $name): bool
    {
        try {
            $parent = $class->getParentClass();
            if ($parent !== null && $parent->hasMethod($name)) {
                return false;
            }
        } catch (\Throwable) {
            // Unresolvable parent — assume introduced here.
        }
        try {
            foreach ($class->getImmediateInterfaces() as $interface) {
                if ($interface->hasMethod($name)) {
                    return false;
                }
            }
        } catch (\Throwable) {
            // Unresolvable interface — assume introduced here.
        }
        return true;
    }

    public static function isConstantIntroducedHere(ReflectionClass $class, string $name): bool
    {
        try {
            $parent = $class->getParentClass();
            if ($parent !== null && $parent->hasConstant($name)) {
                return false;
            }
        } catch (\Throwable) {
        }
        try {
            foreach ($class->getImmediateInterfaces() as $interface) {
                if ($interface->hasConstant($name)) {
                    return false;
                }
            }
        } catch (\Throwable) {
        }
        return true;
    }

    public static function isPropertyIntroducedHere(ReflectionClass $class, string $name): bool
    {
        try {
            $parent = $class->getParentClass();
            if ($parent !== null && $parent->hasProperty($name)) {
                return false;
            }
        } catch (\Throwable) {
        }
        try {
            foreach ($class->getImmediateInterfaces() as $interface) {
                if ($interface->hasProperty($name)) {
                    return false;
                }
            }
        } catch (\Throwable) {
        }
        return true;
    }

    public static function methodVisibility(ReflectionMethod $method): string
    {
        if ($method->isPrivate()) {
            return 'private';
        }
        if ($method->isProtected()) {
            return 'protected';
        }
        return 'public';
    }

    public static function constantVisibility(ReflectionClassConstant $constant): string
    {
        if ($constant->isPrivate()) {
            return 'private';
        }
        if ($constant->isProtected()) {
            return 'protected';
        }
        return 'public';
    }

    public static function propertyVisibility(ReflectionProperty $property): string
    {
        if ($property->isPrivate()) {
            return 'private';
        }
        if ($property->isProtected()) {
            return 'protected';
        }
        return 'public';
    }

    private function formatMethodSignature(ReflectionMethod $method): string
    {
        $modifiers = [];
        if ($method->isAbstract()) {
            $modifiers[] = 'abstract';
        }
        if ($method->isFinal()) {
            $modifiers[] = 'final';
        }
        if ($method->isStatic()) {
            $modifiers[] = 'static';
        }
        $modifiers[] = self::methodVisibility($method);

        $params = array_map(
            fn (ReflectionParameter $p) => $this->formatParameter($p),
            $method->getParameters(),
        );

        $returnType = $method->hasReturnType() ? ': ' . self::formatType($method->getReturnType()) : '';

        return sprintf(
            '%s function %s(%s)%s',
            implode(' ', $modifiers),
            $method->getName(),
            implode(', ', $params),
            $returnType,
        );
    }

    private function formatParameter(ReflectionParameter $param): string
    {
        $type = $param->hasType() ? self::formatType($param->getType()) . ' ' : '';
        $byRef = $param->isPassedByReference() ? '&' : '';
        $variadic = $param->isVariadic() ? '...' : '';
        $name = '$' . $param->getName();
        $default = '';
        if ($param->isDefaultValueAvailable()) {
            $expr = $param->getDefaultValueExpression();
            if ($expr instanceof Expr) {
                $default = ' = ' . $this->prettyPrinter->prettyPrintExpr($expr);
            } else {
                try {
                    $default = ' = ' . var_export($param->getDefaultValue(), true);
                } catch (\Throwable) {
                    $default = ' = ?';
                }
            }
        }
        return $type . $byRef . $variadic . $name . $default;
    }

    public static function formatType(?ReflectionType $type): string
    {
        if ($type === null) {
            return '';
        }
        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map([self::class, 'formatType'], $type->getTypes()));
        }
        if ($type instanceof ReflectionIntersectionType) {
            return implode('&', array_map([self::class, 'formatType'], $type->getTypes()));
        }
        if ($type instanceof ReflectionNamedType) {
            $prefix = ($type->allowsNull() && $type->getName() !== 'mixed' && $type->getName() !== 'null') ? '?' : '';
            return $prefix . $type->getName();
        }
        return (string) $type;
    }

    private function formatPropertySignature(ReflectionProperty $property): string
    {
        $modifiers = [self::propertyVisibility($property)];
        if ($property->isStatic()) {
            $modifiers[] = 'static';
        }
        if ($property->isReadOnly()) {
            $modifiers[] = 'readonly';
        }

        $type = $property->getType() !== null ? self::formatType($property->getType()) . ' ' : '';

        $default = '';
        if ($property->hasDefaultValue()) {
            $expr = $property->getDefaultValueExpression();
            if ($expr !== null) {
                $default = ' = ' . $this->prettyPrinter->prettyPrintExpr($expr);
            }
        }

        return sprintf('%s %s$%s%s', implode(' ', $modifiers), $type, $property->getName(), $default);
    }

    private function formatConstantSignature(ReflectionClassConstant $constant): string
    {
        $modifiers = [self::constantVisibility($constant)];
        if ($constant->isFinal()) {
            $modifiers[] = 'final';
        }
        $value = '?';
        try {
            $expr = $constant->getValueExpression();
            $value = $this->prettyPrinter->prettyPrintExpr($expr);
        } catch (\Throwable) {
            try {
                $value = var_export($constant->getValue(), true);
            } catch (\Throwable) {
                // leave as ?
            }
        }
        return sprintf('%s const %s = %s', implode(' ', $modifiers), $constant->getName(), $value);
    }
}
