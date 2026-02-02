<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Sugar\Runtime\AttributeHelper;

final class AttributeHelperTest extends TestCase
{
    // ===== classNames() tests =====

    public function testClassNamesWithBasicClasses(): void
    {
        $result = AttributeHelper::classNames(['btn', 'btn-primary']);

        $this->assertSame('btn btn-primary', $result);
    }

    public function testClassNamesWithConditionalClasses(): void
    {
        $result = AttributeHelper::classNames([
            'btn',
            'active' => true,
            'disabled' => false,
        ]);

        $this->assertSame('btn active', $result);
    }

    public function testClassNamesIgnoresEmptyString(): void
    {
        $result = AttributeHelper::classNames(['card', '', 'shadow']);

        $this->assertSame('card shadow', $result);
    }

    public function testClassNamesWithMixedConditionals(): void
    {
        $isActive = true;
        $isDisabled = false;
        $hasError = true;

        $result = AttributeHelper::classNames([
            'form-control',
            'is-active' => $isActive,
            'is-disabled' => $isDisabled,
            'has-error' => $hasError,
        ]);

        $this->assertSame('form-control is-active has-error', $result);
    }

    public function testClassNamesWithAllFalseConditions(): void
    {
        $result = AttributeHelper::classNames([
            'active' => false,
            'disabled' => false,
            'hidden' => false,
        ]);

        $this->assertSame('', $result);
    }

    public function testClassNamesWithEmptyArray(): void
    {
        $result = AttributeHelper::classNames([]);

        $this->assertSame('', $result);
    }

    public function testClassNamesIgnoresNullValues(): void
    {
        $result = AttributeHelper::classNames(['btn', 'active' => null]);

        $this->assertSame('btn', $result);
    }

    public function testClassNamesIgnoresZeroAndFalsyValues(): void
    {
        $result = AttributeHelper::classNames([
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
        $userRole = 'admin';
        $isPremium = true;
        $hasNotifications = false;

        $result = AttributeHelper::classNames([
            'user-card',
            'user-admin' => $userRole === 'admin',
            'user-premium' => $isPremium,
            'has-notifications' => $hasNotifications,
        ]);

        $this->assertSame('user-card user-admin user-premium', $result);
    }

    // ===== spreadAttrs() tests =====

    public function testSpreadAttrsWithBasicAttributes(): void
    {
        $result = AttributeHelper::spreadAttrs([
            'id' => 'user-123',
            'class' => 'card',
        ]);

        $this->assertSame('id="user-123" class="card"', $result);
    }

    public function testSpreadAttrsWithBooleanAttributes(): void
    {
        $result = AttributeHelper::spreadAttrs([
            'disabled' => true,
            'required' => true,
            'readonly' => false,
        ]);

        $this->assertSame('disabled required', $result);
    }

    public function testSpreadAttrsOmitsNullValues(): void
    {
        $result = AttributeHelper::spreadAttrs([
            'id' => 'test',
            'hidden' => null,
            'data-value' => 'test',
        ]);

        $this->assertSame('id="test" data-value="test"', $result);
    }

    public function testSpreadAttrsEscaping(): void
    {
        $result = AttributeHelper::spreadAttrs([
            'title' => 'Test "quoted" value',
            'data-html' => '<script>alert("xss")</script>',
        ]);

        $this->assertStringContainsString('&quot;', $result);
        $this->assertStringContainsString('&lt;', $result);
        $this->assertStringContainsString('&gt;', $result);
    }

    public function testSpreadAttrsWithDataAttributes(): void
    {
        $result = AttributeHelper::spreadAttrs([
            'data-user-id' => '123',
            'data-action' => 'click',
            'data-value' => 'test',
        ]);

        $this->assertSame('data-user-id="123" data-action="click" data-value="test"', $result);
    }

    public function testSpreadAttrsWithEmptyArray(): void
    {
        $result = AttributeHelper::spreadAttrs([]);

        $this->assertSame('', $result);
    }

    public function testSpreadAttrsWithNumericValues(): void
    {
        $result = AttributeHelper::spreadAttrs([
            'tabindex' => 0,
            'data-count' => 42,
        ]);

        $this->assertSame('tabindex="0" data-count="42"', $result);
    }

    public function testSpreadAttrsWithMixedBooleanAndRegular(): void
    {
        $result = AttributeHelper::spreadAttrs([
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

        $result = AttributeHelper::spreadAttrs($attrs);

        $this->assertStringContainsString('id="user-card-123"', $result);
        $this->assertStringContainsString('class="card shadow-sm"', $result);
        $this->assertStringContainsString('data-user-id="123"', $result);
        $this->assertStringContainsString('aria-label="User profile card"', $result);
        $this->assertStringNotContainsString('disabled', $result);
    }

    public function testSpreadAttrsWithAriaAttributes(): void
    {
        $result = AttributeHelper::spreadAttrs([
            'aria-label' => 'Close button',
            'aria-expanded' => 'true',
            'role' => 'button',
        ]);

        $this->assertSame('aria-label="Close button" aria-expanded="true" role="button"', $result);
    }
}
