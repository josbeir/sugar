---
title: Pass-through Directives
description: Registered directives handled by other passes.
---

# Pass-through Directives

Pass-through directives look like normal directives, but they are handled by specialized compiler passes instead of the directive compilation pass.

## Directives

- `s:slot` - Component slot assignment.
- `s:bind` - Component attribute binding.
- `s:raw` - Preserve element inner content without directive parsing.

## Examples

### s:slot

Assign element content to a named component slot.

::: code-group
```html [Named slot]
<s-card>
    <div s:slot="header">Title</div>
    <p>Body</p>
</s-card>
```

```html [Multiple slots]
<s-layout>
    <div s:slot="sidebar">Filters</div>
    <div s:slot="content">Results</div>
</s-layout>
```
:::

### s:bind

Bind a set of attributes or props to a component element.

::: code-group
```html [Props object]
<s-card s:bind="$cardProps"></s-card>
```

```html [Merge attributes]
<s-button s:bind="$buttonAttrs">Save</s-button>
```
:::

### Combined usage

Use `s:bind` and `s:slot` together to pass props and slot content.

```html
<s-card s:bind="$cardProps">
    <div s:slot="header">Title</div>
    <p>Body</p>
</s-card>
```

<!--@include: ./_partials/s-raw.md-->
