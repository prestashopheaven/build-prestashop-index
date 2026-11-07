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

use PrestaShopHeaven\Build\PackageIndex\TextTokens;

/**
 * ps find "<term>": does PrestaShop already have it? Searches the merged symbols (classes, methods, constants,
 * functions, hooks with their titles, services, configuration keys, tables) and returns where it is, what it is, the
 * releases it exists in, its signature(s) and any deprecation. A name that equals the term ranks first, then one that
 * starts with it, then one that contains it, then one whose words hold every word of the term ("order status" finds
 * actionOrderStatusUpdate). "Class::method" searches the members of matching classes.
 *
 * @phpstan-type Hit array{score: int, kind: string, name: string, versions: string, deprecated: string, signature: string, detail: string}
 */
final class Finder
{
    /** Small bonus per kind, so a class outranks a method of the same score. */
    private const KIND_BONUS = ['class' => 5, 'hook' => 4, 'function' => 4, 'service' => 3, 'config' => 3, 'method' => 2, 'constant' => 1, 'table' => 1];

    /**
     * The best hits for a term, highest score first.
     *
     * @return list<Hit>
     */
    public static function find(SymbolIndex $index, string $term, int $limit = 20): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        $data = $index->data();
        $versions = $data['versions'];
        $member = null;
        if (str_contains($term, '::')) {
            [$term, $member] = explode('::', $term, 2);
        }
        $words = array_values(array_unique(TextTokens::of($term)));
        $hits = [];
        foreach ($data['classes'] as $name => $class) {
            $short = substr($name, (int) strrpos('\\' . $name, '\\'));
            $score = self::score($term, $words, [$short, $name]);
            if ($member === null && $score > 0) {
                $hits[] = self::hit($score, 'class', $name, $class['v'], $class['d'] ?? '', $class['dm'] ?? '', '', ['c' => 'class', 'i' => 'interface', 't' => 'trait', 'e' => 'enum'][$class['k']] . ' in ' . $class['f'] . (isset($class['p']) ? '; parents: ' . implode(', ', $class['p']) : ''), $versions);
            }
            if ($member !== null && $score === 0) {
                continue;
            }
            foreach ($class['m'] ?? [] as $method => $m) {
                $methodScore = $member !== null ? self::score($member, TextTokens::of($member), [$method]) + intdiv($score, 2) : self::score($term, $words, [$method, "$short::$method"]);
                if ($methodScore > 0 && ($member === null || self::score($member, TextTokens::of($member), [$method]) > 0)) {
                    $signature = is_string($m['g']) ? $m['g'] : implode(' | ', array_map(static fn (string $g, string $set): string => $g . ' [' . VersionSet::describe($set, $versions) . ']', array_keys($m['g']), $m['g']));
                    $hits[] = self::hit($methodScore, 'method', "$name::$method", $m['v'], $m['d'] ?? '', $m['dm'] ?? '', $signature, trim((isset($m['s']) ? 'static ' : '') . ($m['vis'] ?? 'public')), $versions);
                }
            }
            foreach ($class['c'] ?? [] as $constant => $set) {
                $constantScore = $member !== null ? self::score($member, TextTokens::of($member), [$constant]) + intdiv($score, 2) : self::score($term, $words, [$constant]);
                if ($constantScore > 0 && ($member === null || self::score($member, TextTokens::of($member), [$constant]) > 0)) {
                    $hits[] = self::hit($constantScore, 'constant', "$name::$constant", $set, '', '', '', '', $versions);
                }
            }
        }
        if ($member === null) {
            foreach ($data['functions'] as $name => $function) {
                if (($score = self::score($term, $words, [$name])) > 0) {
                    $hits[] = self::hit($score, 'function', $name, $function['v'], '', '', $function['g'], '', $versions);
                }
            }
            foreach ($data['hooks'] as $name => $hook) {
                $score = max(self::score($term, $words, [$name]), intdiv(self::score($term, $words, [$hook['t']]), 2));
                if ($score > 0) {
                    $hits[] = self::hit($score, 'hook', $name, $hook['v'], '', '', '', trim($hook['t'] . ' (' . implode(', ', $hook['s']) . ')'), $versions);
                }
            }
            foreach ($data['services'] as $id => $service) {
                if (($score = self::score($term, $words, [$id, $service['c']])) > 0) {
                    $hits[] = self::hit($score, 'service', $id, $service['v'], '', '', '', $service['c'], $versions);
                }
            }
            foreach ($data['config'] as $key => $set) {
                if (($score = self::score($term, $words, [$key])) > 0) {
                    $hits[] = self::hit($score, 'config', $key, $set, '', '', '', '', $versions);
                }
            }
            foreach ($data['tables'] as $table => $t) {
                if (($score = self::score($term, $words, [$table])) > 0) {
                    $hits[] = self::hit($score, 'table', $table, $t['v'], '', '', '', 'columns: ' . implode(', ', array_keys($t['c'])), $versions);
                }
            }
        }
        usort($hits, static fn (array $a, array $b): int => [$b['score'], $a['name']] <=> [$a['score'], $b['name']]);

        return array_slice($hits, 0, max(1, $limit));
    }

    /**
     * The hits as text lines for the terminal.
     *
     * @param list<Hit> $hits
     *
     * @return list<string>
     */
    public static function format(array $hits): array
    {
        if ($hits === []) {
            return ['PrestaShop has nothing by that name. Next: ps-module find --external "<need>".'];
        }
        $lines = [];
        foreach ($hits as $hit) {
            $several = str_contains($hit['signature'], ' | ');
            $lines[] = sprintf('%-8s %s%s', $hit['kind'], $hit['name'], $several ? '' : $hit['signature']);
            $lines[] = '         in ' . $hit['versions'] . ($hit['deprecated'] !== '' ? '; DEPRECATED in ' . $hit['deprecated'] : '');
            if ($several) {
                $lines[] = '         signatures: ' . $hit['signature'];
            }
            if ($hit['detail'] !== '') {
                $lines[] = '         ' . $hit['detail'];
            }
        }

        return $lines;
    }

    /**
     * The score of the best of a symbol's names: 100 equal, 60 starts with the term, 40 contains it, 10 per word when
     * the name's words hold every word of the term; 0 otherwise.
     *
     * @param list<string> $words the search words of the term
     * @param list<string> $names
     */
    private static function score(string $term, array $words, array $names): int
    {
        $best = 0;
        $lower = strtolower($term);
        foreach ($names as $name) {
            $low = strtolower($name);
            if ($low === $lower) {
                return 100;
            }
            if (str_starts_with($low, $lower)) {
                $best = max($best, 60);
            } elseif (str_contains($low, $lower)) {
                $best = max($best, 40);
            } elseif ($words !== [] && array_diff($words, TextTokens::of($name)) === []) {
                $best = max($best, min(30, 10 * count($words)));
            }
        }

        return $best;
    }

    /**
     * One hit.
     *
     * @param list<string> $versions
     *
     * @return Hit
     */
    private static function hit(int $score, string $kind, string $name, string $set, string $deprecatedSet, string $message, string $signature, string $detail, array $versions): array
    {
        return [
            'score' => $score + self::KIND_BONUS[$kind],
            'kind' => $kind,
            'name' => $name,
            'versions' => VersionSet::describe($set, $versions),
            'deprecated' => $deprecatedSet === '' ? '' : VersionSet::describe($deprecatedSet, $versions) . ($message !== '' ? " ($message)" : ''),
            'signature' => $signature,
            'detail' => $detail,
        ];
    }
}
