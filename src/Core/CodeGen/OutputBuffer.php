<?php
declare(strict_types=1);

namespace Sugar\Core\CodeGen;

/**
 * Buffer for accumulating generated code
 */
final class OutputBuffer
{
    private string $buffer = '';

    /**
     * Write content to buffer
     *
     * @param string $content Content to write
     */
    public function write(string $content): void
    {
        $this->buffer .= $content;
    }

    /**
     * Write content with newline
     *
     * @param string $content Content to write
     */
    public function writeln(string $content): void
    {
        $this->buffer .= $content . "\n";
    }

    /**
     * Get buffered content
     *
     * @return string Buffered content
     */
    public function getContent(): string
    {
        return $this->buffer;
    }

    /**
     * Clear buffer
     */
    public function clear(): void
    {
        $this->buffer = '';
    }
}
