import type { Plugin } from 'vite';

/**
 * Configuration options for the Sugar Vite plugin.
 */
export interface SugarVitePluginOptions {
  /**
   * File extensions that should trigger Sugar template HMR events.
   *
   * @default ['.sugar.php', '.php']
   */
  templateExtensions?: string[];

  /**
   * Whether to inject the bridge runtime tag in Vite-served HTML.
   *
   * @default true
   */
  injectClientBridge?: boolean;

  /**
   * Whether to force a full page reload when partial update fails.
   *
   * @default true
   */
  reloadOnFailure?: boolean;

  /**
   * CSS selector used to resolve the DOM root patched during partial updates.
   *
   * @default '[data-sugar-hmr-root], #app, main'
   */
  updateTargetSelector?: string;

  /**
   * DOM patch strategy applied during partial updates.
   *
    * - `auto`: prefer Alpine Morph, then Idiomorph, then fallback replace
   * - `none`: always use fallback replace
    * - `alpine`: require Alpine Morph (`alpinejs` + `@alpinejs/morph`)
    * - `idiomorph`: require Idiomorph (`idiomorph`)
   *
   * @default 'auto'
   */
  morphStrategy?: 'auto' | 'none' | 'alpine' | 'idiomorph';

  /**
   * Optional custom matcher deciding if a changed file should trigger Sugar HMR.
   */
  isTemplateFile?: (filePath: string) => boolean;
}

/**
 * Create a Vite plugin that emits template change events and provides
 * the Sugar browser bridge runtime module.
 */
export declare function sugarVitePlugin(options?: SugarVitePluginOptions): Plugin;
