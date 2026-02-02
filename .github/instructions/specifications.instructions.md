---
applyTo: '**'
---
# GitHub Copilot Instructions - Sugar Templating Engine

## Project Context

This is the **Sugar Templating Engine** - a modern, standalone PHP templating engine for CakePHP and other frameworks.

## Core Principles

1. **DRY** - Single source of truth for all features
2. **Standalone** - Core engine has zero dependencies (except brick/varexporter)
3. **Fast** - Compile-once, cache-everything strategy with opcache optimization
4. **Extendable** - Plugin architecture for custom functionality
5. **Modern** - PHP 8.4+ features, strict types, immutable where possible

## Specification Documents

**ALWAYS** reference these specification documents before writing code:

- `spec/ARCHITECTURE.md` - Complete architectural specification
- `spec/EXAMPLES.md` - Template syntax examples and usage patterns

## Development Guidelines

### Code Standards

- **PHP Version**: Require PHP 8.4+
- **Strict Types**: Every file MUST start with `declare(strict_types=1);`
- **Property Promotion**: Always use constructor property promotion
- **Readonly**: Use `readonly` for immutable classes and properties
- **No Mixed Types**: Avoid `mixed` unless absolutely necessary
- **Named Arguments**: Use for clarity in complex calls
- **Enums**: Use backed enums for constants with type safety
- **Attributes**: Use for metadata instead of docblocks where applicable

### Architecture Rules

1. **Core is Framework-Agnostic**: No CakePHP or framework dependencies in `src/Core/`
2. **Interfaces First**: Define interfaces before implementations
3. **Single Responsibility**: Each class has one clear purpose
4. **Dependency Injection**: Constructor injection for all dependencies
5. **Immutability**: Prefer readonly classes and properties

### Testing Requirements (TDD)

**ALWAYS follow Red → Green → Refactor**:

1. **Red**: Write failing test FIRST
2. **Green**: Implement minimal code to pass
3. **Refactor**: Improve while keeping tests green

**Test Structure**:
- Use `TemplateTestHelper` trait for loading fixture templates
- Store reusable templates in `tests/fixtures/templates/*.sugar.php`
- Store expected compiled output in `tests/fixtures/expected/compiled/*.php`
- Store expected rendered output in `tests/fixtures/expected/rendered/*.php`
- Use inline templates ONLY for syntax-specific edge cases

**Test Coverage**:
- Minimum 95% code coverage
- 100% coverage for security-critical code (Escaper, ContextDetector)
- All public APIs must be tested

### Quality Tools

Before committing, ALWAYS run:

```bash
vendor/bin/phpcs              # Code style check
vendor/bin/phpcbf              # Code style auto-fix
vendor/bin/phpstan             # Static analysis
vendor/bin/rector --dry-run    # Rector check
vendor/bin/phpunit             # Run tests
```

**NEVER**:
- Pass extra arguments that override PHPCS/PHPStan configs
- Commit code with failing tests
- Commit code with PHPCS/PHPStan errors
- Lose code coverage

### File Structure

```
src/
├── Core/              # Framework-agnostic core engine
│   ├── Compiler.php
│   ├── Engine.php
│   ├── Context/
│   └── Escape/
├── Extension/         # Extension system
├── Cache/            # Cache implementations (FileCache, Psr4Cache)
└── Runtime/          # Runtime utilities

extensions/           # Official extensions
tests/
├── Unit/
├── Integration/
├── Performance/
└── fixtures/
    ├── templates/
    └── expected/
        ├── compiled/
        └── rendered/
```

### Dependencies

**Core Dependencies** (production):
- `php: ^8.4`
- `brick/varexporter: ^0.4` - For optimal cache generation

**Dev Dependencies**:
- `phpunit/phpunit: ^11.0`
- `phpstan/phpstan: ^2.0`
- CakePHP coding standards (phpcs/rector)

### Compilation Strategy

Templates compile to **pure PHP code**:

1. **Source**: `template.sugar.php` with `s:` attributes
2. **Compile**: Transform to pure PHP with context-aware escaping
3. **Cache**: Store as PHP file using `brick/varexporter`
4. **Execute**: Include cached PHP, opcache handles bytecode

### Security

**Context-Aware Auto-Escaping**:
- HTML context: `htmlspecialchars()`
- JavaScript context: `json_encode()` with hex flags
- CSS context: Custom CSS escaping
- URL context: `rawurlencode()`
- Attributes: Attribute-specific escaping

