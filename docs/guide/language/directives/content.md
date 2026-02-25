---
title: Content Directives
description: Inject escaped or raw content into elements.
---

# Content Directives

Content directives replace the element contents with a single value.

## Directives

- `s:text` - Escaped output.
- `s:html` - Raw HTML output.

## Examples

### s:text

Output escaped text from a value.

::: code-group
```sugar [Basic]
<div s:text="$userName"></div>
```

```sugar [Fallback]
<div s:text="$userName ?? 'Guest'"></div>
```

```html [Rendered]
<!-- $userName = '<Alice>' -->
<div>&lt;Alice&gt;</div>
```
:::

### s:html

Output raw HTML from a trusted value.

::: warning
Only use `s:html` with trusted content.
:::

::: code-group
```sugar [Render HTML]
<div s:html="$article->renderedContent"></div>
```

```sugar [Snippet]
<div s:html="$snippet"></div>
```

```html [Rendered]
<!-- $snippet = '<strong>Hello</strong>' -->
<div><strong>Hello</strong></div>
```
:::

## Related Pass-through Directive

`s:raw` is a pass-through directive (not a content replacement directive), but it is commonly used with content directives when you need literal inner markup.

<!--@include: ./_partials/s-raw.md-->

## No Wrapper Output

Add `s:nowrap` to render content without the surrounding element:

```sugar
<div s:text="$headline" s:nowrap></div>
```

```html
<!-- $headline = 'News' -->
News
```
