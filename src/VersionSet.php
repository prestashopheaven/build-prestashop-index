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
 * A set of PrestaShop releases written compactly as ranges of their positions in the merged file's version list:
 * [0, 1, 2, 5] <=> "0-2,5". Most symbols exist in one unbroken range, so this keeps the merged file small.
 */
final class VersionSet
{
    /**
     * The compact form of a list of version positions.
     *
     * @param list<int> $positions
     */
    public static function encode(array $positions): string
    {
        $positions = array_values(array_unique($positions));
        sort($positions);
        $parts = [];
        $start = $previous = null;
        foreach ($positions as $position) {
            if ($previous !== null && $position === $previous + 1) {
                $previous = $position;
                continue;
            }
            if ($start !== null) {
                $parts[] = $start === $previous ? (string) $start : "$start-$previous";
            }
            $start = $previous = $position;
        }
        if ($start !== null) {
            $parts[] = $start === $previous ? (string) $start : "$start-$previous";
        }

        return implode(',', $parts);
    }

    /**
     * The version positions of a compact set.
     *
     * @return list<int>
     */
    public static function decode(string $set): array
    {
        $positions = [];
        foreach (array_filter(explode(',', $set), static fn (string $p): bool => $p !== '') as $part) {
            [$from, $to] = str_contains($part, '-') ? array_map('intval', explode('-', $part, 2)) : [(int) $part, (int) $part];
            for ($i = $from; $i <= $to; ++$i) {
                $positions[] = $i;
            }
        }

        return $positions;
    }

    /**
     * The versions of a compact set as readable text: "8.1.0 – 9.2.0" for an unbroken range, else the list.
     *
     * @param list<string> $versions the version list of the merged file
     */
    public static function describe(string $set, array $versions): string
    {
        $parts = [];
        foreach (array_filter(explode(',', $set), static fn (string $p): bool => $p !== '') as $part) {
            [$from, $to] = str_contains($part, '-') ? array_map('intval', explode('-', $part, 2)) : [(int) $part, (int) $part];
            $parts[] = $from === $to ? ($versions[$from] ?? '?') : ($versions[$from] ?? '?') . ' – ' . ($versions[$to] ?? '?');
        }

        return implode(', ', $parts);
    }
}
