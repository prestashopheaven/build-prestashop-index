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
 * The Composer packages a PrestaShop release ships in its vendor/ (risks B2/B3 of the external check): from its
 * composer.lock, or vendor/composer/installed.json when the lock is missing. A package another one replaces
 * (symfony/symfony replaces symfony/console in PrestaShop 8) is listed with the replacing package's version and "via".
 *
 * @phpstan-type Vendor array<string, array{v: string, l: string, via?: string}>
 */
final class VendorPackages
{
    /**
     * The vendor packages of a PrestaShop folder, sorted by name; empty when it has neither file.
     *
     * @return Vendor
     */
    public static function read(string $psDir): array
    {
        foreach (["$psDir/composer.lock", "$psDir/vendor/composer/installed.json"] as $file) {
            $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            if (is_array($data)) {
                return self::fromComposerData($data);
            }
        }

        return [];
    }

    /**
     * The packages of a decoded composer.lock or installed.json (both layouts of installed.json).
     *
     * @param array<mixed> $data
     *
     * @return Vendor
     */
    public static function fromComposerData(array $data): array
    {
        $list = $data['packages'] ?? $data;
        $packages = [];
        $replaced = [];
        foreach (is_array($list) ? $list : [] as $package) {
            if (!is_array($package) || !isset($package['name'], $package['version'])) {
                continue;
            }
            $version = (string) $package['version'];
            $packages[(string) $package['name']] = ['v' => $version, 'l' => implode(' OR ', (array) ($package['license'] ?? []))];
            foreach ((array) ($package['replace'] ?? []) as $name => $constraint) {
                $replaced[(string) $name] = ['v' => $version, 'l' => $packages[(string) $package['name']]['l'], 'via' => (string) $package['name']];
            }
        }
        $packages += $replaced;
        ksort($packages);

        return $packages;
    }
}
