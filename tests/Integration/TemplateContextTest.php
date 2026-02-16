<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Engine;
use Sugar\Core\Loader\FileTemplateLoader;
use Sugar\Extension\Component\ComponentExtension;
use Sugar\Tests\Helper\Trait\EngineTestTrait;

/**
 * Test template context binding ($this support)
 */
final class TemplateContextTest extends TestCase
{
    use EngineTestTrait;

    private string $templatesPath;

    protected function setUp(): void
    {
        $this->templatesPath = SUGAR_TEST_TEMPLATES_PATH;
    }

    public function testTemplateCanAccessContextViaDollarThis(): void
    {
        // Mock view class (like CakePHP's View)
        $viewContext = new class {
            public function helperMethod(): string
            {
                return 'Helper Output';
            }

            public function formatDate(string $date): string
            {
                return 'Formatted: ' . $date;
            }
        };

        $engine = $this->createEngine($this->templatesPath, $viewContext);

        $output = $engine->render('context-test.sugar.php');

        $this->assertSame('Helper Output', $output);
    }

    public function testTemplateContextIsOptional(): void
    {
        $engine = $this->createEngine($this->templatesPath);

        $output = $engine->render('simple-output.sugar.php', ['name' => 'Alice']);

        $this->assertStringContainsString('Hello Alice!', $output);
    }

    public function testTemplateCanAccessBothContextAndData(): void
    {
        $viewContext = new class {
            public string $siteTitle = 'My Site';

            public function url(string $path): string
            {
                return 'https://example.com' . $path;
            }
        };

        $engine = $this->createEngine($this->templatesPath, $viewContext);

        $output = $engine->render('context-mixed.sugar.php', ['linkText' => 'About Us']);

        $this->assertStringContainsString('<h1>My Site</h1>', $output);
        $this->assertStringContainsString('About Us</a>', $output);
    }

    public function testComponentsInheritTemplateContext(): void
    {
        // Mock view class with method that components should access
        $viewContext = new class {
            public function getMessage(): string
            {
                return 'Context inherited by component!';
            }
        };

        $engine = Engine::builder()
            ->withTemplateLoader(
                new FileTemplateLoader(
                    new SugarConfig(),
                    [$this->templatesPath],
                ),
            )
            ->withTemplateContext($viewContext)
            ->withExtension(new ComponentExtension())
            ->build();

        $output = $engine->render('test-component-context.sugar.php');

        // Component should be able to call $this->getMessage()
        $this->assertStringContainsString('Context inherited by component!', $output);
        $this->assertStringContainsString('<h2>Welcome</h2>', $output);
    }

    public function testConsecutiveEmptyFetchOutputsFollowNativePhpCloseTagBehavior(): void
    {
        $viewContext = new class {
            public object $Html;

            public function __construct()
            {
                $this->Html = new class {
                    public function charset(): string
                    {
                        return '<meta charset="utf-8">';
                    }

                    /**
                     * @param array<int, string> $files
                     */
                    public function css(array $files): string
                    {
                        return '<link rel="stylesheet" href="/app.css">';
                    }
                };
            }

            public function fetch(string $name): string
            {
                return '';
            }
        };

        $engine = $this->createStringEngine(
            templates: [
                'layout/default.sugar.php' => <<<'SUGAR'
<head>
    <?= $this->Html->charset() |> raw() ?>
    <?= $this->Html->css(['app']) |> raw() ?>
    <?= $this->fetch('meta') ?>
    <?= $this->fetch('css') ?>
    <?= $this->fetch('script') ?>
</head>
<body>
    <main s:block="content">Default</main>
</body>
SUGAR,
                'Pages/home.sugar.php' => <<<'SUGAR'
<s-template s:extends="/layout/default" />
<s-template s:block="content">
    <p>Home</p>
</s-template>
SUGAR,
            ],
            context: $viewContext,
        );

        $output = $engine->render('Pages/home.sugar.php');
        $normalizedOutput = str_replace("\r", '', $output);

        $expected = implode("\n", [
            '<head>',
            '    <meta charset="utf-8">    <link rel="stylesheet" href="/app.css">' . str_repeat(' ', 12) . '</head>',
            '<body>',
            '    <main>',
            '    <p>Home</p>',
            '</main>',
            '</body>',
        ]);

        $this->assertSame($expected, $normalizedOutput);
    }
}
