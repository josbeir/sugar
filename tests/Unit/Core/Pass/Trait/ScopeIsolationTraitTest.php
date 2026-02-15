<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Pass\Trait;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Pass\Trait\ScopeIsolationTrait;

final class ScopeIsolationTraitTest extends TestCase
{
    public function testWrapInIsolatedScopeCopiesTemplatePathToClosureNodes(): void
    {
        $document = new DocumentNode([new TextNode('Hello', 1, 1)]);
        $document->setTemplatePath('template.sugar.php');

        $wrapper = new class {
            use ScopeIsolationTrait;

            public function wrap(DocumentNode $document, string $varsExpression): DocumentNode
            {
                return $this->wrapInIsolatedScope($document, $varsExpression);
            }
        };

        $wrapped = $wrapper->wrap($document, '$vars');

        $this->assertSame('template.sugar.php', $wrapped->getTemplatePath());
        $this->assertCount(3, $wrapped->children);
        $this->assertInstanceOf(RawPhpNode::class, $wrapped->children[0]);
        $this->assertInstanceOf(RawPhpNode::class, $wrapped->children[2]);
        $this->assertSame('template.sugar.php', $wrapped->children[0]->getTemplatePath());
        $this->assertSame('template.sugar.php', $wrapped->children[2]->getTemplatePath());
    }

    public function testWrapInIsolatedScopeLeavesTemplatePathUnsetWhenMissing(): void
    {
        $document = new DocumentNode([new TextNode('Hello', 1, 1)]);

        $wrapper = new class {
            use ScopeIsolationTrait;

            public function wrap(DocumentNode $document, string $varsExpression): DocumentNode
            {
                return $this->wrapInIsolatedScope($document, $varsExpression);
            }
        };

        $wrapped = $wrapper->wrap($document, '$vars');

        $this->assertNull($wrapped->getTemplatePath());
        $this->assertCount(3, $wrapped->children);
        $this->assertNull($wrapped->children[0]->getTemplatePath());
        $this->assertNull($wrapped->children[2]->getTemplatePath());
    }
}
