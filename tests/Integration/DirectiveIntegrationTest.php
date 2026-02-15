<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\CodeGen\CodeGenerator;
use Sugar\Core\Compiler\Pipeline\AstPipeline;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Directive\BooleanAttributeDirective;
use Sugar\Core\Directive\ClassDirective;
use Sugar\Core\Directive\FinallyDirective;
use Sugar\Core\Directive\ForeachDirective;
use Sugar\Core\Directive\IfDirective;
use Sugar\Core\Directive\SpreadDirective;
use Sugar\Core\Directive\TryDirective;
use Sugar\Core\Pass\Directive\DirectiveCompilationPass;
use Sugar\Core\Pass\Directive\DirectiveExtractionPass;
use Sugar\Core\Pass\Directive\DirectivePairingPass;
use Sugar\Core\Runtime\HtmlAttributeHelper;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\ExecuteTemplateTrait;
use Sugar\Tests\Helper\Trait\TemplateTestHelperTrait;

/**
 * Integration test: Parser → DirectiveExtractionPass → DirectiveCompilationPass → CodeGenerator
 */
final class DirectiveIntegrationTest extends TestCase
{
    use CompilerTestTrait;
    use ExecuteTemplateTrait;
    use TemplateTestHelperTrait;

    private AstPipeline $pipeline;

    private CodeGenerator $generator;

    protected function setUp(): void
    {
        $this->setUpCompiler(withDefaultDirectives: false);

        $this->registry->register('if', new IfDirective());
        $this->registry->register('foreach', new ForeachDirective());
        $this->registry->register('try', new TryDirective());
        $this->registry->register('finally', new FinallyDirective());

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

    public function testIfDirectiveFullPipeline(): void
    {
        $template = '<div s:if="$isAdmin">Admin Panel</div>';

        // Parse
        $ast = $this->parser->parse($template);
        $this->assertInstanceOf(DocumentNode::class, $ast);

        // Extract + pair + compile directives
        $transformed = $this->pipeline->execute($ast, $this->createContext());

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
        $transformed = $this->pipeline->execute($ast, $this->createContext());
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
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        // Should contain nested control structures
        $this->assertStringContainsString('<?php if ($show): ?>', $code);
        $this->assertStringContainsString('<?php foreach ($items as $item): ?>', $code);
        $this->assertStringContainsString('<?php endforeach; ?>', $code);
        $this->assertStringContainsString('<?php endif; ?>', $code);
        $this->assertStringContainsString('<ul>', $code);
        $this->assertStringContainsString('<li>', $code);
    }

    public function testTryFinallyDirectiveFullPipeline(): void
    {
        $template = '<div s:try>Run</div><div s:finally>Cleanup</div>';

        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        $this->assertStringContainsString('<?php try { ?>', $code);
        $this->assertStringContainsString('<?php } finally { ?>', $code);
        $this->assertStringContainsString('<?php } ?>', $code);
        $this->assertStringContainsString('<div>Run</div>', $code);
        $this->assertStringContainsString('<div>Cleanup</div>', $code);
    }

    public function testFinallyDirectiveRequiresTry(): void
    {
        $this->expectExceptionMessage('s:finally must follow s:try');

        $template = '<div s:finally>Cleanup</div>';
        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());

        $this->generator->generate($transformed);
    }

    public function testRawPipeOutputFullPipeline(): void
    {
        $template = '<div><?= $html |> raw() ?></div>';

        // Parse → Extract → Compile → Generate
        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        // Should output without escaping when using raw() pipe
        $this->assertStringContainsString('<?php echo $html; ?>', $code);
        $this->assertStringNotContainsString('Escaper::html', $code);
        $this->assertStringNotContainsString('raw(', $code);
    }

    public function testRawPipeOutputWithPipesFullPipeline(): void
    {
        $template = '<div><?= $content |> strtoupper(...) |> raw() ?></div>';

        // Parse → Extract → Compile → Generate
        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        // Should output without escaping after pipes
        $this->assertStringContainsString('<?php echo strtoupper($content); ?>', $code);
        $this->assertStringNotContainsString('Escaper::html', $code);
        $this->assertStringNotContainsString('raw(', $code);
    }

    public function testRegularOutputStillEscapedFullPipeline(): void
    {
        $template = '<div><?= $userInput ?></div>';

        // Parse → Extract → Compile → Generate
        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        // Regular output should use Escaper::html
        $this->assertStringContainsString('Escaper::html', $code);
        $this->assertStringContainsString('$userInput', $code);
    }

    public function testBooleanAttributeCheckedFullPipeline(): void
    {
        $this->registry->register('checked', new BooleanAttributeDirective());

        $template = '<input type="checkbox" s:checked="$isSubscribed">';

        // Parse → Extract → Compile → Generate
        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        // Should contain call to booleanAttribute helper
        $this->assertStringContainsString('HtmlAttributeHelper::booleanAttribute', $code);
        $this->assertStringContainsString("'checked'", $code);
        $this->assertStringContainsString('$isSubscribed', $code);
    }

