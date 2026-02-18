<?php
declare(strict_types=1);

namespace Sugar\Core\Compiler;

use PhpToken;

/**
 * Registry for PHP namespace import statements.
 *
 * Provides two complementary responsibilities:
 *
 * - **Canonicalization** (`canonicalize`, static): expands grouped `use` imports
 *   (e.g. `use Ns\{A, B};`) into individual canonical single-line statements and
 *   resolves implicit aliases. This is a pure, stateless transformation.
 *
 * - **Deduplication** (`add` / `all`, instance): accepts canonical import statements
 *   and stores them in a first-wins alias-keyed map so that identical or alias-conflicting
 *   imports added from multiple templates are emitted only once.
 *
 * Example usage (canonicalization only):
 * ```php
 * $statements = PhpImportRegistry::canonicalize('use function Ns\{raw, json};');
 * // ['use function Ns\raw;', 'use function Ns\json;']
 * ```
 *
 * Example usage (deduplication):
 * ```php
 * $registry = new PhpImportRegistry();
 * $registry->add('use Foo\Bar;');
 * $registry->add('use Foo\Bar;'); // duplicate – ignored
 * $imports = $registry->all(); // ['use Foo\Bar;']
 * ```
 */
final class PhpImportRegistry
{
    /**
     * Collected import statements, keyed by normalised token signature for O(1) lookups.
     *
     * @var array<string, string>
     */
    private array $imports = [];

    /**
     * Alias map keyed by `kind:alias` → `kind:target` for alias-collision dedup.
     *
     * @var array<string, string>
     */
    private array $aliases = [];

    /**
     * Expand a raw import statement into canonical single-line statements.
     *
     * Handles grouped braces (`use Ns\{A, B};`), comma-separated lists,
     * `use function`, `use const`, and explicit `as` aliases.
     *
     * @return array<string> One canonical statement per imported symbol.
     */
    public static function canonicalize(string $statement): array
    {
        $clean = trim($statement);
        if (!str_ends_with($clean, ';')) {
            $clean .= ';';
        }

        if (!preg_match('/^use\s+(?:(function|const)\s+)?(.+);$/is', $clean, $matches)) {
            return [$clean];
        }

        $kind = strtolower(trim($matches[1]));
        $body = trim($matches[2]);
        if ($body === '') {
            return [$clean];
        }

        // Grouped: use [function|const] Prefix\{A [as X], B};
        if (str_contains($body, '{') && str_contains($body, '}')) {
            $start = strpos($body, '{');
            $end = strrpos($body, '}');
            if ($start === false || $end === false || $end <= $start) {
                return [$clean];
            }

            $prefix = rtrim(trim(substr($body, 0, $start)), " \\\t\r\n");
            $inner = trim(substr($body, $start + 1, $end - $start - 1));
            if ($inner === '') {
                return [$clean];
            }

            $result = [];
            foreach (explode(',', $inner) as $clause) {
                $parsed = self::parseClause(trim($clause));
                if ($parsed === null) {
                    continue;
                }

                $target = $prefix === ''
                    ? $parsed['target']
                    : $prefix . '\\' . ltrim($parsed['target'], '\\');
                $result[] = self::format($kind, $target, $parsed['alias']);
            }

            return $result !== [] ? $result : [$clean];
        }

        // Comma-separated: use A, B;
        $result = [];
        foreach (explode(',', $body) as $clause) {
            $parsed = self::parseClause(trim($clause));
            if ($parsed === null) {
                continue;
            }

            $result[] = self::format($kind, $parsed['target'], $parsed['alias']);
        }

        return $result !== [] ? $result : [$clean];
    }

    /**
     * Register a raw import statement, canonicalizing and deduplicating it.
     *
     * Grouped imports are expanded first. For each resulting canonical statement
     * the alias map is checked; a first-wins policy applies when two imports
     * share the same alias for the same kind.
     */
    public function add(string $statement): void
    {
        foreach (self::canonicalize($statement) as $canonical) {
            $parsed = $this->parseStatement($canonical);
            if ($parsed === null) {
                $key = $this->normalizeKey($canonical);
                if (!isset($this->imports[$key])) {
                    $this->imports[$key] = $canonical;
                }

                continue;
            }

            $aliasKey = strtolower($parsed['kind'] . ':' . $parsed['alias']);
            $targetKey = strtolower($parsed['kind'] . ':' . trim($parsed['target'], " \\\t\r\n"));

            if (isset($this->aliases[$aliasKey])) {
                // Same alias already registered (either same target → skip, or conflict → first-wins skip)
                continue;
            }

            $this->aliases[$aliasKey] = $targetKey;
            $this->imports[$this->normalizeKey($canonical)] = $canonical;
        }
    }

    /**
     * Return all deduplicated import statements in stable insertion order.
     *
     * @return array<string>
     */
    public function all(): array
    {
        return array_values($this->imports);
    }

    /**
     * Parse 'Target [as Alias]' clause into components.
     *
     * @return array{target: string, alias: string}|null
     */
    private static function parseClause(string $clause): ?array
    {
        if ($clause === '') {
            return null;
        }

        if (!preg_match('/^(.*?)(?:\s+as\s+([A-Za-z_\\x80-\\xff][A-Za-z0-9_\\x80-\\xff]*))?$/i', $clause, $m)) {
            return null;
        }

        $target = trim($m[1]);
        if ($target === '') {
            return null;
        }

        $alias = trim($m[2] ?? '');
        if ($alias === '') {
            $alias = self::defaultAlias($target);
        }

        return ['target' => $target, 'alias' => $alias];
    }

    /**
     * Parse a full canonical import statement into its components.
     *
     * @return array{kind: string, target: string, alias: string}|null
     */
    private function parseStatement(string $statement): ?array
    {
        if (!preg_match('/^use\s+(?:(function|const)\s+)?(.+);$/is', $statement, $m)) {
            return null;
        }

        $kind = strtolower(trim($m[1]));
        $parsed = self::parseClause(trim($m[2]));
        if ($parsed === null) {
            return null;
        }

        return ['kind' => $kind, 'target' => $parsed['target'], 'alias' => $parsed['alias']];
    }

    /**
     * Build a canonical single import statement string.
     */
    private static function format(string $kind, string $target, string $alias): string
    {
        $target = trim($target);
        $stmt = 'use';
        if ($kind !== '') {
            $stmt .= ' ' . $kind;
        }

        $stmt .= ' ' . $target;

        if ($alias !== '' && strcasecmp($alias, self::defaultAlias($target)) !== 0) {
            $stmt .= ' as ' . $alias;
        }

        return $stmt . ';';
    }

    /**
     * Derive the default alias (last segment) from a fully-qualified name.
     */
    private static function defaultAlias(string $target): string
    {
        $normalized = trim($target, " \\\t\r\n");
        $offset = strrpos($normalized, '\\');

        return $offset === false ? $normalized : substr($normalized, $offset + 1);
    }

    /**
     * Compute a token-normalised key for exact-duplicate detection.
     *
     * Strips whitespace and comments so that `use Foo\Bar;` and `use  Foo\Bar ;`
     * map to the same key.
     */
    private function normalizeKey(string $statement): string
    {
        $tokens = PhpToken::tokenize('<?php ' . $statement);
        $parts = [];

        foreach ($tokens as $token) {
            if (in_array($token->id, [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $parts[] = $token->text;
        }

        return implode('', $parts);
    }
}
