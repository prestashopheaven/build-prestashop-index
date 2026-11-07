<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the MIT License
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/MIT
 *
 * @author    prestahaven.com
 * @copyright Since 2026 George van Engers
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace PrestaShopHeaven\Build\PrestashopIndex;

use PrestaShopHeaven\Build\PackageIndex\PhpApiScanner;

/**
 * Collects what a module uses of PrestaShop, statically (no type inference), from its main file, src/, controllers/,
 * classes/ and upgrade/:
 *
 * - classes: imported with use, new X, X::..., extends, implements, instanceof, catch (X ...) and fully qualified names;
 * - methods: static calls X::m(), and self::m() / static::m() / parent::m() / $this->m() inside a class (or a trait,
 *   through the module classes that use it) when the module does not declare m itself, so the call goes to a parent;
 * - constants: X::NAME, and self::NAME / static::NAME / parent::NAME declared in a parent;
 * - functions: global function calls (checked only when PrestaShop declares the function, like pSQL);
 * - hooks: ModuleDefinition::HOOKS (src/Install/ModuleDefinition.php), registerHook('...') and the hook<Name> methods
 *   of the main class and its traits; hooks the module executes itself are its own and left out.
 *
 * Calls on objects ($order->getProducts(), Context::getContext()->shop->id) need type inference and are not collected.
 *
 * @phpstan-type Reference array{kind: string, class: string, name: string, file: string, line: int, via: string}
 * @phpstan-type ModuleClass array{parent: string, traits: list<string>, methods: list<string>, constants: list<string>, trait: bool}
 */
final class ModuleReferences
{
    /** Folders of a module whose PHP is read (besides the main file). */
    public const DIRS = ['src', 'controllers', 'classes', 'upgrade'];

    /** @var list<array{0: int, 1: string, 2: int}|string> */
    private array $tokens = [];

    private string $namespace = '';

    /** @var array<string, string> */
    private array $uses = [];

    /**
     * The references of a module folder, plus its own classes and the hooks it executes itself.
     *
     * @return array{references: list<Reference>, classes: array<string, ModuleClass>, ownHooks: list<string>, files: int}
     */
    public static function collect(string $moduleDir): array
    {
        $moduleDir = rtrim(str_replace('\\', '/', $moduleDir), '/');
        $files = [];
        $main = "$moduleDir/" . basename($moduleDir) . '.php';
        if (is_file($main)) {
            $files[] = $main;
        }
        foreach (self::DIRS as $dir) {
            array_push($files, ...Generator::files("$moduleDir/$dir", ['php']));
        }
        $collector = new self();
        $references = [];
        $classes = [];
        $ownHooks = [];
        $hookMethods = [];
        foreach ($files as $file) {
            $relative = substr($file, strlen($moduleDir) + 1);
            $tokens = PhpApiScanner::tokens((string) file_get_contents($file));
            $found = $collector->scan($tokens, $relative);
            array_push($references, ...$found['references']);
            $classes += $found['classes'];
            array_push($ownHooks, ...HookCalls::fromTokens($tokens)['names']);
            if ($file === $main || str_starts_with($relative, 'src/Module/')) {
                array_push($hookMethods, ...$found['hookMethods']);
            }
            if ($relative === 'src/Install/ModuleDefinition.php') {
                array_push($references, ...self::definitionHooks($tokens, $relative));
            }
        }
        array_push($references, ...$hookMethods);
        $ownHooks = array_values(array_unique($ownHooks));

        return [
            'references' => array_values(array_filter($references, static fn (array $r): bool => $r['kind'] !== 'hook' || !in_array($r['name'], $ownHooks, true))),
            'classes' => $classes,
            'ownHooks' => $ownHooks,
            'files' => count($files),
        ];
    }

