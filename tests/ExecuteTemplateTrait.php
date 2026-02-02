<?php
declare(strict_types=1);

namespace Sugar\Tests;

/**
 * Helper trait for executing compiled templates in tests
 */
trait ExecuteTemplateTrait
{
    /**
     * Execute compiled template code with given variables
     *
     * @param string $compiledCode The compiled PHP template code
     * @param array<string, mixed> $variables Variables to make available in template scope
     * @return string The rendered output
     */
    private function executeTemplate(string $compiledCode, array $variables = []): string
    {
        extract($variables, EXTR_SKIP);

        ob_start();
        // phpcs:ignore Squiz.PHP.Eval.Discouraged
        eval('?>' . $compiledCode);

        return (string)ob_get_clean();
    }

    /**
     * Evaluate a PHP expression with given variables
     *
     * @param string $expression PHP expression to evaluate
     * @param array<string, mixed> $variables Variables to make available in expression scope
     * @return mixed The result of the expression
     */
    private function evaluateExpression(string $expression, array $variables = []): mixed
    {
        extract($variables, EXTR_SKIP);

        return eval(sprintf('return %s;', $expression)); // phpcs:ignore Squiz.PHP.Eval.Discouraged
    }
}
