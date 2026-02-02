<?php
declare(strict_types=1);

namespace Sugar\Tests;

use RuntimeException;

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
        ob_start();
        $this->executePhpCode($compiledCode, $variables, 'sugar_test_');

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
        $code = sprintf('<?php return %s;', $expression);

        return $this->executePhpCode($code, $variables, 'sugar_expr_');
    }

    /**
     * Execute PHP code with given variables using a temporary file
     *
     * @param string $code The PHP code to execute
     * @param array<string, mixed> $variables Variables to make available in scope
     * @param string $prefix Temporary file prefix
     * @return mixed The result of the execution
     */
    private function executePhpCode(string $code, array $variables, string $prefix): mixed
    {
        $tempFile = tempnam(sys_get_temp_dir(), $prefix);
        if ($tempFile === false) {
            throw new RuntimeException('Failed to create temporary file for PHP execution');
        }

        try {
            file_put_contents($tempFile, $code);
            extract($variables, EXTR_SKIP);

            return include $tempFile;
        } finally {
            unlink($tempFile); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
        }
    }
}
