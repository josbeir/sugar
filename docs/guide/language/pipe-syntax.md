---
title: Pipe Syntax
description: PHP 8.5 pipe operator support in Sugar templates.
---

# Pipe Syntax

Use PHP 8.5 pipe operator syntax (`|>`) in output expressions. Sugar compiles pipes to nested function calls at compile time.

::: info
Pipes keep templates readable by turning nested function calls into a left-to-right flow.
:::

::: code-group
```php [Template]
<h1><?= $title |> strtoupper(...) |> substr(..., 0, 50) ?></h1>
<p><?= $product->price |> number_format(..., 2) ?></p>
```

```php [Compiled]
<h1><?= \Sugar\Escape\Escaper::html(substr(strtoupper($title), 0, 50)) ?></h1>
<p><?= \Sugar\Escape\Escaper::html(number_format($product->price, 2)) ?></p>
```
:::

## How It Works

Pipes rewrite `value |> fn(...)` into `fn(value, ...)` at compile time. The placeholder `...` represents the piped value.

| Pipe Input | Pipe Stage | Compiled Call |
| --- | --- | --- |
| `$title` | `strtoupper(...)` | `strtoupper($title)` |
| `strtoupper($title)` | `substr(..., 0, 50)` | `substr(strtoupper($title), 0, 50)` |

## Common Patterns

::: code-group
```php [Formatting]
<p><?= $price |> number_format(..., 2) ?></p>
```

```php [Chaining]
<span><?= $name |> trim(...) |> strtoupper(...) ?></span>
```

```php [Named args]
<p><?= $price |> number_format(..., decimals: 2, thousands_separator: ',') ?></p>
```

```php [Method call]
<p><?= $user |> $formatter->displayName(...) ?></p>
```
:::

## With Directives

Pipes work inside directive expressions and preserve auto-escaping:

```html
<div s:text="$title |> strtoupper(...)"></div>
<a href="/search?q=<?= $query |> rawurlencode(...) ?>">Search</a>
```

## Raw Output

::: warning
Only use `|> raw()` with trusted HTML. Never pass user input to raw output.
:::

```html
<div><?= $article->renderedBody |> raw() ?></div>
```

::: details
Where pipes are supported

- Shorthand output tags (`<?= ... ?>`)
- Directive expressions (`s:text`, `s:if`, `s:class`, etc.)
- Any output context that supports auto-escaping
:::
