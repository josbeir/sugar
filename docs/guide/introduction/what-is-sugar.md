---
title: What Is Sugar
description: Overview, feature comparison, and a before-and-after example.
---

# What Is Sugar

Sugar is a modern PHP (8.2+) templating engine that compiles to pure, optimized PHP code. Sugar templates are still PHP templates at their core, just with a handful of extra superpowers layered in.

Think of it as syntactic PHP: you write HTML and PHP like you always have, but you also get `s:` directives, safe output, and composable helpers without a separate template language.

Sugar supercharges existing PHP templates with:

- Clean `s:directive` syntax for control structures.
- Context-aware XSS escaping for HTML, JavaScript, CSS, and URLs.
- Loop metadata, conditional classes, and empty state fallbacks.
- Full compatibility with existing `<?= $var ?>` output.

The best part? Zero runtime overhead. Sugar compiles once to pure PHP, then opcache takes over for maximum performance.

<!-- @include: _partials/comparison.md -->

## Before and After

::: code-group
```html [Sugar template]
<div
    s:forelse="$users as $user"
    s:class="[
        'user-card',
        $user->isAdmin() ? 'admin' : 'user',
        'online' => $user->isOnline(),
        'no-email' => empty($user->email),
    ]"
>
    <?= $user->name ?>
    <small s:if="$user->email"><?= $user->email ?></small>
</div>
<div s:empty>No users found</div>
```

```php [Existing PHP template]
<?php if (!empty($users)): ?>
    <?php foreach ($users as $user): ?>
        <div class="<?= implode(' ', array_filter([
            'user-card',
            $user->isAdmin() ? 'admin' : 'user',
            $user->isOnline() ? 'online' : null,
            empty($user->email) ? 'no-email' : null,
        ])) ?>">
            <?= htmlspecialchars($user->name, ENT_QUOTES, 'UTF-8') ?>
            <?php if ($user->email): ?>
                <small><?= htmlspecialchars($user->email, ENT_QUOTES, 'UTF-8') ?></small>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <p>No users found</p>
<?php endif; ?>
```

```php [Compiled PHP]
<?php if (!\Sugar\Core\Runtime\EmptyHelper::isEmpty($users)): ?>
    <?php foreach ($users as $user): ?>
        <div class="<?= \Sugar\Core\Runtime\HtmlAttributeHelper::classNames([
            'user-card',
            $user->isAdmin() ? 'admin' : 'user',
            'online' => $user->isOnline(),
            'no-email' => empty($user->email),
        ]) ?>">
            <?= \Sugar\Core\Escape\Escaper::html($user->name) ?>
            <?php if ($user->email): ?>
                <small><?= \Sugar\Core\Escape\Escaper::html($user->email) ?></small>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <div>No users found</div>
<?php endif; ?>
```
:::

## Why Sugar

| Benefit | Why it matters |
| --- | --- |
| `s:` attributes keep templates readable | Control flow and output stay close to the HTML. |
| Context-aware escaping by default | XSS protection across HTML, attribute, URL, JS, and CSS contexts. |
| Pure PHP output | Fast execution and debuggable stack traces. |
| Scope isolation | Prevents accidental variable leakage between templates. |
| Still PHP, so IDEs play nice | Native syntax highlighting, navigation, and refactors work out of the box. (at least for code within PHP tags) |
| Zero core dependencies | Easy to embed and audit. |
