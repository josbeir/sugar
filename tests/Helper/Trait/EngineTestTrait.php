<?php
declare(strict_types=1);

namespace Sugar\Tests\Helper\Trait;

use Sugar\Core\Cache\FileCache;
use Sugar\Core\Cache\TemplateCacheInterface;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Engine;
use Sugar\Core\Loader\FileTemplateLoader;
use Sugar\Core\Loader\StringTemplateLoader;
use Sugar\Core\Loader\TemplateNamespaceDefinition;

/**
 * Helper trait for creating Engine instances in tests
 *
 * Provides factory methods for common engine configurations
 */
trait EngineTestTrait
{
    /**
     * Create an Engine with a file-based template loader
     *
     * @param string $templatePath Path to templates directory
     * @param object|null $context Optional context object to bind as $this
     * @param TemplateCacheInterface|null $cache Optional cache implementation
     * @param bool $debug Debug mode (default: false)
     * @return Engine Configured engine instance
     */
    protected function createEngine(
        string $templatePath,
        ?object $context = null,
        ?TemplateCacheInterface $cache = null,
        bool $debug = false,
    ): Engine {
        $config = new SugarConfig();
        $loader = new FileTemplateLoader([$templatePath]);

        $builder = Engine::builder($config)
            ->withTemplateLoader($loader)
            ->withDebug($debug);

        if ($context !== null) {
            $builder = $builder->withTemplateContext($context);
        }

        if ($cache instanceof TemplateCacheInterface) {
            $builder = $builder->withCache($cache);
        }

        return $builder->build();
    }

    /**
     * Create an Engine using fixture templates
     *
     * @param object|null $context Optional context object
     * @param TemplateCacheInterface|null $cache Optional cache
     * @param bool $debug Debug mode
     * @return Engine Configured engine instance
     */
    protected function createFixtureEngine(
        ?object $context = null,
        ?TemplateCacheInterface $cache = null,
        bool $debug = false,
    ): Engine {
        return $this->createEngine(
            SUGAR_TEST_TEMPLATES_PATH,
            $context,
            $cache,
            $debug,
        );
    }

    /**
     * Create an Engine with a string-based template loader
     *
     * @param array<string, string> $templates Template map (path => source)
     * @param array<string, string> $components Component map (name => source)
     * @param object|null $context Optional context object to bind as $this
     * @param TemplateCacheInterface|null $cache Optional cache implementation
     * @param bool $debug Debug mode (default: false)
     * @return Engine Configured engine instance
     */
    protected function createStringEngine(
        array $templates,
        array $components = [],
        ?object $context = null,
        ?TemplateCacheInterface $cache = null,
        bool $debug = false,
    ): Engine {
        $config = new SugarConfig();
        $resourceTemplates = $templates;
        foreach ($components as $name => $source) {
            $resourceTemplates['@components/' . $config->elementPrefix . $name . '.sugar.php'] = $source;
        }

        $loader = new StringTemplateLoader(
            templates: $resourceTemplates,
        );
        $loader->registerNamespace('components', new TemplateNamespaceDefinition(['components'], ['.sugar.php']));

        $builder = Engine::builder($config)
            ->withTemplateLoader($loader)
            ->withDebug($debug);

        if ($context !== null) {
            $builder = $builder->withTemplateContext($context);
        }

        // Use a unique cache directory per call to avoid stale cache collisions
        // when different tests register templates under identical names
        if (!$cache instanceof TemplateCacheInterface) {
            $cache = new FileCache(
                cacheDir: sys_get_temp_dir() . '/sugar_test_' . bin2hex(random_bytes(8)),
            );
        }

        $builder = $builder->withCache($cache);

        return $builder->build();
    }
}