    public function testBooleanAttributeSelectedFullPipeline(): void
    {
        $this->registry->register('selected', new BooleanAttributeDirective());

        $template = '<option s:selected="$value === \'premium\'">Premium</option>';

        // Parse → Extract → Compile → Generate
        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        // Should contain call to booleanAttribute helper
        $this->assertStringContainsString('HtmlAttributeHelper::booleanAttribute', $code);
        $this->assertStringContainsString("'selected'", $code);
        $this->assertStringContainsString('$value === \'premium\'', $code);
    }

    public function testBooleanAttributeDisabledFullPipeline(): void
    {
        $this->registry->register('disabled', new BooleanAttributeDirective());

        $template = '<button s:disabled="$isProcessing">Submit</button>';

        // Parse → Extract → Compile → Generate
        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        // Should contain call to booleanAttribute helper
        $this->assertStringContainsString('HtmlAttributeHelper::booleanAttribute', $code);
        $this->assertStringContainsString("'disabled'", $code);
        $this->assertStringContainsString('$isProcessing', $code);
    }

    public function testAttributeDirectivesNoTrailingSpaces(): void
    {
        $this->registry->register('checked', new BooleanAttributeDirective());

        $template = '<input type="text" name="email" s:checked="$subscribed">';

        // Parse → Extract → Compile → Generate
        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        // Check that there's no trailing space before the closing tag
        // The CodeGenerator should trim any trailing spaces after attributes
        $this->assertStringContainsString('name="email"', $code);
        $this->assertStringContainsString('booleanAttribute', $code);

        // Should NOT have space before closing tag after PHP code
        $this->assertStringNotContainsString('?> >', $code);
        // Void element should auto-close cleanly after PHP output
        $this->assertStringContainsString('?> />', $code);
    }

    public function testMultipleAttributeDirectivesNoTrailingSpaces(): void
    {
        $this->registry->register('checked', new BooleanAttributeDirective());

        $template = '<input type="checkbox" s:checked="$isActive" s:checked="$isEnabled">';

        // Parse → Extract → Compile → Generate
        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        // Should NOT have trailing space before closing angle bracket
        $this->assertStringNotContainsString(' >', $code);
    }

    public function testClassDirectiveMergesWithExistingStaticClassInPipeline(): void
    {
        $this->registry->register('class', new ClassDirective());

        $template = '<div class="card" s:class="[\'active\' => $isActive]">Content</div>';

        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        $this->assertSame(1, substr_count($code, 'class="'));
        $this->assertStringContainsString("HtmlAttributeHelper::classNames(['card'", $code);
        $this->assertStringContainsString('HtmlAttributeHelper::classNames([\'active\' => $isActive])', $code);
    }

    public function testSpreadDirectiveExcludesExplicitAndMergedNamedAttributesInPipeline(): void
    {
        $this->registry->register('class', new ClassDirective());
        $this->registry->register('spread', new SpreadDirective());

        $template = '<div id="profile" class="card" s:class="[\'active\' => $isActive]" s:spread="$attrs">Content</div>';

        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        $this->assertStringContainsString('HtmlAttributeHelper::spreadAttrs(array_diff_key((array) ($attrs), [\'id\' => true, \'class\' => true]))', $code);
        $this->assertStringContainsString('class="<?php echo ' . HtmlAttributeHelper::class . "::classNames(['card'", $code);
    }

    public function testClassDirectiveRendersMergedOutput(): void
    {
        $this->registry->register('class', new ClassDirective());

        $template = '<div class="card" s:class="[\'active\' => $isActive]">Content</div>';

        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        $output = $this->executeTemplate($code, ['isActive' => true]);
        $this->assertStringContainsString('<div class="card active">Content</div>', $output);
        $this->assertSame(1, substr_count($output, 'class='));

        $inactiveOutput = $this->executeTemplate($code, ['isActive' => false]);
        $this->assertStringContainsString('<div class="card">Content</div>', $inactiveOutput);
    }

    public function testSpreadDirectiveRendersExcludedNamedAttributes(): void
    {
        $this->registry->register('class', new ClassDirective());
        $this->registry->register('spread', new SpreadDirective());

        $template = '<div id="profile" class="card" s:class="[\'active\' => $isActive]" s:spread="$attrs">Content</div>';

        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        $output = $this->executeTemplate($code, [
            'isActive' => true,
            'attrs' => [
                'id' => 'override',
                'class' => 'ignored',
                'disabled' => true,
                'data-role' => 'admin',
            ],
        ]);

        $this->assertStringContainsString('id="profile"', $output);
        $this->assertStringContainsString('class="card active"', $output);
        $this->assertStringContainsString('disabled', $output);
        $this->assertStringContainsString('data-role="admin"', $output);
        $this->assertStringNotContainsString('id="override"', $output);
        $this->assertStringNotContainsString('class="ignored"', $output);
    }

    public function testSelfClosingElementsNoTrailingSpaces(): void
    {
        $this->registry->register('checked', new BooleanAttributeDirective());

        $template = '<input type="checkbox" s:checked="$subscribed" />';

        // Parse → Extract → Compile → Generate
        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        // Should NOT have trailing double space before self-closing tag
        $this->assertStringNotContainsString('  />', $code);
        // Should have correct spacing for self-closing tag
        $this->assertStringContainsString(' />', $code);
    }
}
