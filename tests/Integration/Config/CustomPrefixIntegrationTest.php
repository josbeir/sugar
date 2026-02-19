<?php
declare(strict_types=1);

namespace Sugar\Test\Integration\Config;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Engine;
use Sugar\Core\Loader\StringTemplateLoader;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\ExecuteTemplateTrait;

final class CustomPrefixIntegrationTest extends TestCase
{
    use ExecuteTemplateTrait;
    use CompilerTestTrait;

    public function testCustomPrefixIfDirective(): void
    {
        $config = SugarConfig::withPrefix('x');
        $this->setUpCompiler(config: $config);

        $template = '<div x:if="$show">Hello</div>';
        $compiled = $this->compiler->compile($template);

        $result = $this->executeTemplate($compiled, ['show' => true]);
        $this->assertStringContainsString('<div>Hello</div>', $result);

        $result = $this->executeTemplate($compiled, ['show' => false]);
        $this->assertStringNotContainsString('Hello', $result);
    }

    public function testCustomPrefixForeachDirective(): void
    {
        $config = SugarConfig::withPrefix('v');
        $this->setUpCompiler(config: $config);

        $template = '<li v:foreach="$items as $item"><?= $item ?></li>';
        $compiled = $this->compiler->compile($template);

        $result = $this->executeTemplate($compiled, ['items' => ['a', 'b', 'c']]);
        $this->assertStringContainsString('<li>a</li>', $result);
        $this->assertStringContainsString('<li>b</li>', $result);
        $this->assertStringContainsString('<li>c</li>', $result);
    }

    public function testCustomFragmentElement(): void
    {
        $config = SugarConfig::withPrefix('tw');
        $this->setUpCompiler(config: $config);

        $template = '<tw-template tw:foreach="$items as $item"><li><?= $item ?></li></tw-template>';
        $compiled = $this->compiler->compile($template);

        $result = $this->executeTemplate($compiled, ['items' => [1, 2, 3]]);

        // Should render children without wrapper
        $this->assertStringContainsString('<li>1</li>', $result);
        $this->assertStringContainsString('<li>2</li>', $result);
        $this->assertStringNotContainsString('tw-fragment', $result);
    }

    public function testOldPrefixIgnoredWithCustomConfig(): void
    {
        $config = SugarConfig::withPrefix('x');
        $this->setUpCompiler(config: $config);

        // Old s:if should be treated as regular attribute
        $template = '<div s:if="$show">Hello</div>';
        $compiled = $this->compiler->compile($template);

        $result = $this->executeTemplate($compiled, ['show' => true]);

        // Should render with s:if as regular attribute
        $this->assertStringContainsString('s:if', $result);
    }

    public function testMixedDirectivesWithCustomPrefix(): void
    {
        $config = SugarConfig::withPrefix('x');
        $this->setUpCompiler(config: $config);

        $template = '<div x:if="$show" x:text="$message"></div>';
        $compiled = $this->compiler->compile($template);

        $result = $this->executeTemplate($compiled, [
            'show' => true,
            'message' => '<script>alert("xss")</script>',
        ]);

        $this->assertStringContainsString('<div>', $result);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    /**
     * Test that template inheritance works with a custom prefix (v:extends, v:block).
     */
    public function testCustomPrefixWithTemplateInheritance(): void
    {
        $config = SugarConfig::withPrefix('v');
        $parent = <<<'TEMPLATE'
<!DOCTYPE html>
<html>
<head>
    <title><v-template v:block="title">Default Title</v-template></title>
</head>
<body>
    <v-template v:block="content">Default content</v-template>
</body>
</html>
TEMPLATE;
        $child = <<<'TEMPLATE'
<v-template v:extends="layout.sugar.php">
    <v-template v:block="title">Custom Title</v-template>
    <v-template v:block="content">
        <div v:if="$show">Hello <?= $name ?></div>
    </v-template>
</v-template>
TEMPLATE;

        $loader = new StringTemplateLoader([
            'layout.sugar.php' => $parent,
            'child.sugar.php' => $child,
        ]);

        $engine = Engine::builder($config)
            ->withTemplateLoader($loader)
            ->build();

        $result = $engine->render('child.sugar.php', ['show' => true, 'name' => 'World']);
        $this->assertStringContainsString('Custom Title', $result);
        $this->assertStringContainsString('Hello World', $result);
        $this->assertStringNotContainsString('Default content', $result);

        $result = $engine->render('child.sugar.php', ['show' => false, 'name' => 'World']);
        $this->assertStringNotContainsString('Hello', $result);
    }

    /**
     * Test that includes work with a custom prefix (x:include, x:with).
     */
    public function testCustomPrefixWithInclude(): void
    {
        $config = SugarConfig::withPrefix('x');
        $partial = '<p>User: <?= $username ?></p>';
        $template = <<<'TEMPLATE'
<div>
    <h1>Users</h1>
    <x-template x:foreach="$users as $user">
        <x-template x:include="user.sugar.php" x:with="['username' => $user]"></x-template>
    </x-template>
</div>
TEMPLATE;

        $loader = new StringTemplateLoader([
            'user.sugar.php' => $partial,
            'main.sugar.php' => $template,
        ]);

        $engine = Engine::builder($config)
            ->withTemplateLoader($loader)
            ->build();

        $result = $engine->render('main.sugar.php', ['users' => ['Alice', 'Bob', 'Charlie']]);

        $this->assertStringContainsString('User: Alice', $result);
        $this->assertStringContainsString('User: Bob', $result);
        $this->assertStringContainsString('User: Charlie', $result);
    }
}
