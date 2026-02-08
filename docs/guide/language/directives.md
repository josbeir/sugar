---
title: Directives
description: Overview of available directives and their categories.
---

# Directives

Sugar provides familiar control structures and attribute helpers as HTML attributes.

::: tip
Directives are just attributes prefixed with `s:`. They keep templates readable while compiling to fast PHP.
:::

## Directive Types

| Type | What it does | Examples |
| --- | --- | --- |
| Control Flow | Wraps elements in conditions or loops. | `s:if`, `s:foreach`, `s:switch` |
| Attribute | Computes or merges attributes. | `s:class`, `s:spread`, `s:tag` |
| Content | Replaces element content with output. | `s:text`, `s:html` |
| Pass-through | Handled by other passes. | `s:slot`, `s:bind` |

::: code-group
```html [Control flow]
<div s:if="$isReady">Ready</div>
```

```html [Attribute]
<div s:class="['active' => $isActive]"></div>
```

```html [Content]
<div s:text="$userName"></div>
```

```html [Pass-through]
<s-card s:bind="$cardProps">
	<div s:slot="header">Title</div>
</s-card>
```
:::

::: details
Jump to the full pages

- [Control Flow Directives](./directives/control-flow.md)
- [Attribute Directives](./directives/attribute.md)
- [Content Directives](./directives/content.md)
- [Pass-through Directives](./directives/pass-through.md)
:::

## Special Elements

- `<s-template>` - Fragment element that renders only children.

```html
<s-template>
	<h2>Only this output renders</h2>
</s-template>
```

For related examples, see:
- [Loop Metadata](./loop-metadata.md)
