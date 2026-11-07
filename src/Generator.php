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
 * The index of one PrestaShop release from its source folder (docs/packages/indexes.md section 2), single-threaded:
 *
 * - classes of classes/, controllers/, src/Core, src/Adapter and src/PrestaShopBundle with their parents, public and
 *   protected constants and methods (visibility, static, signature, @deprecated or a deprecation call); a legacy
 *   "XxxCore" class also gets its alias "Xxx";
 * - the functions of config/alias.php (pSQL, bqSQL, ...);
 * - hooks: hook.xml with titles, hook_alias.xml, the literal names executed in the code and the templates, and the
 *   patterns of names built at run time;
 * - service ids of the container YAML under src/, config/ and app/config;
 * - PS_* configuration keys of configuration.xml and the PS_* literals in the code;
 * - tables and columns of db_structure.sql;
 * - the vendor packages with their versions.
 *
 * Keys are short to keep the files small: k kind (c class, i interface, t trait, e enum), f file, p parents (extends,
 * implements, traits), d deprecation, c constants (name => visibility), m methods (name => {v visibility, s static,
 * a abstract, g signature, d deprecation}).
 *
 * @phpstan-type MethodData array{v: string, g: string, s?: int, a?: int, d?: string}
 * @phpstan-type ClassData array{k: string, f: string, p?: list<string>, d?: string, c?: array<string, string>, m?: array<string, MethodData>}
 * @phpstan-type VersionIndex array{format: int, version: string, classes: array<string, ClassData>, aliases: array<string, string>, functions: array<string, array{g: string, f: string, d?: string}>, hooks: array<string, array{t: string, s: list<string>}>, hookAliases: array<string, string>, hookPatterns: array<string, string>, services: array<string, string>, servicePrefixes: list<string>, config: array<string, string>, tables: array<string, list<string>>, vendor: array<string, array{v: string, l: string, via?: string}>}
 */
final class Generator
{
    /** The version of the JSON layout. */
    public const FORMAT = 1;

    /** The PHP folders whose classes are indexed. */
    public const SOURCE_DIRS = ['classes', 'controllers', 'src/Core', 'src/Adapter', 'src/PrestaShopBundle'];

    /** The PHP files whose functions are indexed (the global helpers). */
    public const FUNCTION_FILES = ['config/alias.php'];

    /**
     * Generates the index of the PrestaShop release in $psDir.
     *
     * @return VersionIndex
     *
     * @throws \RuntimeException when $psDir is not a PrestaShop folder
     */
    public static function generate(string $psDir, string $version): array
    {
        $psDir = rtrim(str_replace('\\', '/', $psDir), '/');
        if (!is_dir("$psDir/classes") || !is_dir("$psDir/install/data")) {
            throw new \RuntimeException("$psDir is not a PrestaShop release folder (no classes/ and install/data/).");
        }
        $scanner = new PhpApiScanner();
        $classes = [];
        $aliases = [];
        $functions = [];
        $hooks = [];
        $hookKeys = [];
        $patterns = [];
        $config = [];
        $files = [];
        foreach (self::SOURCE_DIRS as $dir) {
            foreach (self::files("$psDir/$dir", ['php']) as $file) {
                $files[] = $file;
            }
        }
        foreach (self::FUNCTION_FILES as $file) {
            if (is_file("$psDir/$file")) {
                $files[] = "$psDir/$file";
            }
        }
        foreach ($files as $file) {
            $relative = substr($file, strlen($psDir) + 1);
            $tokens = PhpApiScanner::tokens((string) file_get_contents($file));
            $api = $scanner->scanTokens($tokens);
            foreach ($api['classes'] as $class) {
                if (isset($classes[$class['name']])) {
                    continue;
                }
                $classes[$class['name']] = self::classData($class, $relative);
                if (!str_contains($class['name'], '\\') && str_ends_with($class['name'], 'Core') && $class['name'] !== 'Core'
                    && (str_starts_with($relative, 'classes/') || str_starts_with($relative, 'controllers/'))) {
                    $aliases[substr($class['name'], 0, -4)] = $class['name'];
                }
            }
            if (in_array($relative, self::FUNCTION_FILES, true)) {
                foreach ($api['functions'] as $function) {
                    $functions[$function['name']] = ['g' => $function['signature'], 'f' => $relative] + ($function['deprecated'] !== null ? ['d' => $function['deprecated']] : []);
                }
            }
            $calls = HookCalls::fromTokens($tokens);
            foreach ($calls['names'] as $name) {
                self::addHook($hooks, $hookKeys, $name, '', 'code');
            }
            foreach ($calls['patterns'] as $pattern) {
                $patterns[$pattern] ??= $relative;
            }
            foreach ($tokens as $t) {
                if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('/^[\'"](PS_[A-Z0-9_]+)[\'"]$/', $t[1], $m)) {
                    $config[$m[1]] ??= 'code';
                }
            }
        }
        foreach ($aliases as $alias => $class) {
            if (isset($classes[$alias])) {
                unset($aliases[$alias]);
            }
        }
        $data = "$psDir/install/data";
        $hookAliases = InstallData::hookAliases((string) @file_get_contents("$data/xml/hook_alias.xml"));
        foreach (InstallData::hooks((string) @file_get_contents("$data/xml/hook.xml")) as $name => $title) {
            self::addHook($hooks, $hookKeys, $name, $title, 'xml');
        }
        foreach (self::templateFiles($psDir) as $file) {
            foreach (HookCalls::fromTemplate((string) file_get_contents($file)) as $name) {
                self::addHook($hooks, $hookKeys, $name, '', 'template');
            }
        }
        foreach (InstallData::configurationKeys((string) @file_get_contents("$data/xml/configuration.xml")) as $key) {
            $config[$key] = 'install';
        }
        $services = [];
        $prefixes = [];
        foreach (['src', 'config', 'app/config'] as $dir) {
            foreach (self::files("$psDir/$dir", ['yml', 'yaml']) as $file) {
                $found = ServiceIds::fromYaml((string) file_get_contents($file));
                $services += $found['services'];
                array_push($prefixes, ...$found['prefixes']);
            }
        }
        ksort($classes);
        ksort($aliases);
        ksort($functions);
        ksort($hooks, SORT_FLAG_CASE | SORT_STRING);
        ksort($hookAliases);
        ksort($patterns);
        ksort($services);
        ksort($config);
        $prefixes = array_values(array_unique($prefixes));
        sort($prefixes);

