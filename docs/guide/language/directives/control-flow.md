---
title: Control Flow Directives
description: Wrap elements in conditional and loop structures.
---

# Control Flow Directives

Control flow directives wrap an element in a conditional or loop. Only one control-flow directive can appear on a single element.
Use `<s-template>` when you want control flow without adding a wrapper element.

## Directives

- `s:if` - Render when a condition is true.
- `s:unless` - Render when a condition is false.
- `s:isset` - Render when a variable is set.
- `s:empty` - Render when a value is empty.
- `s:foreach` - Loop over an iterable.
- `s:forelse` - Loop with an empty fallback.
- `s:while` - Loop while a condition is true.
- `s:times` - Loop a fixed number of times.
- `s:switch` - Switch/case rendering.
- `s:ifcontent` - Render wrappers only if they contain output.

## Examples

### s:if

Render the element only when the expression evaluates to true.

::: code-group
```html [Basic]
<div s:if="$isReady">Ready</div>
```

```html [Negated]
<div s:if="!$isReady">Loading...</div>
```
:::

### s:unless

Render the element only when the expression evaluates to false.

```html
<div s:unless="$isReady">Loading...</div>
```

### s:isset

Render the element when the variable is set (not null and defined).

```html
<div s:isset="$user">Welcome, <?= $user->name ?></div>
```

### s:empty

Render the element when the value is empty.

```html
<div s:empty="$items">No items found</div>
```

### s:foreach

Repeat the element for every item in an iterable.

::: code-group
```html [List]
<ul s:foreach="$items as $item">
    <li><?= $item ?></li>
</ul>
```

```html [Keyed]
<dl s:foreach="$stats as $label => $value">
    <dt><?= $label ?></dt>
    <dd><?= $value ?></dd>
</dl>
```

```html [Loop metadata]
<ul s:foreach="$items as $item">
    <li s:class="['first' => $loop->first, 'last' => $loop->last, 'odd' => $loop->odd]">
        <?= $item ?> (<?= $loop->iteration ?> of <?= $loop->count ?>)
    </li>
</ul>
```
:::

### s:forelse

Loop over items and fall back to an `s:empty` sibling when empty.

::: code-group
```html [Basic]
<ul s:forelse="$items as $item">
    <li><?= $item ?></li>
</ul>
<div s:empty>No items found</div>
```

```html [Loop metadata]
<ul s:forelse="$items as $item">
    <li s:class="['odd' => $loop->odd, 'even' => $loop->even]">
        <?= $item ?> (<?= $loop->iteration ?>)
    </li>
</ul>
<div s:empty>No items found</div>
```
:::

### s:while

Repeat the element while a condition remains true.

```html
<div s:while="$poller->hasNext()">
    <?= $poller->next() ?>
</div>
```

### s:times

Repeat the element a fixed number of times.

::: code-group
```html [Basic]
<span s:times="3">*</span>
```

```html [With index]
<span s:times="5 as $i">#<?= $i ?></span>
```
:::

### s:switch

Choose between `s:case` and `s:default` children based on a value.

::: code-group
```html [Case]
<div s:switch="$role">
    <span s:case="'admin'">Administrator</span>
    <span s:default>User</span>
</div>
```

```html [Multiple cases]
<div s:switch="$status">
    <span s:case="'open'">Open</span>
    <span s:case="'closed'">Closed</span>
    <span s:default>Unknown</span>
</div>
```

```html [Role switch]
<div s:switch="$role">
    <span s:case="'admin'">Administrator</span>
    <span s:case="'moderator'">Moderator</span>
    <span s:default>User</span>
</div>
```
:::

### s:ifcontent

Render the wrapper only when it would contain output.

```html
<div s:ifcontent class="card">
    <?php if ($showContent): ?>
        <p>Some content here</p>
    <?php endif; ?>
</div>
```
