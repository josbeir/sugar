---
title: Empty Checking
description: Why Sugar uses EmptyHelper instead of PHP empty().
---

# Empty Checking

Sugar uses `EmptyHelper::isEmpty()` for `s:empty`, `s:notempty`, and `s:forelse` instead of PHP's `empty()`. This provides consistent behavior across arrays, countable objects, and iterators while avoiding false positives.

::: info
`empty()` treats some values (like `0` and `"0"`) as empty. `EmptyHelper` follows those rules for scalars, but adds smarter handling for objects and iterables.
:::

## What Counts As Empty

- `null`, `false`, `''`, `'0'`, `0`, `0.0`
- Arrays with zero items
- `Countable` objects with a count of zero
- `Traversable` iterators with no items

Everything else is considered non-empty.

## Examples

::: code-group
```php [Scalars]
EmptyHelper::isEmpty(null);   // true
EmptyHelper::isEmpty('0');    // true
EmptyHelper::isEmpty(0);      // true
EmptyHelper::isEmpty('ok');   // false
```

```php [Arrays]
EmptyHelper::isEmpty([]);          // true
EmptyHelper::isEmpty(['x']);       // false
```

```php [Countable]
EmptyHelper::isEmpty(new ArrayObject([])); // true
```

```php [Iterators]
$iter = new ArrayIterator([]);
EmptyHelper::isEmpty($iter); // true
```
:::

## Generators

Generators cannot be checked without consuming them. `EmptyHelper` throws a `GeneratorNotSupportedException` if you pass a generator.

```php
$generator = (function () {
    yield 1;
})();

EmptyHelper::isEmpty($generator); // throws GeneratorNotSupportedException
```

::: tip
Convert generators to arrays before using them with `s:empty`, `s:notempty`, or `s:forelse`.
:::

## In Templates

```sugar
<ul s:forelse="$items as $item">
    <li><?= $item ?></li>
</ul>
<div s:empty>No items found</div>
<div s:notempty>Items found</div>
```
