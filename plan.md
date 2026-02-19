## Plan: Runtime Template Inheritance & Component Rendering

**TL;DR**: Replace the current compile-time AST-merging inheritance system (`TemplateComposer` → `TemplateResolver` → `BlockMerger`) with a runtime rendering approach. Each template compiles independently into a small closure. At runtime, a shared `TemplateRenderer` service handles extends (block inheritance chain), includes, and component rendering — eliminating monolithic compiled output and unifying the inheritance + component code paths under one reusable runtime.

The key insight: the current system loads, parses, and merges entire parent/included template ASTs at compile time, producing one huge closure. The new system compiles each template in isolation and resolves inheritance/includes/components at runtime via `TemplateRenderer` — exactly how Latte and Twig work.

**Steps**

### Phase 1 — Shared Runtime Infrastructure

1. **Create `TemplateRenderer`** in Runtime. This is the central runtime service — the single piece that replaces both `TemplateComposer`/`TemplateResolver`/`BlockMerger` AND the current `ComponentRenderer`/`ComponentCompiler`. It will:
   - Compile templates on-demand via `CompilerInterface` (lazy, like current `ComponentRenderer`)
   - Cache compiled closures via `TemplateCacheInterface` with `CacheKey::fromTemplate()`
   - Execute compiled closures (include → bind `$this` → invoke with data)
   - Propagate dependencies to the parent `DependencyTracker`
   - Accept optional inline `AstPassInterface` instances for compilation (needed for component variant adjustment)
   - Key methods: `render(string $template, array $data, ?array $inlinePasses = null): string` and `execute(string $compiledPath, array $data): string`

