/**
 * @typedef {Object} SugarVitePluginOptions
 * @property {string[]} [templateExtensions] File extensions that should trigger Sugar partial updates.
 * @property {boolean} [injectClientBridge] Whether to auto-inject the Sugar HMR bridge module into served HTML.
 * @property {boolean} [reloadOnFailure] Whether to force a full page reload when partial update fails.
 * @property {string} [updateTargetSelector] CSS selector for the DOM root that should be partially updated.
 * @property {'auto'|'none'|'alpine'|'idiomorph'} [morphStrategy] DOM patch strategy for partial updates.
 * @property {(filePath: string) => boolean} [isTemplateFile] Custom template matcher callback.
 */

/** @type {string} */
const VIRTUAL_MODULE_ID = 'virtual:sugar-vite-bridge';
/** @type {string} */
const RESOLVED_VIRTUAL_MODULE_ID = '\0' + VIRTUAL_MODULE_ID;
/** @type {string} */
const BRIDGE_MARKER_ATTRIBUTE = 'data-sugar-vite-bridge="1"';
/** @type {string} */
const DEFAULT_UPDATE_TARGET_SELECTOR = '[data-sugar-hmr-root], #app, main';

/**
 * Normalize plugin options with defaults.
 *
 * @param {SugarVitePluginOptions} options Raw plugin options
 */
function resolveOptions(options) {
  const templateExtensions = Array.isArray(options.templateExtensions) && options.templateExtensions.length > 0
    ? options.templateExtensions
    : ['.sugar.php', '.php'];

  return {
    templateExtensions,
    injectClientBridge: options.injectClientBridge ?? true,
    reloadOnFailure: options.reloadOnFailure ?? true,
    updateTargetSelector: options.updateTargetSelector ?? DEFAULT_UPDATE_TARGET_SELECTOR,
    morphStrategy: options.morphStrategy ?? 'auto',
    isTemplateFile: typeof options.isTemplateFile === 'function' ? options.isTemplateFile : null,
  };
}

/**
 * Create bridge runtime module source.
 *
 * @param {boolean} reloadOnFailure Whether to reload on patch failure
 * @param {string} updateTargetSelector Selector used to patch a stable app root
 * @param {'auto'|'none'|'alpine'|'idiomorph'} morphStrategy DOM patch strategy
 */
