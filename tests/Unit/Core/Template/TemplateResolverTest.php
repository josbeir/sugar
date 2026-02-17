<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Template;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Config\Helper\DirectivePrefixHelper;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Directive\Helper\DirectiveClassifier;
use Sugar\Core\Exception\SyntaxException;
use Sugar\Core\Loader\FileTemplateLoader;
use Sugar\Core\Parser\Parser;
use Sugar\Core\Template\BlockMerger;
use Sugar\Core\Template\TemplateResolver;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\NodeBuildersTrait;
use Sugar\Tests\Helper\Trait\TempDirectoryTrait;

final class TemplateResolverTest extends TestCase
{
    use CompilerTestTrait;
    use NodeBuildersTrait;
    use TempDirectoryTrait;

    public function testResolveProcessesExtendsAndReplacesParentBlock(): void
    {
        $this->setUpCompiler();

        $tempDir = $this->createTempDir('sugar_template_resolver_');
        $layoutsDir = $tempDir . '/layouts';
        mkdir($layoutsDir, 0755, true);
        file_put_contents($layoutsDir . '/base.sugar.php', '<main><div s:block="content">Base</div></main>');

        $config = new SugarConfig();
        $prefixHelper = new DirectivePrefixHelper($config->directivePrefix);
        $classifier = new DirectiveClassifier($this->registry, $prefixHelper);
        $merger = new BlockMerger($prefixHelper);
        $resolver = new TemplateResolver(
            new FileTemplateLoader([$tempDir]),
            new Parser($config),
            $prefixHelper,
            $classifier,
            $merger,
        );

        $document = $this->document()
            ->withChildren([
                $this->element('div')
                    ->attribute('s:extends', '../layouts/base.sugar.php')
                    ->build(),
                $this->element('div')
                    ->attribute('s:block', 'content')
                    ->withChild($this->text('Child'))
                    ->build(),
            ])
            ->build();

        $context = new CompilationContext('pages/home.sugar.php', '');
        $loadedTemplates = [];

        try {
            $result = $resolver->resolve($document, $context, $loadedTemplates);

            $this->assertInstanceOf(DocumentNode::class, $result);
            $this->assertCount(1, $result->children);
            $this->assertInstanceOf(ElementNode::class, $result->children[0]);
            $this->assertSame('main', $result->children[0]->tag);
            $this->assertCount(1, $result->children[0]->children);
            $this->assertInstanceOf(ElementNode::class, $result->children[0]->children[0]);
            $this->assertCount(1, $result->children[0]->children[0]->children);
            $this->assertInstanceOf(TextNode::class, $result->children[0]->children[0]->children[0]);
            $this->assertSame('Child', $result->children[0]->children[0]->children[0]->content);
        } finally {
            $this->removeTempDir($tempDir);
        }
    }

    public function testResolveRejectsNestedExtendsPlacement(): void
    {
        $this->setUpCompiler();

        $config = new SugarConfig();
        $prefixHelper = new DirectivePrefixHelper($config->directivePrefix);
        $classifier = new DirectiveClassifier($this->registry, $prefixHelper);
        $merger = new BlockMerger($prefixHelper);
        $resolver = new TemplateResolver(
            new FileTemplateLoader([SUGAR_TEST_TEMPLATE_INHERITANCE_PATH]),
            new Parser($config),
            $prefixHelper,
            $classifier,
            $merger,
        );

        $document = $this->document()
            ->withChild(
                $this->element('main')
                    ->withChild(
                        $this->element('div')
                            ->attribute('s:extends', '../base.sugar.php')
                            ->build(),
                    )
                    ->build(),
            )
            ->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('s:extends is only allowed on root-level template elements.');

        $context = new CompilationContext('pages/home.sugar.php', '');
        $loadedTemplates = [];
        $resolver->resolve($document, $context, $loadedTemplates);
    }

    public function testResolveRejectsNestedExtendsPlacementWithConfiguredPrefixInMessage(): void
    {
        $this->setUpCompiler();

        $config = new SugarConfig(directivePrefix: 'x');
        $prefixHelper = new DirectivePrefixHelper($config->directivePrefix);
        $classifier = new DirectiveClassifier($this->registry, $prefixHelper);
        $merger = new BlockMerger($prefixHelper);
        $resolver = new TemplateResolver(
            new FileTemplateLoader([SUGAR_TEST_TEMPLATE_INHERITANCE_PATH]),
            new Parser($config),
            $prefixHelper,
            $classifier,
            $merger,
        );

        $document = $this->document()
            ->withChild(
                $this->element('main')
                    ->withChild(
                        $this->element('div')
                            ->attribute('x:extends', '../base.sugar.php')
                            ->build(),
                    )
                    ->build(),
            )
            ->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('x:extends is only allowed on root-level template elements.');

        $context = new CompilationContext('pages/home.sugar.php', '');
        $loadedTemplates = [];
        $resolver->resolve($document, $context, $loadedTemplates);
    }

    public function testResolveDetectsCircularIncludes(): void
    {
        $this->setUpCompiler();

        $tempDir = $this->createTempDir('sugar_template_include_cycle_');
        $partialsDir = $tempDir . '/partials';
        mkdir($partialsDir, 0755, true);
        file_put_contents($partialsDir . '/a.sugar.php', '<div s:include="b.sugar.php"></div>');
        file_put_contents($partialsDir . '/b.sugar.php', '<div s:include="a.sugar.php"></div>');

        $config = new SugarConfig();
        $prefixHelper = new DirectivePrefixHelper($config->directivePrefix);
        $classifier = new DirectiveClassifier($this->registry, $prefixHelper);
        $merger = new BlockMerger($prefixHelper);
        $resolver = new TemplateResolver(
            new FileTemplateLoader([$tempDir]),
            new Parser($config),
            $prefixHelper,
            $classifier,
            $merger,
        );

        $document = $this->document()
            ->withChild(
                $this->element('div')
                    ->attribute('s:include', 'partials/a.sugar.php')
                    ->build(),
            )
            ->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Circular template include detected');

        try {
            $context = new CompilationContext('home.sugar.php', '');
            $loadedTemplates = [];
            $resolver->resolve($document, $context, $loadedTemplates);
        } finally {
            $this->removeTempDir($tempDir);
        }
    }
}
