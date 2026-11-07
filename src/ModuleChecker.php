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
 * ps check: checks what a module uses of PrestaShop (ModuleReferences) against every indexed release in its range.
 *
 * - error: a class, method, constant, function or hook is missing in a release of the range;
 * - warning: it is deprecated in a release of the range, its signature differs between the releases of the range, or
 *   a hook is only known by an alias.
 *
 * A reference to something that is not PrestaShop's is skipped: a class outside the index (a global name like
 * DateTime, a vendor class), a member of a class whose parents are not all indexed (it may come from vendor code), and
 * calls the module's own classes answer themselves. Issues are grouped per symbol and message, with the first place and
 * the number of places.
 *
 * @phpstan-import-type Reference from ModuleReferences
 * @phpstan-import-type ModuleClass from ModuleReferences
 *
 * @phpstan-type Issue array{level: string, symbol: string, message: string, file: string, line: int, count: int}
 * @phpstan-type Result array{versions: list<string>, checked: int, skipped: int, issues: list<Issue>}
 */
final class ModuleChecker
{
    /** Namespaces that are PrestaShop's own: a class there is checked even when the index does not have it. */
    public const PRESTASHOP_NAMESPACES = ['PrestaShop\\PrestaShop\\', 'PrestaShopBundle\\'];

    /** @var array<string, Issue> */
    private array $issues = [];

    private int $checked = 0;

    private int $skipped = 0;

    /**
     * Sets up a check against the releases at $positions of the index.
     *
     * @param list<int> $positions
     * @param array<string, ModuleClass> $moduleClasses
     */
    private function __construct(private readonly SymbolIndex $index, private readonly array $positions, private readonly array $moduleClasses)
    {
    }

    /**
     * Checks the references of a module (ModuleReferences::collect()) against the releases from $psMin to $psMax.
     *
     * @param array{references: list<Reference>, classes: array<string, ModuleClass>} $module
     *
     * @return Result
     *
     * @throws \RuntimeException when no indexed release is in the range
     */
    public static function check(SymbolIndex $index, array $module, ?string $psMin, ?string $psMax): array
    {
        $positions = $index->positionsInRange($psMin, $psMax);
        if ($positions === []) {
            throw new \RuntimeException('No indexed PrestaShop release between ' . ($psMin ?? 'any') . ' and ' . ($psMax ?? 'any') . ' (indexed: ' . implode(', ', $index->versions()) . ').');
        }
        $checker = new self($index, $positions, $module['classes']);
        foreach ($module['references'] as $reference) {
            match ($reference['kind']) {
                'class' => $checker->checkClass($reference),
                'method', 'constant' => $checker->checkMember($reference),
                'function' => $checker->checkFunction($reference),
                default => $checker->checkHook($reference),
            };
        }
        $issues = array_values($checker->issues);
        usort($issues, static fn (array $a, array $b): int => [$a['level'], $a['symbol']] <=> [$b['level'], $b['symbol']]);

        return [
            'versions' => array_map(static fn (int $n): string => $index->versions()[$n], $positions),
            'checked' => $checker->checked,
            'skipped' => $checker->skipped,
            'issues' => $issues,
        ];
    }

    /**
     * The result as text lines; the last line is the summary.
     *
     * @param Result $result
     *
     * @return list<string>
     */
    public static function format(array $result): array
    {
        $lines = [];
        foreach ($result['issues'] as $issue) {
            $lines[] = strtoupper($issue['level']) . " {$issue['symbol']}: {$issue['message']}";
            $lines[] = "  {$issue['file']}:{$issue['line']}" . ($issue['count'] > 1 ? ' (and ' . ($issue['count'] - 1) . ' more)' : '');
        }
        $errors = count(array_filter($result['issues'], static fn (array $i): bool => $i['level'] === 'error'));
        $lines[] = sprintf(
            'ps check: %d PrestaShop reference(s) checked against %s; %d error(s), %d warning(s); %d reference(s) skipped (not PrestaShop, or not resolvable without type inference).',
            $result['checked'],
            implode(', ', $result['versions']),
            $errors,
            count($result['issues']) - $errors,
            $result['skipped']
        );

        return $lines;
    }

