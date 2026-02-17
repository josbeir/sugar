<?php
declare(strict_types=1);

namespace Sugar\Core\Template\Support;

use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\RawPhpNode;

/**
 * Provides scope isolation for templates using closures
 *
 * Wraps template content in a closure with:
 * - Output buffering (ob_start/ob_get_clean)
 * - Variable extraction with EXTR_SKIP safety
 * - $this binding for parent context access
 * - Type hints for better type safety
 *
 * Pattern: echo (function(array $__vars): string {
 *              ob_start();
 *              extract($__vars, EXTR_SKIP);
 *              ...template content...
 *              return ob_get_clean();
 *          })->bindTo($this ?? null)($varsExpression);
 */
trait ScopeIsolationTrait
{
    /**
     * Wrap document in isolated scope with variable injection
     *
     * Creates a closure that:
     * 1. Accepts an array of variables
     * 2. Extracts them into local scope with EXTR_SKIP for safety
     * 3. Binds to parent $this context for method/property access
     * 4. Captures output via buffering for clean string return
     *
     * @param \Sugar\Core\Ast\DocumentNode $document Document to wrap
     * @param string $varsExpression PHP expression that evaluates to array of variables
     * @return \Sugar\Core\Ast\DocumentNode Wrapped document with closure nodes
     */
    private function wrapInIsolatedScope(DocumentNode $document, string $varsExpression): DocumentNode
    {
        $openingCode = 'echo (function(array $__vars): string { ob_start(); extract($__vars, EXTR_SKIP);';
        $closingCode = 'return ob_get_clean(); })->bindTo($this ?? null)(' . $varsExpression . ');';

        $openingNode = new RawPhpNode($openingCode, 0, 0);
        $closingNode = new RawPhpNode($closingCode, 0, 0);

        if ($document->getTemplatePath() !== null) {
            $openingNode->setTemplatePath($document->getTemplatePath());
            $closingNode->setTemplatePath($document->getTemplatePath());
        }

        $wrapped = new DocumentNode([
            $openingNode,
            ...$document->children,
            $closingNode,
        ]);
        $wrapped->inheritTemplatePathFrom($document);

        return $wrapped;
    }
}
