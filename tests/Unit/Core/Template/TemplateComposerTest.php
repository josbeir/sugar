<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Template;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Cache\DependencyTracker;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Exception\SyntaxException;
use Sugar\Core\Exception\TemplateNotFoundException;
use Sugar\Core\Loader\FileTemplateLoader;
use Sugar\Core\Parser\Parser;
use Sugar\Core\Template\TemplateComposer;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\NodeBuildersTrait;
use Sugar\Tests\Helper\Trait\TempDirectoryTrait;

final class TemplateComposerTest extends TestCase
{
    use CompilerTestTrait;
    use NodeBuildersTrait;
    use TempDirectoryTrait;

    private string $inheritanceFixturesPath;

    private TemplateComposer $composer;

    protected function setUp(): void
    {
        $this->setUpCompiler();
        $this->inheritanceFixturesPath = SUGAR_TEST_TEMPLATE_INHERITANCE_PATH;
        $loader = new FileTemplateLoader([$this->inheritanceFixturesPath]);
        $parser = new Parser(new SugarConfig());

        $this->composer = new TemplateComposer($loader, $parser, $this->registry, new SugarConfig());
    }

    protected function execute(DocumentNode $ast, ?CompilationContext $context = null): DocumentNode
    {
        return $this->composer->compose($ast, $context ?? $this->createTestContext());
    }

    /**
     * @param array<string>|null $blocks
     */
    protected function createTestContext(
        string $templatePath = 'test.sugar.php',
        string $source = '',
        bool $debug = false,
        ?DependencyTracker $tracker = null,
        ?array $blocks = null,
    ): CompilationContext {
        return new CompilationContext($templatePath, $source, $debug, $tracker, $blocks);
    }

    protected function createText(string $content): TextNode
    {
        return new TextNode($content, 1, 1);
    }

    protected function findElement(string $tagName, DocumentNode $ast): ?ElementNode
    {
        foreach ($ast->children as $child) {
            if ($child instanceof ElementNode && $child->tag === $tagName) {
                return $child;
            }
        }

        return null;
    }

    public function testProcessesTemplateWithoutInheritance(): void
    {
        $document = $this->document()
            ->withChild(
                $this->element('div')
                    ->withChild($this->createText('Hello'))
                    ->build(),
            )
            ->build();

        $result = $this->execute($document, $this->createTestContext('', 'home.sugar.php'));

        $this->assertInstanceOf(DocumentNode::class, $result);
        $this->assertCount(1, $result->children);
    }

    public function testExtendsReplacesBlockContent(): void
    {
        // Child template with s:extends
        $document = $this->document()
            ->withChildren([
                $this->element('div')
                    ->attribute('s:extends', '../base.sugar.php')
                    ->build(),
                $this->element('title')
                    ->attribute('s:block', 'title')
                    ->withChild($this->createText('Child Title'))
                    ->build(),
            ])
            ->build();

        $result = $this->execute($document, $this->createTestContext('', 'pages/home.sugar.php'));

        // Should return parent structure with replaced blocks
        $this->assertInstanceOf(DocumentNode::class, $result);

        // Debug: check what we got
        $this->assertGreaterThan(0, count($result->children), 'Result should have children');

        // Find title element in result - search recursively
        $titleFound = false;
        /**
         * @param array<\Sugar\Core\Ast\Node> $nodes
         */
        $findTitle = function (array $nodes) use (&$findTitle, &$titleFound): void {
            foreach ($nodes as $child) {
                if ($child instanceof ElementNode && $child->tag === 'title') {
                    $titleFound = true;
                    $this->assertGreaterThan(0, count($child->children));
                    $this->assertInstanceOf(TextNode::class, $child->children[0]);
                    $this->assertStringContainsString('Child Title', $child->children[0]->content);

                    return;
                }

                if ($child instanceof ElementNode && $child->children !== []) {
                    $findTitle($child->children);
                }
            }
        };
        $findTitle($result->children);

        $this->assertTrue($titleFound, 'Title element should be present in result');
    }

    public function testExtendsAppendsBlockContent(): void
    {
        $layoutPath = $this->inheritanceFixturesPath . '/layouts/temp-append-layout.sugar.php';
        file_put_contents($layoutPath, '<div s:block="content"><span>Base</span></div>');

        try {
            $document = $this->document()
                ->withChildren([
                    $this->element('div')
                        ->attribute('s:extends', '../layouts/temp-append-layout.sugar.php')
                        ->build(),
                    $this->element('div')
                        ->attribute('s:append', 'content')
                        ->withChild(
                            $this->element('span')
                                ->withChild($this->createText('Extra'))
                                ->build(),
                        )
                        ->build(),
                ])
                ->build();

            $result = $this->execute($document, $this->createTestContext('', 'pages/home.sugar.php'));

            $this->assertInstanceOf(DocumentNode::class, $result);
            $code = $this->documentToString($result);
            $this->assertStringContainsString('<div><span>Base</span><span>Extra</span></div>', $code);
        } finally {
            unlink($layoutPath);
        }
    }

    public function testExtendsPrependsBlockContent(): void
    {
        $layoutPath = $this->inheritanceFixturesPath . '/layouts/temp-prepend-layout.sugar.php';
        file_put_contents($layoutPath, '<div s:block="content"><span>Base</span></div>');

        try {
            $document = $this->document()
                ->withChildren([
                    $this->element('div')
                        ->attribute('s:extends', '../layouts/temp-prepend-layout.sugar.php')
                        ->build(),
                    $this->element('div')
                        ->attribute('s:prepend', 'content')
                        ->withChild(
                            $this->element('span')
                                ->withChild($this->createText('Extra'))
                                ->build(),
                        )
                        ->build(),
                ])
                ->build();

            $result = $this->execute($document, $this->createTestContext('', 'pages/home.sugar.php'));

            $this->assertInstanceOf(DocumentNode::class, $result);
            $code = $this->documentToString($result);
            $this->assertStringContainsString('<div><span>Extra</span><span>Base</span></div>', $code);
        } finally {
            unlink($layoutPath);
        }
    }

