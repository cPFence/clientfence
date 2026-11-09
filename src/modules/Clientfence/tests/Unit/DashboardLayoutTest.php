<?php

declare(strict_types=1);

/**
 * ClientFence: the dashboard card order (Api\Admin::dashboardLayout()), saved or default.
 */

use ClientFence\Mod\Clientfence\Api\Admin;

function cfDashboardLayout(?array $cols): array
{
    return (new ReflectionMethod(Admin::class, 'dashboardLayout'))->invoke(null, $cols);
}

test('a card a saved order lacks joins its default column after the card it follows there', function (): void {
    $saved = [['orders', 'income', 'billing', 'activity'], ['clients', 'products', 'health', 'invoices']];

    expect(cfDashboardLayout($saved))->toBe([['orders', 'income', 'billing', 'paypal', 'activity'], ['clients', 'products', 'health', 'invoices']]);
});

test('without a saved order the PayPal balance card sits under Billing', function (): void {
    expect(cfDashboardLayout(null)[0])->toBe(['income', 'billing', 'paypal', 'orders', 'activity']);
});
