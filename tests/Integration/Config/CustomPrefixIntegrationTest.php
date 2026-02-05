<?php
declare(strict_types=1);

namespace Sugar\Test\Integration\Config;

use PHPUnit\Framework\TestCase;
use Sugar\Compiler;
use Sugar\Config\SugarConfig;
use Sugar\Escape\Escaper;
use Sugar\Parser\Parser;
use Sugar\Pass\ContextAnalysisPass;
use Sugar\TemplateInheritance\FileTemplateLoader;
use Sugar\Tests\ExecuteTemplateTrait;

final class CustomPrefixIntegrationTest extends TestCase
{
    use ExecuteTemplateTrait;

    public function testCustomPrefixIfDirective(): void
    {
        $config = SugarConfig::withPrefix('x');
        $compiler = $this->createCompiler($config);

        $template = '<div x:if="$show">Hello</div>';
        $compiled = $compiler->compile($template);

        $result = $this->executeTemplate($compiled, ['show' => true]);
        $this->assertStringContainsString('<div>Hello</div>', $result);

        $result = $this->executeTemplate($compiled, ['show' => false]);
        $this->assertStringNotContainsString('Hello', $result);
    }

    public function testCustomPrefixForeachDirective(): void
    {
        $config = SugarConfig::withPrefix('v');
        $compiler = $this->createCompiler($config);

        $template = '<li v:foreach="$items as $item"><?= $item ?></li>';
        $compiled = $compiler->compile($template);

        $result = $this->executeTemplate($compiled, ['items' => ['a', 'b', 'c']]);
        $this->assertStringContainsString('<li>a</li>', $result);
        $this->assertStringContainsString('<li>b</li>', $result);
        $this->assertStringContainsString('<li>c</li>', $result);
    }

    public function testCustomFragmentElement(): void
    {
        $config = SugarConfig::withPrefix('tw');
        $compiler = $this->createCompiler($config);

        $template = '<tw-template tw:foreach="$items as $item"><li><?= $item ?></li></tw-template>';
        $compiled = $compiler->compile($template);

        $result = $this->executeTemplate($compiled, ['items' => [1, 2, 3]]);

        // Should render children without wrapper
        $this->assertStringContainsString('<li>1</li>', $result);
        $this->assertStringContainsString('<li>2</li>', $result);
        $this->assertStringNotContainsString('tw-fragment', $result);
    }

    public function testOldPrefixIgnoredWithCustomConfig(): void
    {
        $config = SugarConfig::withPrefix('x');
        $compiler = $this->createCompiler($config);

        // Old s:if should be treated as regular attribute
        $template = '<div s:if="$show">Hello</div>';
        $compiled = $compiler->compile($template);

        $result = $this->executeTemplate($compiled, ['show' => true]);

        // Should render with s:if as regular attribute
        $this->assertStringContainsString('s:if', $result);
    }

    public function testMixedDirectivesWithCustomPrefix(): void
    {
        $config = SugarConfig::withPrefix('x');
        $compiler = $this->createCompiler($config);

        $template = '<div x:if="$show" x:text="$message"></div>';
        $compiled = $compiler->compile($template);

        $result = $this->executeTemplate($compiled, [
            'show' => true,
            'message' => '<script>alert("xss")</script>',
        ]);

        $this->assertStringContainsString('<div>', $result);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    public function testCustomPrefixWithTemplateInheritance(): void
    {
        $config = SugarConfig::withPrefix('v');
        $tmpDir = sys_get_temp_dir() . '/sugar-test-' . uniqid();
        mkdir($tmpDir);

        try {
            // Create parent template with v:block
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
            file_put_contents($tmpDir . '/layout.sugar.php', $parent);

            // Create child template with v:extends and v:block
            $child = <<<'TEMPLATE'
<v-template v:extends="layout.sugar.php">
    <v-template v:block="title">Custom Title</v-template>
    <v-template v:block="content">
        <div v:if="$show">Hello <?= $name ?></div>
    </v-template>
</v-template>
TEMPLATE;

            $loader = new FileTemplateLoader((new SugarConfig())->withTemplatePaths($tmpDir));
            $compiler = new Compiler(
                parser: new Parser($config),
                contextPass: new ContextAnalysisPass(),
                escaper: new Escaper(),
                templateLoader: $loader,
                config: $config,
            );

            $compiled = $compiler->compile($child, 'child.sugar.php');

            // Verify inheritance worked
            $result = $this->executeTemplate($compiled, ['show' => true, 'name' => 'World']);
            $this->assertStringContainsString('Custom Title', $result);
            $this->assertStringContainsString('Hello World', $result);
            $this->assertStringNotContainsString('Default content', $result);

            // Verify v:if directive works
            $result = $this->executeTemplate($compiled, ['show' => false, 'name' => 'World']);
            $this->assertStringNotContainsString('Hello', $result);
        } finally {
            // Cleanup
            if (file_exists($tmpDir . '/layout.sugar.php')) {
                unlink($tmpDir . '/layout.sugar.php');
            }

            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
        }
    }

    public function testCustomPrefixWithInclude(): void
    {
        $config = SugarConfig::withPrefix('x');
        $tmpDir = sys_get_temp_dir() . '/sugar-test-' . uniqid();
        mkdir($tmpDir);

        try {
            // Create partial template
            $partial = '<p>User: <?= $username ?></p>';
            file_put_contents($tmpDir . '/user.sugar.php', $partial);

            // Create main template with x:include
            $template = <<<'TEMPLATE'
<div>
    <h1>Users</h1>
    <x-template x:foreach="$users as $user">
        <x-template x:include="user.sugar.php" x:with="['username' => $user]"></x-template>
    </x-template>
</div>
TEMPLATE;

            $loader = new FileTemplateLoader((new SugarConfig())->withTemplatePaths($tmpDir));
            $compiler = new Compiler(
                parser: new Parser($config),
                contextPass: new ContextAnalysisPass(),
                escaper: new Escaper(),
                templateLoader: $loader,
                config: $config,
            );

            $compiled = $compiler->compile($template, 'main.sugar.php');
            $result = $this->executeTemplate($compiled, ['users' => ['Alice', 'Bob', 'Charlie']]);

            $this->assertStringContainsString('User: Alice', $result);
            $this->assertStringContainsString('User: Bob', $result);
            $this->assertStringContainsString('User: Charlie', $result);
        } finally {
            // Cleanup
            if (file_exists($tmpDir . '/user.sugar.php')) {
                unlink($tmpDir . '/user.sugar.php');
            }

            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
        }
    }

    private function createCompiler(SugarConfig $config): Compiler
    {
        return new Compiler(
            parser: new Parser($config),
            contextPass: new ContextAnalysisPass(),
            escaper: new Escaper(),
            config: $config,
        );
    }
}
