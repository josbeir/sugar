---
title: Exceptions
description: How Sugar reports template errors with helpful snippets.
---

# Exceptions

Sugar throws focused exceptions that include contextual snippets so you can identify where a template failed. These exceptions are designed to be actionable during development and safe to log in production.

::: tip
Most template errors are `SugarException` subclasses. Catch `SugarException` to handle template failures in one place.
:::

## Common Exception Types

- `SyntaxException` - Malformed templates or invalid directives.
- `TemplateNotFoundException` - A template path or include cannot be resolved.
- `ComponentNotFoundException` - A component reference cannot be resolved.
- `UnknownDirectiveException` - An unregistered directive was encountered.
- `TemplateRuntimeException` - Rendering failed due to a runtime error.

## Snippet Output

Sugar exceptions include a short snippet to help you locate the issue:

```text
Template: pages/home.sugar.php
Line: 12

10 | <div s:if="$user">
11 |     <span><?= $user->name ?></span>
12 |     <div s:include="partials/unknown"></div>
13 | </div>
```

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
