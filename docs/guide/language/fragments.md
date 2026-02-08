---
title: Fragment Elements
description: Use <s-template> to avoid wrapper elements.
---

# Fragment Elements (`<s-template>`)

`<s-template>` renders only its children and allows directives without adding a wrapper element.

```html
<s-template s:foreach="$items as $item">
    <span><?= $item ?></span>
</s-template>
```

Restrictions:
- `<s-template>` can only have `s:` directive attributes.
- Regular HTML attributes are not allowed.
- Attribute directives like `s:class` and `s:spread` are not allowed.
