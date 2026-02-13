### s:raw

Use `s:raw` when you need verbatim inner content. Sugar skips parsing directives and special tags inside the element body.

```html
<code s:raw>
    <s-template s:if="$debug">literal</s-template>
    {{ untouched_token }}
</code>
```

Notes:
- `s:raw` applies to element children, not to the outer element itself.
- The `s:raw` attribute is not rendered in final HTML.
