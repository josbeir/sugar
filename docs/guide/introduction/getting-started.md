---
title: Getting Started
description: Quick start, low-level compiler usage, and requirements.
---

# Getting Started

Get a working Sugar setup in minutes, then grow into loaders, caching, and custom compilation as your app evolves.

::: info
Sugar compiles templates to plain PHP and caches the result for fast rendering.
:::

## Installation

```bash [Composer]
composer require josbeir/sugar
```

## Basic Usage

The high-level `Engine` API provides caching, template loading, and context binding out of the box:

```php
use Sugar\Core\Cache\FileCache;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Engine;
use Sugar\Core\Loader\FileTemplateLoader;

$engine = Engine::builder()
    ->withTemplateLoader(new FileTemplateLoader(
        config: new SugarConfig(),
        templatePaths: __DIR__ . '/templates',
        componentPaths: 'components'
    ))
    ->withCache(new FileCache(__DIR__ . '/cache'))
    ->withDebug(true)
    ->build();

echo $engine->render('pages/home', [
    'title' => 'Welcome',
    'user' => $currentUser,
]);
```

::: tip
By default, `FileTemplateLoader` resolves `s:extends` and `s:include` paths relative to the current template. If you prefer absolute-only lookups, pass `absolutePathsOnly: true` and use root-style paths like `layouts/base.sugar.php`.

For a complete overview of all builder methods and configuration options, see [Engine Configuration](/guide/development/index).
:::

## Example Template

::: code-group
```html [pages/home.sugar.php]
<h1 s:text="$title"></h1>

<s-card s:bind="$userCard">
    <div s:slot="header">Welcome back</div>
    <p>Hello, <?= $user->name ?></p>
</s-card>
```

```php [Render]
echo $engine->render('pages/home', [
    'title' => 'Welcome',
    'user' => $currentUser,
    'userCard' => ['class' => 'card'],
]);
```
:::

## Low-Level Compiler API

For advanced use cases where you need direct control over compilation:

```php
use Sugar\Core\Compiler\Compiler;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Escape\Escaper;
use Sugar\Core\Loader\FileTemplateLoader;
use Sugar\Core\Parser\Parser;

$loader = new FileTemplateLoader(
    config: new SugarConfig(),
    templatePaths: __DIR__ . '/templates'
);

$compiler = new Compiler(
    new Parser(new SugarConfig()),
    new Escaper(),
    templateLoader: $loader
);

$compiled = $compiler->compile('<div s:if="$show"><?= $message ?></div>');
```

::: details
When to use the compiler directly

- Pre-compiling templates for a build step
- Integrating Sugar into a custom framework
- Advanced caching or storage strategies
:::

## Requirements

- PHP 8.2+ (tested on 8.2, 8.3, 8.4, 8.5)
- Composer

The pipe syntax (`|>`) is a compile-time feature, so you can use PHP 8.5 syntax even on PHP 8.2.
