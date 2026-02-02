<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\DocumentNode;
use Sugar\CodeGen\CodeGenerator;
use Sugar\Escape\Escaper;
use Sugar\Parser\Parser;
use Sugar\Pass\DirectivePass;

/**
 * Integration test: Parser → DirectivePass → CodeGenerator
 */
final class DirectiveIntegrationTest extends TestCase
{
    private Parser $parser;

    private DirectivePass $pass;

    private CodeGenerator $generator;

    protected function setUp(): void
    {
        $this->parser = new Parser();
        $this->pass = new DirectivePass();
        $this->generator = new CodeGenerator(new Escaper());
    }

    public function testIfDirectiveFullPipeline(): void
    {
        $template = '<div s:if="$isAdmin">Admin Panel</div>';

        // Parse
        $ast = $this->parser->parse($template);
        $this->assertInstanceOf(DocumentNode::class, $ast);

        // Transform directives
        $transformed = $this->pass->transform($ast);

        // Generate code
        $code = $this->generator->generate($transformed);

        // Should contain if/endif PHP tags (control structures work)
        $this->assertStringContainsString('<?php if ($isAdmin): ?>', $code);
        $this->assertStringContainsString('<?php endif; ?>', $code);
        $this->assertStringContainsString('<div>', $code);

        // Note: Content handling between opening/closing tags is a Parser limitation
        // HtmlParser only processes individual tags, not content between them
        // This would require full HTML tree parsing which is complex
    }

    public function testForeachDirectiveFullPipeline(): void
    {
        $template = '<li s:foreach="$items as $item"><?= $item ?></li>';

        // Parse → Transform → Generate
        $ast = $this->parser->parse($template);
        $transformed = $this->pass->transform($ast);
        $code = $this->generator->generate($transformed);

        // Should contain foreach/endforeach
        $this->assertStringContainsString('<?php foreach ($items as $item): ?>', $code);
        $this->assertStringContainsString('<?php endforeach; ?>', $code);
        $this->assertStringContainsString('<li>', $code);
    }

    public function testNestedDirectivesFullPipeline(): void
    {
        $template = '<ul s:if="$show"><li s:foreach="$items as $item"><?= $item ?></li></ul>';

        // Parse → Transform → Generate
        $ast = $this->parser->parse($template);
        $transformed = $this->pass->transform($ast);
        $code = $this->generator->generate($transformed);

        // Should contain nested control structures
        $this->assertStringContainsString('<?php if ($show): ?>', $code);
        $this->assertStringContainsString('<?php foreach ($items as $item): ?>', $code);
        $this->assertStringContainsString('<?php endforeach; ?>', $code);
        $this->assertStringContainsString('<?php endif; ?>', $code);
        $this->assertStringContainsString('<ul>', $code);
        $this->assertStringContainsString('<li>', $code);
    }
}
