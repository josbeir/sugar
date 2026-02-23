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
- `s:notempty` - Render when a value is not empty.
- `s:foreach` - Loop over an iterable.
- `s:forelse` - Loop with an empty fallback.
- `s:while` - Loop while a condition is true.
- `s:times` - Loop a fixed number of times.
- `s:cache` - Cache a rendered fragment by key.
- `s:switch` - Switch/case rendering.
- `s:ifcontent` - Render wrappers only if they contain output.
- `s:try` - Wrap output in a try block with optional finally.
- `s:finally` - Optional sibling for s:try cleanup.

## Examples

### s:if

Render the element only when the expression evaluates to true.

::: code-group
```sugar [Basic]
<div s:if="$isReady">Ready</div>
```

```sugar [Negated]
<div s:if="!$isReady">Loading...</div>
```

```html [Rendered]
<!-- $isReady = true -->
<div>Ready</div>
```
:::

### s:unless

Render the element only when the expression evaluates to false.

For more about empty/false checks, see [Empty Checking](/guide/language/empty-checking).

::: code-group
```sugar [Attribute]
<div s:unless="$isReady">Loading...</div>
```

```sugar [Element]
<s-unless condition="$isReady">Loading...</s-unless>
```

```html [Rendered — attribute]
<!-- $isReady = false -->
<div>Loading...</div>
```

```html [Rendered — element]
<!-- $isReady = false -->
Loading...
```
:::

### s:isset

Render the element when the variable is set (not null and defined).

::: code-group
```sugar [Attribute]
<div s:isset="$user">Welcome, <?= $user->name ?></div>
```

```sugar [Element]
<s-isset value="$user">Welcome, <?= $user->name ?></s-isset>
```

```html [Rendered — attribute]
<!-- $user->name = 'Jasper' -->
<div>Welcome, Jasper</div>
```

```html [Rendered — element]
<!-- $user->name = 'Jasper' -->
Welcome, Jasper
```
:::

### s:empty

Render the element when the value is empty.

For more about empty/false checks, see [Empty Checking](/guide/language/empty-checking).

::: code-group
```sugar [Attribute]
<div s:empty="$items">No items found</div>
```

```sugar [Element]
<s-empty value="$items">No items found</s-empty>
```

```html [Rendered — attribute]
<!-- $items = [] -->
<div>No items found</div>
```

```html [Rendered — element]
<!-- $items = [] -->
No items found
```
:::

### s:notempty

Render the element when the value is not empty.

For more about empty/false checks, see [Empty Checking](/guide/language/empty-checking).

::: code-group
```sugar [Attribute]
<div s:notempty="$items">Items available</div>
```

```sugar [Element]
<s-notempty value="$items">Items available</s-notempty>
```

```html [Rendered — attribute]
<!-- $items = ['A'] -->
<div>Items available</div>
```

```html [Rendered — element]
<!-- $items = ['A'] -->
Items available
```
:::

### s:foreach

Repeat the element for every item in an iterable.

For full loop metadata details, see [Loop Metadata](/guide/language/loop-metadata).

::: code-group
```sugar [List]
<ul s:foreach="$items as $item">
    <li><?= $item ?></li>
</ul>
```

```sugar [Keyed]
<dl s:foreach="$stats as $label => $value">
    <dt><?= $label ?></dt>
    <dd><?= $value ?></dd>
</dl>
```

```sugar [Loop metadata]
<ul s:foreach="$items as $item">
    <li s:class="['first' => $loop->first, 'last' => $loop->last, 'odd' => $loop->odd]">
        <?= $item ?> (<?= $loop->iteration ?> of <?= $loop->count ?>)
    </li>
</ul>
```

```html [Rendered]
<!-- $items = ['A', 'B'] -->
<ul>
    <li>A</li>
</ul>
<ul>
    <li>B</li>
</ul>
```
:::

### s:forelse

Loop over items and fall back to an `s:empty` sibling when empty.

For full loop metadata details, see [Loop Metadata](/guide/language/loop-metadata).

For more about empty/false checks, see [Empty Checking](/guide/language/empty-checking).

::: code-group
```sugar [Basic]
<ul s:forelse="$items as $item">
    <li><?= $item ?></li>
</ul>
<div s:empty>No items found</div>
```

