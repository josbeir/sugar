<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Engine;
use Sugar\Core\Loader\StringTemplateLoader;
use Sugar\Extension\Component\ComponentExtension;

/**
 * Integration test: StringTemplateLoader with full compilation pipeline
 */
final class StringTemplateLoaderIntegrationTest extends TestCase
{
    public function testRendersSimpleTemplateFromMemory(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: [
                'home' => '<h1><?= $title ?></h1><p><?= $content ?></p>',
            ],
        );

        $engine = $this->createEngineWithComponentExtension($loader);

        $result = $engine->render('home', [
            'title' => 'Welcome',
            'content' => 'Hello World',
        ]);

        $this->assertStringContainsString('<h1>Welcome</h1>', $result);
        $this->assertStringContainsString('<p>Hello World</p>', $result);
    }

    public function testRendersTemplateWithDirectives(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: [
                'users' => '<ul><li s:foreach="$users as $user"><?= $user ?></li></ul>',
            ],
        );

        $engine = $this->createEngineWithComponentExtension($loader);

        $result = $engine->render('users', [
            'users' => ['Alice', 'Bob', 'Charlie'],
        ]);

        $this->assertStringContainsString('<ul>', $result);
        $this->assertStringContainsString('<li>Alice</li>', $result);
        $this->assertStringContainsString('<li>Bob</li>', $result);
        $this->assertStringContainsString('<li>Charlie</li>', $result);
    }

    public function testRendersTemplateWithConditionals(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: [
                'conditional' => '<div s:if="$show">Visible</div><span><?php if (!($show ?? false)) { ?>Hidden<?php } ?></span>',
            ],
        );

        $engine = $this->createEngineWithComponentExtension($loader);

        $resultShown = $engine->render('conditional', ['show' => true]);
        $this->assertStringContainsString('Visible', $resultShown);
        $this->assertStringNotContainsString('Hidden', $resultShown);

        $resultHidden = $engine->render('conditional', ['show' => false]);
        $this->assertStringContainsString('<span>Hidden</span>', $resultHidden);
        $this->assertStringNotContainsString('Visible', $resultHidden);
    }

    public function testRendersComponentFromMemory(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: [
                'page' => '<s-button>Click Me</s-button>',
            ],
        );

        $engine = $this->createEngineWithComponentExtension($loader, [
            'button' => '<button class="btn"><?= $slot ?></button>',
        ]);

        $result = $engine->render('page');

        $this->assertStringContainsString('<button class="btn">Click Me</button>', $result);
    }

    public function testRendersComponentWithAttributes(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: [
                'alert-page' => '<s-alert s:bind="[\'title\' => \'Important\']">Message</s-alert>',
            ],
        );

        $engine = $this->createEngineWithComponentExtension($loader, [
            'alert' => '<div class="alert">'
                . '<strong><?= $title ?? \'Notice\' ?></strong>'
                . '<p><?= $slot ?></p>'
                . '</div>',
        ]);

        $result = $engine->render('alert-page');

        $this->assertStringContainsString('class="alert"', $result);
        $this->assertStringContainsString('<strong>Important</strong>', $result);
        $this->assertStringContainsString('<p>Message</p>', $result);
    }

    public function testRendersComponentWithNamedSlots(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: [
                'card-page' => '<s-card>' .
                    '<h2 s:slot="header">Card Title</h2>' .
                    '<p>Body content</p>' .
                    '<small s:slot="footer">Footer</small>' .
                    '</s-card>',
            ],
        );

        $engine = $this->createEngineWithComponentExtension($loader, [
            'card' => '<div class="card">'
                . '<div class="card-header"><?= $header ?></div>'
                . '<div class="card-body"><?= $slot ?></div>'
                . '<div class="card-footer"><?= $footer ?></div>'
                . '</div>',
        ]);

        $result = $engine->render('card-page');

        $this->assertStringContainsString('<div class="card-header"><h2>Card Title</h2></div>', $result);
        $this->assertStringContainsString('<div class="card-body"><p>Body content</p></div>', $result);
        $this->assertStringContainsString('<div class="card-footer"><small>Footer</small></div>', $result);
    }

    public function testDynamicallyAddTemplateAndComponent(): void
    {
        $loader = new StringTemplateLoader(config: new SugarConfig());

        // Add templates dynamically
        $loader->addTemplate('dynamic', '<h1>Dynamic <?= $name ?></h1>');
        $loader->addTemplate('components/s-badge.sugar.php', '<span class="badge"><?= $slot ?></span>');

        $engine = Engine::builder(new SugarConfig())
            ->withTemplateLoader($loader)
            ->withExtension(new ComponentExtension())
            ->build();

        $result = $engine->render('dynamic', ['name' => 'Template']);
        $this->assertStringContainsString('<h1>Dynamic Template</h1>', $result);
    }

    public function testContextAwareEscaping(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: [
                'escaping' => '<div><?= $html ?></div><script>var x = <?= $json ?>;</script>',
            ],
        );

        $engine = Engine::builder(new SugarConfig())
            ->withTemplateLoader($loader)
            ->build();

        $result = $engine->render('escaping', [
            'html' => '<script>alert("XSS")</script>',
            'json' => ['key' => 'value'],
        ]);

        // HTML context should escape
        $this->assertStringContainsString('&lt;script&gt;', $result);
        $this->assertStringNotContainsString('<script>alert("XSS")</script>', $result);

        // JavaScript context should use JSON encoding
        $this->assertStringContainsString('var x = {"key":"value"};', $result);
    }

    public function testSupportsExtensionFallback(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: [
                'exact' => '<div>Exact</div>',
                'sugar.sugar.php' => '<div>Sugar</div>',
                'plain.php' => '<div>Plain</div>',
            ],
        );

        $engine = Engine::builder(new SugarConfig())
            ->withTemplateLoader($loader)
            ->build();

        // Should find exact match
        $this->assertStringContainsString('<div>Exact</div>', $engine->render('exact'));

        // Should find with .sugar.php extension
        $this->assertStringContainsString('<div>Sugar</div>', $engine->render('sugar'));

        // Should find with .php extension
        $this->assertStringContainsString('<div>Plain</div>', $engine->render('plain'));
    }

    public function testSupportsCustomSuffix(): void
    {
        $config = (new SugarConfig())->withFileSuffix('.sugar.tpl');
        $loader = new StringTemplateLoader(
            config: $config,
            templates: [
                'custom.sugar.tpl' => '<div>Custom</div>',
            ],
        );

        $engine = Engine::builder($config)
            ->withTemplateLoader($loader)
            ->build();

        $this->assertStringContainsString('<div>Custom</div>', $engine->render('custom'));
    }

    public function testHandlesTemplateInheritance(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: [
                'layouts/base' => '<html><body><div s:block="content">Default</div></body></html>',
                'pages/home' => '<div s:extends="../layouts/base">' .
                    '<s-template s:block="content">Home Content</s-template>' .
                    '</div>',
            ],
        );

        $engine = Engine::builder(new SugarConfig())
            ->withTemplateLoader($loader)
            ->build();

        $result = $engine->render('pages/home');

        $this->assertStringContainsString('<html><body><div>Home Content</div></body></html>', $result);
        $this->assertStringNotContainsString('Default', $result);
    }

    public function testAbsoluteOnlyResolutionUsesTemplateRoot(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: [
                'layouts/base' => '<html><body><div s:block="content">Default</div></body></html>',
                'pages/home' => '<div s:extends="layouts/base">' .
                    '<s-template s:block="content">Home Content</s-template>' .
                    '</div>',
            ],
            absolutePathsOnly: true,
        );

        $engine = Engine::builder(new SugarConfig())
            ->withTemplateLoader($loader)
            ->build();

        $result = $engine->render('pages/home');

        $this->assertStringContainsString('<html><body><div>Home Content</div></body></html>', $result);
        $this->assertStringNotContainsString('Default', $result);
    }

    public function testHandlesTemplateIncludes(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: [
                'partials/header' => '<header><h1><?= $title ?></h1></header>',
                'partials/footer' => '<footer><p><?= $year ?></p></footer>',
                'includes-page' => '<div s:include="partials/header"></div>' .
                    '<main>Content</main>' .
                    '<div s:include="partials/footer" s:with="[\'year\' => 2024]"></div>',
            ],
        );

        $engine = Engine::builder(new SugarConfig())
            ->withTemplateLoader($loader)
            ->build();

        $result = $engine->render('includes-page', ['title' => 'Welcome']);

        $this->assertStringContainsString('<header><h1>Welcome</h1></header>', $result);
        $this->assertStringContainsString('<main>Content</main>', $result);
        $this->assertStringContainsString('<footer><p>2024</p></footer>', $result);
    }

    public function testIncludesWithRelativePaths(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: [
                'shared/nav' => '<nav><?= $links ?></nav>',
                'pages/home-with-nav' => '<div s:include="../shared/nav" s:with="[\'links\' => \'Home | About\']"></div>',
            ],
        );

        $engine = Engine::builder(new SugarConfig())
            ->withTemplateLoader($loader)
            ->build();

        $result = $engine->render('pages/home-with-nav');

        $this->assertStringContainsString('<nav>Home | About</nav>', $result);
    }

    public function testCachesCompiledTemplates(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: [
                'cached' => '<div><?= $value ?></div>',
            ],
        );

        $engine = Engine::builder(new SugarConfig())
            ->withTemplateLoader($loader)
            ->build();

        // First render - compiles
        $result1 = $engine->render('cached', ['value' => 'First']);
        $this->assertStringContainsString('<div>First</div>', $result1);

        // Second render - should use cache
        $result2 = $engine->render('cached', ['value' => 'Second']);
        $this->assertStringContainsString('<div>Second</div>', $result2);

        // Both should have same structure (compilation is cached)
        $this->assertSame(
            str_replace('First', 'X', $result1),
            str_replace('Second', 'X', $result2),
        );
    }

    public function testUseCaseForTesting(): void
    {
        // Real-world use case: testing custom components without filesystem
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: [
                'test-template' => '<s-custom-component s:bind="[\'data\' => $testData]">Test</s-custom-component>',
            ],
        );

        $engine = $this->createEngineWithComponentExtension($loader, [
            'custom-component' => '<div class="test"><?= json_encode($data) ?>: <?= $slot ?></div>',
        ]);

        $result = $engine->render('test-template', [
            'testData' => ['test' => 'value'],
        ]);

        $this->assertStringContainsString('class="test"', $result);
        // JSON is HTML-escaped in HTML context
        $this->assertStringContainsString('test', $result);
        $this->assertStringContainsString('value', $result);
        $this->assertStringContainsString('Test', $result);
    }

    public function testUseCaseForDatabaseTemplates(): void
    {
        // Real-world use case: templates stored in database
        // Simulating database fetch
        $dbTemplates = [
            'email/welcome' => '<h1>Welcome <?= $name ?>!</h1><p><?= $message ?></p>',
            'email/notification' => '<div>Notification: <?= $text ?></div>',
        ];

        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: $dbTemplates,
        );

        $engine = Engine::builder(new SugarConfig())
            ->withTemplateLoader($loader)
            ->build();

        $welcomeEmail = $engine->render('email/welcome', [
            'name' => 'John Doe',
            'message' => 'Thank you for joining!',
        ]);

        $this->assertStringContainsString('<h1>Welcome John Doe!</h1>', $welcomeEmail);
        $this->assertStringContainsString('<p>Thank you for joining!</p>', $welcomeEmail);
    }

    /**
     * Build an engine configured with the component extension and component loader.
     *
     * @param array<string, string> $components
     */
    private function createEngineWithComponentExtension(StringTemplateLoader $loader, array $components = []): Engine
    {
        foreach ($components as $name => $source) {
            $loader->addTemplate('components/s-' . $name . '.sugar.php', $source);
        }

        return Engine::builder(new SugarConfig())
            ->withTemplateLoader($loader)
            ->withExtension(new ComponentExtension())
            ->build();
    }
}
