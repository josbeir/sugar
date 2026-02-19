<?php
declare(strict_types=1);

namespace Sugar\Core\Compiler\CodeGen;

use Sugar\Core\Escape\Escaper;
use Sugar\Core\Runtime\EmptyHelper;
use Sugar\Core\Runtime\HtmlAttributeHelper;
use Sugar\Core\Runtime\HtmlTagHelper;
use Sugar\Core\Runtime\RuntimeEnvironment;
use Sugar\Core\Runtime\TemplateRenderer;

/**
 * Shared aliases used in compiled templates and compiler-generated PHP fragments.
 */
final class GeneratedAlias
{
    public const RUNTIME_ENV = '__SugarRuntimeEnvironment';

    public const TEMPLATE_RENDERER = '__SugarTemplateRenderer';

    public const ESCAPER = '__SugarEscaper';

    public const HTML_ATTRIBUTE_HELPER = '__SugarHtmlAttributeHelper';

    public const EMPTY_HELPER = '__SugarEmptyHelper';

    public const HTML_TAG_HELPER = '__SugarHtmlTagHelper';

    /**
     * Map runtime/helper FQCNs to their generated alias names.
     *
     * @return array<class-string, string>
     */
    public static function runtimeReferenceMap(): array
    {
        return [
            RuntimeEnvironment::class => self::RUNTIME_ENV,
            TemplateRenderer::class => self::TEMPLATE_RENDERER,
            Escaper::class => self::ESCAPER,
            HtmlAttributeHelper::class => self::HTML_ATTRIBUTE_HELPER,
            EmptyHelper::class => self::EMPTY_HELPER,
            HtmlTagHelper::class => self::HTML_TAG_HELPER,
        ];
    }
}
