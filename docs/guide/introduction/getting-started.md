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

Start with the smallest possible setup, then add optional features like components and file cache.

::: code-group
```php [Minimal]
use Sugar\Core\Engine;
use Sugar\Core\Loader\FileTemplateLoader;

$engine = Engine::builder()
    ->withTemplateLoader(new FileTemplateLoader(
        templatePaths: __DIR__ . '/templates',
    ))
    ->build();

echo $engine->render('pages/home', [
    'title' => 'Welcome',
    'user' => $currentUser,
]);
```

```php [With components + cache]
use Sugar\Core\Cache\FileCache;
use Sugar\Core\Engine;
use Sugar\Core\Loader\FileTemplateLoader;
use Sugar\Extension\Component\ComponentExtension;

$engine = Engine::builder()
    ->withTemplateLoader(new FileTemplateLoader(
        templatePaths: __DIR__ . '/templates',
    ))
    ->withExtension(new ComponentExtension())
    ->withCache(new FileCache(__DIR__ . '/cache'))
    ->withDebug(true)
    ->build();

echo $engine->render('pages/home', [
    'title' => 'Welcome',
    'user' => $currentUser,
]);
```
:::

::: tip
By default, `FileTemplateLoader` resolves `s:extends` and `s:include` paths relative to the current template. To use components, register `ComponentExtension` on the builder. If you prefer absolute-only lookups, pass `absolutePathsOnly: true` and use root-style paths like `layouts/base.sugar.php`.

For a complete overview of all builder methods and configuration options, see [Engine Configuration](/guide/development/index).
:::

## First Template (Simple)

::: code-group
```sugar [pages/home.sugar.php]
<h1><?= $title ?></h1>
<p>Hello, <?= $user->name ?></p>
<p s:if="$showHint">This line is controlled by a Sugar directive.</p>
```

```php [Render]
echo $engine->render('pages/home', [
    'title' => 'Welcome <Sugar>',
    'user' => $currentUser,
    'showHint' => true,
]);
```

```html [Rendered output]
<h1>Welcome &lt;Sugar&gt;</h1>
<p>Hello, Alex &amp; Co.</p>
<p>This line is controlled by a Sugar directive.</p>
```
:::

## Next Step: Components with slots

Once the basic flow is clear, add reusable components:

::: code-group
```sugar [pages/home.sugar.php]
<h1><?= $title ?></h1>
<p s:if="$showHint">This line is controlled by a Sugar directive.</p>

<s-user-panel class="shadow-lg">
    <h3 s:slot="header">Profile</h3>
    <p>Hello, <?= $user->name ?></p>
    <p s:slot="footer">Profile ready</p>
</s-user-panel>
```

```php [Render]
echo $engine->render('pages/home', [
    'title' => 'Welcome <Sugar>',
    'user' => $currentUser,
    'showHint' => true,
]);
```

```sugar [components/s-user-panel.sugar.php]
<article class="card">
    <h3 s:slot="header" class="card-header">Untitled</h3>
    <section><?= $slot ?></section>
    <small s:slot="footer" class="card-footer"></small>
</article>
```

```html [Rendered output]
<h1>Welcome &lt;Sugar&gt;</h1>
<p>This line is controlled by a Sugar directive.</p>
<article class="card shadow-lg">
    <h3 class="card-header">Profile</h3>
    <section><p>Hello, Alex &amp; Co.</p></section>
    <small class="card-footer">Profile ready</small>
</article>
```
:::

In this example:

- Named slots (`s:slot="header"`, `s:slot="footer"`) handle structured component content. The caller's tag replaces the outlet's tag (e.g., `<h3>` replaces the outlet's `<h3>`, `<p>` replaces `<small>`), and attributes are merged.
- `class="shadow-lg"` is a regular HTML attribute merged onto the component root.
- The default slot is the inner content (`<p>Hello, ...</p>`).

Use `s:bind` when you need optional component variables, for example:

```sugar
<s-user-panel s:bind="['compact' => true]">...</s-user-panel>
```

::: tip
Continue with:

- [Components](/guide/extensions/components)
- [Template Inheritance](/guide/language/inheritance)
- [Directives](/guide/language/directives)
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
