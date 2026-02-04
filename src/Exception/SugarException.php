<?php
declare(strict_types=1);

namespace Sugar\Exception;

use Exception;
use Throwable;

/**
 * Base exception for all Sugar template errors
 *
 * Provides location tracking and formatted error messages with template context
 */
abstract class SugarException extends Exception
{
    /**
     * Constructor
     *
     * @param string $message Error message
     * @param string|null $templatePath Path to template file
     * @param int|null $templateLine Line number in template
     * @param int|null $templateColumn Column number in template
     * @param string|null $snippet Code snippet showing error context
     * @param \Throwable|null $previous Previous exception for chaining
     */
    public function __construct(
        string $message,
        public readonly ?string $templatePath = null,
        public readonly ?int $templateLine = null,
        public readonly ?int $templateColumn = null,
        public readonly ?string $snippet = null,
        ?Throwable $previous = null,
    ) {
        $formattedMessage = $this->formatMessage($message);
        parent::__construct($formattedMessage, 0, $previous);
    }

    /**
     * Format error message with location and snippet
     */
    private function formatMessage(string $message): string
    {
        $parts = [];

        // Add location if available
        if ($this->templatePath !== null) {
            $location = $this->templatePath;

            if ($this->templateLine !== null) {
                $location .= ':' . $this->templateLine;

                if ($this->templateColumn !== null) {
                    $location .= ':' . $this->templateColumn;
                }
            }

            $parts[] = 'Template: ' . $location;
        }

        // Add main message
        $parts[] = $message;

        // Add snippet if available
        if ($this->snippet !== null) {
            $parts[] = $this->snippet;
        }

        return implode("\n\n", $parts);
    }
}
