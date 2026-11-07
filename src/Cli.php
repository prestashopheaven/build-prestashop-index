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

use PrestaShopHeaven\Build\PackageIndex\Workspace;

/**
 * The commands of bin/prestashop-index.php (routed from ps-module ps find | ps check):
 *
 *   generate <ps-dir> <version> [--no-merge]    index one release (ps-<version>.json.gz) and merge the symbol file
 *   merge                                        merge every indexed release into symbols.json.gz
 *   versions                                     the indexed releases
 *   find "<term>" [--limit=N] [--json]           search PrestaShop's classes, methods, hooks, services, config keys
 *   check <module-dir> [--ps-min=X] [--ps-max=Y] [--json]   check a module against every release of its range
 *
 * Every command takes --data=DIR (default: PS_INDEX_DIR, else data/ of this package). check reads the range from
 * module.spec.json next to the module folder (ps_min, ps_max), else from ps_versions_compliancy in the main file.
 * Exit code 0 = ok, 1 = failed (ps check: an error found), 2 = wrong usage.
 */
final class Cli
{
    /** The usage line. */
    public const USAGE = 'Usage: prestashop-index.php generate <ps-dir> <version> [--no-merge] | merge | versions | find "<term>" [--limit=N] [--json] | check <module-dir> [--ps-min=X] [--ps-max=Y] [--json]  (all: [--data=DIR])';

    /**
     * Runs a command; writes to $out and $err.
     *
     * @param list<string> $args the arguments without the script name
     * @param resource $out
     * @param resource $err
     */
    public static function run(array $args, $out, $err): int
    {
        [$options, $rest] = Workspace::options($args);
        $store = new IndexStore(IndexStore::defaultDir($options['data'] ?? null));
        $json = isset($options['json']);
        try {
            switch ($rest[0] ?? '') {
                case 'generate':
                    if (!isset($rest[1], $rest[2]) || !preg_match('/^\d+\.\d+\.\d+(\.\d+)?$/', $rest[2])) {
                        throw new \InvalidArgumentException(self::USAGE);
                    }
                    $start = microtime(true);
                    $file = $store->saveVersion(Generator::generate($rest[1], $rest[2]));
                    fwrite($out, sprintf("Wrote %s (%.2f MB) in %.1f s.\n", $file, filesize($file) / 1048576, microtime(true) - $start));
                    if (!isset($options['no-merge'])) {
                        $start = microtime(true);
                        $store->merge();
                        $symbols = $store->dir() . '/' . IndexStore::SYMBOLS;
                        fwrite($out, sprintf("Merged %d release(s) into %s (%.2f MB) in %.1f s.\n", count($store->versions()), $symbols, filesize($symbols) / 1048576, microtime(true) - $start));
                    }

                    return 0;
                case 'merge':
                    $merged = $store->merge();
                    fwrite($out, 'Merged ' . implode(', ', $merged['versions']) . ' into ' . $store->dir() . '/' . IndexStore::SYMBOLS . ".\n");

                    return 0;
                case 'versions':
                    fwrite($out, implode("\n", $store->versions()) . "\n");

                    return 0;
                case 'find':
                    if (trim($rest[1] ?? '') === '') {
                        throw new \InvalidArgumentException(self::USAGE);
                    }
                    $hits = Finder::find($store->symbols(), $rest[1], (int) ($options['limit'] ?? 20));
                    fwrite($out, $json ? self::json($hits) : implode("\n", Finder::format($hits)) . "\n");

                    return 0;
                case 'check':
                    if (!isset($rest[1]) || !is_dir($rest[1])) {
                        throw new \InvalidArgumentException(self::USAGE);
                    }
                    [$min, $max] = self::range($rest[1]);
                    $result = ModuleChecker::check($store->symbols(), ModuleReferences::collect($rest[1]), $options['ps-min'] ?? $min, $options['ps-max'] ?? $max);
                    fwrite($out, $json ? self::json($result) : implode("\n", ModuleChecker::format($result)) . "\n");

                    return array_filter($result['issues'], static fn (array $i): bool => $i['level'] === 'error') === [] ? 0 : 1;
                default:
                    throw new \InvalidArgumentException(self::USAGE);
            }
        } catch (\InvalidArgumentException $e) {
            fwrite($err, $e->getMessage() . "\n");

            return 2;
        } catch (\RuntimeException $e) {
            fwrite($err, $e->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * The PrestaShop range of a module folder: module.spec.json next to it (ps_min, ps_max; null max = no bound), else
     * 'min' / 'max' of ps_versions_compliancy in its main file; [null, null] when neither says.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function range(string $moduleDir): array
    {
        $moduleDir = rtrim(str_replace('\\', '/', $moduleDir), '/');
        foreach (["$moduleDir/module.spec.json", dirname($moduleDir) . '/module.spec.json'] as $file) {
            $spec = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            if (is_array($spec) && isset($spec['ps_min'])) {
                return [(string) $spec['ps_min'], isset($spec['ps_max']) ? (string) $spec['ps_max'] : null];
            }
        }
        $main = (string) @file_get_contents("$moduleDir/" . basename($moduleDir) . '.php');
        $min = preg_match("/'min'\s*=>\s*'([0-9.]+)'/", $main, $m) ? $m[1] : null;
        $max = preg_match("/'max'\s*=>\s*'([0-9.]+)'/", $main, $m) ? $m[1] : null;

        return [$min, $max];
    }

    /**
     * Pretty JSON with a trailing newline.
     *
     * @param array<mixed> $data
     */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
