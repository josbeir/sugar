<?php
/**
 * Alpine-powered docs search component using the generated MiniSearch index.
 *
 * @var \Glaze\Template\SiteContext $this
 */
?>
<div
	x-data="docsSearch({ searchIndexUrl: '<?= $this->url("/search-index.json") ?>' })"
	class="relative mr-2"
	x-cloak
>
	<button
		type="button"
		class="btn btn-ghost btn-sm btn-circle md:hidden"
		aria-label="Open search"
		@click="focus()"
	>
		<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
			<path d="M21 21l-4.2-4.2m1.2-5.05a6.25 6.25 0 1 1-12.5 0 6.25 6.25 0 0 1 12.5 0Z" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"/>
		</svg>
	</button>

	<button
		type="button"
		class="hidden md:flex items-center gap-2 w-72 lg:w-80 h-9 px-3 rounded-box border border-base-300/80 bg-base-100/70 text-left text-sm text-base-content/70 hover:border-base-300 hover:bg-base-100 transition-colors"
		@click="focus()"
		aria-label="Open search"
	>
		<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-primary/70" viewBox="0 0 24 24" fill="none" aria-hidden="true">
			<path d="M21 21l-4.2-4.2m1.2-5.05a6.25 6.25 0 1 1-12.5 0 6.25 6.25 0 0 1 12.5 0Z" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"/>
		</svg>
		<span class="grow">Search</span>
		<kbd class="kbd kbd-xs border-base-300 text-base-content/60" x-text="shortcutHint">Ctrl K</kbd>
	</button>

	<dialog x-ref="searchDialog" class="modal modal-top" @close="onDialogClosed()">
		<div class="modal-box w-11/12 max-w-3xl mt-10 sm:mt-14 mx-auto p-4 sm:p-5 !rounded-box">
			<div class="flex items-center gap-2">
					<label class="input input-lg input-bordered flex items-center gap-2 w-full focus-within:outline-none focus-within:ring-1 focus-within:ring-base-300 focus-within:border-base-300">
						<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 opacity-70" viewBox="0 0 24 24" fill="none" aria-hidden="true">
							<path d="M21 21l-4.2-4.2m1.2-5.05a6.25 6.25 0 1 1-12.5 0 6.25 6.25 0 0 1 12.5 0Z" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"/>
						</svg>
						<input
							x-ref="overlaySearchInput"
							type="search"
							class="grow text-base focus:outline-none"
							placeholder="Search docs"
							x-model.debounce.180ms="query"
							@focus="focus()"
							@keydown.down.prevent="onArrowDown()"
							@keydown.up.prevent="onArrowUp()"
							@keydown.enter.prevent="onEnter()"
							@keydown.escape.prevent="close()"
							aria-label="Search documentation"
						/>
					</label>
					<button type="button" class="btn btn-ghost" @click="close()" aria-label="Close search">Close</button>
			</div>

			<div class="max-h-[60vh] overflow-y-auto mt-3" x-show="results.length > 0">
				<ul class="menu menu-sm w-full">
					<template x-for="(result, index) in results" :key="result.url + '-dialog'">
						<li>
							<a
								:href="result.url"
								class="flex flex-col items-start gap-0.5"
								:class="{ 'bg-base-200': index === activeIndex }"
								@mouseenter="setActiveIndex(index)"
								@click="close()"
							>
								<span class="font-medium" x-text="result.title"></span>
								<span class="text-xs text-base-content/70 line-clamp-2" x-text="result.description || result.url"></span>
							</a>
						</li>
					</template>
				</ul>
			</div>
		</div>
		<form method="dialog" class="modal-backdrop bg-base-content/25 backdrop-blur-md">
			<button aria-label="Close search">close</button>
		</form>
	</dialog>

</div>
