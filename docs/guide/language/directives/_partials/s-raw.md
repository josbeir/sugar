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
