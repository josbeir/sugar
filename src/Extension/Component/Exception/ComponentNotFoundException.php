<?php
declare(strict_types=1);

namespace Sugar\Extension\Component\Exception;

use Sugar\Core\Exception\TemplateRuntimeException;

/**
 * Exception thrown when a component cannot be resolved by the component extension.
 */
final class ComponentNotFoundException extends TemplateRuntimeException
{
}
