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
 * Finds the hooks PrestaShop's code executes: Hook::exec('name') / Hook::execWithoutCache(), the hook dispatcher
 * (dispatchWithParameters, dispatchRenderingWithParameters, dispatchForParameters, renderForParameters, dispatchHook and
 * dispatchRendering with new Hook('name')), {hook h='name'} in Smarty templates and renderhook('name') in Twig. A name
 * built at run time ('actionObject' . $class . 'DeleteAfter') becomes a pattern with * for the unknown parts, kept
 * only when a literal of at least 4 characters follows the last *, so a pattern never matches every hook.
 */
final class HookCalls
{
    /** Static methods of Hook that execute a hook. */
    private const STATIC_METHODS = ['exec', 'execwithoutcache'];

    /** Methods of the hook dispatcher that execute a hook by name. */
    private const DISPATCH_METHODS = ['dispatchwithparameters', 'dispatchrenderingwithparameters', 'dispatchforparameters', 'renderforparameters', 'dispatchhook', 'dispatchrendering'];

    /** The shortest literal after the last * of a pattern. */
    private const MIN_SUFFIX = 4;

    /**
     * The hook names and patterns executed in PHP tokens.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{names: list<string>, patterns: list<string>}
     */
    public static function fromTokens(array $tokens): array
    {
        $names = [];
        $patterns = [];
        $count = count($tokens);
        for ($i = 2; $i < $count; ++$i) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_STRING) {
                continue;
            }
            $method = strtolower($t[1]);
            $operator = $tokens[$i - 1];
            $isStatic = is_array($operator) && $operator[0] === T_DOUBLE_COLON && in_array($method, self::STATIC_METHODS, true)
                && is_array($tokens[$i - 2]) && str_ends_with(strtolower($tokens[$i - 2][1]), 'hook');
            $isDispatch = is_array($operator) && in_array($operator[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true) && in_array($method, self::DISPATCH_METHODS, true);
            if (!$isStatic && !$isDispatch) {
                continue;
            }
            $argument = self::firstArgument($tokens, $i + 1);
            if ($argument === null) {
                continue;
            }
            $name = self::nameOf($argument);
            if ($name !== null && preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                $names[] = $name;
            } elseif ($name !== null && self::isUsefulPattern($name)) {
                $patterns[] = $name;
            }
        }

        return ['names' => array_values(array_unique($names)), 'patterns' => array_values(array_unique($patterns))];
    }

    /**
     * The hook names in a Smarty or Twig template: {hook h='name'} and renderhook('name') / renderhooksarray('name').
     *
     * @return list<string>
     */
    public static function fromTemplate(string $text): array
    {
        preg_match_all('/\{hook\s+h\s*=\s*[\'"]([A-Za-z0-9_]+)[\'"]|renderhooks?(?:array)?\(\s*[\'"]([A-Za-z0-9_]+)[\'"]/i', $text, $m);

        return array_values(array_unique(array_filter(array_merge($m[1], $m[2]), static fn (string $n): bool => $n !== '')));
    }

    /**
     * True when a hook name matches a pattern with * wildcards (case-insensitive, like PrestaShop's hook names).
     */
    public static function matches(string $pattern, string $hook): bool
    {
        $regex = '/^' . str_replace('\*', '[A-Za-z0-9_]+', preg_quote($pattern, '/')) . '$/i';

        return preg_match($regex, $hook) === 1;
    }

    /**
     * The tokens of the first argument of the call whose "(" follows $i (whitespace skipped), or null when there is no
     * argument list.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<array{0: int, 1: string, 2: int}|string>|null
     */
    private static function firstArgument(array $tokens, int $i): ?array
    {
        $count = count($tokens);
        while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
            ++$i;
        }
        if (($tokens[$i] ?? null) !== '(') {
            return null;
        }
        $argument = [];
        $depth = 0;
        for (++$i; $i < $count; ++$i) {
            $t = $tokens[$i];
            if ($depth === 0 && ($t === ',' || $t === ')')) {
                break;
            }
            if ($t === '(' || $t === '[') {
                ++$depth;
            } elseif ($t === ')' || $t === ']') {
                --$depth;
            }
            if (!is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $argument[] = $t;
            }
        }

        return $argument;
    }

    /**
     * The hook name of an argument: the literal, a pattern ("a" . $x . "b" => a*b, "a{$x}b" => a*b), the first literal
     * of new Hook('name', ...), or null when the argument holds no literal at all.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $argument
     */
    private static function nameOf(array $argument): ?string
    {
        if (isset($argument[0], $argument[1]) && is_array($argument[0]) && $argument[0][0] === T_NEW) {
            foreach ($argument as $t) {
                if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                    return substr($t[1], 1, -1);
                }
            }

            return null;
        }
        $name = '';
        $literal = false;
        $depth = 0;
        $inString = false;
        foreach ($argument as $t) {
            if ($t === '"') {
                $inString = !$inString;
                continue;
            }
            if ($t === '(' || $t === '[') {
                ++$depth;
            } elseif ($t === ')' || $t === ']') {
                --$depth;
                continue;
            }
            if ($depth === 0 && is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $name .= substr($t[1], 1, -1);
                $literal = true;
            } elseif ($depth === 0 && $inString && is_array($t) && $t[0] === T_ENCAPSED_AND_WHITESPACE) {
                $name .= $t[1];
                $literal = true;
            } elseif ($depth === 0 && $t === '.') {
                continue;
            } elseif (!str_ends_with($name, '*')) {
                $name .= '*';
            }
        }

        return $literal ? $name : null;
    }

    /**
     * True for a pattern that is narrow enough to accept a hook by it: only name characters and *, and a literal of at
     * least MIN_SUFFIX characters after the last *.
     */
    private static function isUsefulPattern(string $pattern): bool
    {
        if (!preg_match('/^[A-Za-z0-9_*]+$/', $pattern)) {
            return false;
        }
        $suffix = substr($pattern, (int) strrpos($pattern, '*') + 1);

        return strlen($suffix) >= self::MIN_SUFFIX;
    }
}
