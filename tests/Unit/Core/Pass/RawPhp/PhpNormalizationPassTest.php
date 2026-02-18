<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Pass\RawPhp;

use Sugar\Core\Ast\PhpImportNode;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Pass\RawPhp\PhpNormalizationPass;
use Sugar\Tests\Unit\Core\Pass\MiddlewarePassTestCase;

final class PhpNormalizationPassTest extends MiddlewarePassTestCase
{
    protected function getPass(): AstPassInterface
    {
        return new PhpNormalizationPass();
    }

    public function testHoistsLeadingImportsAndKeepsExecutableStatements(): void
    {
        $rawPhp = $this->rawPhp(
            "use DateTimeImmutable as Clock;\n\n\$defineAVar = (new Clock('2024-01-01'))->format('Y');",
            1,
            1,
        );
        $document = $this->createDocument([$rawPhp]);

        $result = $this->execute($document);

        $this->assertCount(2, $result->children);
        $this->assertInstanceOf(PhpImportNode::class, $result->children[0]);
        $this->assertSame('use DateTimeImmutable as Clock;', $result->children[0]->statement);
        $this->assertInstanceOf(RawPhpNode::class, $result->children[1]);
        $this->assertStringNotContainsString('use DateTimeImmutable', $result->children[1]->code);
        $this->assertStringContainsString("\$defineAVar = (new Clock('2024-01-01'))->format('Y');", $result->children[1]->code);
    }

    public function testRemovesRawPhpNodeWhenItOnlyContainsImports(): void
    {
        $rawPhp = $this->rawPhp(
            "use DateTimeImmutable as Clock;\nuse function strlen;\nuse const PHP_VERSION;",
            1,
            1,
        );
        $document = $this->createDocument([$rawPhp]);

        $result = $this->execute($document);

        $this->assertCount(3, $result->children);
        $this->assertInstanceOf(PhpImportNode::class, $result->children[0]);
        $this->assertSame('use DateTimeImmutable as Clock;', $result->children[0]->statement);
        $this->assertInstanceOf(PhpImportNode::class, $result->children[1]);
        $this->assertSame('use function strlen;', $result->children[1]->statement);
        $this->assertInstanceOf(PhpImportNode::class, $result->children[2]);
        $this->assertSame('use const PHP_VERSION;', $result->children[2]->statement);
    }

    public function testLeavesRawPhpUntouchedWhenCodeDoesNotStartWithImports(): void
    {
        $rawPhp = $this->rawPhp(
            "\$value = 'hello';\nuse DateTimeImmutable as Clock;",
            1,
            1,
        );
        $document = $this->createDocument([$rawPhp]);

        $result = $this->execute($document);

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(RawPhpNode::class, $result->children[0]);
        $this->assertSame("\$value = 'hello';\nuse DateTimeImmutable as Clock;", $result->children[0]->code);
    }

    public function testExpandsGroupedImportsIntoIndividualPhpImportNodes(): void
    {
        $rawPhp = $this->rawPhp(
            'use function Sugar\\Core\\Runtime\\{raw, json};',
            1,
            1,
        );
        $document = $this->createDocument([$rawPhp]);

        $result = $this->execute($document);

        $this->assertCount(2, $result->children);
        $this->assertInstanceOf(PhpImportNode::class, $result->children[0]);
        $this->assertSame('use function Sugar\\Core\\Runtime\\raw;', $result->children[0]->statement);
        $this->assertInstanceOf(PhpImportNode::class, $result->children[1]);
        $this->assertSame('use function Sugar\\Core\\Runtime\\json;', $result->children[1]->statement);
    }

    public function testInheritsTemplatePathFromSourceNode(): void
    {
        $rawPhp = $this->rawPhp('use DateTimeImmutable;', 3, 0);
        $rawPhp->setTemplatePath('partials/card.sugar.php');

        $document = $this->createDocument([$rawPhp]);

        $result = $this->execute($document);

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(PhpImportNode::class, $result->children[0]);
        $this->assertSame('partials/card.sugar.php', $result->children[0]->getTemplatePath());
    }
}
