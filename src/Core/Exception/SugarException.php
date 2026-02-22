<?php
declare(strict_types=1);

namespace Sugar\Core\Exception;

use Exception;
use Throwable;

/**
 * Base exception for all Sugar template errors
 *
 * Provides location tracking and formatted error messages.
 * Follows the standard PHP Exception constructor (message, code, previous)
 * with template location set via {@see withLocation()}.
 */
abstract class SugarException extends Exception
{
    /**
     * Default error message for the exception
     * Override in child classes to provide a default message
     */
    protected string $defaultMessage = '';

    /**
     * Unformatted error message without location metadata.
     */
    private string $rawMessage = '';

    /**
     * Path to the template file where the error occurred.
     */
    public ?string $templatePath = null;

    /**
     * Line number in the template where the error occurred.
     */
    public ?int $templateLine = null;

    /**
     * Column number in the template where the error occurred.
     */
    public ?int $templateColumn = null;

    /**
     * Constructor
     *
     * @param string $message Error message (uses defaultMessage if empty)
     * @param int $code Exception code
     * @param \Throwable|null $previous Previous exception for chaining
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        // Use default message if no message provided
        if ($message === '' && $this->defaultMessage !== '') {
            $message = $this->defaultMessage;
        }

        $this->rawMessage = $message;
        parent::__construct($message, $code, $previous);
    }

    /**
     * Attach template location metadata to this exception.
     *
     * Returns $this for fluent chaining directly after construction:
     * ```
     * throw (new SyntaxException('Bad token'))->withLocation('template.php', 10, 5);
     * ```
     *
     * @param string|null $templatePath Path to the template file
     * @param int|null $templateLine Line number in template
     * @param int|null $templateColumn Column number in template
     */
    public function withLocation(
        ?string $templatePath,
        ?int $templateLine = null,
        ?int $templateColumn = null,
    ): static {
        $this->templatePath = $templatePath;
        $this->templateLine = $templateLine;
        $this->templateColumn = $templateColumn;

        // Exception::$message is protected — write the formatted message directly so
        // the final getMessage() returns the location-annotated string.
        $this->message = $this->formatMessage($this->rawMessage);

        return $this;
    }

    /**
     * Return the raw error message without location metadata.
     */
    public function getRawMessage(): string
    {
        return $this->rawMessage;
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
