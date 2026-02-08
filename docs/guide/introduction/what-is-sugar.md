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

## Feature Comparison

Here is a compact comparison with the most common decision points:

| Area | Sugar | Typical alternatives |
| --- | --- | --- |
| Performance | Opcache + compiled PHP | Compiled templates or helper-based output |
| Auto-escaping | All contexts (HTML, attr, URL, JS, CSS) | Often HTML-only or context-limited |
| PHP interop | Full PHP, no extra language | Mixed (some restrict PHP usage) |
| Scope isolation | Closure-based | Varies by engine |
| Components | `s-` templates, props + slots | Class or macro based |
| Pipes/filters | Native PHP 8.5 &#124;> | Engine-specific filters |

::: details
Full comparison matrix

| Feature | Sugar | Blade | Twig | Latte | Tempest |
|---------|-------|-------|------|-------|---------|
| **Performance** | Opcache | Opcache | Compiled | Compiled | Compiled |
| **Learning Curve** | Native PHP + `s:` attributes | Custom | Python-like | PHP-like | HTML + `:attr` |
| **Parser** | ✅ AST-based | Regex | ✅ AST-based | ✅ AST-based | ✅ AST-based |
| **Auto-Escaping** | ✅ All contexts | HTML only | ✅ On by default | ✅ All contexts | ✅ HTML only |
| **PHP Interop** | ✅ Full | ✅ Full | Limited | ✅ Full | ✅ Full |
| **Scope Isolation** | ✅ Closure | ❌ None | ✅ Yes | ✅ Sandbox | ✅ Component |
| **Inheritance** | ✅ Yes | ✅ Yes | ✅ Yes | ✅ Yes | ✅ Components |
| **Components** | ✅ `s-` files | ✅ Class/File | Macros/Embed | N:attributes | ✅ `x-` files |
| **IDE Support** | Native | Plugins | Plugins | PhpStorm | Native PHP |
| **Security** | ✅ Auto + isolate | Basic HTML | ✅ Sandbox mode | ✅ Context + Sandbox | ✅ Auto HTML |
| **Debugging** | ✅ Native traces | Good | Can be hard | ✅ Tracy plugin | ✅ Native traces |
| **Caching** | ✅ Opcache | ✅ Opcache | File cache | ✅ Opcache | ✅ Compiled |
| **Pipes/Filters** | ✅ PHP 8.5 &#124;> | Helpers | `|upper` | `|upper` | Native funcs |
:::

## Before and After

**Your existing PHP template:**
```php
<?php if (!empty($users)): ?>
    <?php foreach ($users as $user): ?>
        <div class="<?= $user->isAdmin() ? 'admin' : 'user' ?>">
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

**Converted to Sugar:**
```html
<div s:forelse="$users as $user" s:class="['admin' => $user->isAdmin(), 'user' => !$user->isAdmin()]">
    <?= $user->name ?>
    <small s:if="$user->email"><?= $user->email ?></small>
</div>
<div s:empty>No users found</div>
```

**Compiles to optimized PHP:**
```php
<?php if (!\Sugar\Runtime\EmptyHelper::isEmpty($users)): ?>
    <?php foreach ($users as $user): ?>
        <div class="<?= \Sugar\Runtime\HtmlAttributeHelper::classNames(['admin' => $user->isAdmin(), 'user' => !$user->isAdmin()]) ?>">
            <?= \Sugar\Escape\Escaper::html($user->name) ?>
            <?php if ($user->email): ?>
                <small><?= \Sugar\Escape\Escaper::html($user->email) ?></small>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <div>No users found</div>
<?php endif; ?>
```

## Why Sugar

- `s:` attribute syntax keeps templates readable.
- Context-aware escaping defeats XSS by default.
- Pure PHP output is fast and debuggable.
- Scope isolation prevents accidental variable leakage.
- Zero dependencies in the core engine.
