import MiniSearch from 'minisearch'

const DEFAULT_SEARCH_INDEX_URL = '/search-index.json';
const MAX_RESULTS = 8;

/** @type {Map<string, Promise<MiniSearch>>} */
const miniSearchPromises = new Map();

/**
 * Create and cache a MiniSearch instance loaded from the generated JSON index.
 *
 * @returns {Promise<MiniSearch>}
 */
async function getMiniSearch(searchIndexUrl) {
	if (miniSearchPromises.has(searchIndexUrl)) {
		return miniSearchPromises.get(searchIndexUrl);
	}

	const promise = (async () => {
		const response = await fetch(searchIndexUrl, { credentials: 'same-origin' });
		if (!response.ok) {
			throw new Error(`Failed to load search index (${response.status})`);
		}

		const documents = await response.json();
		if (!Array.isArray(documents)) {
			throw new Error('Invalid search index payload.');
		}

		const miniSearch = new MiniSearch({
			idField: 'id',
			fields: ['title', 'description', 'content'],
			storeFields: ['title', 'description', 'url'],
			searchOptions: {
				boost: { title: 3, description: 2 },
				prefix: true,
				fuzzy: 0.2,
			},
		});

		await miniSearch.addAllAsync(documents);

		return miniSearch;
	})();
	miniSearchPromises.set(searchIndexUrl, promise);

	return promise;
}

/**
 * Registers the `docsSearch` Alpine component.
 *
 * @param {import('alpinejs').Alpine} Alpine
 */
export default function registerDocsSearch(Alpine) {
	Alpine.data('docsSearch', (options = {}) => ({
		query: '',
		results: [],
		activeIndex: -1,
		shortcutHint: 'Ctrl K',
		searchIndexUrl:
			typeof options.searchIndexUrl === 'string' && options.searchIndexUrl.trim() !== ''
				? options.searchIndexUrl
				: DEFAULT_SEARCH_INDEX_URL,
		loading: false,
		error: '',
		open: false,
		_onGlobalKeydown: null,

		init() {
			const platform = navigator.userAgentData?.platform ?? navigator.platform ?? '';
			if (typeof platform === 'string' && /mac/i.test(platform)) {
				this.shortcutHint = '⌘ K';
			}

			this.$watch('query', () => {
				this.search();
			});

			this._onGlobalKeydown = (event) => {
				if (!(event instanceof KeyboardEvent)) {
					return;
				}

				const target = event.target;
				if (
					target instanceof HTMLInputElement ||
					target instanceof HTMLTextAreaElement ||
					target instanceof HTMLSelectElement ||
					(target instanceof HTMLElement && target.isContentEditable)
				) {
					return;
				}

				if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
					event.preventDefault();
					void this.focus();
				}
			};

			window.addEventListener('keydown', this._onGlobalKeydown);
		},

		destroy() {
			if (this._onGlobalKeydown !== null) {
				window.removeEventListener('keydown', this._onGlobalKeydown);
			}
		},

		async focus() {
			this.open = true;
			if (!this.$refs.searchDialog?.open) {
				this.$refs.searchDialog?.showModal();
			}
			this.$nextTick(() => {
				this.$refs.overlaySearchInput?.focus();
			});
			if (this.query.trim() === '') {
				this.results = [];
				this.activeIndex = -1;
			}

			try {
				await this.ensureIndex();
			} catch {
				// Error state is handled in ensureIndex().
			}
		},

		close() {
			this.open = false;
			this.activeIndex = -1;
			if (this.$refs.searchDialog?.open) {
				this.$refs.searchDialog.close();
			}
		},

		onDialogClosed() {
			this.open = false;
			this.activeIndex = -1;
		},

		onArrowDown() {
			if (!this.open) {
				this.open = true;
			}

			if (this.results.length === 0) {
				return;
			}

			this.activeIndex = this.activeIndex < this.results.length - 1
				? this.activeIndex + 1
				: 0;
		},

		onArrowUp() {
			if (!this.open) {
				this.open = true;
			}

			if (this.results.length === 0) {
				return;
			}

			this.activeIndex = this.activeIndex > 0
				? this.activeIndex - 1
				: this.results.length - 1;
		},

		onEnter() {
			if (this.results.length === 0) {
				return;
			}

			const index = this.activeIndex >= 0 ? this.activeIndex : 0;
			const selected = this.results[index];
			if (!selected || selected.url === '#') {
				return;
			}

			this.close();
			window.location.assign(selected.url);
		},

		setActiveIndex(index) {
			this.activeIndex = index;
		},

		async ensureIndex() {
			if (this.error !== '') {
				this.error = '';
			}

			this.loading = true;
			try {
				await getMiniSearch(this.searchIndexUrl);
			} catch {
				this.error = 'Search is temporarily unavailable.';
				throw new Error('search-index-load-failed');
			} finally {
				this.loading = false;
			}
		},

		async search() {
			const normalized = this.query.trim();
			if (normalized === '') {
				this.results = [];
				this.activeIndex = -1;
				return;
			}

			let miniSearch;
			try {
				await this.ensureIndex();
				miniSearch = await getMiniSearch(this.searchIndexUrl);
			} catch {
				this.results = [];
				return;
			}

			this.results = miniSearch
				.search(normalized)
				.slice(0, MAX_RESULTS)
				.map((match) => ({
					title: typeof match.title === 'string' ? match.title : '',
					description: typeof match.description === 'string' ? match.description : '',
					url: typeof match.url === 'string' ? match.url : '#',
				}));

			if (this.activeIndex >= this.results.length) {
				this.activeIndex = this.results.length > 0 ? 0 : -1;
			}
		},
	}));
}
