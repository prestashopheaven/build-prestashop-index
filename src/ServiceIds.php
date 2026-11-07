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
 * Reads the service ids of a Symfony container YAML file without a YAML parser (PrestaShop's files use custom tags a
 * plain parser rejects): every key directly under "services:" is a service id, with its class (the "class:" child, the
 * id itself when it is a class name) or "@target" for an alias. Resource prototypes ("Namespace\": {resource: ...}")
 * are returned as namespace prefixes; _defaults and _instanceof are skipped.
 */
final class ServiceIds
{
    /**
     * The services and prototype prefixes of one YAML file.
     *
     * @return array{services: array<string, string>, prefixes: list<string>}
     */
    public static function fromYaml(string $yaml): array
    {
        $services = [];
        $prefixes = [];
        $inServices = false;
        $childIndent = null;
        $current = null;
        foreach (preg_split('/\R/', $yaml) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }
            $indent = strlen($line) - strlen(ltrim($line, ' '));
            if ($indent === 0) {
                $inServices = $trimmed === 'services:';
                $childIndent = null;
                $current = null;
                continue;
            }
            if (!$inServices) {
                continue;
            }
            $childIndent ??= $indent;
            if ($indent === $childIndent) {
                $current = null;
                if (!preg_match('/^([\'"]?)(.+?)\1\s*:(?:\s+(.*))?$/', $trimmed, $m)) {
                    continue;
                }
                $id = $m[2];
                $value = trim($m[3] ?? '', " '\"");
                if ($id === '_defaults' || $id === '_instanceof') {
                    continue;
                }
                if (str_ends_with($id, '\\')) {
                    $prefixes[] = str_replace('\\\\', '\\', $id);
                    continue;
                }
                $id = str_replace('\\\\', '\\', $id);
                $services[$id] = str_starts_with($value, '@') ? $value : (self::isClassName($id) ? $id : '');
                $current = $id;
            } elseif ($current !== null && $indent > $childIndent && preg_match('/^(class|alias):\s*[\'"]?([^\'"\s#]+)/', $trimmed, $m)) {
                $services[$current] = $m[1] === 'alias' ? '@' . $m[2] : str_replace('\\\\', '\\', $m[2]);
            }
        }

        return ['services' => $services, 'prefixes' => $prefixes];
    }

    /**
     * True when a service id is a class name (it holds a namespace separator and no dots).
     */
    private static function isClassName(string $id): bool
    {
        return str_contains($id, '\\') && !str_contains($id, '.');
    }
}
