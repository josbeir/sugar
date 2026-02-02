
[![Build Status](https://github.com/josbeir/sugar/actions/workflows/ci.yml/badge.svg)](https://github.com/josbeir/sugar/actions)
[![PHPStan Level 8](https://img.shields.io/badge/PHPStan-level%208-brightgreen)](https://github.com/josbeir/sugar)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/php-8.4%2B-blue.svg)](https://www.php.net/releases/8.2/en.php)
[![codecov](https://codecov.io/github/josbeir/sugar/graph/badge.svg?token=4VGWJQTWH5)](https://codecov.io/github/josbeir/sugar)

# 🍬 Sugar

> PHP templates that don't suck. Write `s:if`, get auto-escaping. Zero magic, pure sweetness.

> [!WARNING]
> **Work in Progress** - Sugar is under active development. Core features are stable, but the API may change before 1.0.

## What is Sugar?

Sugar is a modern PHP (8.4+) templating engine that **compiles to pure, optimized PHP code**. It takes your existing PHP templates and supercharges them with:

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
```php
<div s:forelse="$users as $user" s:class="['admin' => $user->isAdmin(), 'user' => !$user->isAdmin()]">
    <?= $user->name ?>
</div>
<div s:none>No users found</div>
```

**Compiles to optimized PHP:**
```php
<?php if (!empty($users)): ?>
    <?php foreach ($users as $user): ?>
        <div class="<?= \Sugar\Runtime\AttributeHelper::classNames(['admin' => $user->isAdmin(), 'user' => !$user->isAdmin()]) ?>">
            <?= htmlspecialchars((string)($user->name), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>
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

### More Examples

**Context-Aware Escaping**

Sugar automatically applies the right escaping based on where your output appears:

```php
<!-- Sugar Template -->
<div data-user="<?= $name ?>">
<script>const user = <?= $userData ?>;</script>
<style>.user::before { content: '<?= $prefix ?>'; }</style>
<a href="/search?q=<?= $query ?>">Search</a>

<!-- Compiled Output -->
<div data-user="<?= htmlspecialchars($name, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
<script>const user = <?= json_encode($userData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<style>.user::before { content: '<?= \Sugar\Escape\Escaper::escapeCss($prefix) ?>'; }</style>
<a href="/search?q=<?= rawurlencode($query) ?>">Search</a>
```

**Loop Metadata**

Access iteration information without manual counters:

```php
<!-- Sugar Template -->
<ul s:foreach="$items as $item">
    <li s:class="['first' => $loop->first, 'last' => $loop->last, 'odd' => $loop->odd]">
        <?= $item ?> (<?= $loop->iteration ?> of <?= $loop->count ?>)
    </li>
</ul>

<!-- Compiled Output -->
<?php
$__loopStack = [];
$__loopStack[] = $loop = new \Sugar\Runtime\LoopMetadata($items);
foreach ($items as $item): ?>
    <li class="<?= \Sugar\Runtime\AttributeHelper::classNames(['first' => $loop->first, 'last' => $loop->last, 'odd' => $loop->odd]) ?>">
        <?= htmlspecialchars($item, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?> (<?= htmlspecialchars($loop->iteration, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?> of <?= htmlspecialchars($loop->count, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>)
    </li>
    <?php $loop->next(); ?>
<?php endforeach; ?>
<?php $loop = array_pop($__loopStack); ?>
```

**Switch Statements**

```php
<!-- Sugar Template -->
<div s:switch="$role">
    <span s:case="'admin'" class="badge-red">Administrator</span>
    <span s:case="'moderator'" class="badge-blue">Moderator</span>
    <span s:default class="badge-gray">User</span>
</div>

<!-- Compiled Output -->
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


## Why Sugar?

- **🎯 Clean Syntax** - `s:if`, `s:foreach`, `s:switch` directives that feel natural in HTML
- **🛡️ Auto-Escaping** - Context-aware escaping (HTML/JS/CSS/URL) defeats XSS automatically
- **⚡ Pure PHP** - Compiles to optimized PHP code with opcache support
- **🔧 Framework-Agnostic** - Use standalone or integrate with CakePHP, Laravel, Symfony
- **📦 Zero Dependencies** - Core engine has no external requirements (except brick/varexporter)
- **🧪 Battle-Tested** - 262 tests, PHPStan level 8, 95%+ code coverage

## Features

### Directives

Sugar provides familiar control structures as HTML attributes:

- **`s:if` / `s:elseif` / `s:else`** - Conditional rendering
- **`s:foreach` / `s:forelse`** - Iteration with empty fallbacks
- **`s:while`** - Loop structures
- **`s:switch` / `s:case` / `s:default`** - Switch statements
- **`s:unless`** - Inverse conditionals
- **`s:isset` / `s:empty`** - Variable checks

### Utility Directives

- **`s:class`** - Dynamic CSS classes with conditional arrays
- **`s:spread`** - Spread attributes from arrays

### Context-Aware Escaping

Sugar automatically detects output context and applies appropriate escaping:

```php
<div data-user="<?= $name ?>">        <!-- HTML context: htmlspecialchars() -->
<script>const user = '<?= $name ?>';  <!-- JS context: json_encode() -->
<style>.user::before { content: '<?= $name ?>'; }  <!-- CSS context: CSS escaping -->
<a href="?name=<?= $name ?>">         <!-- URL context: rawurlencode() -->
```

### Loop Metadata

Access loop information with the `$loop` variable (inspired by Blade):

```php
<ul s:foreach="$items as $item">
    <li s:class="['first' => $loop->first, 'last' => $loop->last]">
        Item <?= $loop->iteration ?> of <?= $loop->count ?>
    </li>
</ul>
```

## Quick Start

```bash
composer require cakephp/sugar
```

```php
use Sugar\Compiler;
use Sugar\Parser\Parser;
use Sugar\Pass\ContextAnalysisPass;
use Sugar\Escape\Escaper;

$compiler = new Compiler(
    new Parser(),
    new ContextAnalysisPass(),
    new Escaper()
);

$compiled = $compiler->compile('<div s:if="$show"><?= $message ?></div>');
```

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
- [ ] **Custom Components** - Reusable template components with slots and props
- [ ] **Template Inheritance** - Layouts, blocks, and extends (like Blade/Twig)
- [ ] **Custom Filter Functions** - Pipe data through custom transformations
- [ ] **Asset Bundling** - Built-in support for CSS/JS compilation and versioning
- [ ] **Pipe Operator Support** - Use PHP 8.5 pipe operators (`<?= $data |> strtoupper |> trim ?>`)

### Developer Experience
- [ ] **Source Maps** - Map compiled PHP errors back to original Sugar templates
- [ ] **Debug Toolbar Integration** - Show compilation stats and context detection

## Contributing

Sugar is actively developed and welcomes contributions! Check out the issues or submit a PR.

## Requirements

- PHP 8.4+
- Composer

## License

MIT License - see LICENSE file for details

## Credits

Created by [Jasper Smet](https://github.com/josbeir) and the CakePHP Community.
