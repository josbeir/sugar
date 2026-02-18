<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Engine;
use Sugar\Core\Loader\StringTemplateLoader;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\ExecuteTemplateTrait;

final class PhpNormalizationIntegrationTest extends TestCase
{
    use CompilerTestTrait;
    use ExecuteTemplateTrait;

    public function testHoistsImportFromMixedRawPhpBlock(): void
    {
        $this->setUpCompilerWithStringLoader(
            templates: [],
            config: new SugarConfig(),
        );

        $template = <<<'SUGAR'
<?php
use DateTimeImmutable as Clock;

$defineAVar = (new Clock('2024-01-01'))->format('Y');
?>
<div><?= $defineAVar ?></div>
SUGAR;

        $compiled = $this->compiler->compile($template, 'page.sugar.php');
        $renderClosurePosition = strpos($compiled, 'return function(array|object $__data = []): string {');
        $importPosition = strpos($compiled, 'use DateTimeImmutable as Clock;');

        $this->assertNotFalse($renderClosurePosition);
        $this->assertNotFalse($importPosition);
        $this->assertLessThan($renderClosurePosition, $importPosition);

        $output = $this->executeTemplate($compiled);
        $this->assertStringContainsString('<div>2024</div>', $output);
    }

    public function testHoistsImportsWhenUsingTemplateInheritance(): void
    {
        $this->setUpCompilerWithStringLoader(
            templates: [
                'base.sugar.php' => '<main s:block="content">Base</main>',
                'child.sugar.php' => '<s-template s:extends="base.sugar.php"></s-template>'
                    . '<s-template s:block="content">'
                    . '<?php use DateTimeImmutable as Clock; $year = (new Clock("2024-01-01"))->format("Y"); ?>'
                    . '<p><?= $year ?></p>'
                    . '</s-template>',
            ],
            config: new SugarConfig(),
        );

        $compiled = $this->compiler->compile(
            '<s-template s:extends="base.sugar.php"></s-template>'
            . '<s-template s:block="content">'
            . '<?php use DateTimeImmutable as Clock; $year = (new Clock("2024-01-01"))->format("Y"); ?>'
            . '<p><?= $year ?></p>'
            . '</s-template>',
            'child.sugar.php',
        );

        $renderClosurePosition = strpos($compiled, 'return function(array|object $__data = []): string {');
        $importPosition = strpos($compiled, 'use DateTimeImmutable as Clock;');

        $this->assertNotFalse($renderClosurePosition);
        $this->assertNotFalse($importPosition);
        $this->assertLessThan($renderClosurePosition, $importPosition);

        $output = $this->executeTemplate($compiled);
        $this->assertStringContainsString('<p>2024</p>', $output);
    }

    public function testHoistsImportsFromComponentTemplate(): void
    {
        $this->setUpCompilerWithStringLoader(
            templates: [
                'page.sugar.php' => '<s-card>Hi</s-card>',
                'components/s-card.sugar.php' => '<?php use DateTimeImmutable as Clock; '
                    . '$year = (new Clock("2024-01-01"))->format("Y"); ?>'
                    . '<section><span><?= $year ?></span><div><?= $slot ?></div></section>',
            ],
            config: new SugarConfig(),
        );

        $compiled = $this->compiler->compile('<s-card>Hi</s-card>', 'page.sugar.php');
        $renderClosurePosition = strpos($compiled, 'return function(array|object $__data = []): string {');
        $importPosition = strpos($compiled, 'use DateTimeImmutable as Clock;');

        $this->assertNotFalse($renderClosurePosition);
        $this->assertNotFalse($importPosition);
        $this->assertLessThan($renderClosurePosition, $importPosition);

        $output = $this->executeTemplate($compiled);
        $this->assertStringContainsString('<span>2024</span>', $output);
        $this->assertStringContainsString('<div>Hi</div>', $output);
    }

    public function testRenderBlocksKeepsTopLevelImportsFromTemplate(): void
    {
        $loader = new StringTemplateLoader([
            'page.sugar.php' => '<?php use DateTimeImmutable as Clock; ?>'
                . '<main s:block="content"><p><?= (new Clock("2024-01-01"))->format("Y") ?></p></main>'
                . '<aside s:block="meta"><p>Meta</p></aside>',
        ]);

        $engine = Engine::builder(new SugarConfig())
            ->withTemplateLoader($loader)
            ->build();

        $output = $engine->render('page.sugar.php', blocks: ['content']);

        $this->assertStringContainsString('<p>2024</p>', $output);
        $this->assertStringNotContainsString('Meta', $output);
    }

