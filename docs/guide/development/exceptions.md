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
- `CompilationException` - Generated PHP is invalid or compiled templates/components fail to load.
- `TemplateNotFoundException` - A template path or include cannot be resolved.
- `ComponentNotFoundException` - A component reference cannot be resolved.
- `UnknownDirectiveException` - An unregistered directive was encountered.
- `TemplateRuntimeException` - Rendering failed due to a runtime error.

## Exception Rendering

<!-- @include: ./_partials/exception-renderer-preview.md -->

## Handling Exceptions

Catch `SugarException` to report template errors consistently:

```php
use Sugar\Core\Exception\SugarException;

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

- Enable debug mode while developing to improve diagnostics and cache refresh behavior.
- Install `nikic/php-parser` to opt in to compile-time PHP syntax validation for earlier diagnostics.
- Use `Engine::builder()->withDebug(true)->withPhpSyntaxValidation(true)` to enable parser-based validation for a specific engine instance (see [Optional PHP Syntax Validation](./index.md#optional-php-syntax-validation)).
- When enabled in debug mode, Sugar validates output expressions individually and validates generated PHP as a whole, providing earlier syntax diagnostics with template location metadata.
- Without parser-based validation (or with debug disabled), invalid generated PHP is surfaced at include-time as a `CompilationException` with compiled path and parse line information.
- Check includes and component paths when you see missing template exceptions.
- Verify directive registration if you hit `UnknownDirectiveException`.
