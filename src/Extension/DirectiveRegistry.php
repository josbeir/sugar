<?php
declare(strict_types=1);

namespace Sugar\Extension;

use RuntimeException;
use Sugar\Directive\BooleanAttributeDirective;
use Sugar\Directive\ClassDirective;
use Sugar\Directive\ContentDirective;
use Sugar\Directive\EmptyDirective;
use Sugar\Directive\FinallyDirective;
use Sugar\Directive\ForeachDirective;
use Sugar\Directive\ForelseDirective;
use Sugar\Directive\IfContentDirective;
use Sugar\Directive\IfDirective;
use Sugar\Directive\Interface\DirectiveInterface;
use Sugar\Directive\IssetDirective;
use Sugar\Directive\NoWrapDirective;
use Sugar\Directive\PassThroughDirective;
use Sugar\Directive\SpreadDirective;
use Sugar\Directive\SwitchDirective;
use Sugar\Directive\TagDirective;
use Sugar\Directive\TimesDirective;
use Sugar\Directive\TryDirective;
use Sugar\Directive\UnlessDirective;
use Sugar\Directive\WhileDirective;
use Sugar\Enum\DirectiveType;
use Sugar\Enum\OutputContext;
use Sugar\Exception\DidYouMean;
use Sugar\Exception\UnknownDirectiveException;

/**
 * Registry for directive compilers
 *
 * Manages all registered directive compilers (s:if, s:foreach, s:class, etc.).
 * Provides a single source of truth for directive resolution during compilation.
 *
 * By default, comes pre-loaded with all built-in Sugar directives.
 * For a clean slate, use DirectiveRegistry::empty().
 *
 * Supports lazy instantiation by accepting class names:
 * ```php
 * $registry = new DirectiveRegistry(); // Has all built-in directives
 * $registry->register('custom', CustomCompiler::class); // Add custom
 * $registry->register('if', MyIfDirective::class); // Override built-in
 *
 * // Empty registry for complete customization:
 * $registry = DirectiveRegistry::empty();
 * $registry->register('if', IfDirective::class);
 * ```
 */
final class DirectiveRegistry implements DirectiveRegistryInterface
{
    /**
     * Built-in directive mappings
     *
     * Maps directive names to their compiler class names or instances.
     *
     * @var array<string, class-string<\Sugar\Directive\Interface\DirectiveInterface>>
     */
    private const DEFAULT_DIRECTIVES = [
        // Control flow
        'if' => IfDirective::class,
        'elseif' => IfDirective::class,
        'else' => IfDirective::class,
        'unless' => UnlessDirective::class,
        'isset' => IssetDirective::class,
        'empty' => EmptyDirective::class,
        'switch' => SwitchDirective::class,
        'case' => SwitchDirective::class,
        'default' => SwitchDirective::class,
        'try' => TryDirective::class,
        'finally' => FinallyDirective::class,
        // Loops
        'foreach' => ForeachDirective::class,
        'forelse' => ForelseDirective::class,
        'while' => WhileDirective::class,
        'times' => TimesDirective::class,
        // Attributes
        'class' => ClassDirective::class,
        'spread' => SpreadDirective::class,
        'attr' => SpreadDirective::class,
        // Boolean attributes
        'checked' => BooleanAttributeDirective::class,
        'selected' => BooleanAttributeDirective::class,
        'disabled' => BooleanAttributeDirective::class,
        // HTML manipulation
        'tag' => TagDirective::class,
        'ifcontent' => IfContentDirective::class,
        // Pass-through (handled by other passes)
        'slot' => PassThroughDirective::class,
        'bind' => PassThroughDirective::class,
        'component' => PassThroughDirective::class,
        // Content modifiers
        'nowrap' => NoWrapDirective::class,
    ];

    /**
     * @var array<string, \Sugar\Directive\Interface\DirectiveInterface|class-string<\Sugar\Directive\Interface\DirectiveInterface>>
     */
    private array $directives = [];

    /**
     * Constructor - registers all built-in directives by default
     *
     * @param bool $withDefaults Whether to register built-in directives (default: true)
     */
    public function __construct(bool $withDefaults = true)
    {
        if ($withDefaults) {
            $this->registerDefaults();
        }
    }

