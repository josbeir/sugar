---
title: Exceptions
description: How Sugar reports template errors with location metadata and optional HTML rendering.
---

# Exceptions

Sugar throws focused exceptions that include location metadata so you can identify where a template failed. These exceptions are designed to be actionable during development and safe to log in production.

::: tip
Most template errors are `SugarException` subclasses. Catch `SugarException` to handle template failures in one place.
:::

## Common Exception Types

- `SyntaxException` - Malformed templates or invalid directives.
- `TemplateNotFoundException` - A template path or include cannot be resolved.
- `ComponentNotFoundException` - A component reference cannot be resolved.
- `UnknownDirectiveException` - An unregistered directive was encountered.
- `TemplateRuntimeException` - Rendering failed due to a runtime error.

## Exception Rendering

<!-- @include: ./_partials/exception-renderer-preview.md -->

## Handling Exceptions

Catch `SugarException` to report template errors consistently:

```php
use Sugar\Exception\SugarException;

try {
    echo $engine->render('pages/home', ['user' => $user]);
} catch (SugarException $exception) {
    $logger->error($exception->getMessage(), ['exception' => $exception]);
    echo 'Template error.';
}
```

::: warning
Avoid exposing exception details in production responses. Log them instead.
:::

## Debug Tips

- Enable debug mode to add source location comments to compiled templates.
- Check includes and component paths when you see missing template exceptions.
- Verify directive registration if you hit `UnknownDirectiveException`.
