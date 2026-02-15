<?php
declare(strict_types=1);

namespace Sugar\Core\Directive\Interface;

use Sugar\Core\Enum\AttributeMergeMode;

/**
 * Defines optional merge policy hooks for attribute directives.
 *
 * Directives can implement this interface to control how their compiled
 * attribute output is normalized alongside existing attributes.
 */
interface AttributeMergePolicyDirectiveInterface extends DirectiveInterface
{
    /**
     * Returns the merge mode used during attribute extraction.
     */
    public function getAttributeMergeMode(): AttributeMergeMode;

    /**
     * Returns the named attribute targeted by MERGE_NAMED mode.
     *
     * @return string|null Attribute name to merge, or null when not applicable
     */
    public function getMergeTargetAttributeName(): ?string;

    /**
     * Builds a merged expression for MERGE_NAMED mode.
     *
     * @param string $existingExpression Existing attribute PHP expression
     * @param string $incomingExpression Incoming directive-generated PHP expression
     * @return string Merged PHP expression
     */
    public function mergeNamedAttributeExpression(string $existingExpression, string $incomingExpression): string;

    /**
     * Builds the final excluded-source expression for EXCLUDE_NAMED mode.
     *
     * @param string $sourceExpression Original directive source expression
     * @param array<int, string> $excludedAttributeNames Attribute names to exclude from source payload
     * @return string Final PHP expression with exclusions applied
     */
    public function buildExcludedAttributesExpression(string $sourceExpression, array $excludedAttributeNames): string;
}
