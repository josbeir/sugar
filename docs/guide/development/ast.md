---
title: AST Overview
description: Learn how Sugar represents templates and what each AST node does.
---

# AST Overview

Sugar parses templates into an abstract syntax tree (AST), runs compiler passes, and then generates PHP output. Understanding the AST makes it easier to debug, extend, and build custom passes.

## Pipeline At A Glance

1. Parser builds the AST.
2. Compiler passes transform and enrich the tree.
3. Code generator emits PHP.

Common passes include directive extraction, component expansion, and context analysis for escaping.

## Node Catalog

### DocumentNode

The root of a template. It holds the top-level `children` nodes and is the entry point for traversal.

Example:

```html
<h1>Hello</h1>
```

```text
DocumentNode
	ElementNode(tag="h1")
		TextNode("Hello")
```

### ElementNode

Represents an HTML element with `tag`, `attributes`, `children`, and `selfClosing`. When `dynamicTag` is set, the tag name is rendered from a runtime expression.

`dynamicTag` is set by the `s:tag` directive and lets the compiler emit a runtime tag name instead of a literal one. This keeps the AST stable (still an `ElementNode`) while allowing the opening and closing tags to be generated from an expression.

Example:

```sugar
<div s:tag="$tagName">Content</div>
```

```text
ElementNode(tag="div", dynamicTag="$tagName")
	TextNode("Content")
```

Example:

```html
<a href="/profile">Profile</a>
```

```text
ElementNode(tag="a", attributes=[href="/profile"])
	TextNode("Profile")
```

### FragmentNode

Wrapperless node used for `<s-template>` blocks. It renders only its children, accepts only `s:*` attributes, and can be self-closing for directive-only markup.

Example:

```sugar
<s-template s:if="$show">
		<p>Visible</p>
</s-template>
```

```text
FragmentNode(attributes=[s:if])
	ElementNode(tag="p")
		TextNode("Visible")
```

### TextNode

Static text content. No escaping logic lives here; it is emitted directly by the code generator.

Example:

```html
Welcome back
```

```text
TextNode("Welcome back")
```

### OutputNode

Dynamic output expression. It stores:

- `expression`: PHP expression to evaluate
- `escape`: whether escaping is enabled
- `context`: output context used by the escaper
- `pipes`: optional pipe transformations

Example:

```html
<?= $userName ?>
```

```text
OutputNode(expression="$userName", escape=true, context=HTML)
```

### RawPhpNode

Raw PHP code captured from the template. It is passed through without modification.

Example:

```html
<?php $count = count($items); ?>
```

```text
RawPhpNode("$count = count($items);")
```

### RawBodyNode

Verbatim content preserved from `s:raw` regions. The parser does not interpret the contents, and the compiler emits them as-is.

Example:

```sugar
<div s:raw>
	<?php echo $notParsed; ?>
	<span><?= $stillRaw ?></span>
</div>
```

```text
RawBodyNode("<?php echo $notParsed; ?>\n    <span><?= $stillRaw ?></span>")
```

### DirectiveNode

Structural directives like `s:if`, `s:foreach`, or `s:while`. These nodes wrap child nodes and may carry paired siblings (for directives like `forelse`).

Example:

```sugar
<p s:if="$show">Hello</p>
```

```text
DirectiveNode(name="if", expression="$show")
	ElementNode(tag="p")
		TextNode("Hello")
```

### ComponentNode

Represents a component invocation, such as `<s-button>`. It holds component `attributes` and slot `children` until the component expansion pass replaces it with the component template AST.

Example:

```sugar
<s-button class="primary">Save</s-button>
```

```text
ComponentNode(name="button", attributes=[class="primary"])
	TextNode("Save")
```

### RuntimeCallNode

A runtime call that returns output. Used when a template needs a dynamic runtime call (for example, dynamic component rendering).

Example:

```text
RuntimeCallNode(callableExpression="$__sugar->renderComponent", arguments=["$name", "$bindings"])
```

### AttributeNode

Represents a single attribute name and its `AttributeValue`. The value can be boolean, static, output, or mixed parts.

Example:

```html
<input disabled>
```

```text
AttributeNode(name="disabled", value=boolean)
```

## AttributeValue Shapes

`AttributeValue` normalizes attribute values into one of four shapes:

- Boolean: presence-only attributes like `disabled`
- Static: literal strings from markup
- Output: a single `OutputNode`
- Parts: interleaved strings and `OutputNode` values

Examples:

```html
<input disabled>
<a href="/profile">
<a href="<?= $url ?>">
<div class="btn <?= $state ?>">
```

```text
disabled  -> boolean
href      -> static("/profile")
href      -> output(OutputNode("$url"))
class     -> parts(["btn ", OutputNode("$state")])
```

Use `AttributeValue::from()` to normalize legacy shapes (including `null` for boolean attributes). `toParts()` returns a parts list for rendering, or `null` for boolean attributes.

## Output Context And Escaping

`OutputNode` carries an `OutputContext` enum that tells the escaper how to render the value. The context analysis pass sets the correct context for output nodes in element bodies and attributes.

## Traversal Notes

All nodes carry source `line` and `column`, and the base `Node` tracks the originating template path for better diagnostics.

Some nodes implement sibling navigation helpers (for example `DocumentNode`, `ElementNode`, `ComponentNode`, and `DirectiveNode`). Use them when writing passes that depend on node order or need adjacent context.
