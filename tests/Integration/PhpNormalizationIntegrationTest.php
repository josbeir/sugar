<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Cache\FileCache;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Engine;
use Sugar\Core\Loader\StringTemplateLoader;
use Sugar\Extension\Component\ComponentExtension;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\EngineTestTrait;
use Sugar\Tests\Helper\Trait\ExecuteTemplateTrait;

/**
 * Integration tests for PHP normalization and import hoisting.
 *
 * Verifies that `use` import statements are hoisted above the render closure
 * in compiled output, and that templates render correctly with those imports.
 */
final class PhpNormalizationIntegrationTest extends TestCase
{
    use CompilerTestTrait;
    use EngineTestTrait;
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
        $childSource = '<s-template s:extends="base.sugar.php"></s-template>'
            . '<s-template s:block="content">'
            . '<?php use DateTimeImmutable as Clock; $year = (new Clock("2024-01-01"))->format("Y"); ?>'
            . '<p><?= $year ?></p>'
            . '</s-template>';

        // Compile-time: verify import is hoisted above render closure
        $this->setUpCompilerWithStringLoader(
            templates: [
                'base.sugar.php' => '<main s:block="content">Base</main>',
                'child.sugar.php' => $childSource,
            ],
            config: new SugarConfig(),
        );

        $compiled = $this->compiler->compile($childSource, 'child.sugar.php');
        $renderClosurePosition = strpos($compiled, 'return function(array|object $__data = []): string {');
        $importPosition = strpos($compiled, 'use DateTimeImmutable as Clock;');

        $this->assertNotFalse($renderClosurePosition);
        $this->assertNotFalse($importPosition);
        $this->assertLessThan($renderClosurePosition, $importPosition);

        // Runtime: verify rendered output uses the import correctly
        $engine = $this->createStringEngine([
            'base.sugar.php' => '<main s:block="content">Base</main>',
            'child.sugar.php' => $childSource,
        ]);

        $output = $engine->render('child.sugar.php');
        $this->assertStringContainsString('<p>2024</p>', $output);
    }

    public function testComponentTemplateImportsWorkAtRuntime(): void
    {
        // Component templates are compiled at runtime, so imports stay in the
        // component's own compiled template (not hoisted to parent).
        $templates = [
            'page.sugar.php' => '<s-card>Hi</s-card>',
            'components/s-card.sugar.php' => '<?php use DateTimeImmutable as Clock; '
                . '$year = (new Clock("2024-01-01"))->format("Y"); ?>'
                . '<section><span><?= $year ?></span><div><?= $slot ?></div></section>',
        ];

        $loader = new StringTemplateLoader(templates: $templates);
        $engine = Engine::builder(new SugarConfig())
            ->withTemplateLoader($loader)
            ->withCache(new FileCache(
                sys_get_temp_dir() . '/sugar_test_' . bin2hex(random_bytes(8)),
            ))
            ->withExtension(new ComponentExtension())
            ->build();

        $output = $engine->render('page.sugar.php');
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
        $childSource = '<?php use DateTimeImmutable as Clock; ?>'
            . '<s-template s:extends="base.sugar.php"></s-template>'
            . '<s-template s:block="content">'
            . '<p><?= (new Clock("2024-01-01"))->format("Y") ?></p>'
            . '</s-template>';

        // Compile-time: verify import appears exactly once in child's compiled output
        $this->setUpCompilerWithStringLoader(
            templates: [
                'base.sugar.php' => '<main s:block="content">Base</main>',
                'child.sugar.php' => $childSource,
            ],
            config: new SugarConfig(),
        );

        $compiled = $this->compiler->compile($childSource, 'child.sugar.php');
        $this->assertSame(1, substr_count($compiled, 'use DateTimeImmutable as Clock;'));

        // Runtime: verify rendered output uses the import correctly
        $engine = $this->createStringEngine([
            'base.sugar.php' => '<main s:block="content">Base</main>',
            'child.sugar.php' => $childSource,
        ]);

        $output = $engine->render('child.sugar.php');
        $this->assertStringContainsString('<p>2024</p>', $output);
    }

    /**
     * Verify that imports from an ancestor template are available at runtime.
     *
     * With runtime inheritance, ancestor templates are compiled separately.
     * The import from the parent template is not merged into the child's
     * compiled output; instead, it takes effect when the parent renders.
     */
    public function testKeepsTopLevelImportFromAncestorTemplateInExtendsChain(): void
    {
        $engine = $this->createStringEngine([
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
        ]);

        $output = $engine->render('child.sugar.php');
        $this->assertStringContainsString('<p>2024</p>', $output);
        $this->assertStringContainsString('<p>Child</p>', $output);
    }

    public function testDeduplicatesGroupedAndSingleFunctionImportsAcrossTemplates(): void
    {
        $childSource = '<?php use function Sugar\\Core\\Runtime\\{raw, json}; ?>'
            . '<s-template s:extends="layout/default.sugar.php"></s-template>'
            . '<s-template s:block="content">'
            . '<?= ["a", "b"] |> json() ?>'
            . '</s-template>';

        // Compile-time: verify child's own grouped imports are deduplicated
        $this->setUpCompilerWithStringLoader(
            templates: [
                'layout/default.sugar.php' => '<?php use function Sugar\\Core\\Runtime\\json; ?>'
                    . '<main s:block="content"></main>',
                'page.sugar.php' => $childSource,
            ],
            config: new SugarConfig(),
        );

        $compiled = $this->compiler->compile($childSource, 'page.sugar.php');
        $this->assertSame(1, substr_count($compiled, 'use function Sugar\\Core\\Runtime\\json;'));
        $this->assertSame(1, substr_count($compiled, 'use function Sugar\\Core\\Runtime\\raw;'));

        // Runtime: verify rendered output
        $engine = $this->createStringEngine([
            'layout/default.sugar.php' => '<?php use function Sugar\\Core\\Runtime\\json; ?>'
                . '<main s:block="content"></main>',
            'page.sugar.php' => $childSource,
        ]);

        $output = $engine->render('page.sugar.php');
        $this->assertStringContainsString('["a","b"]', $output);
    }
}