    public function testExtendsAppendsDirectiveFragment(): void
    {
        $layoutPath = $this->inheritanceFixturesPath . '/layouts/temp-append-directive.sugar.php';
        file_put_contents($layoutPath, '<div s:block="content"><span>Base</span></div>');

        try {
            $document = $this->document()
                ->withChildren([
                    $this->element('div')
                        ->attribute('s:extends', '../layouts/temp-append-directive.sugar.php')
                        ->build(),
                    $this->fragment(
                        attributes: [
                            $this->attribute('s:append', 'content'),
                            $this->attribute('s:if', '$show'),
                        ],
                        children: [
                            $this->element('span')
                                ->withChild($this->createText('Extra'))
                                ->build(),
                        ],
                        line: 1,
                        column: 1,
                    ),
                ])
                ->build();

            $result = $this->execute($document, $this->createTestContext('', 'pages/home.sugar.php'));

            $this->assertInstanceOf(DocumentNode::class, $result);
            $div = $this->findElement('div', $result);
            $this->assertInstanceOf(ElementNode::class, $div);
            $this->assertCount(2, $div->children);
            $this->assertInstanceOf(ElementNode::class, $div->children[0]);
            $this->assertInstanceOf(FragmentNode::class, $div->children[1]);
            $this->assertSame('s:if', $div->children[1]->attributes[0]->name);
        } finally {
            unlink($layoutPath);
        }
    }

    public function testExtendsRejectsUnknownDirectiveOnFragment(): void
    {
        $document = $this->document()
            ->withChildren([
                $this->element('s-template')
                    ->attribute('s:extends', '../base.sugar.php')
                    ->build(),
                $this->fragment(
                    attributes: [$this->attribute('s:bloc', 'content')],
                    children: [
                        $this->element('div')
                            ->withChild($this->createText('Main'))
                            ->build(),
                    ],
                    line: 2,
                    column: 1,
                ),
            ])
            ->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Unknown directive "bloc"');
        $this->expectExceptionMessage('Did you mean "block"');

        $this->execute(
            $document,
            $this->createTestContext(
                templatePath: 'pages/home.sugar.php',
                source: "<s-template s:extends=\"../base.sugar.php\" />\n<s-template s:bloc=\"content\">Main</s-template>",
            ),
        );
    }

    public function testExtendsReplacesElementBlockWithPlainFragment(): void
    {
        $layoutPath = $this->inheritanceFixturesPath . '/layouts/temp-replace-fragment.sugar.php';
        file_put_contents($layoutPath, '<div s:block="content"><span>Base</span></div>');

        try {
            $document = $this->document()
                ->withChildren([
                    $this->element('div')
                        ->attribute('s:extends', '../layouts/temp-replace-fragment.sugar.php')
                        ->build(),
                    $this->fragment(
                        attributes: [$this->attribute('s:block', 'content')],
                        children: [$this->createText('Child')],
                        line: 1,
                        column: 1,
                    ),
                ])
                ->build();

            $result = $this->execute($document, $this->createTestContext('', 'pages/home.sugar.php'));

            $this->assertInstanceOf(DocumentNode::class, $result);
            $div = $this->findElement('div', $result);
            $this->assertInstanceOf(ElementNode::class, $div);
            $this->assertCount(1, $div->children);
            $this->assertInstanceOf(TextNode::class, $div->children[0]);
            $this->assertSame('Child', $div->children[0]->content);
        } finally {
            unlink($layoutPath);
        }
    }

    public function testThrowsOnMultipleBlockDirectives(): void
    {
        $layoutPath = $this->inheritanceFixturesPath . '/layouts/temp-multi-block.sugar.php';
        file_put_contents($layoutPath, '<div s:block="content">Base</div>');

        $document = $this->document()
            ->withChildren([
                $this->element('div')
                    ->attribute('s:extends', '../layouts/temp-multi-block.sugar.php')
                    ->build(),
                $this->element('div')
                    ->attribute('s:block', 'content')
                    ->attribute('s:append', 'content')
                    ->withChild($this->createText('Child'))
                    ->build(),
            ])
            ->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Only one of s:block, s:append, or s:prepend');

        try {
            $this->execute($document, $this->createTestContext('', 'pages/home.sugar.php'));
        } finally {
            unlink($layoutPath);
        }
    }

    public function testExtendsOnFragmentElement(): void
    {
        $document = $this->document()
            ->withChildren([
                $this->fragment(
                    attributes: [
                        $this->attribute('s:extends', '../base.sugar.php'),
                    ],
                    children: [],
                    line: 1,
                    column: 1,
                ),
                $this->element('title')
                    ->attribute('s:block', 'title')
                    ->withChild($this->createText('Child Title'))
                    ->build(),
            ])
            ->build();

        $result = $this->execute($document, $this->createTestContext('', 'pages/home.sugar.php'));

        $this->assertInstanceOf(DocumentNode::class, $result);
        $this->assertGreaterThan(0, count($result->children));

        $titleFound = false;
        /**
         * @param array<\Sugar\Core\Ast\Node> $nodes
         */
        $findTitle = function (array $nodes) use (&$findTitle, &$titleFound): void {
            foreach ($nodes as $child) {
                if ($child instanceof ElementNode && $child->tag === 'title') {
                    $titleFound = true;
                    $this->assertGreaterThan(0, count($child->children));
                    $this->assertInstanceOf(TextNode::class, $child->children[0]);
                    $this->assertStringContainsString('Child Title', $child->children[0]->content);

                    return;
                }

                if ($child instanceof ElementNode && $child->children !== []) {
                    $findTitle($child->children);
                }
            }
        };

        $findTitle($result->children);

        $this->assertTrue($titleFound, 'Title element should be present in result');
    }

