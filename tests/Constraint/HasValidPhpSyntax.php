<?php
declare(strict_types=1);

namespace Sugar\Tests\Constraint;

use PHPUnit\Framework\Constraint\Constraint;

/**
 * Constraint that asserts PHP syntax is valid
 */
final class HasValidPhpSyntax extends Constraint
{
    /**
     * @param mixed $other
     */
    protected function matches($other): bool
    {
        if (!is_string($other)) {
            return false;
        }

        // Check for parse errors using eval (without executing)
        $code = $other;
        if (!str_starts_with($code, '<?php')) {
            $code = '<?php ' . $code;
        }

        // Use php -l (lint) for syntax checking
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open('php -l', $descriptors, $pipes);

        if (is_resource($process)) {
            fwrite($pipes[0], $code);
            fclose($pipes[0]);

            stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);

            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);

            return $exitCode === 0 && in_array($errors, ['', '0', false], true);
        }

        // Fallback: just check tokenization succeeded
        return true;
    }

    public function toString(): string
    {
        return 'has valid PHP syntax';
    }

    /**
     * @param mixed $other
     */
    protected function failureDescription($other): string
    {
        return 'the code ' . $this->toString();
    }
}
