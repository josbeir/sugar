<?php
declare(strict_types=1);

namespace Sugar\Core\Ast;

/**
 * A single, canonical PHP namespace import statement hoisted from a raw PHP block.
 *
 * Each instance carries exactly one `use`, `use function`, or `use const` statement
 * in its canonical single-line form (no grouped braces). The normalization pass
 * produces these nodes from {@see RawPhpNode} blocks; the code generator collects them,
 * deduplicates across the full composed document, and emits them at file scope.
 *
 * Example statement value: `use function Sugar\Core\Runtime\json;`
 */
final class PhpImportNode extends Node
{
    /**
     * @param string $statement Canonical single-line import statement (e.g. `use Foo\Bar;`)
     * @param int $line Source line number
     * @param int $column Source column number
     */
    public function __construct(
        public readonly string $statement,
        int $line,
        int $column,
    ) {
        parent::__construct($line, $column);
    }
}
