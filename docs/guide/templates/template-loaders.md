---
title: Template Loaders
description: FileTemplateLoader and StringTemplateLoader.
---

# Template Loaders

Template loaders decide how Sugar resolves templates and components. Use file-based loaders for production and string-based loaders for tests or dynamic templates.

::: tip
Keep template paths and component paths in one place so your engine configuration stays predictable across environments.
:::

## FileTemplateLoader

Use the filesystem for template resolution. `templatePaths` can be a single path or a list of paths.

::: code-group
```php
use Sugar\Loader\FileTemplateLoader;
use Sugar\Config\SugarConfig;

$loader = new FileTemplateLoader(
    config: new SugarConfig(),
    templatePaths: __DIR__ . '/templates',
    componentPaths: 'components'
);
```

```php [Multiple paths]
$loader = new FileTemplateLoader(
    config: new SugarConfig(),
    templatePaths: [
        __DIR__ . '/templates',
        __DIR__ . '/vendor/package/templates',
    ],
    componentPaths: 'components'
);
```
:::

## StringTemplateLoader

Use in-memory templates for tests, demos, or dynamic content.

```php
use Sugar\Loader\StringTemplateLoader;
use Sugar\Config\SugarConfig;

$loader = new StringTemplateLoader(
    config: new SugarConfig(),
    templates: [
        'email/welcome' => '<h1>Welcome <?= $name ?>!</h1>',
    ],
    components: [
        'button' => '<button class="btn"><?= $slot ?></button>',
    ]
);
```

::: details
When to use each loader

- `FileTemplateLoader` for real applications and caching
- `StringTemplateLoader` for tests, previews, or isolated render calls
:::
