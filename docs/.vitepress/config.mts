import { defineConfig } from 'vitepress'

import fs from 'fs';
import { dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url))
const sugarLang = JSON.parse(fs.readFileSync(`${__dirname}/sugar.tmLanguage.json`, 'utf8'))

// https://vitepress.dev/reference/site-config
export default defineConfig({
  title: "Sugar Templates",
  description: "A modern PHP templating engine that compiles to pure PHP",
  markdown: {
    languages: ['html', 'php', 'blade', sugarLang],
  },
  head: [
    [
      'script',
      {
        'data-collect-dnt': 'true',
        async: 'true',
        src: 'https://scripts.simpleanalyticscdn.com/latest.js'
      }
    ]
  ],
  themeConfig: {
    editLink: {
      pattern: 'https://github.com/josbeir/sugar/edit/main/docs/:path',
    },

	search: {
      provider: 'local'
    },

    logo: {
      src: '/hero/sugar-cube-static.svg',
      alt: 'Sugar cube'
    },
    // https://vitepress.dev/reference/default-theme-config
    nav: [
      { text: 'Home', link: '/' },
      {
        text: 'Guide',
        items: [
          { text: 'Introduction', link: '/guide/introduction/what-is-sugar' },
          { text: 'Directives', link: '/guide/language/directives' },
          { text: 'Language', link: '/guide/language/pipe-syntax' },
          { text: 'Templates', link: '/guide/templates/components' },
          { text: 'Runtime', link: '/guide/runtime/vite' },
          { text: 'Development', link: '/guide/development/' },
          { text: 'Reference', link: '/guide/reference/architecture' }
        ]
      }
    ],

	outline: {
		level: [2, 3]
	},

    sidebar: {
      '/guide/': [
        {
          text: 'Introduction',
          items: [
            { text: 'What Is Sugar', link: '/guide/introduction/what-is-sugar' },
            { text: 'Getting Started', link: '/guide/introduction/getting-started' }
          ]
        },
        {
          text: 'Templates',
          items: [
            { text: 'Template Inheritance', link: '/guide/templates/inheritance' },
            { text: 'Components', link: '/guide/templates/components' }
          ]
        },
        {
          text: 'Directives',
          items: [
            { text: 'Introduction', link: '/guide/language/directives' },
            { text: 'Control Flow', link: '/guide/language/directives/control-flow' },
            { text: 'Attribute', link: '/guide/language/directives/attribute' },
            { text: 'Content', link: '/guide/language/directives/content' },
            { text: 'Pass-through', link: '/guide/language/directives/pass-through' }
          ]
        },
        {
          text: 'Language',
          items: [
            { text: 'Pipe Syntax', link: '/guide/language/pipe-syntax' },
            { text: 'Context-Aware Escaping', link: '/guide/language/escaping' },
            { text: 'Fragment Elements', link: '/guide/language/fragments' },
            { text: 'Empty Checking', link: '/guide/language/empty-checking' },
            { text: 'Loop Metadata', link: '/guide/language/loop-metadata' }
          ]
        },
        {
          text: 'Development',
          items: [
            { text: 'Engine Configuration', link: '/guide/development/' },
            { text: 'AST Overview', link: '/guide/development/ast' },
            { text: 'Helper Reference', link: '/guide/development/helpers' },
            { text: 'Creating Extensions', link: '/guide/development/creating-extensions' },
            { text: 'Exceptions', link: '/guide/development/exceptions' },
            { text: 'Custom Directives', link: '/guide/development/custom-directives' }
          ]
        },
        {
          text: 'Runtime',
          items: [
            { text: 'Vite Integration', link: '/guide/runtime/vite' }
          ]
        },
        {
          text: 'Reference',
		  collapsed: true,
          items: [
            { text: 'Architecture', link: '/guide/reference/architecture' },
            { text: 'Contributing', link: '/guide/reference/contributing' },
            { text: 'License', link: '/guide/reference/license' }
          ]
        }
      ]
    },

    socialLinks: [
      { icon: 'github', link: 'https://github.com/josbeir/sugar' }
    ],

    footer: {
      message: 'Released under the <a href="https://github.com/josbeir/sugar/blob/main/LICENSE.md">MIT License</a>.',
    }
  }
})
