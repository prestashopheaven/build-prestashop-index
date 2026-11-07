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
 * Merges the indexes of several PrestaShop releases into the symbol file: every symbol with the releases it exists in
 * (a VersionSet over the "versions" list), the releases where it is deprecated, and its signature per release when the
 * signature changes. ps find and ps check read only this file. Vendor packages keep their version per release.
 *
 * @phpstan-import-type VersionIndex from Generator
 *
 * @phpstan-type MergedMethod array{v: string, g: string|array<string, string>, s?: int, vis?: string, d?: string, dm?: string}
 * @phpstan-type MergedClass array{k: string, f: string, v: string, p?: list<string>, d?: string, dm?: string, c?: array<string, string>, m?: array<string, MergedMethod>}
 * @phpstan-type Merged array{format: int, versions: list<string>, classes: array<string, MergedClass>, aliases: array<string, array{t: string, v: string}>, functions: array<string, array{v: string, g: string}>, hooks: array<string, array{v: string, t: string, s: list<string>}>, hookAliases: array<string, array{t: string, v: string}>, hookPatterns: array<string, string>, services: array<string, array{v: string, c: string}>, servicePrefixes: array<string, string>, config: array<string, string>, tables: array<string, array{v: string, c: array<string, string>}>, vendor: array<string, array<string, string>>}
 */
final class Merger
{
    /** The version of the JSON layout. */
    public const FORMAT = 1;

    /**
     * Merges release indexes (in any order; the result lists the versions oldest first).
     *
     * @param list<VersionIndex> $indexes
     *
     * @return Merged
     */
    public static function merge(array $indexes): array
    {
        usort($indexes, static fn (array $a, array $b): int => version_compare($a['version'], $b['version']));
        $versions = array_map(static fn (array $index): string => $index['version'], $indexes);
        $classes = $aliases = $functions = $hooks = $hookAliases = $patterns = $services = $prefixes = $config = $tables = $vendor = [];
        foreach ($indexes as $n => $index) {
            foreach ($index['classes'] as $name => $class) {
                $merged = $classes[$name] ?? ['k' => $class['k'], 'f' => $class['f'], 'v' => [], 'p' => [], 'd' => [], 'c' => [], 'm' => []];
                $merged['k'] = $class['k'];
                $merged['f'] = $class['f'];
                $merged['v'][] = $n;
                $merged['p'] = array_values(array_unique(array_merge($merged['p'], $class['p'] ?? [])));
                if (isset($class['d'])) {
                    $merged['d'][] = $n;
                    $merged['dm'] = $class['d'];
                }
                foreach ($class['c'] ?? [] as $constant => $visibility) {
                    $merged['c'][$constant][] = $n;
                }
                foreach ($class['m'] ?? [] as $method => $data) {
                    $m = $merged['m'][$method] ?? ['v' => [], 'g' => [], 'vis' => [], 'd' => []];
                    $m['v'][] = $n;
                    $m['g'][$data['g']][] = $n;
                    $m['vis'][$data['v']][] = $n;
                    if (isset($data['s'])) {
                        $m['s'] = 1;
                    }
                    if (isset($data['d'])) {
                        $m['d'][] = $n;
                        $m['dm'] = $data['d'];
                    }
                    $merged['m'][$method] = $m;
                }
                $classes[$name] = $merged;
            }
            foreach ($index['aliases'] as $alias => $target) {
                $aliases[$alias] = ['t' => $target, 'v' => array_merge($aliases[$alias]['v'] ?? [], [$n])];
            }
            foreach ($index['functions'] as $name => $function) {
                $functions[$name] = ['v' => array_merge($functions[$name]['v'] ?? [], [$n]), 'g' => $function['g']];
            }
            foreach ($index['hooks'] as $name => $hook) {
                $known = $hooks[$name] ?? ['v' => [], 't' => '', 's' => []];
                $hooks[$name] = [
                    'v' => array_merge($known['v'], [$n]),
                    't' => $hook['t'] !== '' ? $hook['t'] : $known['t'],
                    's' => array_values(array_unique(array_merge($known['s'], $hook['s']))),
                ];
            }
            foreach ($index['hookAliases'] as $alias => $target) {
                $hookAliases[$alias] = ['t' => $target, 'v' => array_merge($hookAliases[$alias]['v'] ?? [], [$n])];
            }
            foreach (array_keys($index['hookPatterns']) as $pattern) {
                $patterns[$pattern][] = $n;
            }
            foreach ($index['services'] as $id => $class) {
                $services[$id] = ['v' => array_merge($services[$id]['v'] ?? [], [$n]), 'c' => $class];
            }
            foreach ($index['servicePrefixes'] as $prefix) {
                $prefixes[$prefix][] = $n;
            }
            foreach (array_keys($index['config']) as $key) {
                $config[$key][] = $n;
            }
            foreach ($index['tables'] as $table => $columns) {
                $tables[$table]['v'][] = $n;
                foreach ($columns as $column) {
                    $tables[$table]['c'][$column][] = $n;
                }
            }
            foreach ($index['vendor'] as $package => $data) {
                $vendor[$package][$index['version']] = $data['v'];
            }
        }

        return [
            'format' => self::FORMAT,
            'versions' => $versions,
            'classes' => array_map(self::finishClass(...), $classes),
            'aliases' => self::finishSets($aliases),
            'functions' => self::finishSets($functions),
            'hooks' => self::finishSets($hooks),
            'hookAliases' => self::finishSets($hookAliases),
            'hookPatterns' => array_map([VersionSet::class, 'encode'], $patterns),
            'services' => self::finishSets($services),
            'servicePrefixes' => array_map([VersionSet::class, 'encode'], $prefixes),
            'config' => array_map([VersionSet::class, 'encode'], $config),
            'tables' => array_map(static fn (array $t): array => ['v' => VersionSet::encode($t['v']), 'c' => array_map([VersionSet::class, 'encode'], $t['c'] ?? [])], $tables),
            'vendor' => $vendor,
        ];
    }

