---
title: Context-Aware Escaping
description: Automatic escaping and raw output controls.
---

# Context-Aware Escaping

Sugar automatically applies the right escaping based on where your output appears.

::: info
Sugar analyzes output context at compile time and applies the appropriate escape routine. You write plain `<?= $value ?>` and get safe output by default.
:::

::: code-group
```sugar [Template]
<div data-user="<?= $name ?>">
<script>const user = <?= $userData ?>;</script>
<style>.user::before { content: '<?= $prefix ?>'; }</style>
<a href="/search?q=<?= $query ?>">Search</a>
```

```php [Compiled]
<div data-user="<?= \Sugar\Escape\Escaper::attr($name) ?>">
<script>const user = <?= \Sugar\Escape\Escaper::js($userData) ?>;</script>
<style>.user::before { content: '<?= \Sugar\Escape\Escaper::css($prefix) ?>'; }</style>
<a href="/search?q=<?= \Sugar\Escape\Escaper::url($query) ?>">Search</a>
```
:::

## What Sugar Detects

::: tip
Escaping is context-aware, not string-aware. The same variable can be escaped differently depending on where it appears.
:::

| Context | Example | Escaper | Notes |
| --- | --- | --- | --- |
| HTML text | `<p><?= $title ?></p>` | `Escaper::html()` | Default for plain text nodes. |
| HTML attribute | `<div title="<?= $title ?>">` | `Escaper::attr()` | Protects quotes and attribute breaks. |
| URL | `<a href="/q?search=<?= $query ?>">` | `Escaper::url()` | Uses `rawurlencode`. |
| JavaScript | `<script>const u = <?= $data ?>;</script>` | `Escaper::js()` | Uses JSON encoding with hex flags. |
| CSS | `<style>.x{content:'<?= $label ?>'}</style>` | `Escaper::css()` | Escapes unsafe CSS chars. |

## Common Patterns

::: code-group
```sugar [Attributes]
<button data-id="<?= $id ?>">Open</button>
<input value="<?= $value ?>">
```

```sugar [URL]
<a href="/search?q=<?= $query ?>">Search</a>
```

```sugar [Script]
<script>
	const payload = <?= $payload ?>;
</script>
```

```sugar [Style]
<style>
	.badge::before { content: '<?= $label ?>'; }
</style>
```
:::

## When to Override Escaping

::: warning
Only bypass escaping for trusted, pre-sanitized content. Never pass user input to raw output.
:::

## Raw Output (`|> raw()`)

Use `|> raw()` for trusted HTML:

```sugar
<div><?= $article->renderedBody |> raw() ?></div>
```

Only use raw output for trusted content. Never pass user input to `|> raw()`.

## JSON Output (`|> json()`)

Use `|> json()` when you want JSON output with context-aware escaping. In HTML, it compiles to `Escaper::json()`. Inside attributes it compiles to `Escaper::attrJson()` so quotes stay safe.

```sugar
<script>
	const payload = <?= $payload |> json() ?>;
</script>

<div x-data="{ data: <?= $payload |> json() ?> }"></div>
```

::: tip
Use `|> json()` for arrays/objects. It keeps escaping enabled, unlike `|> raw()`.
:::

::: details
Need a reminder of the escape helpers?

- `Escaper::html()` for text nodes
- `Escaper::attr()` for attribute values
- `Escaper::url()` for URL parts
- `Escaper::js()` for JavaScript
- `Escaper::css()` for CSS
- `Escaper::json()` for JSON output
:::
