---
title: Template Context
description: Bind a context object and use $this in templates.
---

# Template Context

Template context lets you expose helper methods to every template via `$this`.

::: tip
Treat the context as a lightweight helper object. Keep it stateless when possible.
:::

```php
use Sugar\Engine;

$viewContext = new class {
    public function url(string $path): string
    {
        return '/app' . $path;
    }
};

$engine = Engine::builder()
    ->withTemplateLoader($loader)
    ->withTemplateContext($viewContext)
    ->build();
```

In templates:
```html
<a href="<?= $this->url('/profile') ?>">Profile</a>
```

## Common Patterns

::: code-group
```php [Helpers]
$viewContext = new class {
    public function url(string $path): string
    {
        return '/app' . $path;
    }

    public function asset(string $path): string
    {
        return '/assets/' . ltrim($path, '/');
    }
};
```

```html [Template]
<link rel="stylesheet" href="<?= $this->asset('app.css') ?>">
```
:::

::: details
When to use template context

- Shared helpers like URL or asset builders
- Formatting helpers (dates, numbers) reused across templates
- Framework integration points that should not be global functions
:::