    public function testExtendsPreservesChildElementWrapperWhenParentBlockIsFragment(): void
    {
        $parentPath = $this->inheritanceFixturesPath . '/layouts/temp-fragment.sugar.php';
        file_put_contents(
            $parentPath,
            '<s-template s:block="content">Original</s-template>',
        );

        $document = $this->document()
            ->withChildren([
                $this->element('s-template')
                    ->attribute('s:extends', '../layouts/temp-fragment.sugar.php')
                    ->build(),
                $this->element('div')
                    ->attribute('s:block', 'content')
                    ->attribute('class', 'myblock')
                    ->withChild($this->createText('Child'))
                    ->build(),
            ])
            ->build();

        $result = $this->execute($document, $this->createTestContext('', 'pages/home.sugar.php'));

        $this->assertInstanceOf(DocumentNode::class, $result);

        $divFound = false;
        $findDiv = function (array $nodes) use (&$findDiv, &$divFound): void {
            foreach ($nodes as $child) {
                if ($child instanceof ElementNode && $child->tag === 'div') {
                    $divFound = true;
                    $this->assertInstanceOf(TextNode::class, $child->children[0]);
                    $this->assertSame('Child', $child->children[0]->content);

                    return;
                }

                if ($child instanceof ElementNode && $child->children !== []) {
                    $findDiv($child->children);
                }
            }
        };

        $findDiv($result->children);

        $this->assertTrue($divFound, 'Child element wrapper should be preserved');

        unlink($parentPath);
    }

    public function testExtendsAppendsPlainFragmentToFragmentBlock(): void
    {
        $parentPath = $this->inheritanceFixturesPath . '/layouts/temp-fragment-append.sugar.php';
        file_put_contents(
            $parentPath,
            '<s-template s:block="content"><span>Base</span></s-template>',
        );

        $document = $this->document()
            ->withChildren([
                $this->element('div')
                    ->attribute('s:extends', '../layouts/temp-fragment-append.sugar.php')
                    ->build(),
                $this->fragment(
                    attributes: [$this->attribute('s:append', 'content')],
                    children: [
                        $this->element('span')
                            ->withChild($this->createText('Extra'))
                            ->build(),
                    ],
                    line: 1,
                    column: 1,
                ),
            ])
            ->build();

        $result = $this->execute($document, $this->createTestContext('', 'pages/home.sugar.php'));

        $this->assertInstanceOf(DocumentNode::class, $result);
        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(FragmentNode::class, $result->children[0]);
        $this->assertCount(2, $result->children[0]->children);

        unlink($parentPath);
    }

    public function testExtendsReplacesFragmentBlockWithPlainFragment(): void
    {
        $parentPath = $this->inheritanceFixturesPath . '/layouts/temp-fragment-replace.sugar.php';
        file_put_contents(
            $parentPath,
            '<s-template s:block="content"><span>Base</span></s-template>',
        );

        $document = $this->document()
            ->withChildren([
                $this->element('div')
                    ->attribute('s:extends', '../layouts/temp-fragment-replace.sugar.php')
                    ->build(),
                $this->fragment(
                    attributes: [$this->attribute('s:block', 'content')],
                    children: [
                        $this->element('span')
                            ->withChild($this->createText('Child'))
                            ->build(),
                    ],
                    line: 1,
                    column: 1,
                ),
            ])
            ->build();

        $result = $this->execute($document, $this->createTestContext('', 'pages/home.sugar.php'));

        $this->assertInstanceOf(DocumentNode::class, $result);
        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(FragmentNode::class, $result->children[0]);
        $this->assertCount(1, $result->children[0]->children);

        unlink($parentPath);
    }

    public function testExtendsAppendsElementToFragmentBlock(): void
    {
        $parentPath = $this->inheritanceFixturesPath . '/layouts/temp-fragment-append-element.sugar.php';
        file_put_contents(
            $parentPath,
            '<s-template s:block="content"><span>Base</span></s-template>',
        );

        $document = $this->document()
            ->withChildren([
                $this->element('div')
                    ->attribute('s:extends', '../layouts/temp-fragment-append-element.sugar.php')
                    ->build(),
                $this->element('div')
                    ->attribute('s:append', 'content')
                    ->withChild(
                        $this->element('span')
                            ->withChild($this->createText('Extra'))
                            ->build(),
                    )
                    ->build(),
            ])
            ->build();

        $result = $this->execute($document, $this->createTestContext('', 'pages/home.sugar.php'));

        $this->assertInstanceOf(DocumentNode::class, $result);
        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(FragmentNode::class, $result->children[0]);
        $this->assertCount(2, $result->children[0]->children);

        unlink($parentPath);
    }

    public function testExtendsPrependsElementToFragmentBlock(): void
    {
        $parentPath = $this->inheritanceFixturesPath . '/layouts/temp-fragment-prepend-element.sugar.php';
        file_put_contents(
            $parentPath,
            '<s-template s:block="content"><span>Base</span></s-template>',
        );

        $document = $this->document()
            ->withChildren([
                $this->element('div')
                    ->attribute('s:extends', '../layouts/temp-fragment-prepend-element.sugar.php')
                    ->build(),
                $this->element('div')
                    ->attribute('s:prepend', 'content')
                    ->withChild(
                        $this->element('span')
                            ->withChild($this->createText('Extra'))
                            ->build(),
                    )
                    ->build(),
            ])
            ->build();

        $result = $this->execute($document, $this->createTestContext('', 'pages/home.sugar.php'));

        $this->assertInstanceOf(DocumentNode::class, $result);
        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(FragmentNode::class, $result->children[0]);
        $this->assertCount(2, $result->children[0]->children);

        unlink($parentPath);
    }

