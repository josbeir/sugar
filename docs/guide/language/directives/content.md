---
title: Content Directives
description: Inject escaped or raw content into elements.
---

# Content Directives

Content directives replace the element contents with a single value.

## Directives

- `s:text` - Escaped output.
- `s:html` - Raw HTML output.
- `s:raw` - Verbatim inner markup output (child directives/tags are not parsed).

## Examples

### s:text

Output escaped text from a value.

::: code-group
```html [Basic]
<div s:text="$userName"></div>
```

```html [Fallback]
<div s:text="$userName ?? 'Guest'"></div>
```
:::

### s:html

Output raw HTML from a trusted value.

::: warning
Only use `s:html` with trusted content.
:::

::: code-group
```html [Render HTML]
<div s:html="$article->renderedContent"></div>
```

```html [Snippet]
<div s:html="$snippet"></div>
```
:::

<!--@include: ./_partials/s-raw.md-->

## No Wrapper Output

Add `s:nowrap` to render content without the surrounding element:

```html
<div s:text="$headline" s:nowrap></div>
```
