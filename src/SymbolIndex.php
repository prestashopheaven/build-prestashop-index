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

/**
 * Lookups in the merged symbol file, case-insensitive like PHP and PrestaShop: classes (and the legacy aliases such as
 * Order => OrderCore), methods and constants through the parents a class has in a given release, functions, hooks
 * (by name, alias or run-time pattern), and the vendor packages per release.
 *
 * @phpstan-import-type Merged from Merger
 * @phpstan-import-type MergedClass from Merger
 * @phpstan-import-type MergedMethod from Merger
 */
final class SymbolIndex
{
    /** @var array<string, string>|null lowercase class name => its spelling */
    private ?array $classKeys = null;

    /** @var array<string, string>|null lowercase alias => its spelling */
    private ?array $aliasKeys = null;

    /** @var array<string, string>|null lowercase hook => its spelling */
    private ?array $hookKeys = null;

    /** @var array<string, array<string, string>> class => lowercase method => its spelling */
    private array $methodKeys = [];

    /**
     * Wraps merged data (Merger::merge() or the symbol file).
     *
     * @param Merged $data
     */
    public function __construct(private readonly array $data)
    {
    }

    /**
     * The merged data.
     *
     * @return Merged
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * The indexed releases, oldest first.
     *
     * @return list<string>
     */
    public function versions(): array
    {
        return $this->data['versions'];
    }

    /**
     * The positions of the indexed releases from $min to $max (both inclusive; null = no bound).
     *
     * @return list<int>
     */
    public function positionsInRange(?string $min, ?string $max): array
    {
        $positions = [];
        foreach ($this->data['versions'] as $n => $version) {
            if (($min === null || version_compare($version, $min, '>=')) && ($max === null || version_compare($version, $max, '<='))) {
                $positions[] = $n;
            }
        }

        return $positions;
    }

    /**
     * The spelling of a class in the index, following a legacy alias (Order => OrderCore); null when unknown.
     */
    public function className(string $name): ?string
    {
        $lower = strtolower(ltrim($name, '\\'));
        $this->classKeys ??= self::keys($this->data['classes']);
        if (isset($this->classKeys[$lower])) {
            return $this->classKeys[$lower];
        }
        $this->aliasKeys ??= self::keys($this->data['aliases']);

        return isset($this->aliasKeys[$lower]) ? $this->data['aliases'][$this->aliasKeys[$lower]]['t'] : null;
    }

    /**
     * The merged entry of a class (by name or legacy alias), or null.
     *
     * @return MergedClass|null
     */
    public function classEntry(string $name): ?array
    {
        $class = $this->className($name);

        return $class === null ? null : $this->data['classes'][$class];
    }

    /**
     * The positions of the releases in which a class name can be used: its own set, or the alias's set for a legacy
     * alias.
     *
     * @return list<int>
     */
    public function classPositions(string $name): array
    {
        $lower = strtolower(ltrim($name, '\\'));
        $this->classKeys ??= self::keys($this->data['classes']);
        if (isset($this->classKeys[$lower])) {
            return VersionSet::decode($this->data['classes'][$this->classKeys[$lower]]['v']);
        }
        $this->aliasKeys ??= self::keys($this->data['aliases']);

        return isset($this->aliasKeys[$lower]) ? VersionSet::decode($this->data['aliases'][$this->aliasKeys[$lower]]['v']) : [];
    }

    /**
     * Finds a method on a class or its parents (extends, implements, traits) as they are in release $position; returns
     * the class that declares it and its entry, or null.
     *
     * @return array{0: string, 1: MergedMethod}|null
     */
    public function findMethod(string $class, string $method, int $position): ?array
    {
        return $this->walk($class, $position, function (string $name, array $entry) use ($method, $position): ?array {
            $this->methodKeys[$name] ??= self::keys($entry['m'] ?? []);
            $spelling = $this->methodKeys[$name][strtolower($method)] ?? null;
            if ($spelling === null || !in_array($position, VersionSet::decode($entry['m'][$spelling]['v']), true)) {
                return null;
            }

            return [$name, $entry['m'][$spelling]];
        });
    }

