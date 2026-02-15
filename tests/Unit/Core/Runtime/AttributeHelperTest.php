<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Runtime\HtmlAttributeHelper;

final class AttributeHelperTest extends TestCase
{
    // ===== classNames() tests =====

    public function testClassNamesWithBasicClasses(): void
    {
        $result = HtmlAttributeHelper::classNames(['btn', 'btn-primary']);

        $this->assertSame('btn btn-primary', $result);
    }

    public function testClassNamesWithConditionalClasses(): void
    {
        $result = HtmlAttributeHelper::classNames([
            'btn',
            'active' => true,
            'disabled' => false,
        ]);

        $this->assertSame('btn active', $result);
    }

    public function testClassNamesIgnoresEmptyString(): void
    {
        $result = HtmlAttributeHelper::classNames(['card', '', 'shadow']);

        $this->assertSame('card shadow', $result);
    }

    public function testClassNamesWithMixedConditionals(): void
    {
        $isActive = true;
        $isDisabled = false;
        $hasError = true;

        $result = HtmlAttributeHelper::classNames([
            'form-control',
            'is-active' => $isActive,
            'is-disabled' => $isDisabled,
            'has-error' => $hasError,
        ]);

        $this->assertSame('form-control is-active has-error', $result);
    }

    public function testClassNamesWithAllFalseConditions(): void
    {
        $result = HtmlAttributeHelper::classNames([
            'active' => false,
            'disabled' => false,
            'hidden' => false,
        ]);

        $this->assertSame('', $result);
    }

    public function testClassNamesWithEmptyArray(): void
    {
        $result = HtmlAttributeHelper::classNames([]);

        $this->assertSame('', $result);
    }

    public function testClassNamesIgnoresNullValues(): void
    {
        $result = HtmlAttributeHelper::classNames(['btn', 'active' => null]);

        $this->assertSame('btn', $result);
    }

    public function testClassNamesIgnoresZeroAndFalsyValues(): void
    {
        $result = HtmlAttributeHelper::classNames([
            'btn',
            0,
            false,
            'active' => 0,
            'disabled' => '',
        ]);

        $this->assertSame('btn', $result);
    }

    public function testClassNamesComplexRealWorldExample(): void
    {
        $isAdmin = true;
        $isPremium = true;
        $hasNotifications = false;

        $result = HtmlAttributeHelper::classNames([
            'user-card',
            'user-admin' => $isAdmin,
            'user-premium' => $isPremium,
            'has-notifications' => $hasNotifications,
        ]);

        $this->assertSame('user-card user-admin user-premium', $result);
    }

    // ===== spreadAttrs() tests =====

    public function testSpreadAttrsWithBasicAttributes(): void
    {
        $result = HtmlAttributeHelper::spreadAttrs([
            'id' => 'user-123',
            'class' => 'card',
        ]);

        $this->assertSame('id="user-123" class="card"', $result);
    }

    public function testSpreadAttrsWithBooleanAttributes(): void
    {
        $result = HtmlAttributeHelper::spreadAttrs([
            'disabled' => true,
            'required' => true,
            'readonly' => false,
        ]);

        $this->assertSame('disabled required', $result);
    }

    public function testSpreadAttrsOmitsNullValues(): void
    {
        $result = HtmlAttributeHelper::spreadAttrs([
            'id' => 'test',
            'hidden' => null,
            'data-value' => 'test',
        ]);

        $this->assertSame('id="test" data-value="test"', $result);
    }

    public function testSpreadAttrsEscaping(): void
    {
        $result = HtmlAttributeHelper::spreadAttrs([
            'title' => 'Test "quoted" value',
            'data-html' => '<script>alert("xss")</script>',
        ]);

        $this->assertStringContainsString('&quot;', $result);
        $this->assertStringContainsString('&lt;', $result);
        $this->assertStringContainsString('&gt;', $result);
    }

    public function testSpreadAttrsWithDataAttributes(): void
    {
        $result = HtmlAttributeHelper::spreadAttrs([
            'data-user-id' => '123',
            'data-action' => 'click',
            'data-value' => 'test',
        ]);

        $this->assertSame('data-user-id="123" data-action="click" data-value="test"', $result);
    }