    /**
     * A class reference.
     *
     * @param Reference $reference
     */
    private function checkClass(array $reference): void
    {
        $class = $reference['class'];
        if (!$this->isPrestashop($class)) {
            ++$this->skipped;

            return;
        }
        ++$this->checked;
        $this->missing($reference, $class, $this->index->classPositions($class));
        $entry = $this->index->classEntry($class);
        if ($entry !== null && isset($entry['d'])) {
            $this->deprecated($reference, $class, VersionSet::decode($entry['d']), $entry['dm'] ?? '');
        }
    }

    /**
     * A method call or constant.
     *
     * @param Reference $reference
     */
    private function checkMember(array $reference): void
    {
        $isMethod = $reference['kind'] === 'method';
        $all = $this->prestashopOwners($reference['class'], $reference['name'], $isMethod) ?? [];
        $owners = array_values(array_filter($all, fn (string $owner): bool => $this->index->className($owner) !== null));
        // A parent or trait from vendor code (one of our runtime packages, say) may declare the member itself.
        $vendorOwner = count($owners) !== count($all);
        if ($owners === []) {
            ++$this->skipped;

            return;
        }
        $symbol = $owners[0] . '::' . $reference['name'] . ($isMethod ? '()' : '');
        $present = [];
        $signatures = [];
        $deprecated = [];
        $message = '';
        foreach ($this->positions as $n) {
            foreach ($owners as $owner) {
                if (!$isMethod) {
                    if ($this->index->findConstant($owner, $reference['name'], $n) !== null) {
                        $present[] = $n;
                        break;
                    }
                    continue;
                }
                $found = $this->index->findMethod($owner, $reference['name'], $n);
                if ($found === null) {
                    continue;
                }
                $present[] = $n;
                $method = $found[1];
                $signatures[$found[0] . '::' . $reference['name'] . $this->signatureAt($method['g'], $n)][] = $n;
                if (isset($method['d']) && in_array($n, VersionSet::decode($method['d']), true)) {
                    $deprecated[] = $n;
                    $message = $method['dm'] ?? '';
                }
                break;
            }
        }
        if ($present === [] && ($vendorOwner || $this->unknowable($owners, $isMethod))) {
            ++$this->skipped;

            return;
        }
        ++$this->checked;
        if (!$vendorOwner) {
            $this->missing($reference, $symbol, $present);
        }
        $this->deprecated($reference, $symbol, $deprecated, $message);
        if (count($signatures) > 1) {
            $parts = [];
            foreach ($signatures as $signature => $set) {
                $parts[] = $signature . ' (' . VersionSet::describe(VersionSet::encode($set), $this->index->versions()) . ')';
            }
            $this->add('warning', $symbol, 'the signature differs between the releases: ' . implode('; ', $parts), $reference);
        }
    }

    /**
     * A global function call: checked only when PrestaShop declares the function.
     *
     * @param Reference $reference
     */
    private function checkFunction(array $reference): void
    {
        $entry = $this->index->functionEntry($reference['name']);
        if ($entry === null) {
            ++$this->skipped;

            return;
        }
        ++$this->checked;
        $this->missing($reference, $reference['name'] . '()', VersionSet::decode($entry['v']));
    }

    /**
     * A hook the module registers or implements.
     *
     * @param Reference $reference
     */
    private function checkHook(array $reference): void
    {
        ++$this->checked;
        $found = $this->index->hookPositions($reference['name']);
        $symbol = 'hook ' . $reference['name'];
        $this->missing($reference, $symbol, array_keys($found));
        $aliases = array_unique(array_filter(array_intersect_key($found, array_flip($this->positions)), static fn (string $how): bool => str_starts_with($how, 'alias of ')));
        foreach ($aliases as $how) {
            $this->add('warning', $symbol, "only known as an $how in some releases: register the hook by its real name", $reference);
        }
    }

