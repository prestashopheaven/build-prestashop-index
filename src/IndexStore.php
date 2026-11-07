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
 * The data folder of the PrestaShop index: one gzip-compressed JSON per release (ps-<version>.json.gz) and the merged
 * symbol file (symbols.json.gz). The default folder is data/ in this package; PS_INDEX_DIR or --data=DIR overrides it
 * (for data downloaded from a GitHub release).
 *
 * @phpstan-import-type VersionIndex from Generator
 * @phpstan-import-type Merged from Merger
 */
final class IndexStore
{
    /** The merged symbol file. */
    public const SYMBOLS = 'symbols.json.gz';

    private readonly string $dir;

    /**
     * Opens a data folder (created on the first save).
     */
    public function __construct(string $dir)
    {
        $this->dir = rtrim(str_replace('\\', '/', $dir), '/');
    }

    /**
     * The data folder: $override, else the environment variable PS_INDEX_DIR, else data/ of this package.
     */
    public static function defaultDir(?string $override = null): string
    {
        $env = getenv('PS_INDEX_DIR');

        return $override ?? (is_string($env) && $env !== '' ? $env : str_replace('\\', '/', dirname(__DIR__)) . '/data');
    }

    /**
     * The folder.
     */
    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * Saves the index of one release; returns its file.
     *
     * @param VersionIndex $index
     */
    public function saveVersion(array $index): string
    {
        $file = $this->dir . '/ps-' . $index['version'] . '.json.gz';
        $this->write($file, $index);

        return $file;
    }

    /**
     * The index of one release.
     *
     * @return VersionIndex
     *
     * @throws \RuntimeException when it is not in the folder
     */
    public function loadVersion(string $version): array
    {
        /* @var VersionIndex */
        return $this->read($this->dir . "/ps-$version.json.gz");
    }

    /**
     * The releases in the folder, oldest first.
     *
     * @return list<string>
     */
    public function versions(): array
    {
        $versions = [];
        foreach (glob($this->dir . '/ps-*.json.gz') ?: [] as $file) {
            $versions[] = substr(basename($file), 3, -8);
        }
        usort($versions, 'version_compare');

        return $versions;
    }

    /**
     * Merges every release of the folder and saves the symbol file; returns the merged data.
     *
     * @return Merged
     *
     * @throws \RuntimeException when the folder holds no release
     */
    public function merge(): array
    {
        $versions = $this->versions();
        if ($versions === []) {
            throw new \RuntimeException("No PrestaShop release indexed in {$this->dir} (run: prestashop-index.php generate <ps-dir> <version>).");
        }
        $merged = Merger::merge(array_map($this->loadVersion(...), $versions));
        $this->write($this->dir . '/' . self::SYMBOLS, $merged);

        return $merged;
    }

    /**
     * The merged symbols.
     *
     * @throws \RuntimeException when the symbol file is missing
     */
    public function symbols(): SymbolIndex
    {
        /** @var Merged $data */
        $data = $this->read($this->dir . '/' . self::SYMBOLS);

        return new SymbolIndex($data);
    }

    /**
     * Writes data as gzip-compressed JSON.
     *
     * @param array<string, mixed> $data
     */
    private function write(string $file, array $data): void
    {
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
        file_put_contents($file, gzencode((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 9));
    }

    /**
     * Reads gzip-compressed JSON.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException when the file is missing or broken
     */
    private function read(string $file): array
    {
        $raw = is_file($file) ? @gzdecode((string) file_get_contents($file)) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            throw new \RuntimeException("$file is missing or not a PrestaShop index file.");
        }

        return $data;
    }
}