        return [
            'format' => self::FORMAT,
            'version' => $version,
            'classes' => $classes,
            'aliases' => $aliases,
            'functions' => $functions,
            'hooks' => $hooks,
            'hookAliases' => $hookAliases,
            'hookPatterns' => $patterns,
            'services' => $services,
            'servicePrefixes' => $prefixes,
            'config' => $config,
            'tables' => InstallData::tables((string) @file_get_contents("$data/db_structure.sql")),
            'vendor' => VendorPackages::read($psDir),
        ];
    }

    /**
     * The compact data of one class: private members left out.
     *
     * @param array{name: string, kind: string, deprecated: ?string, extends: list<string>, implements: list<string>, traits: list<string>, constants: list<array{name: string, visibility: string}>, methods: list<array{name: string, visibility: string, static: bool, abstract: bool, signature: string, deprecated: ?string}>} $class
     *
     * @return ClassData
     */
    private static function classData(array $class, string $file): array
    {
        $data = ['k' => $class['kind'][0], 'f' => $file];
        $parents = array_values(array_unique(array_merge($class['extends'], $class['implements'], $class['traits'])));
        if ($parents !== []) {
            $data['p'] = $parents;
        }
        if ($class['deprecated'] !== null) {
            $data['d'] = $class['deprecated'];
        }
        foreach ($class['constants'] as $constant) {
            if ($constant['visibility'] !== 'private') {
                $data['c'][$constant['name']] = $constant['visibility'];
            }
        }
        foreach ($class['methods'] as $method) {
            if ($method['visibility'] === 'private') {
                continue;
            }
            $entry = ['v' => $method['visibility'], 'g' => $method['signature']];
            if ($method['static']) {
                $entry['s'] = 1;
            }
            if ($method['abstract']) {
                $entry['a'] = 1;
            }
            if ($method['deprecated'] !== null) {
                $entry['d'] = $method['deprecated'];
            }
            $data['m'][$method['name']] = $entry;
        }

        return $data;
    }

    /**
     * Adds a hook (case-insensitive: the first spelling stays, a title fills in, every source is kept).
     *
     * @param array<string, array{t: string, s: list<string>}> $hooks
     * @param array<string, string> $keys lowercase name => the spelling in $hooks
     */
    private static function addHook(array &$hooks, array &$keys, string $name, string $title, string $source): void
    {
        $name = $keys[strtolower($name)] ??= $name;
        $hook = $hooks[$name] ?? ['t' => '', 's' => []];
        if ($hook['t'] === '') {
            $hook['t'] = $title;
        }
        if (!in_array($source, $hook['s'], true)) {
            $hook['s'][] = $source;
            sort($hook['s']);
        }
        $hooks[$name] = $hook;
    }

    /**
     * The Smarty and Twig templates that can execute hooks: themes/, the back-office theme (any folder with
     * themes/default/template, the renamed admin folder) and the Twig views of the PrestaShop bundle.
     *
     * @return list<string>
     */
    private static function templateFiles(string $psDir): array
    {
        $files = self::files("$psDir/themes", ['tpl']);
        foreach (glob("$psDir/*/themes/default/template", GLOB_ONLYDIR) ?: [] as $admin) {
            array_push($files, ...self::files($admin, ['tpl']));
        }

        return array_merge($files, self::files("$psDir/src/PrestaShopBundle/Resources/views", ['twig']));
    }

    /**
     * Every file with one of the extensions under a folder, sorted, with "/" separators (empty when it is missing).
     *
     * @param list<string> $extensions
     *
     * @return list<string>
     */
    public static function files(string $dir, array $extensions): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && in_array(strtolower($file->getExtension()), $extensions, true)) {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
        sort($files);

        return $files;
    }
}