function createBridgeRuntimeSource(reloadOnFailure, updateTargetSelector, morphStrategy) {
  return `
if (import.meta.hot) {
  import.meta.hot.on("sugar:partial-update", (detail) => {
    window.dispatchEvent(new CustomEvent("sugar:partial-update", { detail }));
  });
}

try {
  const persistedError = sessionStorage.getItem("__sugarLastHmrError");
  if (persistedError) {
    window.__sugarLastHmrError = persistedError;
    if (typeof console !== "undefined" && typeof console.warn === "function") {
      console.warn("[sugar] previous partial update failure", persistedError);
    }
    sessionStorage.removeItem("__sugarLastHmrError");
  }
} catch (_persistedErrorReadError) {}

window.__sugarHmrHandlePartialUpdate = async function __sugarHmrHandlePartialUpdate(detail = {}) {
  const customHandler = window.__sugarHmrApplyPartialUpdate;
  if (typeof customHandler === "function") {
    await customHandler(detail);
    return;
  }

  let alpineMorph = null;
  let idiomorphMorph = null;

  const resolveAlpineMorph = async () => {
    if (typeof alpineMorph === "function") {
      return alpineMorph;
    }

    if (window.Alpine && typeof window.Alpine.morph === "function") {
      alpineMorph = window.Alpine.morph.bind(window.Alpine);

      return alpineMorph;
    }

    let alpineModule;
    let morphModule;

    try {
      const alpineModuleUrl = '/@id/' + 'alpinejs';
      const alpineMorphModuleUrl = '/@id/' + '@alpinejs/morph';
      [alpineModule, morphModule] = await Promise.all([
        import(/* @vite-ignore */ alpineModuleUrl),
        import(/* @vite-ignore */ alpineMorphModuleUrl),
      ]);
    } catch (error) {
      throw new Error(
        "Sugar HMR could not load Alpine Morph modules. "
          + "Install 'alpinejs' and '@alpinejs/morph' or use morphStrategy 'idiomorph'/'none'.",
      );
    }

    const alpine = window.Alpine
      ?? alpineModule?.default
      ?? alpineModule?.Alpine;
    const morphPlugin = morphModule?.default
      ?? morphModule?.morph
      ?? morphModule;

    if (!alpine || typeof alpine.plugin !== "function") {
      throw new Error("Sugar HMR could not resolve Alpine runtime for morphStrategy 'alpine'.");
    }

    if (typeof alpine.morph !== "function") {
      alpine.plugin(morphPlugin);
    }

    if (!window.Alpine) {
      window.Alpine = alpine;
    }

    if (typeof alpine.morph !== "function") {
      throw new Error("Sugar HMR could not resolve Alpine.morph after loading @alpinejs/morph.");
    }

    alpineMorph = alpine.morph.bind(alpine);

    return alpineMorph;
  };

  const resolveIdiomorphMorph = async () => {
    if (typeof idiomorphMorph === "function") {
      return idiomorphMorph;
    }

    if (window.Idiomorph && typeof window.Idiomorph.morph === "function") {
      idiomorphMorph = window.Idiomorph.morph.bind(window.Idiomorph);

      return idiomorphMorph;
    }

    let idiomorphModule;

    try {
      const idiomorphModuleUrl = '/@id/' + 'idiomorph';
      idiomorphModule = await import(/* @vite-ignore */ idiomorphModuleUrl);
    } catch (_error) {
      throw new Error(
        "Sugar HMR could not load Idiomorph. "
          + "Install 'idiomorph' or switch morphStrategy to 'alpine'/'none'.",
      );
    }
    const candidate = idiomorphModule?.Idiomorph?.morph
      ?? idiomorphModule?.default?.morph
      ?? idiomorphModule?.morph;

    if (typeof candidate !== "function") {
      throw new Error("Sugar HMR could not resolve Idiomorph.morph from the idiomorph module.");
    }

    idiomorphMorph = candidate;

    return idiomorphMorph;
  };

  const patchTarget = async (currentTarget, nextTarget) => {
    const strategy = ${JSON.stringify(morphStrategy)};

    if (strategy === "alpine") {
      const morph = await resolveAlpineMorph();
      morph(currentTarget, nextTarget.outerHTML);

      return;
    }

    if (strategy === "idiomorph") {
      const morph = await resolveIdiomorphMorph();
      morph(currentTarget, nextTarget);

      return;
    }

    if (strategy === "auto") {
      try {
        const morph = await resolveAlpineMorph();
        morph(currentTarget, nextTarget.outerHTML);

        return;
      } catch (_alpineUnavailable) {
        // Fall through to Idiomorph strategy when Alpine Morph isn't available.
      }

      try {
        const morph = await resolveIdiomorphMorph();
        morph(currentTarget, nextTarget);

        return;
      } catch (_idiomorphUnavailable) {
        // Fall through to attribute + HTML replacement when Idiomorph isn't available.
      }
    }

    for (const attribute of Array.from(currentTarget.attributes)) {
      currentTarget.removeAttribute(attribute.name);
    }
    for (const attribute of Array.from(nextTarget.attributes)) {
      currentTarget.setAttribute(attribute.name, attribute.value);
    }
    currentTarget.innerHTML = nextTarget.innerHTML;
  };

  try {
    const fetchHtml = async () => {
      const requestUrl = new URL(window.location.href);
      requestUrl.searchParams.set("__sugar_hmr", String(Date.now()));
      const response = await fetch(requestUrl.toString(), {
        headers: {
          "X-Sugar-HMR": "1",
          Accept: "text/html,application/xhtml+xml",
        },
        cache: "no-store",
        credentials: "same-origin",
      });

      const contentType = (response.headers.get("content-type") || "").toLowerCase();
      const isHtml = contentType.includes("text/html") || contentType.includes("application/xhtml+xml");
      if (!response.ok || !isHtml) {
        throw new Error("Unable to fetch HTML response for Sugar partial update (status: " + response.status + ").");
      }

      return response.text();
    };

    let html;
    try {
      html = await fetchHtml();
    } catch (_firstError) {
      html = await fetchHtml();
    }

    const nextDocument = new DOMParser().parseFromString(html, "text/html");
    const targetSelector = ${JSON.stringify(updateTargetSelector)};
    const currentTarget = document.querySelector(targetSelector);
    const nextTarget = nextDocument.querySelector(targetSelector);
    if (!currentTarget || !nextTarget) {
      throw new Error("Unable to resolve Sugar HMR target using selector: " + targetSelector);
    }

    document.title = nextDocument.title;
    await patchTarget(currentTarget, nextTarget);
    window.dispatchEvent(new CustomEvent("sugar:partial-applied", { detail }));
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    window.__sugarLastHmrError = message;
    try {
      sessionStorage.setItem("__sugarLastHmrError", message);
    } catch (_persistedErrorWriteError) {}
    if (typeof console !== "undefined" && typeof console.warn === "function") {
      console.warn("[sugar] partial update failed, falling back to full reload", message);
    }
    if (${reloadOnFailure}) {
      window.location.reload();
    }
  }
};
`.trim();
}

/**
 * Sugar Vite plugin for template-driven partial-update HMR integration.
 *
 * The plugin does two things:
 * - broadcasts `sugar:partial-update` custom HMR payloads when template files change
 * - exposes a virtual client bridge module that dispatches a browser `sugar:partial-update` event
 *
 * @param {SugarVitePluginOptions} [options]
 * @returns {import('vite').Plugin}
 */
export function sugarVitePlugin(options = {}) {
  const resolvedOptions = resolveOptions(options);

  /** @type {(filePath: string) => boolean} */
  const isTemplateFile = resolvedOptions.isTemplateFile
    ? resolvedOptions.isTemplateFile
    : (filePath) => resolvedOptions.templateExtensions.some((extension) => filePath.endsWith(extension));

  return {
    name: 'sugar-vite-plugin',

    configureServer(server) {
      server.watcher.on('change', (filePath) => {
        if (!isTemplateFile(filePath)) {
          return;
        }

        server.ws.send({
          type: 'custom',
          event: 'sugar:partial-update',
          data: {
            file: filePath,
            timestamp: Date.now(),
          },
        });
      });
    },

    transformIndexHtml(html) {
      if (!resolvedOptions.injectClientBridge || html.includes(BRIDGE_MARKER_ATTRIBUTE)) {
        return html;
      }

      const bridgeTag = '<script type="module" data-sugar-vite-bridge="1" src="/@id/' + VIRTUAL_MODULE_ID + '"></script>';

      if (html.includes('</head>')) {
        return html.replace('</head>', bridgeTag + '\n</head>');
      }

      return bridgeTag + '\n' + html;
    },

    resolveId(id) {
      if (id === VIRTUAL_MODULE_ID) {
        return RESOLVED_VIRTUAL_MODULE_ID;
      }

      return null;
    },

    load(id) {
      if (id !== RESOLVED_VIRTUAL_MODULE_ID) {
        return null;
      }

      return createBridgeRuntimeSource(
        resolvedOptions.reloadOnFailure,
        resolvedOptions.updateTargetSelector,
        resolvedOptions.morphStrategy,
      );
    },
  };
}