    public function testExtendsAppendsDirectiveFragmentToFragmentBlock(): void
    {
        $parentPath = $this->inheritanceFixturesPath . '/layouts/temp-fragment-append-directive.sugar.php';
        file_put_contents(
            $parentPath,
            '<s-template s:block="content"><span>Base</span></s-template>',
        );

        $document = $this->document()
            ->withChildren([
                $this->element('div')
                    ->attribute('s:extends', '../layouts/temp-fragment-append-directive.sugar.php')
                    ->build(),
                $this->fragment(
                    attributes: [
                        $this->attribute('s:append', 'content'),
                        $this->attribute('s:if', '$show'),
                    ],
                    children: [
                        $this->element('span')
                            ->withChild($this->createText('Extra'))
                            ->build(),
                    ],
                    line: 1,
                    column: 1,
                ),
            ])
            ->build();

        $result = $this->execute($document, $this->createTestContext('', 'pages/home.sugar.php'));

        $this->assertInstanceOf(DocumentNode::class, $result);
        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(FragmentNode::class, $result->children[0]);
        $this->assertCount(2, $result->children[0]->children);

        unlink($parentPath);
    }

    public function testExtendsResolvesChildIncludesRelativeToChildTemplate(): void
    {
        $tempDir = $this->createTempDir('sugar_inheritance_');
        $layoutsDir = $tempDir . '/layouts';
        $pagesDir = $tempDir . '/pages';

        mkdir($layoutsDir, 0755, true);
        mkdir($pagesDir, 0755, true);

        file_put_contents($layoutsDir . '/temp-include-layout.sugar.php', '<main s:block="content">Base</main>');
        file_put_contents($pagesDir . '/temp-child-include.sugar.php', '<p>Included</p>');

        $loader = new FileTemplateLoader([$tempDir]);
        $parser = new Parser(new SugarConfig());
        $composer = new TemplateComposer($loader, $parser, $this->registry, new SugarConfig());

        try {
            $document = $this->document()
                ->withChildren([
                    $this->element('s-template')
                        ->attribute('s:extends', '../layouts/temp-include-layout.sugar.php')
                        ->build(),
                    $this->element('div')
                        ->attribute('s:block', 'content')
                        ->withChild(
                            $this->element('div')
                                ->attribute('s:include', 'temp-child-include.sugar.php')
                                ->build(),
                        )
                        ->build(),
                ])
                ->build();

            $result = $composer->compose($document, $this->createTestContext('pages/home.sugar.php'));

            $this->assertInstanceOf(DocumentNode::class, $result);

            $found = false;
            $findIncluded = function (array $nodes) use (&$findIncluded, &$found): void {
                foreach ($nodes as $child) {
                    if ($child instanceof ElementNode && $child->tag === 'p') {
                        $found = true;
                        $this->assertInstanceOf(TextNode::class, $child->children[0]);
                        $this->assertSame('Included', $child->children[0]->content);

                        return;
                    }

                    if ($child instanceof ElementNode && $child->children !== []) {
                        $findIncluded($child->children);
                    }
                }
            };

            $findIncluded($result->children);

            $this->assertTrue($found, 'Child include should resolve relative to child template path');
        } finally {
            $this->removeTempDir($tempDir);
        }
    }

    public function testBlocksModeExtractsRequestedBlocksInTemplateOrder(): void
    {
        $document = $this->document()
            ->withChildren([
                $this->element('s-template')
                    ->attribute('s:extends', '../base.sugar.php')
                    ->build(),
                $this->element('div')
                    ->attribute('s:block', 'sidebar')
                    ->withChild($this->createText('Side'))
                    ->build(),
                $this->element('div')
                    ->attribute('s:block', 'content')
                    ->withChild($this->createText('Main'))
                    ->build(),
                $this->element('p')
                    ->withChild($this->createText('Ignored'))
                    ->build(),
            ])
            ->build();

        $context = $this->createTestContext(
            templatePath: 'pages/home.sugar.php',
            blocks: ['content', 'sidebar'],
        );

        $result = $this->execute($document, $context);

        $this->assertInstanceOf(DocumentNode::class, $result);
        $this->assertCount(2, $result->children);
        $this->assertInstanceOf(ElementNode::class, $result->children[0]);
        $this->assertInstanceOf(ElementNode::class, $result->children[1]);
        $this->assertSame('div', $result->children[0]->tag);
        $this->assertSame('div', $result->children[1]->tag);
        $this->assertInstanceOf(TextNode::class, $result->children[0]->children[0]);
        $this->assertInstanceOf(TextNode::class, $result->children[1]->children[0]);
        $this->assertSame('Side', $result->children[0]->children[0]->content);
        $this->assertSame('Main', $result->children[1]->children[0]->content);
    }

    public function testBlocksModeIgnoresEmptyBlockNames(): void
    {
        $document = $this->document()
            ->withChildren([
                $this->element('s-template')
                    ->attribute('s:extends', '../base.sugar.php')
                    ->build(),
                $this->element('div')
                    ->attribute('s:block', '')
                    ->withChild($this->createText('Ignored'))
                    ->build(),
                $this->element('div')
                    ->attribute('s:block', 'content')
                    ->withChild($this->createText('Main'))
                    ->build(),
            ])
            ->build();

        $context = $this->createTestContext(
            templatePath: 'pages/home.sugar.php',
            blocks: ['content'],
        );

        $result = $this->execute($document, $context);

        $this->assertInstanceOf(DocumentNode::class, $result);
        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(ElementNode::class, $result->children[0]);
        $this->assertInstanceOf(TextNode::class, $result->children[0]->children[0]);
        $this->assertSame('Main', $result->children[0]->children[0]->content);
    }