    public function testSpreadAttrsWithEmptyArray(): void
    {
        $result = HtmlAttributeHelper::spreadAttrs([]);

        $this->assertSame('', $result);
    }

    public function testSpreadAttrsWithNumericValues(): void
    {
        $result = HtmlAttributeHelper::spreadAttrs([
            'tabindex' => 0,
            'data-count' => 42,
        ]);

        $this->assertSame('tabindex="0" data-count="42"', $result);
    }

    public function testSpreadAttrsWithMixedBooleanAndRegular(): void
    {
        $result = HtmlAttributeHelper::spreadAttrs([
            'id' => 'button-1',
            'disabled' => true,
            'class' => 'btn btn-primary',
            'hidden' => false,
        ]);

        $this->assertSame('id="button-1" disabled class="btn btn-primary"', $result);
    }

    public function testSpreadAttrsRealWorldExample(): void
    {
        $attrs = [
            'id' => 'user-card-123',
            'class' => 'card shadow-sm',
            'data-user-id' => '123',
            'data-role' => 'admin',
            'aria-label' => 'User profile card',
            'disabled' => false,
        ];

        $result = HtmlAttributeHelper::spreadAttrs($attrs);

        $this->assertStringContainsString('id="user-card-123"', $result);
        $this->assertStringContainsString('class="card shadow-sm"', $result);
        $this->assertStringContainsString('data-user-id="123"', $result);
        $this->assertStringContainsString('aria-label="User profile card"', $result);
        $this->assertStringNotContainsString('disabled', $result);
    }

    public function testSpreadAttrsWithAriaAttributes(): void
    {
        $result = HtmlAttributeHelper::spreadAttrs([
            'aria-label' => 'Close button',
            'aria-expanded' => 'true',
            'role' => 'button',
        ]);

        $this->assertSame('aria-label="Close button" aria-expanded="true" role="button"', $result);
    }

    // ===== booleanAttribute() tests =====

    public function testBooleanAttributeWithTrueCondition(): void
    {
        $result = HtmlAttributeHelper::booleanAttribute('checked', true);

        $this->assertSame('checked', $result);
    }

    public function testBooleanAttributeWithFalseCondition(): void
    {
        $result = HtmlAttributeHelper::booleanAttribute('checked', false);

        $this->assertSame('', $result);
    }

    public function testBooleanAttributeWithTruthyValue(): void
    {
        $result = HtmlAttributeHelper::booleanAttribute('disabled', 1);

        $this->assertSame('disabled', $result);
    }

    public function testBooleanAttributeWithFalsyValue(): void
    {
        $result = HtmlAttributeHelper::booleanAttribute('disabled', 0);

        $this->assertSame('', $result);
    }

    public function testBooleanAttributeWithNullValue(): void
    {
        $result = HtmlAttributeHelper::booleanAttribute('required', null);

        $this->assertSame('', $result);
    }

    public function testBooleanAttributeWithEmptyString(): void
    {
        $result = HtmlAttributeHelper::booleanAttribute('selected', '');

        $this->assertSame('', $result);
    }

    public function testBooleanAttributeWithNonEmptyString(): void
    {
        $result = HtmlAttributeHelper::booleanAttribute('selected', 'yes');

        $this->assertSame('selected', $result);
    }

    public function testBooleanAttributeDisabled(): void
    {
        $isProcessing = true;
        $result = HtmlAttributeHelper::booleanAttribute('disabled', $isProcessing);

        $this->assertSame('disabled', $result);
    }

    public function testBooleanAttributeSelected(): void
    {
        // Test with truthy condition
        $selectedValue = 'premium'; // Value that would come from user data
        /** @phpstan-ignore identical.alwaysTrue */
        $result = HtmlAttributeHelper::booleanAttribute('selected', $selectedValue === 'premium');

        $this->assertSame('selected', $result);

        // Test with falsy condition
        $selectedValue = 'basic';
        /** @phpstan-ignore identical.alwaysFalse */
        $result = HtmlAttributeHelper::booleanAttribute('selected', $selectedValue === 'premium');

        $this->assertSame('', $result);
    }

    public function testBooleanAttributeChecked(): void
    {
        $items = [1, 2, 3];
        $item = 2;
        $result = HtmlAttributeHelper::booleanAttribute('checked', in_array($item, $items));

        $this->assertSame('checked', $result);
    }
}
