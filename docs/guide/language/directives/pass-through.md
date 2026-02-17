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
```sugar [Named slot]
<s-card>
    <div s:slot="header">Title</div>
    <p>Body</p>
</s-card>
```

```sugar [Multiple slots]
<s-layout>
    <div s:slot="sidebar">Filters</div>
    <div s:slot="content">Results</div>
</s-layout>
```
:::

### s:bind

Bind a set of attributes or props to a component element.

::: code-group
```sugar [Props object]
<s-card s:bind="$cardProps"></s-card>
```

```sugar [Merge attributes]
<s-button s:bind="$buttonAttrs">Save</s-button>
```
:::

<!--@include: ./_partials/s-raw.md-->
