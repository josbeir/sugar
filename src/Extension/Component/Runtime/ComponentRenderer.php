<?php
declare(strict_types=1);

namespace Sugar\Extension\Component\Runtime;

use Sugar\Core\Compiler\Pipeline\Enum\PassPriority;
use Sugar\Core\Exception\TemplateRuntimeException;
use Sugar\Core\Runtime\RuntimeEnvironment;
use Sugar\Core\Runtime\TemplateRenderer;
use Sugar\Core\Util\ValueNormalizer;
use Sugar\Extension\Component\Exception\ComponentNotFoundException;
use Sugar\Extension\Component\Loader\ComponentLoaderInterface;
use Sugar\Extension\Component\Pass\ComponentVariantAdjustmentPass;
use Sugar\Extension\Component\Pass\SlotOutletPass;

/**
 * Renders components at runtime for dynamic component calls.
 *
 * Delegates template compilation and execution to the shared TemplateRenderer
 * service while handling component-specific concerns: name resolution, slot
 * normalization, attribute merging, and the ComponentVariantAdjustmentPass.
 */
final class ComponentRenderer
{
    /**
     * @param \Sugar\Extension\Component\Loader\ComponentLoaderInterface $loader Component template loader
     * @param string $slotAttributeName Full slot attribute name (e.g. 's:slot')
     */
    public function __construct(
        private readonly ComponentLoaderInterface $loader,
        private readonly string $slotAttributeName,
    ) {
    }

    /**
     * Render a component by name with bindings, slots, and attributes.
     *
     * @param string $name Component name
     * @param array<string, mixed> $vars Bound variables (s:bind)
     * @param array<string, mixed> $slots Slot content
     * @param array<string, mixed> $attributes Runtime attributes
     * @param array<string, array{tag: string, attrs: array<string, string|null>}> $slotMeta Caller slot element metadata for tag swapping and attribute merging
     */
    public function renderComponent(
        string $name,
        array $vars = [],
        array $slots = [],
        array $attributes = [],
        array $slotMeta = [],
    ): string {
        $componentName = trim($name);
        if ($componentName === '') {
            throw new ComponentNotFoundException('Component "" not found');
        }

        try {
            $componentPath = $this->loader->getComponentPath($componentName);
        } catch (TemplateRuntimeException $templateRuntimeException) {
            throw new ComponentNotFoundException(
                $templateRuntimeException->getRawMessage(),
                $templateRuntimeException->getCode(),
                previous: $templateRuntimeException,
            );
        }

        $slotNames = array_keys($slots);
        if (!in_array('slot', $slotNames, true)) {
            $slotNames[] = 'slot';
        }

        sort($slotNames);

        $data = $this->normalizeRenderData($vars, $slots, $attributes, $slotMeta);
        $inlinePasses = $this->buildInlinePasses($slotNames);

        $templateRenderer = RuntimeEnvironment::requireService(TemplateRenderer::class);

        // Track component source file for cache invalidation
        $templateRenderer->addDependency(
            $this->loader->getComponentFilePath($componentName),
        );

        return $templateRenderer->renderTemplate(
            template: $componentPath,
            data: $data,
            inlinePasses: $inlinePasses,
            variantKeys: $slotNames,
        );
    }

    /**
     * Build inline passes needed for component variant compilation.
     *
     * Registers three inline passes:
     * - SlotOutletPass at PRE_DIRECTIVE_EXTRACTION: Transforms `s:slot` outlets into
     *   conditional PHP that delegates to `SlotOutletHelper::render()`.
     * - ComponentVariantAdjustmentPass at DIRECTIVE_PAIRING: Applies attribute overrides
     *   to root elements stored inside directive nodes.
     * - ComponentVariantAdjustmentPass at POST_DIRECTIVE_COMPILATION: Disables escaping
     *   for slot variables and applies attribute overrides to root elements.
     *
     * @param array<string> $slotNames Slot names for escaping adjustment
     * @return array<array{pass: \Sugar\Core\Compiler\Pipeline\AstPassInterface, priority: \Sugar\Core\Compiler\Pipeline\Enum\PassPriority}>
     */
    private function buildInlinePasses(array $slotNames): array
    {
        $slotVars = array_values(array_unique(array_merge(['slot'], $slotNames)));

        return [
            [
                'pass' => new SlotOutletPass($this->slotAttributeName),
                'priority' => PassPriority::PRE_DIRECTIVE_EXTRACTION,
            ],
            [
                'pass' => new ComponentVariantAdjustmentPass($slotVars, directiveRootOnly: true),
                'priority' => PassPriority::DIRECTIVE_PAIRING,
            ],
            [
                'pass' => new ComponentVariantAdjustmentPass($slotVars),
                'priority' => PassPriority::POST_DIRECTIVE_COMPILATION,
            ],
        ];
    }

    /**
     * Normalize render data for component execution.
     *
     * Merges bound variables, normalized slot content, attributes, and slot
     * metadata into a single data array for template execution.
     *
     * @param array<string, mixed> $vars Bound variables
     * @param array<string, mixed> $slots Slot content
     * @param array<string, mixed> $attributes Runtime attributes
     * @param array<string, array{tag: string, attrs: array<string, string|null>}> $slotMeta Caller slot element metadata
     * @return array<string, mixed> Merged data for template execution
     */
    private function normalizeRenderData(
        array $vars,
        array $slots,
        array $attributes,
        array $slotMeta = [],
    ): array {
        $normalizedSlots = [];
        foreach ($slots as $slotName => $value) {
            $normalizedSlots[$slotName] = ValueNormalizer::toDisplayString($value);
        }

        if (!isset($normalizedSlots['slot'])) {
            $normalizedSlots['slot'] = '';
        }

        $normalizedAttributes = $this->normalizeAttributes($attributes);

        $data = $vars;
        foreach ($normalizedSlots as $slotName => $value) {
            $data[$slotName] = $value;
        }

        $data['__sugar_attrs'] = $normalizedAttributes;
        $data['__slot_meta'] = $slotMeta;

        return $data;
    }

    /**
     * Normalize attribute array values to stringable values.
     *
     * @param array<string, mixed> $attributes Raw attributes
     * @return array<string, mixed> Normalized attributes
     */
    private function normalizeAttributes(array $attributes): array
    {
        $normalized = [];

        foreach ($attributes as $name => $value) {
            $key = (string)$name;
            $normalized[$key] = ValueNormalizer::toAttributeValue($value);
        }

        return $normalized;
    }
}