```sugar [Loop metadata]
<ul s:forelse="$items as $item">
    <li s:class="['odd' => $loop->odd, 'even' => $loop->even]">
        <?= $item ?> (<?= $loop->iteration ?>)
    </li>
</ul>
<div s:empty>No items found</div>
```

```html [Rendered when empty]
<!-- $items = [] -->
<div>No items found</div>
```
:::

### s:while

Repeat the element while a condition remains true.

::: code-group
```sugar [Attribute]
<div s:while="$poller->hasNext()">
    <?= $poller->next() ?>
</div>
```

```sugar [Element]
<s-while condition="$poller->hasNext()">
    <?= $poller->next() ?>
</s-while>
```
:::

### s:times

Repeat the element a fixed number of times.

::: code-group
```sugar [Basic]
<span s:times="3">*</span>
```

```sugar [With index]
<span s:times="5 as $i">#<?= $i ?></span>
```

```sugar [Element — basic]
<s-times count="3"><span>*</span></s-times>
```

```sugar [Element — with index]
<s-times count="5 as $i"><span>#<?= $i ?></span></s-times>
```
:::

### s:cache

Cache a fragment's rendered output using a configured PSR-16 cache store.

`s:cache` is opt-in at engine setup time. Register the optional FragmentCache extension:

```php
use Sugar\Core\Engine;
use Sugar\Extension\FragmentCache\FragmentCacheExtension;

$cache = new YourPsr16CacheStore(); // must implement Psr\SimpleCache\CacheInterface

$engine = Engine::builder()
    ->withTemplateLoader($loader)
    ->withExtension(new FragmentCacheExtension($cache, defaultTtl: 300))
    ->build();
```

Directive forms:

- `s:cache` - auto key, default TTL from `new FragmentCacheExtension(..., defaultTtl: ...)`
- `s:cache="'users-' . $userId"` - explicit key, default TTL
- `s:cache="['key' => 'users-' . $userId, 'ttl' => 60]"` - explicit key + per-fragment TTL override

::: code-group
```sugar [Auto key]
<section s:cache>
    <h2>Popular items</h2>
    <?= $expensiveHtml ?>
</section>
```

```sugar [Explicit key]
<section s:cache="'users-' . $userId">
    <?= $userCardHtml ?>
</section>
```

```sugar [Key + TTL override]
<section s:cache="['key' => 'users-' . $userId, 'ttl' => 120]">
    <?= $userCardHtml ?>
</section>
```
:::

If no fragment cache store is configured, `s:cache` is treated as a no-op wrapper and content still renders.

The element form uses a `key` attribute instead of the directive expression. Omitting `key` uses the same auto-key behaviour as bare `s:cache`:

```sugar
<s-cache key="'sidebar'">
    <nav>...</nav>
</s-cache>

<!-- Auto key -->
<s-cache>
    <section>...</section>
</s-cache>
```

### s:switch

Choose between `s:case` and `s:default` children based on a value.

::: code-group
```sugar [Case]
<div s:switch="$role">
    <span s:case="'admin'">Administrator</span>
    <span s:default>User</span>
</div>
```

```sugar [Multiple cases]
<div s:switch="$status">
    <span s:case="'open'">Open</span>
    <span s:case="'closed'">Closed</span>
    <span s:default>Unknown</span>
</div>
```

```sugar [Role switch]
<div s:switch="$role">
    <span s:case="'admin'">Administrator</span>
    <span s:case="'moderator'">Moderator</span>
    <span s:default>User</span>
</div>
```
:::

### s:ifcontent

Render the wrapper only when it would contain output.

```sugar
<div s:ifcontent class="card">
    <?php if ($showContent): ?>
        <p>Some content here</p>
    <?php endif; ?>
</div>
```

### s:try / s:finally

Wrap output in a `try` block with an optional `finally` sibling. There is no `s:catch` directive; if `s:finally` is omitted, Sugar emits a catch that returns `null` to keep the PHP valid and silently stop output on errors.

::: code-group
```sugar [Attribute]
<div s:try>
    <?= $content ?>
</div>
<div s:finally>
    <?php $logger->flush(); ?>
</div>
```

```sugar [Element]
<s-try>
    <?= $content ?>
</s-try>
<div s:finally>
    <?php $logger->flush(); ?>
</div>
```
:::
