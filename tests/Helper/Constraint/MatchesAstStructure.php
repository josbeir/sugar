<?php
declare(strict_types=1);

namespace Sugar\Tests\Helper\Constraint;

use PHPUnit\Framework\Constraint\Constraint;
use Sugar\Ast\DocumentNode;

/**
 * Constraint that asserts AST matches expected structure
 */
final class MatchesAstStructure extends Constraint
{
    /**
     * @param array<string|array{type: string, properties?: array<string, mixed>}> $expectedStructure
     */
    public function __construct(
        private readonly array $expectedStructure,
    ) {
    }

    /**
     * @param mixed $other
     */
    protected function matches($other): bool
    {
        if ($other instanceof DocumentNode) {
            return $this->matchesStructure($other->children, $this->expectedStructure);
        }

        if (is_array($other)) {
            return $this->matchesStructure($other, $this->expectedStructure);
        }

        return false;
    }

    /**
     * @param array<\Sugar\Ast\Node> $nodes
     * @param array<string|array{type: string, properties?: array<string, mixed>}> $structure
     */
    private function matchesStructure(array $nodes, array $structure): bool
    {
        if (count($nodes) !== count($structure)) {
            return false;
        }

        foreach ($structure as $i => $expected) {
            if (!isset($nodes[$i])) {
                return false;
            }

            if (is_string($expected)) {
                // Simple type check
                if (!$nodes[$i] instanceof $expected) {
                    return false;
                }
            } elseif (is_array($expected)) {
                // Type check with properties
                $type = $expected['type'] ?? null;
                $properties = $expected['properties'] ?? [];

                if ($type !== null && !$nodes[$i] instanceof $type) {
                    return false;
                }

                foreach ($properties as $prop => $value) {
                    if (!property_exists($nodes[$i], $prop) || $nodes[$i]->$prop !== $value) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    public function toString(): string
    {
        return 'matches expected AST structure';
    }

    /**
     * @param mixed $other
     */
    protected function failureDescription($other): string
    {
        return 'the AST ' . $this->toString();
    }
}
