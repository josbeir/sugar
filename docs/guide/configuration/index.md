---
title: Configuration
description: Engine config, custom prefix, and directive registry.
---

# Configuration

Configure the engine once and keep the rest of your templates clean. Most teams only need a custom prefix, loader paths, and a cache directory.

::: tip
Build your engine from a single `SugarConfig` instance so loaders, parser, and compiler share the same configuration.
:::

## Engine Configuration

Use the builder to configure loaders, cache, and debug mode in one place:

```php
use Sugar\Engine;
use Sugar\Cache\FileCache;
use Sugar\Loader\FileTemplateLoader;
use Sugar\Config\SugarConfig;

$config = SugarConfig::withPrefix('x');

$engine = Engine::builder($config)
    ->withTemplateLoader(new FileTemplateLoader(
        config: $config,
        templatePaths: [__DIR__ . '/templates'],
        componentPaths: ['components']
    ))
    ->withCache(new FileCache(__DIR__ . '/cache/templates'))
    ->withDebug(true)
    ->build();
```

::: info
`withDebug(true)` enables file timestamp checks for development. Disable it in production for best performance.
:::

## Custom Directive Prefix

Swap the `s:` prefix if you need to avoid collisions with another templating system:

```php
use Sugar\Config\SugarConfig;

$config = SugarConfig::withPrefix('v');
```

::: tip
After changing the prefix, use it consistently in templates and component tags.
:::

## Custom Directive Registry

Register only the directives you want to allow in a given environment:

```php
use Sugar\Extension\DirectiveRegistry;
use Sugar\Directive\IfDirective;
use Sugar\Directive\ForeachDirective;
use Sugar\Directive\CustomDirective;

$registry = DirectiveRegistry::empty();
$registry->register('if', IfDirective::class);
$registry->register('foreach', ForeachDirective::class);
$registry->register('custom', CustomDirective::class);
```

::: details
When to customize the directive registry

- Reduce surface area in locked-down environments
- Add project-specific directives
- Provide feature flags by swapping registries
:::