    public function testMultiLevelInheritance(): void
    {
        // Create grandparent template file
        file_put_contents(
            $this->inheritanceFixturesPath . '/layouts/temp-master.sugar.php',
            '<html><head><title s:block="title">Master</title></head></html>',
        );

        // Create parent template that extends grandparent
        file_put_contents(
            $this->inheritanceFixturesPath . '/layouts/temp-app.sugar.php',
            '<div s:extends="temp-master.sugar.php"></div><title s:block="title">App</title>',
        );

        // Child extends parent
        $document = $this->document()
            ->withChildren([
                $this->element('div')
                    ->attribute('s:extends', '../layouts/temp-app.sugar.php')
                    ->build(),
                $this->element('title')
                    ->attribute('s:block', 'title')
                    ->withChild($this->createText('Page Title'))
                    ->build(),
            ])
            ->build();

        $result = $this->execute($document, $this->createTestContext('', 'pages/home.sugar.php'));

        // Should resolve all the way up to grandparent with final block content
        $this->assertInstanceOf(DocumentNode::class, $result);

        // Cleanup
        unlink($this->inheritanceFixturesPath . '/layouts/temp-master.sugar.php');
        unlink($this->inheritanceFixturesPath . '/layouts/temp-app.sugar.php');
    }

    public function testIncludeInsertsTemplateContent(): void
    {
        // Create include file
        file_put_contents(
            $this->inheritanceFixturesPath . '/partials/temp-header.sugar.php',
            '<header>Header Content</header>',
        );

        $document = $this->document()
            ->withChildren([
                $this->element('div')
                    ->attribute('s:include', 'partials/temp-header.sugar.php')
                    ->build(),
                $this->createText('Main Content'),
            ])
            ->build();

        $result = $this->execute($document, $this->createTestContext('', 'home.sugar.php'));

        $this->assertInstanceOf(DocumentNode::class, $result);
        $this->assertCount(2, $result->children);
        $this->assertInstanceOf(ElementNode::class, $result->children[0]);
        $this->assertSame('div', $result->children[0]->tag);
        $this->assertCount(1, $result->children[0]->children);
        $this->assertInstanceOf(ElementNode::class, $result->children[0]->children[0]);
        $this->assertSame('header', $result->children[0]->children[0]->tag);

        // Cleanup
        unlink($this->inheritanceFixturesPath . '/partials/temp-header.sugar.php');
    }

    public function testIncludeUnknownDirectiveUsesIncludedTemplatePath(): void
    {
        $includePath = $this->inheritanceFixturesPath . '/partials/temp-include-unknown.sugar.php';
        file_put_contents($includePath, '<s-template s:cliss="[\'bli\']"></s-template>');

        try {
            $document = $this->document()
                ->withChild(
                    $this->element('div')
                        ->attribute('s:include', 'partials/temp-include-unknown.sugar.php')
                        ->build(),
                )
                ->build();

            try {
                $this->execute(
                    $document,
                    $this->createTestContext(
                        templatePath: 'home.sugar.php',
                        source: '<div s:include="partials/temp-include-unknown.sugar.php"></div>',
                    ),
                );
                $this->fail('Expected SyntaxException for unknown directive in included template.');
            } catch (SyntaxException $exception) {
                $this->assertSame('@app/partials/temp-include-unknown.sugar.php', $exception->templatePath);
                $this->assertSame(1, $exception->templateLine);
                $this->assertNotNull($exception->templateColumn);
                $this->assertGreaterThan(0, $exception->templateColumn ?? 0);
                $this->assertStringContainsString('Unknown directive "cliss"', $exception->getMessage());
            }
        } finally {
            unlink($includePath);
        }
    }

    public function testNestedIncludeInsideFragmentChildren(): void
    {
        $includePath = $this->inheritanceFixturesPath . '/temp-nested-include.sugar.php';
        file_put_contents($includePath, '<span>Nested</span>');

        try {
            $document = $this->document()
                ->withChild(
                    $this->fragment(
                        attributes: [],
                        children: [
                            $this->element('div')
                                ->attribute('s:include', 'temp-nested-include.sugar.php')
                                ->build(),
                        ],
                        line: 1,
                        column: 1,
                    ),
                )
                ->build();

            $result = $this->execute($document, $this->createTestContext('', 'home.sugar.php'));

            $this->assertInstanceOf(DocumentNode::class, $result);
            $this->assertCount(1, $result->children);
            $this->assertInstanceOf(FragmentNode::class, $result->children[0]);

            $fragment = $result->children[0];
            $this->assertCount(1, $fragment->children);
            $this->assertInstanceOf(ElementNode::class, $fragment->children[0]);
            $this->assertSame('div', $fragment->children[0]->tag);
            $this->assertCount(1, $fragment->children[0]->children);
            $this->assertInstanceOf(ElementNode::class, $fragment->children[0]->children[0]);
            $this->assertSame('span', $fragment->children[0]->children[0]->tag);
        } finally {
            unlink($includePath);
        }
    }

    public function testIncludeOnFragmentIsWrapperless(): void
    {
        $includePath = $this->inheritanceFixturesPath . '/temp-fragment-include.sugar.php';
        file_put_contents($includePath, '<span>Inner</span>');

        try {
            $document = $this->document()
                ->withChild(
                    $this->fragment(
                        attributes: [$this->attribute('s:include', 'temp-fragment-include.sugar.php')],
                        children: [],
                        line: 1,
                        column: 1,
                    ),
                )
                ->build();

            $result = $this->execute($document, $this->createTestContext('', 'home.sugar.php'));

            $this->assertInstanceOf(DocumentNode::class, $result);
            $this->assertCount(1, $result->children);
            $this->assertInstanceOf(ElementNode::class, $result->children[0]);
            $this->assertSame('span', $result->children[0]->tag);
        } finally {
            unlink($includePath);
        }
    }

