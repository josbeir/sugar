<?php
declare(strict_types=1);

namespace Sugar\Tests;

use RuntimeException;
use Sugar\Config\SugarConfig;
use Sugar\Context\CompilationContext;
use Sugar\TemplateInheritance\FileTemplateLoader;

/**
 * Test helper trait for loading template fixtures
 *
 * Provides DRY methods for loading templates and expected outputs
 */
trait TemplateTestHelperTrait
{
    private string $fixturesPath = __DIR__ . '/fixtures/templates/';

    private string $expectedCompiledPath = __DIR__ . '/fixtures/expected/compiled/';

    private string $expectedRenderedPath = __DIR__ . '/fixtures/expected/rendered/';

    /**
     * Load a template fixture
     */
    protected function loadTemplate(string $name): string
    {
        $path = $this->fixturesPath . $name;

        if (!file_exists($path)) {
            throw new RuntimeException('Template fixture not found: ' . $path);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Failed to read template fixture: ' . $path);
        }

        return $content;
    }

    /**
     * Load expected compiled PHP code
     */
    protected function loadExpectedCompiled(string $name): string
    {
        $path = $this->expectedCompiledPath . $name;

        if (!file_exists($path)) {
            throw new RuntimeException('Expected compiled fixture not found: ' . $path);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Failed to read expected compiled fixture: ' . $path);
        }

        return $content;
    }

    /**
     * Load expected rendered output
     */
    protected function loadExpectedRendered(string $name): string
    {
        $path = $this->expectedRenderedPath . $name;

        if (!file_exists($path)) {
            throw new RuntimeException('Expected rendered fixture not found: ' . $path);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Failed to read expected rendered fixture: ' . $path);
        }

        return $content;
    }

    /**
     * Create a compilation context for testing
     *
     * @param string $source Template source code
     * @param string $templatePath Template path (default: 'test.sugar.php')
     * @param bool $debug Debug mode (default: false)
     */
    protected function createContext(
        string $source = '',
        string $templatePath = 'test.sugar.php',
        bool $debug = false,
    ): CompilationContext {
        return new CompilationContext($templatePath, $source, $debug);
    }

    /**
     * Create a FileTemplateLoader with given path
     *
     * @param string $path Template directory path
     * @return \Sugar\TemplateInheritance\FileTemplateLoader Template loader instance
     */
    protected function createLoader(string $path): FileTemplateLoader
    {
        return new FileTemplateLoader(
            (new SugarConfig())->withTemplatePaths($path),
        );
    }

    /**
     * Create a FileTemplateLoader using fixture templates
     *
     * @return \Sugar\TemplateInheritance\FileTemplateLoader Template loader instance
     */
    protected function createFixtureLoader(): FileTemplateLoader
    {
        return $this->createLoader(SUGAR_TEST_TEMPLATES_PATH);
    }

    /**
     * Create a FileTemplateLoader for component testing
     *
     * @return \Sugar\TemplateInheritance\FileTemplateLoader Template loader instance
     */
    protected function createComponentLoader(): FileTemplateLoader
    {
        return $this->createLoader(SUGAR_TEST_COMPONENTS_PATH);
    }
}
