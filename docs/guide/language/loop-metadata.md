---
title: Loop Metadata
description: Use $loop to access iteration details.
---

# Loop Metadata

Sugar exposes loop metadata via the `$loop` variable inside `s:foreach` and `s:forelse` directives. Use it to style first/last items, show counters, or branch on odd/even rows.

::: tip
`$loop` is only available inside loop directives. It is not a global template variable.
:::

## Common Fields

- `$loop->index` - Zero-based index.
- `$loop->iteration` - One-based index.
- `$loop->count` - Total item count.
- `$loop->first` - True on the first item.
- `$loop->last` - True on the last item.
- `$loop->odd` / `$loop->even` - Odd/even row flags.

## Examples

::: code-group
```sugar [List]
<ul s:foreach="$items as $item">
	<li s:class="['first' => $loop->first, 'last' => $loop->last]">
		<?= $loop->iteration ?>. <?= $item ?>
	</li>
</ul>
```

```sugar [Table rows]
<table>
	<tr s:foreach="$rows as $row" s:class="['odd' => $loop->odd, 'even' => $loop->even]">
		<td><?= $row['label'] ?></td>
		<td><?= $row['value'] ?></td>
	</tr>
</table>
```
:::

::: details
Need the loop from `s:forelse`?

`$loop` is available in the loop body just like `s:foreach`. The `s:empty` fallback does not have loop metadata.
:::
