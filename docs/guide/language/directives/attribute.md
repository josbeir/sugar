---
title: Attribute Directives
description: Modify element attributes with helpers.
---

# Attribute Directives

Attribute directives compute or adjust attributes while keeping templates readable. They can be combined with control flow directives.

## Directives

- `s:class` - Conditional class lists.
- `s:spread` - Spread attributes from arrays.
- `s:checked` - Conditionally add `checked`.
- `s:selected` - Conditionally add `selected`.
- `s:disabled` - Conditionally add `disabled`.
- `s:tag` - Dynamic HTML tag names.

## Examples

### s:class

Build classes from an associative array of conditions.

::: code-group
```html [Array map]
<div s:class="['active' => $isActive, 'disabled' => $isDisabled]"></div>
```

```html [Merge]
<div class="card" s:class="['featured' => $isFeatured]"></div>
```
:::

### s:spread

Spread an array of attributes onto the element.

::: code-group
```html [Simple]
<div s:spread="$attrs"></div>
```

```html [Merged]
<button class="btn" s:spread="$extraAttrs">Save</button>
```
:::

### s:checked

Apply the `checked` attribute when the expression is truthy.

```html
<input type="checkbox" s:checked="$newsletter">
```

### s:selected

Apply the `selected` attribute when the expression is truthy.

```html
<option s:selected="$value === $selected"><?= $label ?></option>
```

### s:disabled

Apply the `disabled` attribute when the expression is truthy.

```html
<button s:disabled="$isSaving">Save</button>
```

### s:tag

Compute the element tag name at runtime.

::: code-group
```html [Heading]
<div s:tag="$headingLevel">Page Title</div>
```

```html [Component wrapper]
<div s:tag="$wrapperTag" class="panel">Content</div>
```
:::
