<?php
declare(strict_types=1);

namespace Sugar\Exception;

use Exception;
use Throwable;

/**
 * Base exception for all Sugar template errors
 *
 * Provides location tracking and formatted error messages
 */
abstract class SugarException extends Exception
{
    /**
     * Default error message for the exception
     * Override in child classes to provide a default message
     */
    protected string $defaultMessage = '';

    /**
     * Constructor
     *
     * @param string $message Error message (uses defaultMessage if empty)
     * @param string|null $templatePath Path to template file
     * @param int|null $templateLine Line number in template
     * @param int|null $templateColumn Column number in template
     * @param \Throwable|null $previous Previous exception for chaining
     */
    public function __construct(
        string $message = '',
        public readonly ?string $templatePath = null,
        public readonly ?int $templateLine = null,
        public readonly ?int $templateColumn = null,
        ?Throwable $previous = null,
    ) {
        // Use default message if no message provided
        if ($message === '' && $this->defaultMessage !== '') {
            $message = $this->defaultMessage;
        }

        $formattedMessage = $this->formatMessage($message);
        parent::__construct($formattedMessage, 0, $previous);
    }

    /**
     * Format error message with location metadata.
     */
    protected function formatMessage(string $message): string
    {
        $formattedMessage = $message;

        if ($this->templatePath !== null) {
            $location = sprintf('template: %s', $this->templatePath);
            if ($this->templateLine !== null) {
                $location .= sprintf(' line:%d', $this->templateLine);
            }

            if ($this->templateColumn !== null && $this->templateColumn > 0) {
                $location .= sprintf(' column:%d', $this->templateColumn);
            }

            $formattedMessage .= sprintf(' (%s)', $location);
        }

        return $formattedMessage;
    }
}
