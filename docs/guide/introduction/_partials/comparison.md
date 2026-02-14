## Feature Comparison

Legend: :white_check_mark: yes, :x: no or limited (text used where not binary or when features are optional)

These are high-level defaults as commonly described; behavior can vary by version, configuration, and framework integration. Use this table as a quick orientation and confirm details in the official docs for your stack.

<div class="comparison-table">
<div class="comparison-table__inner">

| Feature | Sugar | Blade | Twig | Latte | Tempest |
| --- | --- | --- | --- | --- | --- |
| Learning Curve | PHP + `s:` | Custom | Python-like | PHP-like | PHP + `:attr` |
| AST parser | :white_check_mark: | :x: | :white_check_mark: | :white_check_mark: | :white_check_mark: |
| Context-aware escaping | :white_check_mark: | :x: | :x: | :white_check_mark: | :x: |
| Full PHP interop | :white_check_mark: | :white_check_mark: | :x: | :white_check_mark: | :white_check_mark: |
| Scope isolation | :white_check_mark: | :x: | :white_check_mark: | :white_check_mark: | :white_check_mark: |
| Layout inheritance | :white_check_mark: | :white_check_mark: | :white_check_mark: | :white_check_mark: | :x: |
| Compiles to PHP | :white_check_mark: | :white_check_mark: | :white_check_mark: | :white_check_mark: | :white_check_mark: |
| Components | `s-` files | Class/File | Macros/Embed | N:attributes | `x-` files |
| Editor support | Native | Plugins | Plugins | Plugins | Native |
| Security | Auto + isolate (custom pass) | Basic HTML | Sandbox mode | Context + Sandbox | Auto + isolate |
| Debugging | Native traces | Good | Can be hard | Tracy plugin | Native traces |
| Pipes/Filters | Native `\|>` - funcs | Helpers | `\|upper` | `\|upper` | Native funcs |

</div>
</div>

**Notes on the criteria:**

- **Performance**: whether templates compile to PHP and benefit from opcache at runtime.
- **Learning curve**: how much new syntax you need to learn beyond PHP and HTML.
- **AST parser**: whether the engine parses templates into a structured AST before compiling.
- **Context-aware escaping**: automatic escaping beyond HTML (attributes, URLs, JS, CSS).
- **PHP interop**: ability to use PHP directly in templates without a separate language layer.
- **Scope isolation**: whether includes/components isolate variables by default.
- **Compiles to PHP**: whether the engine produces PHP code that can be cached by OPcache at runtime.

## How Sugar Compares (Neutral Overview)

Every engine makes different tradeoffs in syntax, safety, and integration. Sugar is designed to feel like native PHP templates with attribute-driven directives. Other engines lean into custom syntax, tag-based DSLs, or class-based components. None of these approaches are universally better; they fit different teams and constraints.

### Syntax and Familiarity

Sugar keeps standard PHP and HTML intact, adding `s:` attributes for structure. That makes it easy for teams already comfortable with PHP templates. Engines like Blade or Twig offer more opinionated syntax that can be cleaner for designers, but they also introduce a separate language to learn and debug.

### Escaping and Safety

Sugar focuses on context-aware escaping across HTML, attributes, URLs, JS, and CSS. Some engines default to HTML-only escaping or require explicit filters for non-HTML contexts. In practice, the best choice depends on how much dynamic data your templates handle and how strict you want the defaults to be.

### Components and Composition

Sugar uses `s-` prefixed component templates with props and slots. Other engines may use class-based components, macros, or custom tags. Sugar leans on template binding to keep logic close to the template without requiring class-backed components.

### Inheritance and Includes

Sugar supports layout inheritance and includes with scope isolation. Some engines expose more granular inheritance features (like named stacks), while others prioritize component composition over layout inheritance. If your app is component-first, you may lean more on component systems than template inheritance regardless of engine.

### Performance and Debugging

Sugar compiles to pure PHP and relies on opcache, similar to many engines. Debugging experience often comes down to the quality of compiled output and source mapping. Sugar aims to keep compiled output readable so stack traces are still useful.

### When Sugar Is a Good Fit

Sugar tends to work well for teams that want a PHP-first template style, attribute-based control flow, and context-aware escaping without adopting a separate template language. If your team prefers a full DSL or a framework-specific component model, another engine might feel more natural.
