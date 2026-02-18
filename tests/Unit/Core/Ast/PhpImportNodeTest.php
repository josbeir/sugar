<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Ast;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\PhpImportNode;

/**
 * Tests for PhpImportNode AST node.
 */
final class PhpImportNodeTest extends TestCase
{
    public function testConstructorStoresStatement(): void
    {
        $node = new PhpImportNode('use DateTimeImmutable;', 3, 7);

        $this->assertSame('use DateTimeImmutable;', $node->statement);
        $this->assertSame(3, $node->line);
        $this->assertSame(7, $node->column);
    }

    public function testTemplatePathInheritance(): void
    {
        $node = new PhpImportNode('use Foo\\Bar;', 1, 0);
        $node->setTemplatePath('views/base.sugar.php');

        $this->assertSame('views/base.sugar.php', $node->getTemplatePath());
    }

    public function testInheritTemplatePathFromOtherNode(): void
    {
        $source = new PhpImportNode('use Foo\\Bar;', 1, 0);
        $source->setTemplatePath('partials/card.sugar.php');

        $target = new PhpImportNode('use Baz\\Qux;', 5, 0);
        $target->inheritTemplatePathFrom($source);

        $this->assertSame('partials/card.sugar.php', $target->getTemplatePath());
    }
}
