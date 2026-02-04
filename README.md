
[![Build Status](https://github.com/josbeir/sugar/actions/workflows/ci.yml/badge.svg)](https://github.com/josbeir/sugar/actions)
[![PHPStan Level 8](https://img.shields.io/badge/PHPStan-level%208-brightgreen)](https://github.com/josbeir/sugar)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/php-8.2%2B-blue.svg)](https://www.php.net/releases/8.2/en.php)
[![codecov](https://codecov.io/github/josbeir/sugar/graph/badge.svg?token=4VGWJQTWH5)](https://codecov.io/github/josbeir/sugar)

# 🍬 Sugar

> PHP templates that don't suck. Write `s:if`, get auto-escaping. Zero magic, pure sweetness.

> [!WARNING]
> **Work in Progress** - Sugar is under active development. Core features are stable, but the API may change before 1.0.

## Table of Contents

- [What is Sugar?](#what-is-sugar)
  - [Before & After](#before--after)
  - [More Examples](#more-examples)
    - [PHP Pipe Syntax](#php-pipe-syntax)
    - [Context-Aware Escaping](#context-aware-escaping)
    - [Loop Metadata](#loop-metadata)
    - [Switch Statements](#switch-statements)
    - [Output Directives](#output-directives)
    - [Reusable Components](#reusable-components)
    - [Standalone Variable Checks](#standalone-variable-checks)
- [Why Sugar?](#why-sugar)
- [Features](#features)
  - [Directives](#directives)
  - [Pipe Syntax](#pipe-syntax)
  - [Context-Aware Escaping](#context-aware-escaping-1)
  - [Loop Metadata](#loop-metadata-1)
  - [Fragment Elements (`<s-template>`)](#fragment-elements-s-template)
  - [Template Inheritance & Composition](#template-inheritance--composition)
  - [Components](#components)
- [Quick Start](#quick-start)
  - [Configuration](#configuration)
- [Architecture](#architecture)
- [Debug Mode](#debug-mode)
- [Roadmap](#roadmap)
- [Contributing](#contributing)
- [Requirements](#requirements)
- [License](#license)

## What is Sugar?

Sugar is a modern PHP (8.2+) templating engine that **compiles to pure, optimized PHP code**. It takes your existing PHP templates and supercharges them with:

- **Clean `s:directive` syntax** for control structures (no more `<?php if/foreach/endforeach ?>`  clutter)
- **Automatic context-aware XSS escaping** - Sugar detects whether you're in HTML, JavaScript, CSS, or URLs and applies the right escaping automatically
- **Enhanced features** like loop metadata (`$loop->first`, `$loop->count`), conditional CSS classes, and empty state fallbacks
- **100% backwards compatible** - Your existing `<?= $var ?>` syntax still works. Incrementally adopt Sugar's features as you need them.

The best part? **Zero runtime overhead**. Sugar compiles once to pure PHP, then opcache takes over for maximum performance.

### Before & After

**Your existing PHP template:**
```php
<?php if (!empty($users)): ?>
    <?php foreach ($users as $user): ?>
        <div class="<?= $user->isAdmin() ? 'admin' : 'user' ?>">
            <?= htmlspecialchars($user->name, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <p>No users found</p>
<?php endif; ?>
```

**Converted to Sugar:**
```html
<!-- Mix Sugar directives with regular PHP - both work together! -->
<div s:forelse="$users as $user" s:class="['admin' => $user->isAdmin(), 'user' => !$user->isAdmin()]">
    <?= $user->name ?>
    <small s:if="$user->email"><?= $user->email ?></small>
</div>
<div s:empty>No users found</div>
```

**Compiles to optimized PHP:**
```php
<?php if (!empty($users)): ?>
    <?php foreach ($users as $user): ?>
        <div class="<?= \Sugar\Runtime\HtmlAttributeHelper::classNames(['admin' => $user->isAdmin(), 'user' => !$user->isAdmin()]) ?>">
            <?= htmlspecialchars((string)($user->name), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>
            <?php if ($user->email): ?>
                <small><?= htmlspecialchars((string)($user->email), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></small>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <div>No users found</div>
<?php endif; ?>
```

Notice:
- ✅ **XSS protection added automatically** - `htmlspecialchars()` is inserted by the compiler
- ✅ **Cleaner markup** - No PHP noise in your HTML
- ✅ **Type-safe** - Proper flags and encoding for modern HTML5
- ✅ **Still pure PHP** - Can be cached, debugged, and profiled like any PHP file
- ✅ **Mix and match** - Regular `<?php if/foreach ?>` and Sugar directives work together seamlessly

### More Examples

#### PHP Pipe Syntax

> **Note:** Use modern PHP 8.5 pipe syntax even on PHP 8.2+! Sugar compiles pipes to standard function calls at compile time.

```html
<h1><?= $title |> strtoupper(...) |> substr(..., 0, 50) ?></h1>
<p><?= $product->price |> number_format(..., 2) ?></p>
<div><?= $text |> strip_tags(...) |> trim(...) ?></div>
```

Compiles to nested function calls with auto-escaping preserved:

```php
<h1><?= htmlspecialchars((string)(substr(strtoupper($title), 0, 50)), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></h1>
<p><?= htmlspecialchars((string)(number_format($product->price, 2)), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></p>
<div><?= htmlspecialchars((string)(trim(strip_tags($text))), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div>
```

#### Context-Aware Escaping

Sugar automatically applies the right escaping based on where your output appears. Regular PHP logic works normally:

Sugar template:
```html
<div data-user="<?= $name ?>" s:if="$isActive">
    <?php $displayName = strtoupper($name); // Regular PHP works fine ?>
    <span><?= $displayName ?></span>
</div>
<script>const user = <?= $userData ?>;</script>
<style>.user::before { content: '<?= $prefix ?>'; }</style>
<a href="/search?q=<?= $query ?>">Search</a>
```

Compiled Output:
```php
<?php if ($isActive): ?>
    <div data-user="<?= htmlspecialchars($name, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
        <?php $displayName = strtoupper($name); // Regular PHP works fine ?>
        <span><?= htmlspecialchars($displayName, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></span>
    </div>
<?php endif; ?>
<script>const user = <?= json_encode($userData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<style>.user::before { content: '<?= \Sugar\Escape\Escaper::escapeCss($prefix) ?>'; }</style>
<a href="/search?q=<?= rawurlencode($query) ?>">Search</a>
```

#### Loop Metadata

Access iteration information without manual counters:

Sugar template:
```html
<ul s:foreach="$items as $item">
    <li s:class="['first' => $loop->first, 'last' => $loop->last, 'odd' => $loop->odd]">
        <?= $item ?> (<?= $loop->iteration ?> of <?= $loop->count ?>)
    </li>
</ul>
```

Compiled Output:
```php
<?php
$__loopStack = [];
$__loopStack[] = $loop = new \Sugar\Runtime\LoopMetadata($items);
foreach ($items as $item): ?>
    <li class="<?= \Sugar\Runtime\HtmlAttributeHelper::classNames(['first' => $loop->first, 'last' => $loop->last, 'odd' => $loop->odd]) ?>">
        <?= htmlspecialchars($item, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?> (<?= htmlspecialchars($loop->iteration, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?> of <?= htmlspecialchars($loop->count, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>)
    </li>
    <?php $loop->next(); ?>
<?php endforeach; ?>
<?php $loop = array_pop($__loopStack); ?>
```

#### Switch Statements

Sugar template:
```html
<div s:switch="$role">
    <span s:case="'admin'" class="badge-red">Administrator</span>
    <span s:case="'moderator'" class="badge-blue">Moderator</span>
    <span s:default class="badge-gray">User</span>
</div>
```

Compiled Output:
```php
<div>
    <?php switch ($role): ?>
        <?php case 'admin': ?>
            <span class="badge-red"><?= htmlspecialchars('Administrator', ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></span>
            <?php break; ?>
        <?php case 'moderator': ?>
            <span class="badge-blue"><?= htmlspecialchars('Moderator', ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></span>
            <?php break; ?>
        <?php default: ?>
            <span class="badge-gray"><?= htmlspecialchars('User', ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></span>
    <?php endswitch; ?>
</div>
```

#### Output Directives

Use `s:text` and `s:html` for explicit output control:

```html
<!-- s:text - Escaped output (same as <?= ?>) -->
<div s:text="$userName"></div>
<!-- Compiles to: -->
<div><?= htmlspecialchars((string)($userName), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div>

<!-- s:html - Unescaped output for trusted HTML -->
<div s:html="$article->renderedContent"></div>
<!-- Compiles to: -->
<div><?= $article->renderedContent ?></div>
```

> [!WARNING]
> **`s:html` Security**: Only use with trusted content you control. Never use with user input as it bypasses XSS protection!

#### Reusable Components

Create reusable UI components with named slots and automatic attribute merging:

Component definition (`components/s-card.sugar.php`):
```html
<div class="card" s:class="['card-featured' => $featured ?? false]">
    <div class="card-header" s:if="isset($header)">
        <?= $header ?>
    </div>
    <div class="card-body">
        <?= $slot ?>
    </div>
    <div class="card-footer" s:if="isset($footer)">
        <?= $footer ?>
    </div>
</div>
```

Usage:
```html
<s-card s-bind:featured="true" class="shadow-lg" data-id="123">
    <h3 s:slot="header">Product Title</h3>

    <p>This is the main card content in the default slot.</p>

    <button s:slot="footer">Learn More</button>
</s-card>
```

Compiled Output:
```php
<?php (function($__vars) { extract($__vars); ?>
    <div class="card shadow-lg" data-id="123" class="<?= \Sugar\Runtime\HtmlAttributeHelper::classNames(['card-featured' => $featured ?? false]) ?>">
        <?php if (isset($header)): ?>
            <div class="card-header">
                <h3><?= htmlspecialchars('Product Title', ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></h3>
            </div>
        <?php endif; ?>
        <div class="card-body">
            <p><?= htmlspecialchars('This is the main card content in the default slot.', ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></p>
        </div>
        <?php if (isset($footer)): ?>
            <div class="card-footer">
                <button><?= htmlspecialchars('Learn More', ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></button>
            </div>
        <?php endif; ?>
    </div>
<?php })(['featured' => true, 'header' => '<h3>Product Title</h3>', 'footer' => '<button>Learn More</button>', 'slot' => '<p>This is the main card content in the default slot.</p>']); ?>
```

Notice:
- ✅ **Named slots** - Organize component content with `<s-slot:name>`
- ✅ **Attribute merging** - Component's `class` attribute merges with usage's `class`, other attributes are passed through
- ✅ **Scoped variables** - Component props are isolated in a closure
- ✅ **No raw() needed** - Slot content is automatically safe from double-escaping

#### Standalone Variable Checks

Use `s:empty` and `s:isset` to conditionally render content based on variable state:

```html
<!-- s:empty - Render when variable is empty -->
<div s:empty="$cart">Your cart is empty</div>
<!-- Compiles to: -->
<?php if (empty($cart)): ?>
<div>Your cart is empty</div>
<?php endif; ?>

<!-- s:isset - Render when variable is set -->
<div s:isset="$user">Welcome back!</div>
<!-- Compiles to: -->
<?php if (isset($user)): ?>
<div>Welcome back!</div>
<?php endif; ?>
```

## Why Sugar?

- **🎯 Clean Syntax** - `s:if`, `s:foreach`, `s:switch` directives that feel natural in HTML
- **🛡️ Auto-Escaping** - Context-aware escaping (HTML/JS/CSS/URL) defeats XSS automatically
- **⚡ Pure PHP** - Compiles to optimized PHP code with opcache support
- **🔧 Framework-Agnostic** - Use standalone or integrate with CakePHP, Laravel, Symfony
- **📦 Zero Dependencies** - Core engine has no external requirements (except brick/varexporter)
- **🧪 Battle-Tested** - 360 tests, PHPStan level 8, 95%+ code coverage
- **🎨 Fragment Elements** - `<s-template>` for applying directives without wrapper elements

## Features

### Directives

Sugar provides familiar control structures as HTML attributes:

#### Control Flow
- **`s:if` / `s:elseif` / `s:else`** - Conditional rendering
- **`s:foreach` / `s:forelse` / `s:empty`** - Iteration with empty fallbacks (`s:empty` can also be used standalone)
- **`s:while`** - Loop structures
- **`s:switch` / `s:case` / `s:default`** - Switch statements
- **`s:unless`** - Inverse conditionals
- **`s:isset` / `s:empty`** - Variable checks (standalone usage)

#### Output Directives
- **`s:text`** - Escaped output (alternative to `<?= ?>`)
- **`s:html`** - Raw/unescaped output for trusted HTML (use with caution)

#### Template Composition
- **`s:extends`** - Extend a parent template layout
- **`s:block`** - Define named content blocks that can be overridden
- **`s:include`** - Include other templates (extension-less paths supported)
- **`s:with`** - Pass variables to included templates with isolated scope

#### Special Elements
- **`<s-template>`** - Fragment element that renders only children (no wrapper element)

#### Utility Directives
- **`s:class`** - Dynamic CSS classes with conditional arrays
- **`s:spread`** - Spread attributes from arrays

### Pipe Syntax

Use PHP 8.5 pipe operator syntax (`|>`) in output expressions - works on PHP 8.2+ through compile-time transformation:

```html
<?= $name |> strtoupper(...) |> substr(..., 0, 10) ?>
```

- **PHP 8.5 syntax** - Use modern pipe operators today
- **Backwards compatible** - Compiles to nested function calls for PHP 8.2+
- **Zero overhead** - Pure compile-time transformation
- **Auto-escaping preserved** - Works seamlessly with context detection
- **Works everywhere** - Output tags (`<?= ?>`), directives, all contexts

### Context-Aware Escaping

Sugar automatically detects output context and applies appropriate escaping:

```html
<div data-user="<?= $name ?>">        <!-- HTML context: htmlspecialchars() -->
<script>const user = '<?= $name ?>';  <!-- JS context: json_encode() -->
<style>.user::before { content: '<?= $name ?>'; }  <!-- CSS context: CSS escaping -->
<a href="?name=<?= $name ?>">         <!-- URL context: rawurlencode() -->
```

#### Disabling Auto-Escaping (Raw Output)

When you need to output trusted HTML or pre-encoded content, use `raw()` or its short alias `r()`:

```php
<!-- Regular output (auto-escaped) -->
<div><?= $userInput ?></div>
<!-- Output: <div>&lt;script&gt;alert('xss')&lt;/script&gt;</div> -->

<!-- Raw output for trusted content -->
<div><?= raw($article->renderedBody) ?></div>
<!-- Output: <div><p>Article content...</p></div> -->

<!-- Short form -->
<div><?= r($trustedHtml) ?></div>

<!-- Works with complex expressions -->
<div><?= raw($cms->renderBlock('hero')) ?></div>
```

**Note:** `raw()` and `r()` only work with **shorthand echo syntax** `<?= ?>`. If you're using long-form PHP blocks `<?php echo ?>`, you're already writing raw PHP, so just omit the function:

```php
<!-- ✅ Shorthand - parser unwraps raw() -->
<?= raw($html) ?>

<!-- ❌ Long-form - raw() stays as function call (use runtime function) -->
<?php echo raw($html); ?>

<!-- ✅ Long-form - just omit raw() entirely -->
<?php echo $html; ?>  // Already raw, no auto-escaping in <?php ?> blocks
```

> [!WARNING]
> **Security Notice**: Only use `raw()` or `r()` with trusted content you control. Never use with user input as it bypasses XSS protection and creates security vulnerabilities.

```php
// ✅ SAFE: Content you control
<?= raw($article->renderedMarkdown) ?>

// ❌ DANGEROUS: User input (XSS vulnerability!)
<?= raw($_GET['comment']) ?>

// ✅ SAFE: Let auto-escaping protect you
<?= $_GET['comment'] ?>
```

The parser detects `raw()` and `r()` at compile-time and unwraps them, so there's zero runtime overhead.

### Loop Metadata

Access loop information with the `$loop` variable (inspired by Blade):

```html
<ul s:foreach="$items as $item">
    <li s:class="['first' => $loop->first, 'last' => $loop->last]">
        Item <?= $loop->iteration ?> of <?= $loop->count ?>
    </li>
</ul>
```

### Fragment Elements (`<s-template>`)

Sometimes you need to apply directives without adding an extra wrapper element. Use `<s-template>` - a phantom element that renders only its children:

#### Problem: Unwanted Wrapper

```html
<!-- ❌ Adds an extra <div> wrapper -->
<div class="container">
    <div s:foreach="$items as $item">
        <span><?= $item ?></span>
    </div>
</div>

<!-- Output has unwanted nested div -->
<div class="container">
    <div>
        <span>A</span>
        <span>B</span>
    </div>
</div>
```

#### Solution: Fragment Element

```html
<!-- ✅ No extra wrapper - renders children directly -->
<div class="container">
    <s-template s:foreach="$items as $item">
        <span><?= $item ?></span>
    </s-template>
</div>

<!-- Output: spans render directly in container -->
<div class="container">
    <span>A</span>
    <span>B</span>
</div>
```

#### Common Use Cases

**Conditional Multiple Elements:**
```html
<s-template s:if="$showHeader">
    <header>Header</header>
    <nav>Navigation</nav>
</s-template>
```

**Loops Without Wrappers:**
```html
<table>
    <s-template s:foreach="$rows as $row">
        <tr><td><?= $row ?></td></tr>
    </s-template>
</table>
```

**Nested Fragments:**
```html
<s-template s:if="$condition">
    <s-template s:foreach="$items as $item">
        <div><?= $item ?></div>
    </s-template>
</s-template>
```

**Restrictions:**
- `<s-template>` can **only** have `s:` directive attributes
- Regular HTML attributes are **not allowed** (throws compile error)
- Attribute directives like `s:class`, `s:spread` are **not allowed** (no element to apply them to)
- Only control flow (`s:if`, `s:foreach`) and content directives (`s:text`, `s:html`) are permitted

### Template Inheritance & Composition

Build reusable layouts with template inheritance, similar to Blade or Twig:

#### Basic Layout Inheritance

**Base Layout** (`layouts/base.sugar.php`):
```html
<!DOCTYPE html>
<html>
<head>
    <title s:block="title">Default Title</title>
</head>
<body>
    <header s:block="header">
        <h1>My Site</h1>
    </header>

    <main s:block="content">
        Default content
    </main>

    <footer s:block="footer">
        &copy; 2026
    </footer>
</body>
</html>
```

**Child Template** (`pages/home.sugar.php`):
```html
<div s:extends="../layouts/base.sugar.php"></div>

<title s:block="title">Home Page</title>

<div s:block="content">
    <h2>Welcome!</h2>
    <p>This is the home page content.</p>
</div>
```

**Compiled Result**:
```html
<!DOCTYPE html>
<html>
<head>
    <title>Home Page</title>
</head>
<body>
    <header>
        <h1>My Site</h1>
    </header>

    <main>
        <h2>Welcome!</h2>
        <p>This is the home page content.</p>
    </main>

    <footer>
        &copy; 2026
    </footer>
</body>
</html>
```

#### Multi-Level Inheritance

Templates can extend templates that extend other templates:

```html
<!-- layouts/master.sugar.php -->
<html>
    <title s:block="title">Master</title>
    <body s:block="body">Master body</body>
</html>

<!-- layouts/app.sugar.php -->
<div s:extends="master.sugar.php"></div>
<title s:block="title">App Layout</title>

<!-- pages/profile.sugar.php -->
<div s:extends="../layouts/app.sugar.php"></div>
<title s:block="title">User Profile</title>
```

#### Template Includes

Include reusable template fragments with `s:include`:

```html
<!-- partials/header.sugar.php -->
<header>
    <h1><?= $title ?></h1>
</header>

<!-- pages/home.sugar.php -->
<div s:include="partials/header"></div>
<main>Page content here</main>
```

**Extension-less paths supported**: You can write `s:include="partials/header"` instead of `s:include="partials/header.sugar.php"` - Sugar automatically tries both.

#### Isolated Variable Scope with `s:with`

Pass specific variables to included templates for better encapsulation:

```html
<!-- partials/user-card.sugar.php -->
<div class="card">
    <h3><?= $name ?></h3>
    <p><?= $email ?></p>
</div>

<!-- pages/team.sugar.php -->
<div s:foreach="$users as $user">
    <div s:include="partials/user-card" s:with="['name' => $user->name, 'email' => $user->email]"></div>
</div>
```

The `s:with` directive creates an isolated scope where only the specified variables are available to the included template.

### Components

Create reusable UI components with slots and attribute merging. Components are templates that accept props and automatically merge HTML/framework attributes to their root element.

#### Basic Component Usage

**Define Component** (`components/button.sugar.php`):
```html
<button class="btn" type="button">
    <?= $slot ?>
</button>
```

**Use Component**:
```html
<s-button>Click Me</s-button>
```

**Compiled Output**:
```html
<button class="btn" type="button">
    Click Me
</button>
```

#### Component Props with `s-bind:`

Pass data to components as props using the `s-bind:` prefix. Props become variables inside the component scope.

**Component** (`components/alert.sugar.php`):
```html
<div class="<?= $class ?? 'alert alert-info' ?>">
    <strong s:if="$title"><?= $title ?></strong>
    <?= $slot ?>
</div>
```

**Usage**:
```html
<!-- String literals need inner quotes (like Vue/Alpine) -->
<s-alert s-bind:class="'alert alert-success'" s-bind:title="'Well done!'">
    Your changes have been saved.
</s-alert>

<!-- Pass variables without quotes -->
<s-alert s-bind:class="$alertClass" s-bind:title="$message">
    <?= $content ?>
</s-alert>

<!-- Pass expressions -->
<s-alert s-bind:class="'alert alert-' . ($hasError ? 'danger' : 'success')">
    Operation complete
</s-alert>
```

**Compiled Output**:
```php
<?php (function($__vars) { extract($__vars); ?><div class="alert alert-info">
    <strong><?php echo htmlspecialchars((string)($title ?? 'Notice'), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></strong>
    <?php echo $slot; ?></div>
<?php })(['title' => 'Well done!', 'slot' => 'Your changes have been saved.']); ?>
```

> **Note:** The `s-bind:` syntax treats values as **PHP expressions**. String literals require inner quotes (`"'success'"`), similar to Vue's `:prop` or Alpine's `x-bind:`. Pass variables directly without quotes (`"$var"`). Slot content is pre-rendered HTML and should be output with `raw()` in your component templates to avoid double-escaping.

**Compiled Output**:
```html
<div class="alert alert-success">
    <strong>Well done!</strong>
    Your changes have been saved.
</div>
```

#### Attribute Merging

HTML attributes and framework directives automatically merge to the component's root element. This enables seamless integration with Alpine.js, Vue, HTMX, and other frameworks:

**Component** (`components/card.sugar.php`):
```html
<div class="card">
    <h3><?= $title ?></h3>
    <div class="card-body"><?= $slot ?></div>
</div>
```

**Usage with HTML & Framework Attributes**:
```html
<s-card
    s-bind:title="'User Profile'"
    class="shadow-lg"
    id="profile-card"
    @click="handleClick"
    x-data="{ open: false }">
    Profile content here
</s-card>
```

**Compiled Output**:
```html
<div class="card shadow-lg" id="profile-card" @click="handleClick" x-data="{ open: false }">
    <h3>User Profile</h3>
    <div class="card-body">Profile content here</div>
</div>
```

#### Special Class Handling

The `class` attribute **appends** instead of replacing, allowing component styles to combine with usage styles:

**Component** (`components/badge.sugar.php`):
```html
<span class="badge"><?= $slot ?></span>
```

**Usage**:
```html
<s-badge class="badge-lg badge-primary">Admin</s-badge>
```

**Compiled Output**:
```html
<span class="badge badge-lg badge-primary">Admin</span>
```

#### Named Slots

Components support multiple named slots using the `s:slot` attribute. This allows you to pass different content to specific areas of your component:

**Component** (`components/card.sugar.php`):
```html
<div class="card">
    <div class="card-header"><?= $header ?></div>
    <div class="card-body"><?= $slot ?></div>
    <div class="card-footer" s:if="isset($footer)"><?= $footer ?></div>
</div>
```

**Usage**:
```html
<s-card>
    <!-- Single element slot -->
    <h3 s:slot="header">User Profile</h3>

    <!-- Multiple elements slot - use s-template to avoid wrapper -->
    <s-template s:slot="footer">
        <button class="btn-secondary">Cancel</button>
        <button class="btn-primary">Save Changes</button>
    </s-template>

    <p>This is the main content in the default slot.</p>
</s-card>
```

**Compiled Output**:
```html
<div class="card">
    <div class="card-header"><h3>User Profile</h3></div>
    <div class="card-body"><p>This is the main content in the default slot.</p></div>
    <div class="card-footer">
        <button class="btn-secondary">Cancel</button>
        <button class="btn-primary">Save Changes</button>
    </div>
</div>
```

**Key Points**:
- Elements with `s:slot="name"` are passed to the named slot variable `$name`
- Elements without `s:slot` go to the default `$slot`
- Use `<s-template s:slot="name">` to pass multiple elements without a wrapper
- Named slots are optional - check with `isset($slotName)` in your component
- The `s:slot` attribute is removed from the final output
You can use Sugar directives on component invocations:

```html
<!-- Conditional components -->
<s-alert s:if="$hasErrors" s-bind:type="'error'">
    <?= $errorMessage ?>
</s-alert>

<!-- Components in loops -->
<s-badge s:foreach="$tags as $tag" class="mx-1">
    <?= $tag ?>
</s-badge>

<!-- Dynamic classes -->
<s-button s:class="['btn-primary' => $isPrimary, 'btn-disabled' => $disabled]">
    Submit
</s-button>
```

#### Framework Integration

Components work seamlessly with attribute-based frameworks:

**Alpine.js**:
```html
<s-modal s-bind:title="'Confirm'" x-data="{ open: false }" @close="open = false">
    Are you sure?
</s-modal>
```

**Vue**:
```html
<s-dropdown s-bind:items="$menuItems" v-model="selectedItem" @change="handleChange">
    Select option
</s-dropdown>
```

**HTMX**:
```html
<s-form s-bind:action="'/api/users'" hx-post="/api/users" hx-target="#result">
    Form content
</s-form>
```

#### Component Best Practices

✅ **DO:**
- Use `s-bind:` for component-specific data (props)
- Use regular attributes for HTML/framework features
- Keep component templates simple with single root element
- Use descriptive prop names (`s-bind:type`, not `s-bind:t`)
- Output slot variables directly: `<?= $slot ?>` - Sugar automatically disables escaping for slots

❌ **DON'T:**
- Don't use `s-bind:` for HTML attributes like `class`, `id`, `data-*` (just use them directly)
- Don't add control flow directives (`s:if`, `s:foreach`) to component root element (they won't be seen by consumers)
- Avoid multiple root elements in components (first one wins for attribute merging)

**Note on Slots:**
Slot content is pre-rendered HTML from component usage. Sugar's ComponentExpansionPass automatically marks slot variables (`$slot`, `$header`, `$footer`, etc.) as safe, so you don't need to use `raw()`. The compiled output will skip escaping for these variables.

## Quick Start

```bash
composer require cakephp/sugar
```

```php
use Sugar\Compiler;
use Sugar\Parser\Parser;
use Sugar\Pass\ContextAnalysisPass;
use Sugar\Escape\Escaper;
use Sugar\TemplateInheritance\FileTemplateLoader;

// Basic compiler
$compiler = new Compiler(
    new Parser(),
    new ContextAnalysisPass(),
    new Escaper()
);

// With template inheritance support
$loader = new FileTemplateLoader(__DIR__ . '/templates');
$compiler = new Compiler(
    new Parser(),
    new ContextAnalysisPass(),
    new Escaper(),
    templateLoader: $loader
);

$compiled = $compiler->compile('<div s:if="$show"><?= $message ?></div>');
```

### Configuration

#### Custom Directive Prefix

By default, Sugar uses the `s:` prefix for directives (e.g., `s:if`, `s:foreach`). You can customize this to match your preferences or integrate with other frameworks:

```php
use Sugar\Config\SugarConfig;

// Option 1: Using named constructor
$config = SugarConfig::withPrefix('x');

// Option 2: Explicit configuration
$config = new SugarConfig(
    directivePrefix: 'v',
    fragmentElement: 'v-fragment'  // Optional, defaults to "{prefix}-template"
);

// Pass config to Parser and Compiler
$compiler = new Compiler(
    parser: new Parser($config),
    contextPass: new ContextAnalysisPass(),
    escaper: new Escaper(),
    config: $config
);

// Now use your custom prefix
$template = '<div x:if="$show">Hello</div>';  // Using 'x' prefix
$compiled = $compiler->compile($template);
```

**Common prefix conventions:**
- `s:` (default) - Sugar's default namespace
- `x:` - Inspired by JSX conventions
- `v:` - Vue.js style directives
- `tw:` - Tailwind-style naming

**Benefits:**
- ✅ Avoid conflicts with other attribute-based frameworks
- ✅ Match your team's naming conventions
- ✅ Support multiple template styles in the same project
- ✅ Backward compatible - defaults to `s:` prefix

## Architecture

Sugar follows a clear compilation pipeline:

1. **Parser** - Converts template source to an Abstract Syntax Tree (AST)
2. **DirectiveExtractionPass** - Extracts `s:*` attributes into directive nodes
3. **DirectiveCompilationPass** - Compiles directives to PHP control structures
4. **ContextAnalysisPass** - Detects output contexts (HTML/JS/CSS/URL)
5. **CodeGenerator** - Generates optimized PHP code with inline escaping

The compiled output is pure PHP that can be cached and executed with opcache for maximum performance.

## Debug Mode

Enable debug mode to add source location comments to compiled templates:

```php
$compiled = $compiler->compile(
    $source,
    debug: true,
    sourceFile: 'templates/user/profile.sugar.php'
);
```

Output includes helpful comments:
```php
<?php if ($user): /* L1:C0 */ ?>
<div> <!-- L2:C4 -->
    <?php echo htmlspecialchars($name); /* L2:C14 s:text */ ?>
</div>
```

## Roadmap

### Core Features
- [x] **Template Inheritance** - Layouts, blocks, extends, and includes (✅ Completed)
- [x] **Custom Components** - Reusable template components with slots, props, and attribute merging (✅ Completed)
- [ ] **Custom Filter Functions** - Pipe data through custom transformations
- [ ] **Pipe Operator Support** - Use PHP 8.5 pipe operators (`<?= $data |> strtoupper |> trim ?>`)

### Developer Experience
- [ ] **Source Maps** - Map compiled PHP errors back to original Sugar templates
- [ ] **Debug Toolbar Integration** - Show compilation stats and context detection

## Contributing

Sugar is actively developed and welcomes contributions! Check out the issues or submit a PR.

## Requirements

- **PHP 8.2+** (tested on 8.2, 8.3, 8.4, 8.5)
- Composer

**Note:** Sugar uses PHP 8.2+ features (readonly classes, enums) but compiles templates that work on PHP 8.2+. The pipe syntax (`|>`) is a compile-time feature - you can use PHP 8.5 syntax even on PHP 8.2!

## License

MIT License - see LICENSE file for details