    /**
     * Finds a constant on a class or its parents in release $position; returns the declaring class, or null.
     */
    public function findConstant(string $class, string $constant, int $position): ?string
    {
        $found = $this->walk($class, $position, static function (string $name, array $entry) use ($constant, $position): ?array {
            return isset($entry['c'][$constant]) && in_array($position, VersionSet::decode($entry['c'][$constant]), true) ? [$name] : null;
        });

        return $found === null ? null : $found[0];
    }

    /**
     * True when every parent of a class is in the index (or it has none), so a method or constant missing in it is
     * really missing and not declared by a vendor class the index does not hold.
     */
    public function hasKnownAncestry(string $class): bool
    {
        $seen = [];
        $queue = [$class];
        while ($queue !== []) {
            $name = $this->className((string) array_shift($queue));
            if ($name === null) {
                return false;
            }
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            array_push($queue, ...$this->data['classes'][$name]['p'] ?? []);
        }

        return true;
    }

    /**
     * The positions in which a hook exists: by its name, an alias of it, or a run-time pattern that matches it; with
     * how it was found ("name", "alias of X", "pattern X") per position.
     *
     * @return array<int, string>
     */
    public function hookPositions(string $hook): array
    {
        $this->hookKeys ??= self::keys($this->data['hooks']);
        $found = [];
        $spelling = $this->hookKeys[strtolower($hook)] ?? null;
        if ($spelling !== null) {
            foreach (VersionSet::decode($this->data['hooks'][$spelling]['v']) as $n) {
                $found[$n] = 'name';
            }
        }
        foreach ($this->data['hookAliases'] as $alias => $aliasData) {
            if (strcasecmp($alias, $hook) === 0) {
                foreach (VersionSet::decode($aliasData['v']) as $n) {
                    $found[$n] ??= 'alias of ' . $aliasData['t'];
                }
            }
        }
        foreach ($this->data['hookPatterns'] as $pattern => $set) {
            if (HookCalls::matches($pattern, $hook)) {
                foreach (VersionSet::decode($set) as $n) {
                    $found[$n] ??= "pattern $pattern";
                }
            }
        }
        ksort($found);

        return $found;
    }

    /**
     * The merged entry of a global function (pSQL, ...), or null.
     *
     * @return array{v: string, g: string}|null
     */
    public function functionEntry(string $name): ?array
    {
        foreach ($this->data['functions'] as $function => $entry) {
            if (strcasecmp($function, $name) === 0) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The version of a vendor package per release ("9.2.0" => "v6.4.1"); empty when PrestaShop never ships it.
     *
     * @return array<string, string>
     */
    public function vendorVersions(string $package): array
    {
        return $this->data['vendor'][strtolower($package)] ?? [];
    }

    /**
     * Breadth-first walk over a class and its parents as they exist in release $position; returns the first non-null
     * result of $visit(name, entry).
     *
     * @param callable(string, MergedClass): (array<int, mixed>|null) $visit
     *
     * @return array<int, mixed>|null
     */
    private function walk(string $class, int $position, callable $visit): ?array
    {
        $seen = [];
        $queue = [$class];
        while ($queue !== []) {
            $name = $this->className((string) array_shift($queue));
            if ($name === null || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $entry = $this->data['classes'][$name];
            if (!in_array($position, VersionSet::decode($entry['v']), true)) {
                continue;
            }
            $result = $visit($name, $entry);
            if ($result !== null) {
                return $result;
            }
            array_push($queue, ...$entry['p'] ?? []);
        }

        return null;
    }

    /**
     * Lowercase key => key of an array.
     *
     * @param array<string, mixed> $entries
     *
     * @return array<string, string>
     */
    private static function keys(array $entries): array
    {
        $keys = [];
        foreach (array_keys($entries) as $key) {
            $keys[strtolower((string) $key)] ??= (string) $key;
        }

        return $keys;
    }
}