    /**
     * Create an empty registry without built-in directives
     *
     * Use this when you want complete control over directive registration.
     *
     * @return self Empty registry instance
     */
    public static function empty(): self
    {
        return new self(withDefaults: false);
    }

    /**
     * Register a directive compiler
     *
     * Accepts either an instance or a class name for lazy instantiation.
     *
     * @param string $name Directive name (e.g., 'if', 'foreach', 'while')
     * @param \Sugar\Directive\Interface\DirectiveInterface|class-string<\Sugar\Directive\Interface\DirectiveInterface> $compiler The compiler instance or class name
     */
    public function register(string $name, DirectiveInterface|string $compiler): void
    {
        $this->directives[$name] = $compiler;
    }

    /**
     * Check if a directive is registered
     *
     * @param string $name Directive name
     * @return bool True if registered, false otherwise
     */
    public function has(string $name): bool
    {
        return isset($this->directives[$name]);
    }

    /**
     * Get a registered directive compiler
     *
     * Instantiates the compiler if a class name was registered.
     *
     * @param string $name Directive name
     * @return \Sugar\Directive\Interface\DirectiveInterface The compiler implementation
     * @throws \Sugar\Exception\UnknownDirectiveException If directive is not registered
     */
    public function get(string $name): DirectiveInterface
    {
        if (!$this->has($name)) {
            $suggestion = DidYouMean::suggest($name, array_keys($this->directives));

            throw new UnknownDirectiveException(
                directiveName: $name,
                suggestion: $suggestion,
            );
        }

        $compiler = $this->directives[$name];

        // Lazy instantiation: if a class name was registered, instantiate it
        if (is_string($compiler)) {
            $compiler = $this->resolveExtension($compiler, DirectiveInterface::class);
            $this->directives[$name] = $compiler; // Cache the instance
        }

        return $compiler;
    }

    /**
     * Get all registered directives
     *
     * Resolves all lazy-loaded class strings to instances.
     *
     * @return array<string, \Sugar\Directive\Interface\DirectiveInterface>
     */
    public function all(): array
    {
        // Resolve all lazy instances
        foreach (array_keys($this->directives) as $name) {
            $this->get($name); // This will instantiate and cache it
        }

        /** @var array<string, \Sugar\Directive\Interface\DirectiveInterface> $directives */
        $directives = $this->directives;

        return $directives;
    }

    /**
     * Get directives of a specific type
     *
     * @param \Sugar\Enum\DirectiveType $type Directive type to filter by
     * @return array<string, \Sugar\Directive\Interface\DirectiveInterface> Filtered directives
     */
    public function getByType(DirectiveType $type): array
    {
        $filtered = [];

        foreach (array_keys($this->directives) as $name) {
            $compiler = $this->get($name);
            if ($compiler->getType() === $type) {
                $filtered[$name] = $compiler;
            }
        }

        return $filtered;
    }

    /**
     * Register all built-in Sugar directives
     *
     * Called automatically by constructor unless withDefaults=false.
     */
    private function registerDefaults(): void
    {
        // Register all class-based directives
        foreach (self::DEFAULT_DIRECTIVES as $name => $compiler) {
            $this->register($name, $compiler);
        }

        // Register instance-based directives (ContentDirective with different configs)
        $this->register('text', new ContentDirective(escape: true));
        $this->register('html', new ContentDirective(escape: false, context: OutputContext::RAW));
    }

    /**
     * Resolve an extension to an instance
     *
     * Validates and instantiates a class string if needed.
     *
     * @template T of object
     * @param T|class-string<T> $extension Extension instance or class name
     * @param class-string<T> $interfaceName Required interface
     * @return T Resolved extension instance
     * @throws \RuntimeException If class doesn't exist or doesn't implement interface
     */
    private function resolveExtension(object|string $extension, string $interfaceName): object
    {
        if (is_string($extension)) {
            if (!class_exists($extension)) {
                throw new RuntimeException(sprintf('Extension class "%s" does not exist', $extension));
            }

            if (!is_subclass_of($extension, $interfaceName)) {
                throw new RuntimeException(
                    sprintf(
                        'Extension class "%s" must implement %s',
                        $extension,
                        $interfaceName,
                    ),
                );
            }

            return new $extension();
        }

        return $extension;
    }
}
