---
title: Attribute Directives
description: Modify element attributes with helpers.
---

# Attribute Directives

Attribute directives compute or adjust attributes while keeping templates readable. They can be combined with control flow directives.

## Directives

- `s:class` - Conditional class lists.
- `s:spread` / `s:attr` - Spread attributes from arrays.
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

```html [Rendered]
<!-- $isActive = true, $isDisabled = false, $isFeatured = true -->
<div class="active"></div>
<div class="card featured"></div>
```
:::

### s:spread / s:attr

Spread an array of attributes onto the element. `s:attr` is a short alias.

::: code-group
```html [Simple]
<div s:spread="$attrs"></div>
<div s:attr="$attrs"></div>
```

```html [Rendered]
<!-- $attrs = ['id' => 'user-1', 'class' => 'card', 'disabled' => true] -->
<div id="user-1" class="card" disabled></div>
```

```html [Merged]
<button class="btn" s:spread="$extraAttrs">Save</button>
```

```html [Rendered (merged)]
<!-- $extraAttrs = ['type' => 'submit', 'disabled' => true] -->
<button class="btn" type="submit" disabled>Save</button>
```
:::

### s:checked

Apply the `checked` attribute when the expression is truthy.

::: code-group
```html [Input]
<input type="checkbox" s:checked="$newsletter">
```

```html [Rendered]
<!-- $newsletter = true -->
<input type="checkbox" checked>
```
:::

### s:selected

Apply the `selected` attribute when the expression is truthy.

::: code-group
```html [Option]
<option s:selected="$value === $selected"><?= $label ?></option>
```

```html [Rendered]
<!-- $value = 'gold', $selected = 'gold', $label = 'Gold' -->
<option selected>Gold</option>
```
:::

### s:disabled

Apply the `disabled` attribute when the expression is truthy.

::: code-group
```html [Button]
<button s:disabled="$isSaving">Save</button>
```

```html [Rendered]
<!-- $isSaving = true -->
<button disabled>Save</button>
```
:::

### s:tag

Compute the element tag name at runtime.

::: code-group
```html [Heading]
<div s:tag="$headingLevel">Page Title</div>
```

```html [Component wrapper]
<div s:tag="$wrapperTag" class="panel">Content</div>
```

```html [Rendered]
<!-- $headingLevel = 'h2', $wrapperTag = 'section' -->
<h2>Page Title</h2>
<section class="panel">Content</section>
```
:::
