---
title: Debug Mode
description: Source location comments in compiled output.
---

# Debug Mode

Debug mode makes compiled output easier to trace back to the original template source. It is designed for development and should be disabled in production.

::: tip
Turn on debug mode when you are inspecting compiled templates or troubleshooting template output.
:::

## Enable Debug Mode

Enable debug mode to add source location comments to compiled templates:

```php
$engine = Engine::builder()
    ->withTemplateLoader($loader)
    ->withDebug(true)
    ->build();
```

## What You Get

With debug mode enabled, compiled templates include source location comments that help you map back to the original file and line number.

::: code-group
```php [Compiled]
<?php /* sugar: templates/pages/home.sugar.php:12 */ ?>
<h1><?= \Sugar\Escape\Escaper::html($title) ?></h1>
```

```html [Template]
<h1><?= $title ?></h1>
```
:::

::: warning
Disable debug mode in production to avoid extra filesystem checks and template metadata in output.
:::