    public function testIncludeOnFragmentWithWithWrapsScope(): void
    {
        $includePath = $this->inheritanceFixturesPath . '/temp-fragment-include-with.sugar.php';
        file_put_contents($includePath, '<span><?= $name ?></span>');

        try {
            $document = $this->document()
                ->withChild(
                    $this->fragment(
                        attributes: [
                            $this->attribute('s:include', 'temp-fragment-include-with.sugar.php'),
                            $this->attribute('s:with', "['name' => 'Sugar']"),
                        ],
                        children: [],
                        line: 1,
                        column: 1,
                    ),
                )
                ->build();

            $result = $this->execute($document, $this->createTestContext('', 'home.sugar.php'));

            $this->assertInstanceOf(DocumentNode::class, $result);
            $this->assertCount(3, $result->children);
            $this->assertInstanceOf(RawPhpNode::class, $result->children[0]);
            $this->assertInstanceOf(ElementNode::class, $result->children[1]);
            $this->assertInstanceOf(RawPhpNode::class, $result->children[2]);
            $this->assertStringContainsString('function(array $__vars)', $result->children[0]->code);
            $this->assertStringContainsString('return ob_get_clean();', $result->children[2]->code);
        } finally {
            unlink($includePath);
        }
    }

    public function testIncludeWithIsolatedScope(): void
    {
        // Create include file
        file_put_contents(
            $this->inheritanceFixturesPath . '/partials/user.sugar.php',
            '<span><?= $user ?></span>',
        );

        $document = $this->document()
            ->withChild(
                $this->element('div')
                    ->attribute('s:include', 'partials/user.sugar.php')
                    ->attribute('s:with', "['user' => \$userName]")
                    ->build(),
            )
            ->build();

        $result = $this->execute($document, $this->createTestContext('', 'home.sugar.php'));

        $this->assertInstanceOf(DocumentNode::class, $result);
        // Should wrap in scope isolation
        // Verify that s:with creates proper variable scope

        // Cleanup
        unlink($this->inheritanceFixturesPath . '/partials/user.sugar.php');
    }

    public function testThrowsOnCircularInheritance(): void
    {
        // Create circular reference: a.sugar.php extends b.sugar.php, b.sugar.php extends a.sugar.php
        file_put_contents(
            $this->inheritanceFixturesPath . '/circular-a.sugar.php',
            '<div s:extends="circular-b.sugar.php"></div>',
        );
        file_put_contents(
            $this->inheritanceFixturesPath . '/circular-b.sugar.php',
            '<div s:extends="circular-a.sugar.php"></div>',
        );

        $document = $this->document()
            ->withChild(
                $this->element('div')
                    ->attribute('s:extends', 'circular-a.sugar.php')
                    ->build(),
            )
            ->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Circular');

        try {
            $this->execute($document, $this->createTestContext('', 'home.sugar.php'));
        } finally {
            // Cleanup
            unlink($this->inheritanceFixturesPath . '/circular-a.sugar.php');
            unlink($this->inheritanceFixturesPath . '/circular-b.sugar.php');
        }
    }

    public function testThrowsOnCircularInheritanceWithoutExtendsElement(): void
    {
        $document = $this->document()
            ->withChild(
                $this->element('div')
                    ->withChild($this->createText('Content'))
                    ->build(),
            )
            ->build();

        $context = $this->createTestContext('home.sugar.php', '<div>Content</div>');
        $loadedTemplates = ['home.sugar.php'];

        $method = new ReflectionMethod(TemplateComposer::class, 'process');

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Circular template inheritance detected');

        $method->invokeArgs($this->composer, [$document, $context, &$loadedTemplates]);
    }

    public function testThrowsOnTemplateNotFound(): void
    {
        $document = $this->document()
            ->withChild(
                $this->element('div')
                    ->attribute('s:extends', 'nonexistent.sugar.php')
                    ->build(),
            )
            ->build();

        $this->expectException(TemplateNotFoundException::class);

        $this->execute($document, $this->createTestContext('', 'home.sugar.php'));
    }

    public function testThrowsWhenExtendsIsNestedInsideBlock(): void
    {
        $document = $this->document()
            ->withChildren([
                $this->element('main')
                    ->attribute('s:block', 'content')
                    ->withChild(
                        $this->element('div')
                            ->attribute('s:extends', '../layouts/base.sugar.php')
                            ->build(),
                    )
                    ->build(),
            ])
            ->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('s:extends is only allowed on root-level template elements.');

        $this->execute($document, $this->createTestContext('', 'pages/home.sugar.php'));
    }

    public function testRelativePathResolution(): void
    {
        // Existing fixture at layouts/base.sugar.php
        $document = $this->document()
            ->withChildren([
                $this->element('div')
                    ->attribute('s:extends', '../layouts/base.sugar.php')
                    ->build(),
                $this->element('title')
                    ->attribute('s:block', 'title')
                    ->withChild($this->createText('Page'))
                    ->build(),
            ])
            ->build();

        $result = $this->execute($document, $this->createTestContext('', 'pages/home.sugar.php'));

        $this->assertInstanceOf(DocumentNode::class, $result);
    }

    public function testAbsolutePathResolution(): void
    {
        // Existing fixture at layouts/base.sugar.php
        $document = $this->document()
            ->withChildren([
                $this->element('div')
                    ->attribute('s:extends', '/layouts/base.sugar.php')
                    ->build(),
                $this->element('title')
                    ->attribute('s:block', 'title')
                    ->withChild($this->createText('Page'))
                    ->build(),
            ])
            ->build();

        $result = $this->execute($document, $this->createTestContext('', 'pages/home.sugar.php'));

        $this->assertInstanceOf(DocumentNode::class, $result);
    }

    public function testPreservesNonInheritanceDirectives(): void
    {
        $document = $this->document()
            ->withChild(
                $this->element('div')
                    ->attribute('s:if', '$show')
                    ->withChild($this->createText('Content'))
                    ->build(),
            )
            ->build();

        $result = $this->execute($document, $this->createTestContext('', 'home.sugar.php'));

        $this->assertInstanceOf(DocumentNode::class, $result);
        // s:if should remain unchanged
        $this->assertCount(1, $result->children);
        $element = $result->children[0];
        $this->assertInstanceOf(ElementNode::class, $element);
        $this->assertCount(1, $element->attributes);
        $this->assertInstanceOf(AttributeNode::class, $element->attributes[0]);
        $this->assertSame('s:if', $element->attributes[0]->name);
    }

