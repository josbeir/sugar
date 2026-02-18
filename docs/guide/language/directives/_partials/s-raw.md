### s:raw

Use `s:raw` when you need verbatim inner content. Sugar skips parsing directives and special tags inside the node body.

`s:raw` is supported on attribute-bearing template nodes (elements, fragments, and components).

```sugar
<code s:raw>
    <s-template s:if="$debug">literal</s-template>
    {{ untouched_token }}
</code>
```

Notes:
- `s:raw` applies to children, not to the outer node itself.
- The `s:raw` attribute is not rendered in final HTML.

### Raw PHP imports (`use`, `use function`, `use const`)

When a raw PHP block starts with import statements, Sugar hoists those imports to the compiled template prelude (outside the render closure), where PHP allows them.

```sugar
<?php
use DateTimeImmutable as Clock;

$year = (new Clock('2024-01-01'))->format('Y');
?>

<p><?= $year ?></p>
```

Behavior details:
- Leading imports are extracted and emitted once at file scope.
- Remaining executable PHP stays in place inside the template output flow.
- Equivalent imports are de-duplicated, even when they appear multiple times (for example across includes, inheritance output, or expanded components).

This means repeating the same library import across template files does not produce repeated `use` lines in compiled output.