    public function testEmitsSingleImportWhenSameImportAppearsMultipleTimes(): void
    {
        $this->setUpCompilerWithStringLoader(
            templates: [],
            config: new SugarConfig(),
        );

        $template = <<<'SUGAR'
<?php use DateTimeImmutable as Clock; ?>
<?php use DateTimeImmutable as Clock; ?>
<div><?= (new Clock('2024-01-01'))->format('Y') ?></div>
SUGAR;

        $compiled = $this->compiler->compile($template, 'page.sugar.php');

        $this->assertSame(1, substr_count($compiled, 'use DateTimeImmutable as Clock;'));

        $output = $this->executeTemplate($compiled);
        $this->assertStringContainsString('<div>2024</div>', $output);
    }

    public function testKeepsTopLevelImportFromChildTemplateUsingExtends(): void
    {
        $this->setUpCompilerWithStringLoader(
            templates: [
                'base.sugar.php' => '<main s:block="content">Base</main>',
                'child.sugar.php' => '<?php use DateTimeImmutable as Clock; ?>'
                    . '<s-template s:extends="base.sugar.php"></s-template>'
                    . '<s-template s:block="content">'
                    . '<p><?= (new Clock("2024-01-01"))->format("Y") ?></p>'
                    . '</s-template>',
            ],
            config: new SugarConfig(),
        );

        $compiled = $this->compiler->compile(
            '<?php use DateTimeImmutable as Clock; ?>'
            . '<s-template s:extends="base.sugar.php"></s-template>'
            . '<s-template s:block="content">'
            . '<p><?= (new Clock("2024-01-01"))->format("Y") ?></p>'
            . '</s-template>',
            'child.sugar.php',
        );

        $this->assertSame(1, substr_count($compiled, 'use DateTimeImmutable as Clock;'));

        $output = $this->executeTemplate($compiled);
        $this->assertStringContainsString('<p>2024</p>', $output);
    }

    public function testKeepsTopLevelImportFromAncestorTemplateInExtendsChain(): void
    {
        $this->setUpCompilerWithStringLoader(
            templates: [
                'grand.sugar.php' => '<main s:block="content">Grand</main>',
                'parent.sugar.php' => '<?php use DateTimeImmutable as Clock; ?>'
                    . '<s-template s:extends="grand.sugar.php"></s-template>'
                    . '<s-template s:block="content">'
                    . '<s-template s:parent />'
                    . '<p><?= (new Clock("2024-01-01"))->format("Y") ?></p>'
                    . '</s-template>',
                'child.sugar.php' => '<s-template s:extends="parent.sugar.php"></s-template>'
                    . '<s-template s:block="content">'
                    . '<s-template s:parent />'
                    . '<p>Child</p>'
                    . '</s-template>',
            ],
            config: new SugarConfig(),
        );

        $compiled = $this->compiler->compile(
            '<s-template s:extends="parent.sugar.php"></s-template>'
            . '<s-template s:block="content">'
            . '<s-template s:parent />'
            . '<p>Child</p>'
            . '</s-template>',
            'child.sugar.php',
        );

        $this->assertSame(1, substr_count($compiled, 'use DateTimeImmutable as Clock;'));

        $output = $this->executeTemplate($compiled);
        $this->assertStringContainsString('<p>2024</p>', $output);
        $this->assertStringContainsString('<p>Child</p>', $output);
    }

    public function testDeduplicatesGroupedAndSingleFunctionImportsAcrossTemplates(): void
    {
        $this->setUpCompilerWithStringLoader(
            templates: [
                'layout/default.sugar.php' => '<?php use function Sugar\\Core\\Runtime\\json; ?>'
                    . '<main s:block="content"></main>',
                'page.sugar.php' => '<?php use function Sugar\\Core\\Runtime\\{raw, json}; ?>'
                    . '<s-template s:extends="layout/default.sugar.php"></s-template>'
                    . '<s-template s:block="content">'
                    . '<?= ["a", "b"] |> json() ?>'
                    . '</s-template>',
            ],
            config: new SugarConfig(),
        );

        $compiled = $this->compiler->compile(
            '<?php use function Sugar\\Core\\Runtime\\{raw, json}; ?>'
            . '<s-template s:extends="layout/default.sugar.php"></s-template>'
            . '<s-template s:block="content">'
            . '<?= ["a", "b"] |> json() ?>'
            . '</s-template>',
            'page.sugar.php',
        );

        $this->assertSame(1, substr_count($compiled, 'use function Sugar\\Core\\Runtime\\json;'));
        $this->assertSame(1, substr_count($compiled, 'use function Sugar\\Core\\Runtime\\raw;'));

        $output = $this->executeTemplate($compiled);
        $this->assertStringContainsString('["a","b"]', $output);
    }
}