    /**
     * The hooks of the constant HOOKS in ModuleDefinition (every string literal in its array).
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<Reference>
     */
    public static function definitionHooks(array $tokens, string $file): array
    {
        $hooks = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_CONST) {
                continue;
            }
            $j = $i + 1;
            while ($j < $count && (!is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING)) {
                ++$j;
            }
            if ($j >= $count || $tokens[$j][1] !== 'HOOKS') {
                continue;
            }
            for (; $j < $count && $tokens[$j] !== ';'; ++$j) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                    $hooks[] = self::reference('hook', '', substr($tokens[$j][1], 1, -1), $file, $tokens[$j][2], 'ModuleDefinition::HOOKS');
                }
            }
        }

        return $hooks;
    }

    /**
     * Scans the tokens of one file.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{references: list<Reference>, classes: array<string, ModuleClass>, hookMethods: list<Reference>}
     */
    public function scan(array $tokens, string $file): array
    {
        $this->tokens = $tokens;
        $this->namespace = '';
        $this->uses = [];
        $count = count($tokens);
        $references = [];
        $classes = [];
        $hookMethods = [];
        $class = null;
        $classDepth = -1;
        $depth = 0;
        for ($i = 0; $i < $count; ++$i) {
            $t = $tokens[$i];
            if ($t === '{' || (is_array($t) && ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES))) {
                ++$depth;
                continue;
            }
            if ($t === '}') {
                if (--$depth === $classDepth) {
                    $class = null;
                    $classDepth = -1;
                }
                continue;
            }
            if (!is_array($t)) {
                continue;
            }
            [$id, $text, $line] = $t;
            if ($id === T_NAMESPACE && $this->isName($this->next($i))) {
                $this->namespace = $this->text($this->next($i));
                $this->uses = [];
            } elseif ($id === T_USE && $class === null && $depth === 0 || ($id === T_USE && $class === null && $this->namespaceBlock($depth))) {
                foreach ($this->readImports($i) as [$name, $useLine]) {
                    $references[] = self::reference('class', $name, '', $file, $useLine, 'use');
                }
            } elseif (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && $this->isDeclaration($i)) {
                $class = $this->qualify($this->text($this->next($i)));
                $info = $this->readClassHeader($i, $file, $references);
                $info['trait'] = $id === T_TRAIT;
                $classes[$class] = $info;
                $classDepth = $depth;
            } elseif ($id === T_USE && $class !== null && $depth === $classDepth + 1) {
                [$traits, $i] = $this->readTraitUse($i);
                array_push($classes[$class]['traits'], ...$traits);
            } elseif ($id === T_FUNCTION && $class !== null && $depth === $classDepth + 1 && $this->isName($this->next($i))) {
                $method = $this->text($this->next($i));
                $classes[$class]['methods'][] = strtolower($method);
                if (preg_match('/^hook([A-Za-z0-9_]+)$/i', $method, $m)) {
                    $hookMethods[] = self::reference('hook', '', lcfirst($m[1]), $file, $line, "method $method");
                }
            } elseif ($id === T_CONST && $class !== null && $depth === $classDepth + 1) {
                $classes[$class]['constants'][] = $this->constantName($i);
            } elseif ($id === T_NEW && $this->isName($this->next($i)) && !in_array(strtolower($this->text($this->next($i))), ['self', 'static', 'parent'], true)) {
                $references[] = self::reference('class', $this->resolve($this->text($this->next($i))), '', $file, $line, 'new');
            } elseif (($id === T_INSTANCEOF) && $this->isName($this->next($i))) {
                $references[] = self::reference('class', $this->resolve($this->text($this->next($i))), '', $file, $line, 'instanceof');
            } elseif ($id === T_CATCH) {
                foreach ($this->catchTypes($i) as $type) {
                    $references[] = self::reference('class', $type, '', $file, $line, 'catch');
                }
            } elseif ($id === T_DOUBLE_COLON) {
                $reference = $this->staticAccess($i, $class, $file);
                if ($reference !== null) {
                    $references[] = $reference;
                }
            } elseif ($id === T_VARIABLE && $text === '$this' && $class !== null) {
                $arrow = $this->next($i);
                $name = $arrow === null ? null : $this->next($arrow);
                $after = $name === null ? null : $this->next($name);
                if ($arrow !== null && $name !== null && $after !== null && is_array($tokens[$arrow]) && $tokens[$arrow][0] === T_OBJECT_OPERATOR
                    && is_array($tokens[$name]) && $tokens[$name][0] === T_STRING && $tokens[$after] === '(') {
                    $references[] = self::reference('method', $class, $tokens[$name][1], $file, $line, '$this');
                }
            } elseif ($id === T_STRING && $this->isFunctionCall($i)) {
                $references[] = self::reference('function', '', $text, $file, $line, 'call');
            } elseif ($id === T_STRING && in_array(strtolower($text), ['registerhook', 'unregisterhook'], true) && $this->previousIsArrow($i)
                && $this->text($this->next($i)) === '(') {
                foreach ($this->stringArguments($i) as [$hook, $hookLine]) {
                    $references[] = self::reference('hook', '', $hook, $file, $hookLine, $text);
                }
            } elseif ($id === T_NAME_FULLY_QUALIFIED) {
                $isCall = $this->isFunctionCall($i);
                $references[] = $isCall
                    ? self::reference('function', '', substr($text, 1), $file, $line, 'call')
                    : self::reference('class', substr($text, 1), '', $file, $line, 'name');
            }
        }

        return ['references' => $references, 'classes' => $classes, 'hookMethods' => $hookMethods];
    }

    /**
     * Reads "extends X implements Y, Z" of the class at $i; adds them as references and returns the class info.
     *
     * @param list<Reference> $references
     *
     * @return ModuleClass
     */
    private function readClassHeader(int $i, string $file, array &$references): array
    {
        $info = ['parent' => '', 'traits' => [], 'methods' => [], 'constants' => [], 'trait' => false];
        $list = null;
        for ($j = $i + 1; $j < count($this->tokens) && $this->tokens[$j] !== '{'; ++$j) {
            $t = $this->tokens[$j];
            if (is_array($t) && ($t[0] === T_EXTENDS || $t[0] === T_IMPLEMENTS)) {
                $list = $t[0] === T_EXTENDS ? 'extends' : 'implements';
            } elseif ($list !== null && $this->isName($j)) {
                $name = $this->resolve($t[1]);
                $references[] = self::reference('class', $name, '', $file, $t[2], $list);
                if ($list === 'extends' && $info['parent'] === '') {
                    $info['parent'] = $name;
                }
            }
        }

        return $info;
    }

    /**
     * The reference of "X::name" at the "::" token $i (method call or constant), or null for ::class, static
     * properties and members the module declares itself.
     *
     * @return Reference|null
     */
    private function staticAccess(int $i, ?string $class, string $file): ?array
    {
        $before = $this->previous($i);
        $after = $this->next($i);
        if ($before === null || $after === null || !$this->isName($before) || !is_array($this->tokens[$after]) || $this->tokens[$after][0] !== T_STRING) {
            return null;
        }
        $member = $this->tokens[$after][1];
        if (strtolower($member) === 'class') {
            return null;
        }
        $receiver = strtolower($this->text($before));
        $call = $this->next($after);
        $isCall = $call !== null && $this->tokens[$call] === '(';
        $line = $this->tokens[$after][2];
        if (in_array($receiver, ['self', 'static', 'parent'], true)) {
            if ($class === null) {
                return null;
            }

            return self::reference($isCall ? 'method' : 'constant', $class, $member, $file, $line, $receiver);
        }

        return self::reference($isCall ? 'method' : 'constant', $this->resolve($this->text($before)), $member, $file, $line, 'static');
    }

    /**
     * The types of "catch (A | B $e)" at the catch token $i, resolved.
     *
     * @return list<string>
     */
    private function catchTypes(int $i): array
    {
        $types = [];
        for ($j = $i + 1; $j < count($this->tokens) && $this->tokens[$j] !== ')'; ++$j) {
            if ($this->isName($j)) {
                $types[] = $this->resolve($this->text($j));
            }
        }

        return $types;
    }

    /**
     * The string literals among the arguments of the call whose name is at $i ("registerHook('a')" or
     * "registerHook(['a', 'b'])"), with their lines.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function stringArguments(int $i): array
    {
        $open = (int) $this->next($i);
        $strings = [];
        $depth = 0;
        for ($j = $open; $j < count($this->tokens); ++$j) {
            $t = $this->tokens[$j];
            if ($t === '(') {
                ++$depth;
            } elseif ($t === ')' && --$depth === 0) {
                break;
            } elseif (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $strings[] = [substr($t[1], 1, -1), $t[2]];
            }
        }

        return $strings;
    }

    /**
     * Reads a top-level use statement at $i into the imports; returns the imported class names with their line.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function readImports(int $i): array
    {
        $imports = [];
        $prefix = '';
        $current = '';
        $line = 0;
        $alias = null;
        $skip = false;
        for ($j = $i + 1; $j < count($this->tokens); ++$j) {
            $t = $this->tokens[$j];
            $id = is_array($t) ? $t[0] : null;
            if ($id === T_FUNCTION || $id === T_CONST) {
                $skip = true;
            } elseif ($id === T_AS) {
                $alias = $this->text((int) $this->next($j));
                $j = (int) $this->next($j);
            } elseif ($t === '{') {
                $prefix = rtrim($current, '\\') . '\\';
                $current = '';
            } elseif ($t === ',' || $t === '}' || $t === ';') {
                if ($current !== '' && !$skip) {
                    $full = ltrim($prefix . $current, '\\');
                    $this->uses[strtolower($alias ?? substr($full, (int) strrpos('\\' . $full, '\\')))] = $full;
                    $imports[] = [$full, $line];
                }
                $current = '';
                $alias = null;
                if ($t === ';') {
                    break;
                }
            } elseif ($this->isName($j)) {
                $current .= $t[1];
                $line = $t[2];
            }
        }

        return $imports;
    }

    /**
     * The trait names of "use A, B;" or "use A { ... }" inside a class at $i, resolved, and the index of the ";" or of
     * the "}" that closes its conflict block (skipped, so "A::x insteadof B" is not read as a constant).
     *
     * @return array{0: list<string>, 1: int}
     */
    private function readTraitUse(int $i): array
    {
        $traits = [];
        $count = count($this->tokens);
        for ($j = $i + 1; $j < $count && $this->tokens[$j] !== ';' && $this->tokens[$j] !== '{'; ++$j) {
            if ($this->isName($j)) {
                $traits[] = $this->resolve($this->text($j));
            }
        }
        if (($this->tokens[$j] ?? null) === '{') {
            while ($j < $count && $this->tokens[$j] !== '}') {
                ++$j;
            }
        }

        return [$traits, $j];
    }

    /**
     * The name of the first constant after the const token $i.
     */
    private function constantName(int $i): string
    {
        $count = count($this->tokens);
        $j = $i + 1;
        while ($j < $count && !(is_array($this->tokens[$j]) && $this->tokens[$j][0] === T_STRING && $this->text($this->next($j)) === '=')) {
            ++$j;
        }

        return $j < $count ? $this->tokens[$j][1] : '';
    }

    /**
     * True when the class keyword at $i declares a named class (not ::class, not new class).
     */
    private function isDeclaration(int $i): bool
    {
        $before = $this->previous($i);
        $after = $this->next($i);

        return $after !== null && is_array($this->tokens[$after]) && $this->tokens[$after][0] === T_STRING
            && ($before === null || !is_array($this->tokens[$before]) || !in_array($this->tokens[$before][0], [T_DOUBLE_COLON, T_NEW], true));
    }

    /**
     * True when the name at $i is called as a global function: followed by "(", not a method, not a declaration and
     * not after new.
     */
    private function isFunctionCall(int $i): bool
    {
        $after = $this->next($i);
        $before = $this->previous($i);
        if ($after === null || $this->tokens[$after] !== '(') {
            return false;
        }

        return $before === null || !is_array($this->tokens[$before]) || !in_array($this->tokens[$before][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST], true);
    }

    /**
     * True when the token before $i is -> or ?->.
     */
    private function previousIsArrow(int $i): bool
    {
        $before = $this->previous($i);

        return $before !== null && is_array($this->tokens[$before]) && in_array($this->tokens[$before][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
    }

    /**
     * True at the depth of a braced namespace block ("namespace X { use ...; }").
     */
    private function namespaceBlock(int $depth): bool
    {
        return $depth === 1 && $this->namespace !== '';
    }

    /**
     * True when the token at $i is a name.
     */
    private function isName(?int $i): bool
    {
        return $i !== null && is_array($this->tokens[$i]) && in_array($this->tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_STATIC], true);
    }

    /**
     * The text of the token at $i.
     */
    private function text(?int $i): string
    {
        $t = $i === null ? '' : $this->tokens[$i];

        return is_array($t) ? $t[1] : $t;
    }

    /**
     * A name declared in the current namespace, fully qualified.
     */
    private function qualify(string $name): string
    {
        return $this->namespace === '' ? $name : $this->namespace . '\\' . $name;
    }

    /**
     * Resolves a class name as written against the imports and the namespace.
     */
    private function resolve(string $name): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }
        $first = strtolower(explode('\\', $name)[0]);
        if (isset($this->uses[$first])) {
            return $this->uses[$first] . substr($name, strlen($first));
        }

        return $this->qualify($name);
    }

    /**
     * The index of the next significant token after $i, or null.
     */
    private function next(int $i): ?int
    {
        $count = count($this->tokens);
        ++$i;
        while ($i < $count && $this->isInsignificant($i)) {
            ++$i;
        }

        return $i < $count ? $i : null;
    }

    /**
     * The index of the previous significant token before $i, or null.
     */
    private function previous(int $i): ?int
    {
        --$i;
        while ($i >= 0 && $this->isInsignificant($i)) {
            --$i;
        }

        return $i >= 0 ? $i : null;
    }

    /**
     * True for whitespace and comments.
     */
    private function isInsignificant(int $i): bool
    {
        $t = $this->tokens[$i];

        return is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /**
     * One reference.
     *
     * @return Reference
     */
    private static function reference(string $kind, string $class, string $name, string $file, int $line, string $via): array
    {
        return ['kind' => $kind, 'class' => $class, 'name' => $name, 'file' => $file, 'line' => $line, 'via' => $via];
    }
}