    /**
     * Encodes the version lists of a merged class and drops what is empty or always the same.
     *
     * @param array{k: string, f: string, v: list<int>, p: list<string>, d: list<int>, dm?: string, c: array<string, list<int>>, m: array<string, array{v: list<int>, g: array<string, list<int>>, vis: array<string, list<int>>, d: list<int>, s?: int, dm?: string}>} $class
     *
     * @return MergedClass
     */
    private static function finishClass(array $class): array
    {
        $out = ['k' => $class['k'], 'f' => $class['f'], 'v' => VersionSet::encode($class['v'])];
        if ($class['p'] !== []) {
            $out['p'] = $class['p'];
        }
        if ($class['d'] !== []) {
            $out['d'] = VersionSet::encode($class['d']);
            $out['dm'] = (string) ($class['dm'] ?? '');
        }
        if ($class['c'] !== []) {
            $out['c'] = array_map([VersionSet::class, 'encode'], $class['c']);
        }
        foreach ($class['m'] as $name => $method) {
            $m = ['v' => VersionSet::encode($method['v'])];
            $m['g'] = count($method['g']) === 1 ? (string) array_key_first($method['g']) : array_map([VersionSet::class, 'encode'], $method['g']);
            if (isset($method['s'])) {
                $m['s'] = 1;
            }
            if (array_keys($method['vis']) !== ['public']) {
                $m['vis'] = count($method['vis']) === 1 ? (string) array_key_first($method['vis']) : implode(',', array_map(static fn (string $vis, array $set): string => "$vis:" . VersionSet::encode($set), array_keys($method['vis']), $method['vis']));
            }
            if ($method['d'] !== []) {
                $m['d'] = VersionSet::encode($method['d']);
                $m['dm'] = (string) ($method['dm'] ?? '');
            }
            $out['m'][$name] = $m;
        }
        ksort($out);

        return $out;
    }

    /**
     * Encodes the "v" list of every entry.
     *
     * @template T of array{v: list<int>}
     *
     * @param array<string, T> $entries
     *
     * @return array<string, mixed>
     */
    private static function finishSets(array $entries): array
    {
        foreach ($entries as $name => $entry) {
            $entry['v'] = VersionSet::encode($entry['v']);
            $entries[$name] = $entry;
        }

        return $entries;
    }
}
