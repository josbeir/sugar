<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Core\CodeGen\CodeGenerator;
use Sugar\Core\Compiler\Pipeline\AstPipeline;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Directive\IfContentDirective;
use Sugar\Core\Directive\TagDirective;
use Sugar\Core\Pass\Directive\DirectiveCompilationPass;
use Sugar\Core\Pass\Directive\DirectiveExtractionPass;
use Sugar\Core\Pass\Directive\DirectivePairingPass;
use Sugar\Core\Runtime\HtmlTagHelper;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\TemplateTestHelperTrait;

/**
 * Integration tests for directive compilation pipeline
 *
 * Tests the Parse → Extract → Compile → Generate pipeline to ensure directives
 * are correctly processed at each stage and produce expected code structure.
 *
 * These tests verify the COMPILATION process, while HtmlManipulationExamplesTest
 * verifies the EXECUTION behavior.
 */
final class DirectiveCompilationPipelineTest extends TestCase
{
    use CompilerTestTrait;
    use TemplateTestHelperTrait;

    private AstPipeline $pipeline;

    private CodeGenerator $generator;

    protected function setUp(): void
    {
        $this->setUpCompiler(withDefaultDirectives: false);

        $this->registry->register('tag', new TagDirective());
        $this->registry->register('ifcontent', new IfContentDirective());

        $extractionPass = new DirectiveExtractionPass($this->registry, new SugarConfig());
        $pairingPass = new DirectivePairingPass($this->registry);
        $compilationPass = new DirectiveCompilationPass($this->registry);
        $this->pipeline = new AstPipeline([
            $extractionPass,
            $pairingPass,
            $compilationPass,
        ]);
        $this->generator = new CodeGenerator($this->escaper, $this->createContext());
    }

    public function testTagDirectiveFullPipeline(): void
    {
        $template = '<div s:tag="$tagName" class="wrapper">Content</div>';

        // Parse → Extract → Compile → Generate
        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        // Should contain tag validation
        $this->assertStringContainsString(HtmlTagHelper::class . '::validateTagName', $code);
        $this->assertStringContainsString('$tagName', $code);
        $this->assertStringContainsString('$__tag_', $code);
    }

    public function testTagDirectiveWithExpression(): void
    {
        $template = '<h1 s:tag="\'h\' . $level">Title</h1>';

        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        $this->assertStringContainsString('\'h\' . $level', $code);
        $this->assertStringContainsString(HtmlTagHelper::class . '::validateTagName', $code);
    }

    public function testIfContentDirectiveFullPipeline(): void
    {
        $template = '<div class="card" s:ifcontent><?= $content ?></div>';

        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        // Should contain output buffering
        $this->assertStringContainsString('ob_start()', $code);
        $this->assertStringContainsString('ob_get_clean()', $code);
        $this->assertStringContainsString('$__content_', $code);
        $this->assertStringContainsString('trim', $code);
    }

    public function testCombiningTagAndIfContentPipeline(): void
    {
        $template = '<div s:tag="$tag" class="wrapper" s:ifcontent><?= $content ?></div>';

        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        // Should contain both tag validation and output buffering
        $this->assertStringContainsString(HtmlTagHelper::class . '::validateTagName', $code);
        $this->assertStringContainsString('ob_start()', $code);
        $this->assertStringContainsString('$__tag_', $code);
        $this->assertStringContainsString('$__content_', $code);
    }

    public function testNestedDirectivesInPipeline(): void
    {
        $template = <<<'SUGAR'
<div s:tag="$outer" s:ifcontent>
    <h2 s:tag="$inner" s:ifcontent><?= $title ?></h2>
</div>
SUGAR;

        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        // Should have multiple tag variables and content variables
        $this->assertStringContainsString('$__tag_', $code);
        $this->assertStringContainsString('$__content_', $code);
        $this->assertStringContainsString('$outer', $code);
        $this->assertStringContainsString('$inner', $code);
        $this->assertStringContainsString('$title', $code);
    }
}