    /**
     * The classes outside the module that can answer a method or constant a reference uses: for one of the module's
     * own classes, its parents and traits (and, for a trait, the module classes that use it) that are not the module's,
     * found by walking the module's classes; null when the module declares the member itself.
     *
     * @return list<string>|null
     */
    private function prestashopOwners(string $class, string $member, bool $isMethod): ?array
    {
        $owners = [];
        $seen = [];
        $queue = [$class];
        $key = $isMethod ? strtolower($member) : $member;
        while ($queue !== []) {
            $name = (string) array_shift($queue);
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $own = $this->moduleClasses[$name] ?? null;
            if ($own === null) {
                $owners[] = $name;
                continue;
            }
            if (in_array($key, $isMethod ? $own['methods'] : $own['constants'], true)) {
                return null;
            }
            array_push($queue, ...$own['traits']);
            if ($own['parent'] !== '') {
                $queue[] = $own['parent'];
            }
            if ($own['trait']) {
                foreach ($this->moduleClasses as $host => $info) {
                    if (in_array($name, $info['traits'], true)) {
                        $queue[] = $host;
                    }
                }
            }
        }

        return $owners;
    }

    /**
     * True when a member found in none of the owners may still exist: an owner has a parent the index does not hold
     * (vendor code), or answers any method through __call / __callStatic.
     *
     * @param list<string> $owners
     */
    private function unknowable(array $owners, bool $isMethod): bool
    {
        foreach ($owners as $owner) {
            if (!$this->index->hasKnownAncestry($owner)) {
                return true;
            }
            foreach ($isMethod ? $this->positions : [] as $n) {
                if ($this->index->findMethod($owner, '__call', $n) !== null || $this->index->findMethod($owner, '__callStatic', $n) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The signature of a merged method entry in release $n.
     *
     * @param string|array<string, string> $signatures
     */
    private function signatureAt(string|array $signatures, int $n): string
    {
        if (is_string($signatures)) {
            return $signatures;
        }
        $matching = array_filter($signatures, static fn (string $set): bool => in_array($n, VersionSet::decode($set), true));

        return (string) array_key_first($matching);
    }

    /**
     * True for a class of PrestaShop: in the index, or in one of PrestaShop's own namespaces.
     */
    private function isPrestashop(string $class): bool
    {
        if ($this->index->className($class) !== null) {
            return true;
        }
        foreach (self::PRESTASHOP_NAMESPACES as $namespace) {
            if (str_starts_with($class, $namespace)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An error when the symbol is missing in releases of the range.
     *
     * @param Reference $reference
     * @param list<int> $present the positions where it exists
     */
    private function missing(array $reference, string $symbol, array $present): void
    {
        $missing = array_values(array_diff($this->positions, $present));
        if ($missing !== []) {
            $this->add('error', $symbol, 'missing in PrestaShop ' . $this->describe($missing), $reference);
        }
    }

    /**
     * A warning when the symbol is deprecated in releases of the range.
     *
     * @param Reference $reference
     * @param list<int> $deprecated the positions where it is deprecated
     */
    private function deprecated(array $reference, string $symbol, array $deprecated, string $message): void
    {
        $inRange = array_values(array_intersect($this->positions, $deprecated));
        if ($inRange !== []) {
            $message = ltrim($message, ' :');
            $this->add('warning', $symbol, 'deprecated in PrestaShop ' . $this->describe($inRange) . ($message !== '' ? ": $message" : ''), $reference);
        }
    }

    /**
     * Positions as readable versions.
     *
     * @param list<int> $positions
     */
    private function describe(array $positions): string
    {
        return VersionSet::describe(VersionSet::encode($positions), $this->index->versions());
    }

    /**
     * Adds an issue, or counts one more place of the same issue.
     *
     * @param Reference $reference
     */
    private function add(string $level, string $symbol, string $message, array $reference): void
    {
        $key = "$level|$symbol|$message";
        if (isset($this->issues[$key])) {
            ++$this->issues[$key]['count'];

            return;
        }
        $this->issues[$key] = ['level' => $level, 'symbol' => $symbol, 'message' => $message, 'file' => $reference['file'], 'line' => $reference['line'], 'count' => 1];
    }
}
