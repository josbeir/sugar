---
title: Conditional Wrappers
description: Render wrappers only when content exists.
---

# Conditional Wrappers

Use `s:ifcontent` to render a wrapper only when it contains content:

```html
<div s:ifcontent class="card">
    <?php if ($showContent): ?>
        <p>Some content here</p>
    <?php endif; ?>
</div>
```
