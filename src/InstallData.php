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
 * Reads the install data of a PrestaShop release (install/data): the hook list (hook.xml), the hook aliases
 * (hook_alias.xml), the configuration keys (configuration.xml) and the tables with their columns (db_structure.sql).
 * Every reader takes the file's text, so it works on any release layout.
 */
final class InstallData
{
    /**
     * The hooks of hook.xml: name => title.
     *
     * @return array<string, string>
     */
    public static function hooks(string $xml): array
    {
        $hooks = [];
        preg_match_all('#<hook\b[^>]*>(.*?)</hook>#s', $xml, $blocks);
        foreach ($blocks[1] as $block) {
            if (preg_match('#<name>\s*([^<]+?)\s*</name>#', $block, $name)) {
                $title = preg_match('#<title>\s*([^<]*?)\s*</title>#', $block, $t) ? html_entity_decode($t[1], ENT_QUOTES | ENT_XML1) : '';
                $hooks[$name[1]] = $title;
            }
        }

        return $hooks;
    }

    /**
     * The aliases of hook_alias.xml: alias => hook name.
     *
     * @return array<string, string>
     */
    public static function hookAliases(string $xml): array
    {
        $aliases = [];
        preg_match_all('#<hook_alias\b[^>]*>\s*<alias>\s*([^<]+?)\s*</alias>\s*<name>\s*([^<]+?)\s*</name>#s', $xml, $m, PREG_SET_ORDER);
        foreach ($m as $alias) {
            $aliases[$alias[1]] = $alias[2];
        }

        return $aliases;
    }

    /**
     * The configuration keys of configuration.xml (name="..." attributes).
     *
     * @return list<string>
     */
    public static function configurationKeys(string $xml): array
    {
        preg_match_all('#<configuration\b[^>]*\bname="([A-Za-z0-9_]+)"#', $xml, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * The tables of db_structure.sql without PREFIX_: table => its columns.
     *
     * @return array<string, list<string>>
     */
    public static function tables(string $sql): array
    {
        $tables = [];
        preg_match_all('#CREATE TABLE(?: IF NOT EXISTS)?\s+`?PREFIX_([A-Za-z0-9_]+)`?\s*\((.*?)\)\s*ENGINE#s', $sql, $m, PREG_SET_ORDER);
        foreach ($m as $table) {
            preg_match_all('#^\s*`([A-Za-z0-9_]+)`\s+[a-z]#mi', $table[2], $columns);
            $tables[$table[1]] = $columns[1];
        }
        ksort($tables);

        return $tables;
    }
}
