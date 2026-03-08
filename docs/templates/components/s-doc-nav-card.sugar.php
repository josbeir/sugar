<?php
/**
 * @var string|null $direction
 */

$resolvedDirection = strtolower((string)($direction ?? 'next'));
$isPrevious = $resolvedDirection === 'prev';
$resolvedLabel = $isPrevious ? 'Previous' : 'Next';
?>
<a class="card card-border bg-base-100 hover:border-primary transition-colors">
	<div class="card-body p-4 gap-1" s:class="['items-end text-right' => !$isPrevious]">
		<span class="text-xs uppercase tracking-wide text-base-content/60">
			<?= $resolvedLabel ?>
		</span>
		<span class="font-medium text-sm sm:text-base" s:if="$isPrevious">
			&larr; <span s:slot="title"><?= $slot ?></span>
		</span>
		<span class="font-medium text-sm sm:text-base" s:if="!$isPrevious">
			<span s:slot="title"><?= $slot ?></span> &rarr;
		</span>
	</div>
</a>
