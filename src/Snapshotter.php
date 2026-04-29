<?php

declare(strict_types=1);

namespace Composer\ApiSurfaceCheck;

use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
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
use Roave\BetterReflection\Reflector\Exception\IdentifierNotFound;
use Roave\BetterReflection\Reflector\Reflector;
use Roave\BetterReflection\SourceLocator\Type\AggregateSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\Composer\Factory\Exception\MissingComposerJson;
use Roave\BetterReflection\SourceLocator\Type\Composer\Factory\Exception\MissingInstalledJson;
use Roave\BetterReflection\SourceLocator\Type\Composer\Factory\MakeLocatorForComposerJsonAndInstalledJson;
use Roave\BetterReflection\SourceLocator\Type\DirectoriesSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\MemoizingSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\PhpInternalSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\SingleFileSourceLocator;

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
    private Parser $parser;

    /**
     * @param list<string> $sourceRoots         Directories used to resolve parent classes / interfaces.
     * @param string|null  $composerProjectPath Optional project root with composer.json + vendor/composer/installed.json. When set, parent / interface
     *                                          lookups go through composer's PSR-4 mappings (O(1) per FQCN) instead of recursive directory scans.
     */
    public function __construct(private array $sourceRoots, private ?string $composerProjectPath = null)
    {
        $this->prettyPrinter = new PrettyPrinter();
        $this->parser = (new ParserFactory())->createForHostVersion();
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

        $reflector = $this->buildReflector(array_keys($targetFiles));
        $records = [];

        // Enumerate target classes by parsing each target file directly with
        // PhpParser, then resolving each FQCN through better-reflection. We
        // deliberately avoid $reflector->reflectAllClasses(): that would force
        // the source locators to recursively parse every PHP file in every
        // source root (including vendor/ when install-dependencies is on),
        // which can take many minutes on real-world projects. With per-file
        // enumeration the upfront cost is bounded by the number of changed
        // files; vendor is only touched lazily during parent resolution.
        foreach ($targetFiles as $abs => $relative) {
            $fileLines = self::readFileLines($abs);
            foreach ($this->extractClassFqcns($abs) as $fqcn) {
                try {
                    $class = $reflector->reflectClass($fqcn);
                } catch (IdentifierNotFound) {
                    continue;
                }

                if ($class->isAnonymous()) {
                    continue;
                }

                foreach ($this->collectFromClass($class, $relative, $fileLines) as $record) {
                    $records[] = $record;
                }
            }
        }

        usort($records, static function (array $a, array $b): int {
            return [$a['file'], $a['line'], $a['type'], $a['fqcn'], $a['member'] ?? '']
                <=> [$b['file'], $b['line'], $b['type'], $b['fqcn'], $b['member'] ?? ''];
        });

        return $records;
    }

    /**
     * @return list<string> Fully-qualified names of named classes/interfaces/traits/enums declared in the file.
     */
    private function extractClassFqcns(string $absPath): array
    {
        $code = @file_get_contents($absPath);
        if ($code === false) {
            return [];
        }

        $ast = $this->parser->parse($code);
        if ($ast === null) {
            return [];
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $ast = $traverser->traverse($ast);

        $nodes = (new NodeFinder())->find(
            $ast,
            static fn ($node): bool =>
                $node instanceof Class_
                || $node instanceof Interface_
                || $node instanceof Trait_
                || $node instanceof Enum_,
        );

        $fqcns = [];
        foreach ($nodes as $node) {
            $name = $node->namespacedName ?? null;
            if ($name === null) {
                continue;
            }
            $fqcns[] = $name->toString();
        }

        return $fqcns;
    }

    /**
     * @param list<string> $targetAbsPaths Absolute paths of files we'll be snapshotting (added at the front of the
     *                                     aggregate so target FQCN lookups don't fall through to vendor / src walks).
     */
    private function buildReflector(array $targetAbsPaths): Reflector
    {
        $br = new BetterReflection();
        $astLocator = $br->astLocator();
        $stubber = $br->sourceStubber();

        $locators = [];

        // Target files first: lookups for classes declared in changed files
        // resolve in O(1) without falling through to the slower DirectoriesSourceLocator.
        foreach ($targetAbsPaths as $abs) {
            if (is_file($abs)) {
                $locators[] = new SingleFileSourceLocator($abs, $astLocator);
            }
        }

        // Composer-aware locator: when a project with composer.json + installed.json
        // is provided, all parent / interface lookups under PSR-4 / PSR-0 namespaces
        // resolve in O(1) via prefix mapping. Without this, parent resolution into
        // vendor walks every file (~thousands of file reads + AST parses per FQCN
        // miss) — the dominant cost on real-world projects.
        if ($this->composerProjectPath !== null && is_dir($this->composerProjectPath)) {
            try {
                $locators[] = (new MakeLocatorForComposerJsonAndInstalledJson())($this->composerProjectPath, $astLocator);
            } catch (MissingComposerJson | MissingInstalledJson) {
                // Fall through to source-roots only.
            }
        }

        foreach ($this->sourceRoots as $root) {
            if (is_dir($root)) {
                $locators[] = new DirectoriesSourceLocator([$root], $astLocator);
            }
        }
        $locators[] = new PhpInternalSourceLocator($astLocator, $stubber);

        // Memoize by FQCN: any parent / interface looked up more than once
        // (which happens for every method / constant / property check on
        // a class) reuses the cached result instead of re-walking locators.
        return new DefaultReflector(new MemoizingSourceLocator(new AggregateSourceLocator($locators)));
    }

    /**
     * @param list<string> $fileLines Source file split by line (0-indexed array, but file lines are 1-indexed).
     * @return iterable<array<string,mixed>>
     */
    private function collectFromClass(ReflectionClass $class, string $relativeFile, array $fileLines): iterable
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
            'signature_source' => self::extractDeclarationSource($fileLines, $class->getStartLine(), $class->getEndLine(), stripBody: true),
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
                'signature_source' => self::extractDeclarationSource($fileLines, $method->getStartLine(), $method->getEndLine(), stripBody: !$method->isAbstract()),
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
                'signature_source' => self::extractDeclarationSource($fileLines, $constant->getStartLine(), $constant->getEndLine(), stripBody: false),
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
                'signature_source' => self::extractDeclarationSource($fileLines, $property->getStartLine(), $property->getEndLine(), stripBody: false),
            ];
        }
    }

    /**
     * @return list<string>
     */
    private static function readFileLines(string $absPath): array
    {
        $content = @file_get_contents($absPath);
        if ($content === false) {
            return [];
        }
        return explode("\n", $content);
    }

    /**
     * Slice the source between $startLine and $endLine (1-based inclusive). When $stripBody is true,
     * truncate the slice at the opening `{` of the body — found by scanning forward from after the
     * declaration's closing `)` at depth 0. Falls back to the un-truncated slice if no balanced `{` is found.
     *
     * Indentation of the first line is removed from every line so the snippet renders without leading dead space.
     *
     * @param list<string> $fileLines
     */
    private static function extractDeclarationSource(array $fileLines, int $startLine, int $endLine, bool $stripBody): string
    {
        if ($startLine < 1 || $endLine < $startLine || $startLine > count($fileLines)) {
            return '';
        }
        $slice = array_slice($fileLines, $startLine - 1, $endLine - $startLine + 1);
        $source = implode("\n", $slice);

        if ($stripBody) {
            $depth = 0;
            $sawCloseParen = false;
            $len = strlen($source);
            for ($i = 0; $i < $len; $i++) {
                $ch = $source[$i];
                if ($ch === '(') {
                    $depth++;
                } elseif ($ch === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $sawCloseParen = true;
                    }
                } elseif ($ch === '{' && $depth === 0 && $sawCloseParen) {
                    $source = rtrim(substr($source, 0, $i));
                    break;
                }
            }
        }

        // Dedent: leading whitespace common to first non-empty line.
        if (preg_match('/^([ \t]+)/', $source, $m) === 1) {
            $indent = $m[1];
            $lines = explode("\n", $source);
            foreach ($lines as $i => $line) {
                if (str_starts_with($line, $indent)) {
                    $lines[$i] = substr($line, strlen($indent));
                }
            }
            $source = implode("\n", $lines);
        }

        return rtrim($source);
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
