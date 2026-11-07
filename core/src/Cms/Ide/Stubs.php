<?php

declare(strict_types=1);

namespace Kestello\Cms\Ide;

use ReflectionClass;
use ReflectionClassConstant;
use ReflectionEnum;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;

/**
 * Stubs of Kestello's public API for editors and PHPStan in a plugin or theme
 * that lives in its own repository: signatures and doc comments, no bodies.
 *
 * Everything a plugin may use: every class, interface, trait and enum not
 * marked @internal (those marked @api promise the most stability), and the
 * helper functions (Kestello\__ and friends). Methods marked @internal are
 * left out, and so are private members.
 *
 * @internal
 */
final class Stubs
{
    /**
     * The classes declared in the PHP files under $dir that are not @internal.
     *
     * @return list<class-string>
     */
    public static function publicClasses(string $dir): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            foreach (self::declared((string) file_get_contents($file->getPathname())) as $class) {
                if (!class_exists($class) && !interface_exists($class) && !enum_exists($class) && !trait_exists($class)) {
                    continue;
                }
                $r = new ReflectionClass($class);
                $doc = (string) $r->getDocComment();
                if (preg_match('/@internal\b/', $doc) !== 1) {
                    $found[] = $class;
                }
            }
        }
        sort($found);

        return $found;
    }

    /**
     * Class names a file declares (read as tokens, so nothing is loaded).
     *
     * @return list<class-string>
     */
    public static function declared(string $code): array
    {
        $tokens = token_get_all($code);
        $namespace = '';
        $out = [];
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t)) {
                continue;
            }
            if ($t[0] === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < $n && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                        $namespace .= $tokens[$j][1];
                    }
                }
            } elseif (in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $prev = $tokens[$i - 1] ?? null;
                $prev2 = $tokens[$i - 2] ?? null;
                if (($prev === null || (is_array($prev) && $prev[0] === T_DOUBLE_COLON)) || (is_array($prev2) && $prev2[0] === T_NEW)) {
                    continue; // Foo::class, new class
                }
                for ($j = $i + 1; $j < $n; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        /** @var class-string $name */
                        $name = ltrim($namespace . '\\' . $tokens[$j][1], '\\');
                        $out[] = $name;
                        break;
                    }
                    if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_WHITESPACE) {
                        break;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * The stub file.
     *
     * @param list<class-string> $classes
     * @param list<string> $functions fully qualified
     */
    public static function render(array $classes, array $functions, string $version): string
    {
        $byNamespace = [];
        foreach ($classes as $class) {
            $r = new ReflectionClass($class);
            $byNamespace[$r->getNamespaceName()][] = self::classStub($r);
        }
        foreach ($functions as $function) {
            $r = new ReflectionFunction($function);
            $byNamespace[$r->getNamespaceName()][] = self::doc($r->getDocComment(), '') . 'function ' . $r->getShortName() . self::signature($r) . " {}\n";
        }
        ksort($byNamespace);

        $out = "<?php\n\n// Kestello {$version}: stubs of the public API for editors and static analysis.\n"
            . "// Made by `bin/cms ide:helpers --stubs`. Not loaded by anything: never include this file.\n";
        foreach ($byNamespace as $namespace => $stubs) {
            $out .= "\nnamespace {$namespace} {\n\n" . implode("\n", $stubs) . "}\n";
        }

        return $out;
    }

    /** @param ReflectionClass<object> $r */
    private static function classStub(ReflectionClass $r): string
    {
        $head = match (true) {
            $r->isInterface() => 'interface ',
            $r->isTrait() => 'trait ',
            $r->isEnum() => 'enum ',
            default => ($r->isFinal() ? 'final ' : '') . ($r->isAbstract() ? 'abstract ' : '') . ($r->isReadOnly() ? 'readonly ' : '') . 'class ',
        };
        $line = $head . $r->getShortName();
        if ($r->isEnum()) {
            $backing = (new ReflectionEnum($r->getName()))->getBackingType();
            $line .= $backing !== null ? ': ' . $backing : '';
        }
        $parent = $r->getParentClass();
        if ($parent !== false) {
            $line .= ' extends \\' . $parent->getName();
        }
        $interfaces = array_values(array_filter(
            $r->getInterfaceNames(),
            static fn(string $i) => !in_array($i, ['UnitEnum', 'BackedEnum'], true) && ($parent === false || !in_array($i, $parent->getInterfaceNames(), true)),
        ));
        if ($interfaces !== []) {
            $line .= ($r->isInterface() ? ' extends ' : ' implements ') . implode(', ', array_map(static fn(string $i) => '\\' . $i, $interfaces));
        }

        $body = '';
        if ($r->isEnum()) {
            foreach ((new ReflectionEnum($r->getName()))->getCases() as $case) {
                $value = $case instanceof \ReflectionEnumBackedCase ? ' = ' . var_export($case->getBackingValue(), true) : '';
                $body .= "    case {$case->getName()}{$value};\n";
            }
        }
        foreach ($r->getReflectionConstants() as $c) {
            if ($c->getDeclaringClass()->getName() !== $r->getName() || $c->isPrivate() || ($r->isEnum() && $c->isEnumCase())) {
                continue;
            }
            $body .= self::doc($c->getDocComment(), '    ') . '    ' . self::constVisibility($c) . 'const ' . $c->getName() . ' = ' . self::export($c->getValue()) . ";\n";
        }
        foreach ($r->getProperties() as $p) {
            if ($p->getDeclaringClass()->getName() !== $r->getName() || $p->isPrivate() || $p->isPromoted() || $r->isEnum()) {
                continue;
            }
            $body .= self::doc($p->getDocComment(), '    ') . '    ' . self::propertyStub($p) . ";\n";
        }
        foreach ($r->getMethods() as $m) {
            if ($m->getDeclaringClass()->getName() !== $r->getName() || $m->isPrivate() || preg_match('/@internal\b/', (string) $m->getDocComment()) === 1) {
                continue;
            }
            if ($r->isEnum() && in_array($m->getName(), ['cases', 'from', 'tryFrom'], true)) {
                continue;
            }
            $mods = ($m->isAbstract() && !$r->isInterface() ? 'abstract ' : '') . ($m->isFinal() && !$r->isFinal() ? 'final ' : '')
                . ($m->isProtected() ? 'protected ' : 'public ') . ($m->isStatic() ? 'static ' : '');
            $end = $m->isAbstract() || $r->isInterface() ? ';' : ' {}';
            $body .= self::doc($m->getDocComment(), '    ') . '    ' . $mods . 'function ' . $m->getName() . self::signature($m) . $end . "\n";
        }

        return self::doc($r->getDocComment(), '') . $line . "\n{\n" . $body . "}\n";
    }

    private static function constVisibility(ReflectionClassConstant $c): string
    {
        return ($c->isFinal() ? 'final ' : '') . ($c->isProtected() ? 'protected ' : 'public ');
    }

    private static function propertyStub(ReflectionProperty $p): string
    {
        $out = ($p->isProtected() ? 'protected ' : 'public ') . ($p->isStatic() ? 'static ' : '') . ($p->isReadOnly() ? 'readonly ' : '');
        $out .= $p->hasType() ? self::type($p->getType()) . ' ' : '';
        $out .= '$' . $p->getName();
        if ($p->hasDefaultValue() && !$p->isReadOnly()) {
            $out .= ' = ' . self::export($p->getDefaultValue());
        }

        return $out;
    }

    private static function signature(ReflectionFunctionAbstract $f): string
    {
        $params = array_map(self::parameter(...), $f->getParameters());
        $promoted = $f instanceof ReflectionMethod && $f->isConstructor();
        if ($promoted) {
            foreach ($f->getParameters() as $i => $p) {
                if ($p->isPromoted()) {
                    $prop = new ReflectionProperty($f->getDeclaringClass()->getName(), $p->getName());
                    $params[$i] = ($prop->isProtected() ? 'protected ' : ($prop->isPrivate() ? 'private ' : 'public ')) . ($prop->isReadOnly() ? 'readonly ' : '') . $params[$i];
                }
            }
        }
        $return = $f->hasReturnType() ? ': ' . self::type($f->getReturnType()) : '';

        return '(' . implode(', ', $params) . ')' . $return;
    }

    private static function parameter(ReflectionParameter $p): string
    {
        $out = $p->hasType() ? self::type($p->getType()) . ' ' : '';
        $out .= ($p->isPassedByReference() ? '&' : '') . ($p->isVariadic() ? '...' : '') . '$' . $p->getName();
        if ($p->isDefaultValueAvailable()) {
            $constant = $p->isDefaultValueConstant() ? $p->getDefaultValueConstantName() : null;
            $out .= ' = ' . ($constant !== null ? self::constantName($constant) : self::export($p->getDefaultValue()));
        }

        return $out;
    }

    private static function constantName(string $name): string
    {
        if (str_contains($name, '::')) {
            [$class, $const] = explode('::', $name, 2);
            return (in_array(strtolower($class), ['self', 'static', 'parent'], true) ? $class : '\\' . ltrim($class, '\\')) . '::' . $const;
        }

        return '\\' . ltrim($name, '\\');
    }

    private static function type(?ReflectionType $type): string
    {
        if ($type === null) {
            return '';
        }
        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();
            $builtin = $type->isBuiltin() || in_array(strtolower($name), ['self', 'static', 'parent'], true);
            $text = $builtin ? $name : '\\' . $name;

            return $type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? '?' . $text : $text;
        }
        if ($type instanceof \ReflectionUnionType) {
            return implode('|', array_map(static fn(ReflectionType $t) => $t instanceof \ReflectionIntersectionType ? '(' . self::type($t) . ')' : self::type($t), $type->getTypes()));
        }
        if ($type instanceof \ReflectionIntersectionType) {
            return implode('&', array_map(self::type(...), $type->getTypes()));
        }

        return (string) $type;
    }

    private static function export(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => self::exportArray($value),
            $value instanceof \UnitEnum => '\\' . $value::class . '::' . $value->name,
            is_object($value) => 'new \\' . $value::class . '()', // "new" in an initializer: the class is what matters
            default => var_export($value, true),
        };
    }

    /** @param array<array-key, mixed> $value */
    private static function exportArray(array $value): string
    {
        if ($value === []) {
            return '[]';
        }
        $list = array_is_list($value);
        $items = [];
        foreach ($value as $k => $v) {
            $items[] = ($list ? '' : var_export($k, true) . ' => ') . self::export($v);
        }

        return '[' . implode(', ', $items) . ']';
    }

    private static function doc(string|false $doc, string $indent): string
    {
        if ($doc === false || $doc === '') {
            return '';
        }
        $lines = explode("\n", $doc);

        return implode("\n", array_map(static fn(string $l, int $i) => $i === 0 ? $indent . trim($l) : $indent . ' ' . ltrim($l), $lines, array_keys($lines))) . "\n";
    }
}
