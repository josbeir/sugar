<?php
declare(strict_types=1);

namespace Sugar\Context;

use Sugar\Enum\OutputContext;

/**
 * Tracks context during AST traversal for context analysis
 */
final class AnalysisContext
{
    /**
     * @param array<string> $elementStack Stack of element tags
     */
    public function __construct(
        private array $elementStack = [],
    ) {
    }

    /**
     * Push element onto stack (immutable)
     */
    public function push(string $tag): self
    {
        $new = clone $this;
        $new->elementStack[] = strtolower($tag);

        return $new;
    }

    /**
     * Pop element from stack (immutable)
     */
    public function pop(string $tag): self
    {
        $tag = strtolower($tag);
        $new = clone $this;

        // Find and remove the last occurrence of this tag
        for ($i = count($new->elementStack) - 1; $i >= 0; $i--) {
            if ($new->elementStack[$i] === $tag) {
                array_splice($new->elementStack, $i, 1);
                break;
            }
        }

        return $new;
    }

    /**
     * Determine output context based on current element stack
     */
    public function determineContext(): OutputContext
    {
        // Check if we're inside <script> or <style>
        if (in_array('script', $this->elementStack, true)) {
            return OutputContext::JAVASCRIPT;
        }

        if (in_array('style', $this->elementStack, true)) {
            return OutputContext::CSS;
        }

        // Default to HTML content
        return OutputContext::HTML;
    }

    /**
     * Get current element stack (for testing)
     *
     * @return array<string>
     */
    public function getElementStack(): array
    {
        return $this->elementStack;
    }
}
