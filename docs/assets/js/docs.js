import 'htmx.org';
import Alpine from 'alpinejs'
import intersect from '@alpinejs/intersect'
import registerTocPage from './toc.js'
import registerThemeToggle, { applyTheme, resolvePreferredTheme } from './theme.js'
import registerCodeGroups from './code-groups.js'
import registerDocsSearch from './search.js'

Alpine.plugin(intersect)
registerTocPage(Alpine)
registerThemeToggle(Alpine)
registerDocsSearch(Alpine)
registerCodeGroups()

window.Alpine = Alpine
Alpine.start()

document.addEventListener('DOMContentLoaded', () => {
	applyTheme(resolvePreferredTheme());
});
