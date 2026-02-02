<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\DocumentNode;
use Sugar\CodeGen\CodeGenerator;
use Sugar\Directive\ForeachCompiler;
use Sugar\Directive\IfCompiler;
use Sugar\Escape\Escaper;
use Sugar\Extension\ExtensionRegistry;
use Sugar\Parser\Parser;
use Sugar\Pass\Directive\DirectiveCompilationPass;
use Sugar\Pass\Directive\DirectiveExtractionPass;

/**
 * Integration test: Parser → DirectiveExtractionPass → DirectiveCompilationPass → CodeGenerator
 */
final class DirectiveIntegrationTest extends TestCase
{
    private Parser $parser;

    private DirectiveExtractionPass $extractionPass;

    private DirectiveCompilationPass $compilationPass;

    private CodeGenerator $generator;

    protected function setUp(): void
    {
        $this->parser = new Parser();
        $this->extractionPass = new DirectiveExtractionPass();

        $registry = new ExtensionRegistry();
        $registry->registerDirective('if', new IfCompiler());
        $registry->registerDirective('foreach', new ForeachCompiler());

        $this->compilationPass = new DirectiveCompilationPass($registry);
        $this->generator = new CodeGenerator(new Escaper());
    }

    public function testIfDirectiveFullPipeline(): void
    {
        $template = '<div s:if="$isAdmin">Admin Panel</div>';

        // Parse
        $ast = $this->parser->parse($template);
        $this->assertInstanceOf(DocumentNode::class, $ast);

        // Extract directives
        $extracted = $this->extractionPass->transform($ast);

        // Compile directives
        $transformed = $this->compilationPass->transform($extracted);

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

        // Parse → Extract → Compile → Generate
        $ast = $this->parser->parse($template);
        $extracted = $this->extractionPass->transform($ast);
        $transformed = $this->compilationPass->transform($extracted);
        $code = $this->generator->generate($transformed);

        // Should contain foreach/endforeach
        $this->assertStringContainsString('<?php foreach ($items as $item): ?>', $code);
        $this->assertStringContainsString('<?php endforeach; ?>', $code);
        $this->assertStringContainsString('<li>', $code);
    }

    public function testNestedDirectivesFullPipeline(): void
    {
        $template = '<ul s:if="$show"><li s:foreach="$items as $item"><?= $item ?></li></ul>';

        // Parse → Extract → Compile → Generate
        $ast = $this->parser->parse($template);
        $extracted = $this->extractionPass->transform($ast);
        $transformed = $this->compilationPass->transform($extracted);
        $code = $this->generator->generate($transformed);
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
