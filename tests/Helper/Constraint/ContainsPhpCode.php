<?php
declare(strict_types=1);

namespace Sugar\Tests\Helper\Constraint;

use PHPUnit\Framework\Constraint\Constraint;
use Sugar\Core\Ast\RawPhpNode;

/**
 * Constraint that asserts PHP code is present in node(s)
 */
final class ContainsPhpCode extends Constraint
{
    public function __construct(
        private readonly string $expectedCode,
    ) {
    }

    /**
     * @param mixed $other
     */
    protected function matches($other): bool
    {
        if (is_array($other)) {
            foreach ($other as $node) {
                if ($node instanceof RawPhpNode && str_contains($node->code, $this->expectedCode)) {
                    return true;
                }
            }

            return false;
        }

        if ($other instanceof RawPhpNode) {
            return str_contains($other->code, $this->expectedCode);
        }

        if (is_string($other)) {
            return str_contains($other, $this->expectedCode);
        }

        return false;
    }

    public function toString(): string
    {
        return sprintf('contains PHP code "%s"', $this->expectedCode);
    }

    /**
     * @param mixed $other
     */
    protected function failureDescription($other): string
    {
        return 'the result ' . $this->toString();
    }
}
