/**
 * Registers the `tocPage` Alpine component that tracks which TOC section is
 * currently being read by observing the scroll position relative to the sticky
 * header.
 *
 * Active state is stored in the global `toc` Alpine store (`$store.toc.active`)
 * so that TOC links rendered outside the `x-data="tocPage"` scope (e.g. the
 * mobile dropdown) can still bind to it.
 *
 * Attach as `x-data="tocPage"` on the flex container wrapping the prose
 * content (marked with `data-prose`). TOC links bind their active state via
 * `:class="{ '...': $store.toc.active === '<id>' }"` from any scope.
 *
 * The scroll listener is cleaned up via Alpine's `destroy()` lifecycle hook
 * when the element is removed from the DOM (e.g. on HTMX page swap).
 *
 * @param {import('alpinejs').Alpine} Alpine
 */
export default function registerTocPage(Alpine) {
	Alpine.store('toc', { active: '' });

	Alpine.data('tocPage', () => ({
		_rafPending: false,
		_offScroll: /** @type {(() => void) | null} */ (null),

		init() {
			this.$nextTick(() => {
				const sections = /** @type {HTMLElement[]} */ (
					Array.from(this.$el.querySelectorAll('[data-prose] section[id]'))
				);
				if (sections.length === 0) {
					return;
				}

				const ids = sections.map((s) => s.id);
				const HEADER_OFFSET = 96; // matches sticky top-24 (6rem)

				const update = () => {
					const atBottom = window.scrollY + window.innerHeight >= document.documentElement.scrollHeight - 8;
					if (atBottom) {
						Alpine.store('toc').active = ids[ids.length - 1];
						return;
					}

					let active = ids[0];
					for (const section of sections) {
						if (section.getBoundingClientRect().top <= HEADER_OFFSET) {
							active = section.id;
						}
					}
					Alpine.store('toc').active = active;
				};

				const onScroll = () => {
					if (this._rafPending) {
						return;
					}
					this._rafPending = true;
					requestAnimationFrame(() => {
						this._rafPending = false;
						update();
					});
				};

				window.addEventListener('scroll', onScroll, { passive: true });
				this._offScroll = () => window.removeEventListener('scroll', onScroll);
				update();
			});
		},

		destroy() {
			this._offScroll?.();
		},
	}));
}