**NEVER**:
- Output user data without escaping
- Trust inline templates without validation
- Skip XSS vector testing

### Extension System

Extensions implement `ExtensionInterface`:

```php
#[SugarExtension(name: 'ExtensionName', version: '1.0.0')]
final class MyExtension implements ExtensionInterface
{
    public function register(RegistrationContext $context): void
    {
        $context->directive('name', $handler);
        $context->function('name', $callable);
        $context->filter('name', $callable);
    }
}
```

**NO** `name()` method - use `#[SugarExtension]` attribute instead.

## Code Generation Patterns

### Example: Creating a new Compiler Directive

1. **Write test first** (TDD Red):

```php
public function testCompilesForeachDirective(): void
{
    $template = '<div s:foreach="$items as $item"><?= $item ?></div>';
    $result = $this->engine->render($template, ['items' => [1, 2, 3]]);
    
    $this->assertStringContainsString('1', $result);
    $this->assertStringContainsString('2', $result);
}
```

2. **Implement** (TDD Green):

```php
final class Compiler implements CompilerInterface
{
    public function directive(string $name, callable $handler): void
    {
        $this->directives->register($name, $handler);
    }
}
```

3. **Refactor**: Improve code quality while keeping tests green

### Example: Creating a new Cache Implementation

```php
declare(strict_types=1);

namespace Sugar\Cache;

final class MemoryCache implements CacheInterface
{
    public function __construct(
        private array $cache = [],
    ) {}
    
    public function get(string $key): ?CompiledTemplate
    {
        return $this->cache[$key] ?? null;
    }
    
    public function set(string $key, CompiledTemplate $template): void
    {
        $this->cache[$key] = $template;
    }
    
    public function has(string $key): bool
    {
        return isset($this->cache[$key]);
    }
}
```

## When Writing Code

1. **Check spec first**: Read relevant sections in `spec/ARCHITECTURE.md`
2. **Write test first**: Follow TDD red/green/refactor
3. **Use modern PHP**: 8.4+ features (readonly, enums, attributes, property promotion)
4. **Keep it DRY**: Reuse existing code, avoid duplication
5. **Type everything**: Strict types, full type declarations
6. **Document interfaces**: Clear docblocks on public APIs
7. **Run quality tools**: PHPCS, PHPStan, Rector before committing

## Common Patterns

### Readonly Value Objects

```php
declare(strict_types=1);

namespace Sugar\Core;

final readonly class CompiledTemplate
{
    public function __construct(
        public string $source,
        public string $compiledCode,
        public int $timestamp,
        public array $metadata,
    ) {}
}
```

### Service Classes with DI

```php
declare(strict_types=1);

namespace Sugar\Core;

final class Engine implements EngineInterface
{
    public function __construct(
        private readonly CompilerInterface $compiler,
        private readonly CacheInterface $cache,
        private readonly ContextDetector $contextDetector,
    ) {}
}
```

### Enums for Type Safety

```php
declare(strict_types=1);

namespace Sugar\Core\Context;

enum OutputContext: string
{
    case HTML = 'html';
    case HTML_ATTRIBUTE = 'html_attr';
    case JAVASCRIPT = 'javascript';
    case CSS = 'css';
    case URL = 'url';
    case RAW = 'raw';
}
```

## Performance Requirements

| Operation | Target | Memory |
|-----------|--------|--------|
| Template compilation | < 10ms | < 1MB |
| Cached render (simple) | < 0.1ms | < 100KB |
| Cached render (complex) | < 0.5ms | < 500KB |
| Context detection | < 0.01ms | Negligible |

**Goal**: Faster than Blade, Twig, and Latte with full auto-escaping

## Questions to Ask Before Implementing

1. Does this follow the spec in `spec/ARCHITECTURE.md`?
2. Have I written the test first (TDD)?
3. Am I using PHP 8.4+ features appropriately?
4. Is this code DRY?
5. Is the core staying framework-agnostic?
6. Will this pass PHPCS, PHPStan, and Rector?
7. Does this maintain or improve code coverage?

## Remember

- **Spec is source of truth**: Always check `spec/ARCHITECTURE.md` first
- **TDD is mandatory**: Red → Green → Refactor
- **Modern PHP**: Use 8.4+ features extensively
- **Quality first**: Never commit failing code
- **Security critical**: 100% test coverage for Escaper and ContextDetector
