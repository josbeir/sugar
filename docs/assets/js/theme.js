const THEME_STORAGE_KEY = 'glaze-docs-theme';
const DARK_THEME = 'glaze-docs-dark';
const LIGHT_THEME = 'glaze-docs-light';

/**
 * Resolve the preferred docs theme from localStorage or OS preference.
 *
 * @returns {string} Theme identifier.
 */
export function resolvePreferredTheme() {
	const storedTheme = window.localStorage.getItem(THEME_STORAGE_KEY);
	if (storedTheme === DARK_THEME || storedTheme === LIGHT_THEME) {
		return storedTheme;
	}

	return window.matchMedia('(prefers-color-scheme: light)').matches ? LIGHT_THEME : DARK_THEME;
}

/**
 * Apply the selected docs theme to the root element and persist the choice.
 *
 * @param {string} theme Theme identifier.
 */
export function applyTheme(theme) {
	if (theme !== DARK_THEME && theme !== LIGHT_THEME) {
		return;
	}

	document.documentElement.setAttribute('data-theme', theme);
	window.localStorage.setItem(THEME_STORAGE_KEY, theme);
}

/**
 * Registers the `themeToggle` Alpine component.
 *
 * Attach as `x-data="themeToggle"` on the DaisyUI swap label and add
 * `x-model="isLight"` to the inner checkbox. Alpine drives the checked state
 * so DaisyUI's swap CSS shows the correct icon, while `$watch` persists the
 * chosen theme to localStorage and updates `data-theme` on the root element.
 *
 * @param {import('alpinejs').Alpine} Alpine
 */
export default function registerThemeToggle(Alpine) {
	Alpine.data('themeToggle', () => ({
		isLight: false,

		init() {
			this.isLight = resolvePreferredTheme() === LIGHT_THEME;
			this.$watch('isLight', (value) => applyTheme(value ? LIGHT_THEME : DARK_THEME));
		},
	}));
}
