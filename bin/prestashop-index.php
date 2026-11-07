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

/*
 * The PrestaShop index (ps-module ps find | ps check): generate <ps-dir> <version> | merge | versions | find "<term>"
 * | check <module-dir>. See PrestaShopHeaven\Build\PrestashopIndex\Cli for the options.
 */

declare(strict_types=1);

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php', __DIR__ . '/../../../vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}
ini_set('memory_limit', '1G');

exit(PrestaShopHeaven\Build\PrestashopIndex\Cli::run(array_slice($argv, 1), STDOUT, STDERR));