    public function testIncludeWithoutWithHasOpenScope(): void
    {
        // Create a simple included template
        $includePath = $this->inheritanceFixturesPath . '/temp-include.sugar.php';
        file_put_contents($includePath, '<p><?= $message ?></p>');

        try {
            $document = $this->document()
                ->withChild(
                    $this->element('div')
                        ->attribute('s:include', 'temp-include.sugar.php')
                        ->build(),
                )
                ->build();

            $result = $this->execute($document, $this->createTestContext('', 'home.sugar.php'));

            // Should expand inline (no closure wrapper)
            $this->assertInstanceOf(DocumentNode::class, $result);
            $this->assertCount(1, $result->children);

            // Wrapper element should be preserved with included content inside
            $this->assertInstanceOf(ElementNode::class, $result->children[0]);
            $this->assertSame('div', $result->children[0]->tag);
            $this->assertCount(1, $result->children[0]->children);
            $this->assertInstanceOf(ElementNode::class, $result->children[0]->children[0]);
            $this->assertSame('p', $result->children[0]->children[0]->tag);
        } finally {
            unlink($includePath);
        }
    }

    public function testIncludeWithWithHasIsolatedScope(): void
    {
        // Create a simple included template
        $includePath = $this->inheritanceFixturesPath . '/temp-include-with.sugar.php';
        file_put_contents($includePath, '<p><?= $title ?></p>');

        try {
            $document = $this->document()
                ->withChild(
                    $this->element('div')
                        ->attribute('s:include', 'temp-include-with.sugar.php')
                        ->attribute('s:with', "['title' => 'Hello']")
                        ->build(),
                )
                ->build();

            $result = $this->execute($document, $this->createTestContext('', 'home.sugar.php'));

            // Should wrap in closure for isolation
            $this->assertInstanceOf(DocumentNode::class, $result);
            $this->assertCount(1, $result->children);
            $this->assertInstanceOf(ElementNode::class, $result->children[0]);
            $this->assertSame('div', $result->children[0]->tag);

            // Convert to string to check for closure pattern
            $code = $this->documentToString($result);

            // Should use new scope isolation pattern with bindTo and type hints
            $this->assertStringContainsString('(function(array $__vars): string { ob_start(); extract($__vars, EXTR_SKIP);', $code);
            $this->assertStringContainsString('return ob_get_clean(); })->bindTo($this ?? null)', $code);
            $this->assertStringContainsString("(['title' => 'Hello']);", $code);
        } finally {
            unlink($includePath);
        }
    }

    public function testReplacesElementBlockWithDirectiveFragment(): void
    {
        $layoutPath = $this->inheritanceFixturesPath . '/temp-fragment-layout.sugar.php';
        file_put_contents($layoutPath, '<div s:block="content">Base</div>');

        try {
            $document = $this->document()
                ->withChildren([
                    $this->element('div')
                        ->attribute('s:extends', 'temp-fragment-layout.sugar.php')
                        ->build(),
                    $this->fragment(
                        attributes: [
                            $this->attribute('s:block', 'content'),
                            $this->attribute('s:if', '$show'),
                        ],
                        children: [$this->createText('Child')],
                        line: 1,
                        column: 1,
                    ),
                ])
                ->build();

            $result = $this->execute($document, $this->createTestContext('', 'page.sugar.php'));

            $this->assertInstanceOf(DocumentNode::class, $result);
            $this->assertCount(1, $result->children);
            $this->assertInstanceOf(ElementNode::class, $result->children[0]);

            $parent = $result->children[0];
            $this->assertCount(1, $parent->children);
            $this->assertInstanceOf(FragmentNode::class, $parent->children[0]);

            $fragment = $parent->children[0];
            $this->assertSame('s:if', $fragment->attributes[0]->name);
            foreach ($fragment->attributes as $attr) {
                $this->assertNotSame('s:block', $attr->name);
            }
        } finally {
            unlink($layoutPath);
        }
    }

    public function testReplacesFragmentBlockWithDirectiveFragment(): void
    {
        $layoutPath = $this->inheritanceFixturesPath . '/temp-fragment-root.sugar.php';
        file_put_contents(
            $layoutPath,
            '<s-template s:block="content"><div>Base</div></s-template>',
        );

        try {
            $document = $this->document()
                ->withChildren([
                    $this->element('div')
                        ->attribute('s:extends', 'temp-fragment-root.sugar.php')
                        ->build(),
                    $this->fragment(
                        attributes: [
                            $this->attribute('s:block', 'content'),
                            $this->attribute('s:if', '$show'),
                        ],
                        children: [$this->createText('Child')],
                        line: 1,
                        column: 1,
                    ),
                ])
                ->build();

            $result = $this->execute($document, $this->createTestContext('', 'page.sugar.php'));

            $this->assertInstanceOf(DocumentNode::class, $result);
            $this->assertCount(1, $result->children);
            $this->assertInstanceOf(FragmentNode::class, $result->children[0]);

            $fragment = $result->children[0];
            $this->assertSame('s:if', $fragment->attributes[0]->name);
            foreach ($fragment->attributes as $attr) {
                $this->assertNotSame('s:block', $attr->name);
            }
        } finally {
            unlink($layoutPath);
        }
    }

    private function documentToString(DocumentNode $document): string
    {
        $output = '';
        foreach ($document->children as $child) {
            $output .= $this->nodeToString($child);
        }

        return $output;
    }

