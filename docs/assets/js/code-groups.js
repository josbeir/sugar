/**
 * Enhance rendered Djot code-group tabs with keyboard navigation and
 * re-initialization support after HTMX content swaps.
 */
export default function registerCodeGroups() {
	/**
	 * Initialize code groups within a root element.
	 *
	 * @param {ParentNode} root Root node to scan.
	 */
	const initGroups = (root) => {
		const groups = Array.from(root.querySelectorAll('.glaze-code-group[role="tablist"]'));

		for (const group of groups) {
			if (group.dataset.codeGroupReady === '1') {
				continue;
			}

			group.dataset.codeGroupReady = '1';
			const tabs = Array.from(group.querySelectorAll('input[role="tab"]'));
			const panels = Array.from(group.querySelectorAll(':scope > [role="tabpanel"]'));
			if (tabs.length === 0) {
				continue;
			}

			const pairCount = Math.min(tabs.length, panels.length);
			if (pairCount === 0) {
				continue;
			}

			if (!tabs.some((tab) => tab.checked)) {
				tabs[0].checked = true;
			}

			const updateSelection = () => {
				for (let index = 0; index < pairCount; index++) {
					const tab = tabs[index];
					const panel = panels[index];
					if (!tab || !panel) {
						continue;
					}

					const isActive = tab.checked;
					tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
					panel.hidden = !isActive;
				}
			};

			for (const tab of tabs) {
				tab.addEventListener('change', updateSelection);
			}

			group.addEventListener('keydown', (event) => {
				const activeElement = document.activeElement;
				if (!(activeElement instanceof HTMLInputElement)) {
					return;
				}

				const currentIndex = tabs.indexOf(activeElement);
				if (currentIndex === -1) {
					return;
				}

				const focusTab = (index) => {
					const next = tabs[index];
					if (!next) {
						return;
					}

					next.checked = true;
					next.focus();
					next.dispatchEvent(new Event('change', { bubbles: true }));
				};

				switch (event.key) {
					case 'ArrowLeft':
					case 'ArrowUp': {
						event.preventDefault();
						focusTab((currentIndex - 1 + tabs.length) % tabs.length);
						break;
					}
					case 'ArrowRight':
					case 'ArrowDown': {
						event.preventDefault();
						focusTab((currentIndex + 1) % tabs.length);
						break;
					}
					case 'Home': {
						event.preventDefault();
						focusTab(0);
						break;
					}
					case 'End': {
						event.preventDefault();
						focusTab(tabs.length - 1);
						break;
					}
					default:
				}
			});

			updateSelection();
		}
	};

	document.addEventListener('DOMContentLoaded', () => initGroups(document));
	document.body.addEventListener('htmx:afterSwap', (event) => {
		if (event.target instanceof Element) {
			initGroups(event.target);
		}
	});
}