2. **Create `BlockManager`** in Runtime. Manages the block inheritance chain at runtime:
   - Maintains a stack of block definition maps (one level per `s:extends` in the chain)
   - `pushLevel()` / `popLevel()` — called when entering/leaving an extends render
   - `defineBlock(string $name, Closure $block)` — registers a block at the current (child) level
   - `renderBlock(string $name, Closure $default, array $data): string` — renders the most-derived block for `$name`, falling back to `$default` (the parent's inline default)
   - `renderParent(string $name, array $data): string` — renders the next level up's version of the block (for `s:parent` support)
   - Multi-level inheritance (A extends B extends C) works naturally: C renders layout with `renderBlock()`, B defines overrides, A defines further overrides — the stack resolves to A's version first

3. **Register `TemplateRenderer` as a core runtime service** in EngineBuilder.php under a new constant (e.g., `RuntimeEnvironment::TEMPLATE_RENDERER_SERVICE_ID = 'renderer.template'`). This is always available — not extension-dependent. The `TemplateRenderer` receives a `BlockManager` (or creates one internally per render context).

### Phase 2 — Inheritance Compilation Pass

4. **Create `InheritanceCompilationPass`** in Pass implementing `AstPassInterface`. This replaces `TemplateComposer`. Registered at `PassPriority::POST_DIRECTIVE_COMPILATION` (priority 35, after `s:foreach`/`s:if` etc. are compiled, so block content is already valid PHP). The pass transforms nodes with inheritance attributes into runtime calls:

   - **`s:extends="path"`** on a root element → Strip the extends element. Collect all sibling `s:block`/`s:append`/`s:prepend` elements on the document. For each, emit a `defineBlock()` runtime call wrapping the (already-compiled) block content in a closure. Emit a final `$__tpl->renderExtends('resolved/path', $__data)` call that pushes the block level, compiles+executes the parent, and returns the result.

   - **`s:block="name"`** (in parent templates, without `s:extends`) → Wrap the element's children in a `$__tpl->renderBlock('name', function(array $__data) { extract(...); ...compiled content... })` call. The element tag itself (`<main>`, `<title>`, etc.) is preserved around the `renderBlock()` output.

   - **`s:append="name"`** → Same as `s:block` but the emitted closure first calls `$__tpl->renderParent('name', $__data)` before its own content.

   - **`s:prepend="name"`** → Same but calls `renderParent()` after its own content.

   - **`s:parent`** on `<s-template>` → Replace with `<?php echo $__tpl->renderParent('currentBlockName', $__data); ?>`. The current block name is tracked during the pass.

   - **`s:include="path"`** on an `ElementNode` → Keep the element tags, replace children with `<?php echo $__tpl->include('resolved/path', get_defined_vars()); ?>`. On a `FragmentNode` → replace entirely with the include call (no wrapper element).

   - **`s:include` + `s:with="[...]"`** → Pass the `s:with` expression instead of `get_defined_vars()`: `$__tpl->include('path', [...])`.

5. **Update `DirectivePrefixHelper`** at DirectivePrefixHelper.php — inheritance attributes (`block`, `append`, `prepend`, `parent`, `extends`, `include`, `with`) must survive through the directive extraction/pairing/compilation passes untouched. Currently `isInheritanceAttribute()` identifies them; ensure the `DirectiveExtractionPass` skips them so they remain as plain attributes for `InheritanceCompilationPass` to handle later.

6. **Update `Compiler`** at Compiler.php — Remove the `if ($enableInheritance) { $this->templateComposer->compose(...) }` call from `compileAst()`. The `InheritanceCompilationPass` in the pipeline handles everything. Keep the `$enableInheritance` flag only to register the `InheritanceCompilationPass` in the pipeline (so inline/string templates without a path don't get inheritance processing).

7. **Register `InheritanceCompilationPass`** in CompilerPipelineFactory.php at `PassPriority::POST_DIRECTIVE_COMPILATION` (35) when inheritance is enabled.

### Phase 3 — Compiled Output Shape

8. The **new compiled output for a child template** extending a parent:
   ```
   // (pseudocode — no actual code blocks in plan)
   // Child: defines blocks as closures, then calls renderExtends('parent', $__data)
   // Each block closure accepts (array $__data), calls extract(), renders compiled content
   // s:parent inside a block → echo $__tpl->renderParent('blockName', $__data)
   ```

9. The **new compiled output for a parent/layout template**:
   ```
   // Parent: normal template with renderBlock('name', defaultClosure) calls at block positions
   // The element tag (<title>, <main>) wraps the renderBlock output
   // Default closure contains the parent's fallback content
   ```

10. The **new compiled output for includes**:
    ```
    // <nav s:include="path"> → <nav><?php echo $__tpl->include('path', get_defined_vars()); ?></nav>
    // <s-template s:include="path" /> → <?php echo $__tpl->include('path', get_defined_vars()); ?>
    // s:with → pass explicit array instead of get_defined_vars()
    ```

### Phase 4 — Unify Component Extension

11. **Refactor `ComponentRenderer`** at ComponentRenderer.php to delegate template compilation and execution to the shared `TemplateRenderer`. `ComponentRenderer` retains component-specific logic: slot normalization (`normalizeRenderData()`), attribute merging, and the `ComponentVariantAdjustmentPass` (passed as an inline pass to `TemplateRenderer::render()`). It fetches `TemplateRenderer` from `RuntimeEnvironment` rather than owning its own compile/cache/execute logic.

12. **Simplify `ComponentExpansionPass`** at ComponentExpansionPass.php. Currently this does both static (inline expansion) and dynamic (RuntimeCallNode) rendering. Since all components now use runtime rendering:
    - Remove the entire static expansion path (`expandComponent()`, template loading, slot resolution at AST level, `ScopeIsolationTrait` wrapping)
    - All component nodes (both `ComponentNode` and `s:component` directive) emit `RuntimeCallNode` targeting `ComponentRenderer::renderComponent()` — same as current dynamic path
    - Slot extraction still happens at compile time (building slot content expressions from children), but the actual component template rendering happens at runtime

13. **Remove `ComponentCompiler`** at ComponentCompiler.php — its compile logic is absorbed by `TemplateRenderer`.

14. **Simplify `SlotResolver`** and `SlotOutletResolver` at Helper. `SlotResolver` (extracting slots from caller children) stays mostly as-is since slot expressions are built at compile time. `SlotOutletResolver` (resolving outlets in the component template) moves to runtime — the component template's `s:slot` outlets are compiled as `renderBlock()`-like calls, reusing the same block infrastructure from `BlockManager`.

### Phase 5 — Remove Compile-Time Inheritance Code

15. **Delete `TemplateComposer`** at TemplateComposer.php — no longer needed.

16. **Delete `TemplateResolver`** at TemplateResolver.php — inheritance resolution is now runtime.

17. **Delete `BlockMerger`** at BlockMerger.php — block merging is now handled by `BlockManager` at runtime.

18. **Delete `BlockMergeMode`** enum at BlockMergeMode.php — `s:append`/`s:prepend` are syntactic sugar over `s:block` + `s:parent` in the compiled output.

19. **Evaluate `ScopeIsolationTrait`** at ScopeIsolationTrait.php — may no longer be needed since scope isolation is handled by `TemplateRenderer::render()` (each template gets its own closure + `extract()`). Remove if unused, or keep if components still need it for compile-time slot content wrapping.

20. **Remove `NodeCloner` usage in template loading** — currently `TemplateResolver` deep-clones parsed ASTs to prevent mutation during multiple compositions. With runtime rendering, each template is compiled once independently; no AST cloning needed.

### Phase 6 — Update Engine & Cache

21. **Simplify `Engine::render()`** at Engine.php — the `$blocks` parameter (for partial block rendering) can be removed or simplified. Currently it passes block names to `CompilationContext` for `BlockMerger::extractBlocks()`. With runtime rendering, partial block rendering is trivially handled by the runtime (just render specific blocks from a compiled template).

22. **Update `CacheKey::fromTemplate()`** at CacheKey.php — with each template compiled independently, the block-variant suffix for inheritance may no longer be needed (though it stays useful for component slot variants).

23. **Update dependency tracking** — With runtime inheritance, a child template's `DependencyTracker` won't know about parent dependencies at compile time. This is fine: each template tracks its own source, and `TemplateRenderer` at runtime propagates sub-template dependencies to the root tracker (same pattern as `ComponentRenderer` already uses). Cache freshness for parents is checked independently.

### Phase 7 — Tests

24. **Rewrite unit tests** for the deleted classes (`TemplateComposerTest`, `TemplateResolverTest`, `BlockMergerTest`) → replace with unit tests for `TemplateRenderer`, `BlockManager`, and `InheritanceCompilationPass`. Use TDD: write failing tests first for each runtime behavior (block override, multi-level inheritance, append/prepend, s:parent, scope isolation, circular detection).

25. **Update integration tests** — TemplateInheritanceIntegrationTest.php and ComponentIntegrationTest.php should remain GREEN with **zero behavior changes**. Template syntax and rendered output must be identical. Only the compiled output shape changes (smaller files, runtime calls instead of monolithic PHP).

26. **Add compiled output assertions** — new tests verifying the compiled output contains `TemplateRenderer` runtime calls instead of monolithic merged HTML.

27. **Update `FragmentTemplateInheritanceTest`** at FragmentTemplateInheritanceTest.php — fragment elements (`<s-template>`) with blocks work through the same `InheritanceCompilationPass` path.

**Verification**
- phpunit — all existing integration tests pass with identical rendered output
- phpcs + phpcbf — code style clean
- phpstan — static analysis clean
- `vendor/bin/rector --dry-run` — no issues
- Manually inspect compiled output for a child template to verify it's small (block closures + `renderExtends()` call) instead of monolithic
- Run benchmark at parser.php to verify acceptable performance (runtime overhead vs. compile-time savings)

**Decisions**
- **All runtime**: Both static and dynamic components use `TemplateRenderer` at runtime — one code path, maximum DRY
- **`get_defined_vars()` for open-scope includes**: Maintains full backward compatibility with current AST-merging scope sharing
- **Big-bang refactor**: No feature flags or dual-path complexity; clean replacement
- **`InheritanceCompilationPass` at POST_DIRECTIVE_COMPILATION**: Runs after `s:foreach`/`s:if` etc. are compiled, so block content is already valid PHP when wrapped in closures
- **`s:append`/`s:prepend` compile as `s:block` + implicit `s:parent`**: No special merge modes in runtime; simpler `BlockManager`