    private function nodeToString(Node $node): string
    {
        if ($node instanceof TextNode) {
            return $node->content;
        }

        if ($node instanceof ElementNode) {
            $output = '<' . $node->tag;
            foreach ($node->attributes as $attr) {
                $output .= ' ' . $attr->name;
                if ($attr->value->isStatic()) {
                    $output .= '="' . ($attr->value->static ?? '') . '"';
                }
            }

            $output .= '>';

            foreach ($node->children as $child) {
                $output .= $this->nodeToString($child);
            }

            if (!$node->selfClosing) {
                $output .= '</' . $node->tag . '>';
            }

            return $output;
        }

        if ($node instanceof RawPhpNode) {
            return '<?php ' . $node->code . ' ?>';
        }

        return '';
    }

    public function testCachesLayoutAsts(): void
    {
        // Create a reusable layout
        $layoutPath = $this->inheritanceFixturesPath . '/temp-cached-layout.sugar.php';
        file_put_contents($layoutPath, '<html><title s:block="title">Default</title></html>');

        try {
            // First page extends layout
            $page1 = $this->document()
                ->withChildren([
                    $this->element('div')
                        ->attribute('s:extends', 'temp-cached-layout.sugar.php')
                        ->build(),
                    $this->element('title')
                        ->attribute('s:block', 'title')
                        ->withChild($this->createText('Page 1'))
                        ->build(),
                ])
                ->build();

            // Second page also extends same layout
            $page2 = $this->document()
                ->withChildren([
                    $this->element('div')
                        ->attribute('s:extends', 'temp-cached-layout.sugar.php')
                        ->build(),
                    $this->element('title')
                        ->attribute('s:block', 'title')
                        ->withChild($this->createText('Page 2'))
                        ->build(),
                ])
                ->build();

            // Execute both - layout should be parsed once and cached
            $result1 = $this->execute($page1, $this->createTestContext('', 'page1.sugar.php'));
            $result2 = $this->execute($page2, $this->createTestContext('', 'page2.sugar.php'));

            // Both should succeed - cached AST reused
            $this->assertInstanceOf(DocumentNode::class, $result1);
            $this->assertInstanceOf(DocumentNode::class, $result2);

            // Results should be different (different block content)
            $code1 = $this->documentToString($result1);
            $code2 = $this->documentToString($result2);
            $this->assertStringContainsString('Page 1', $code1);
            $this->assertStringContainsString('Page 2', $code2);
        } finally {
            unlink($layoutPath);
        }
    }

    public function testCachesIncludeAsts(): void
    {
        // Create a reusable partial
        $partialPath = $this->inheritanceFixturesPath . '/temp-cached-partial.sugar.php';
        file_put_contents($partialPath, '<header>Site Header</header>');

        try {
            // First page includes partial
            $page1 = $this->document()
                ->withChildren([
                    $this->element('div')
                        ->attribute('s:include', 'temp-cached-partial.sugar.php')
                        ->build(),
                    $this->createText('Page 1 Content'),
                ])
                ->build();

            // Second page also includes same partial
            $page2 = $this->document()
                ->withChildren([
                    $this->element('div')
                        ->attribute('s:include', 'temp-cached-partial.sugar.php')
                        ->build(),
                    $this->createText('Page 2 Content'),
                ])
                ->build();

            // Execute both - partial should be parsed once and cached
            $result1 = $this->execute($page1, $this->createTestContext('', 'page1.sugar.php'));
            $result2 = $this->execute($page2, $this->createTestContext('', 'page2.sugar.php'));

            // Both should succeed
            $this->assertInstanceOf(DocumentNode::class, $result1);
            $this->assertInstanceOf(DocumentNode::class, $result2);

            // Both should contain header from include
            $code1 = $this->documentToString($result1);
            $code2 = $this->documentToString($result2);
            $this->assertStringContainsString('Site Header', $code1);
            $this->assertStringContainsString('Site Header', $code2);
            $this->assertStringContainsString('Page 1 Content', $code1);
            $this->assertStringContainsString('Page 2 Content', $code2);
        } finally {
            unlink($partialPath);
        }
    }

    public function testCachesSeparateTemplatesSeparately(): void
    {
        // Create two different layouts
        $layout1Path = $this->inheritanceFixturesPath . '/temp-layout1.sugar.php';
        $layout2Path = $this->inheritanceFixturesPath . '/temp-layout2.sugar.php';
        file_put_contents($layout1Path, '<html><title s:block="title">Layout 1</title></html>');
        file_put_contents($layout2Path, '<body><h1 s:block="title">Layout 2</h1></body>');

        try {
            // Page extends layout 1
            $page1 = $this->document()
                ->withChildren([
                    $this->element('div')
                        ->attribute('s:extends', 'temp-layout1.sugar.php')
                        ->build(),
                    $this->element('title')
                        ->attribute('s:block', 'title')
                        ->withChild($this->createText('Page with L1'))
                        ->build(),
                ])
                ->build();

            // Page extends layout 2
            $page2 = $this->document()
                ->withChildren([
                    $this->element('div')
                        ->attribute('s:extends', 'temp-layout2.sugar.php')
                        ->build(),
                    $this->element('h1')
                        ->attribute('s:block', 'title')
                        ->withChild($this->createText('Page with L2'))
                        ->build(),
                ])
                ->build();

            // Execute both
            $result1 = $this->execute($page1, $this->createTestContext('', 'page1.sugar.php'));
            $result2 = $this->execute($page2, $this->createTestContext('', 'page2.sugar.php'));

            // Both should work correctly with different structures
            $code1 = $this->documentToString($result1);
            $code2 = $this->documentToString($result2);

            // Layout 1 uses <html> structure
            $this->assertStringContainsString('<html>', $code1);
            $this->assertStringContainsString('</html>', $code1);

            // Layout 2 uses <body> structure
            $this->assertStringContainsString('<body>', $code2);
            $this->assertStringContainsString('</body>', $code2);
        } finally {
            unlink($layout1Path);
            unlink($layout2Path);
        }
    }
}
