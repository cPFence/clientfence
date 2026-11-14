<?php

declare(strict_types=1);

/**
 * ClientFence: staff apply a promotion to an order on an unpaid invoice, or set one on an order for its renewals
 * (Promotions.php). Invoice, invoice lines and order rows are in in-memory SQLite; the services are small fakes.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Invoice\Promotions;
use ClientFence\Mod\Product\Entity\Product;
use ClientFence\Mod\Product\Entity\Promo;

use function Tests\Helpers\container;

/**
 * Invoice #5 of client #7: order #9 (price 100, untaxed unless $taxRate), unpaid. The promotion takes $discount off.
 * With $taxRate, prices include tax: the order is 100 gross, its invoice line net. $fee: a 10% gateway fee on the invoice.
 */
function promoWorld(string $status = 'unpaid', float $discount = 20.0, float $taxRate = 0.0, bool $fee = false, bool $recurring = true): object
{
    $world = new stdClass();
    $world->redemptions = [];
    $world->paidWithCredits = false;
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE invoice (id INTEGER PRIMARY KEY, client_id INTEGER, status TEXT, gateway_id INTEGER, taxrate REAL, tax_adjust REAL, currency TEXT, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE invoice_item (id INTEGER PRIMARY KEY, invoice_id INTEGER, type TEXT, rel_id INTEGER, task TEXT, unit TEXT, title TEXT, price REAL, quantity INTEGER, taxed INTEGER)');
    $db->executeStatement('CREATE TABLE client_order (id INTEGER PRIMARY KEY, promo_id INTEGER, promo_recurring INTEGER, promo_used INTEGER, discount REAL, updated_at TEXT)');
    $db->insert('invoice', ['id' => 5, 'client_id' => 7, 'status' => $status, 'gateway_id' => $fee ? 3 : null, 'taxrate' => $taxRate, 'tax_adjust' => 0, 'currency' => 'USD']);
    $net = $taxRate > 0 ? round(100 * 100 / (100 + $taxRate), 2) : 100.0;
    $db->insert('invoice_item', ['invoice_id' => 5, 'type' => 'order', 'rel_id' => 9, 'task' => 'activate', 'unit' => 'product', 'title' => 'Hosting', 'price' => $net, 'quantity' => 1, 'taxed' => $taxRate > 0 ? 1 : 0]);
    if ($fee) {
        $db->insert('invoice_item', ['invoice_id' => 5, 'type' => 'custom', 'task' => 'gateway_fee', 'title' => 'PayPal fee', 'price' => 10, 'quantity' => 1, 'taxed' => 0]);
    }
    $db->insert('client_order', ['id' => 9, 'discount' => 0]);
    $world->db = $db;

    $model = function (string $class, array $values): object {
        $m = new $class();
        $m->loadBean(new Tests\Helpers\DummyBean());
        foreach ($values as $key => $value) {
            $m->{$key} = $value;
        }

        return $m;
    };
    $world->invoice = $model(Model_Invoice::class, ['id' => 5, 'client_id' => 7, 'taxrate' => $taxRate, 'tax_adjust' => 0]);
    $world->order = $model(Model_ClientOrder::class, ['id' => 9, 'client_id' => 7, 'product_id' => 2, 'config' => '{}', 'period' => '1M', 'currency' => 'USD', 'price' => 100, 'quantity' => 1]);
    $client = $model(Model_Client::class, ['id' => 7]);

    $world->promo = new Promo();
    (new ReflectionProperty($world->promo, 'id'))->setValue($world->promo, 4);
    $world->promo->setCode('SAVE')->setRecurring($recurring);

    $product = Mockery::mock(Product::class);
    $product->allows('getType')->andReturn('custom');
    $products = Mockery::mock();
    $products->allows([
        'promoCanBeApplied' => true, 'isPromoAvailableForClientGroup' => true, 'canClientUsePromo' => true,
        'clientHasActivePromoApplicationForUpdate' => false, 'isPromoApplicableToProduct' => true, 'findProductById' => $product,
        'getPromoDiscountTitle' => 'SAVE',
    ]);
    $world->uses = 0;
    $products->allows('usePromo')->andReturnUsing(function () use ($world): void {
        ++$world->uses;
    });
    // Product\Service::getOrderPromoDiscount(): at most the order's price.
    $products->allows('getOrderPromoDiscount')->andReturnUsing(fn ($product, $promo, $config, float $price, float $quantity): float => min($discount, $price * $quantity));
    $products->allows('createPromoRedemption')->andReturnUsing(function (...$args) use ($world): void {
        $world->redemptions[] = [$args[4], $args[5], $args[8] ?? 'committed'];
    });

    $sum = fn (string $where = ''): float => (float) $db->fetchOne('SELECT COALESCE(SUM(price * quantity), 0) FROM invoice_item WHERE invoice_id = 5' . $where);
    $total = fn (Model_Invoice $invoice): float => round($sum() + round($sum(' AND taxed = 1') * (float) $invoice->taxrate / 100, 2) + (float) $invoice->tax_adjust, 2);
    $invoices = Mockery::mock();
    $invoices->allows('pricesIncludeTax')->andReturn($taxRate > 0);
    $invoices->allows('getTotalWithTax')->andReturnUsing($total);
    $invoices->allows('getBalanceDue')->andReturnUsing($total);
    $invoices->allows('isInvoiceTypeDeposit')->andReturn(false);
    $invoices->allows('tryPayWithCredits')->andReturnUsing(function () use ($world): void {
        $world->paidWithCredits = true;
    });
    $items = Mockery::mock();
    $items->allows('addNew')->andReturnUsing(function (Model_Invoice $invoice, array $data) use ($db): int {
        $db->insert('invoice_item', ['invoice_id' => 5, 'type' => 'custom', 'rel_id' => $data['rel_id'] ?? null, 'task' => $data['task'] ?? null, 'unit' => $data['unit'] ?? null, 'title' => $data['title'], 'price' => $data['price'], 'quantity' => 1, 'taxed' => (int) $data['taxed']]);

        return 1;
    });
    $items->allows('remove')->andReturnUsing(function (Model_InvoiceItem $line) use ($db): bool {
        return (bool) $db->delete('invoice_item', ['id' => $line->id]);
    });
    $items->allows('getTotalWithTax')->andReturnUsing(fn (Model_InvoiceItem $line): float => (float) $line->price);
    $rates = Mockery::mock();
    $rates->allows('getRateByCode')->andReturn(1.0);

    $di = container();
    $di['dbal'] = $db;
    $di['logger'] = new ClientFence\Log();
    $di['mod_service'] = $di->protect(fn (string $module, string $sub = ''): object => match (true) {
        $module === 'Product' => $products,
        $module === 'Currency' => new class($rates) {
            public function __construct(private readonly object $rates)
            {
            }

            public function getCurrencyRepository(): object
            {
                return $this->rates;
            }

            public function getBaseCurrencyRate(): float
            {
                return 1.0;
            }
        },
        $sub === 'InvoiceItem' => $items,
        default => $invoices,
    });
    $di['db'] = Mockery::mock();
    $di['db']->allows('getExistingModelById')->andReturnUsing(fn (string $type): object => $type === 'Client' ? $client : $world->order);
    $di['db']->allows('findOne')->andReturnUsing(function () use ($db, $model): ?Model_InvoiceItem {
        $row = $db->fetchAssociative("SELECT * FROM invoice_item WHERE task = 'gateway_fee'");

        return $row ? $model(Model_InvoiceItem::class, $row) : null;
    });
    $di['db']->allows('load')->andReturn($model(Model_PayGateway::class, ['id' => 3, 'name' => 'PayPal', 'config' => json_encode(['fee' => ['percent' => 10]])]));
    $world->di = $di;

    return $world;
}

test('a promotion applied to an unpaid invoice adds a discount line and records it on the order', function (): void {
    $world = promoWorld();

    expect(Promotions::apply($world->di, $world->invoice, $world->promo, null))->toBe(20.0);

    $line = $world->db->fetchAssociative("SELECT * FROM invoice_item WHERE unit = 'discount'");
    expect((float) $line['price'])->toBe(-20.0)->and((int) $line['rel_id'])->toBe(9)
        ->and($world->db->fetchAssociative('SELECT promo_id, promo_recurring, promo_used, discount FROM client_order WHERE id = 9'))
        ->toEqual(['promo_id' => 4, 'promo_recurring' => 1, 'promo_used' => 1, 'discount' => 20.0])
        // Reserved until the invoice is paid, like a code at checkout.
        ->and($world->redemptions)->toBe([['checkout', 20.0, 'reserved']]);
});

test('a promotion never takes the order line below zero', function (): void {
    $world = promoWorld(discount: 250.0);

    expect(Promotions::apply($world->di, $world->invoice, $world->promo, null))->toBe(100.0);
    expect((float) $world->db->fetchOne('SELECT SUM(price) FROM invoice_item'))->toBe(0.0);
});

test('with prices that include tax the total goes down by the discount the client sees', function (): void {
    $world = promoWorld(discount: 30.0, taxRate: 20.0);
    $invoices = $world->di['mod_service']('Invoice');
    $before = $invoices->getTotalWithTax($world->invoice);

    // 30 off the 100 gross is 25 net on the line.
    expect(Promotions::apply($world->di, $world->invoice, $world->promo, null))->toBe(25.0);
    expect($invoices->getTotalWithTax($world->invoice))->toBe(round($before - 30, 2));
});

test('the gateway fee line follows the lower balance', function (): void {
    $world = promoWorld(fee: true);

    Promotions::apply($world->di, $world->invoice, $world->promo, null);

    // 100 - 20 = 80 before the fee: 10% is 8.
    expect((float) $world->db->fetchOne("SELECT price FROM invoice_item WHERE task = 'gateway_fee'"))->toBe(8.0);
});

test('a promotion is refused on a paid or cancelled invoice and on an order that already has a discount', function (string $status, bool $discounted): void {
    $world = promoWorld($status);
    if ($discounted) {
        $world->db->insert('invoice_item', ['invoice_id' => 5, 'type' => 'custom', 'rel_id' => 9, 'unit' => 'discount', 'title' => 'CODE', 'price' => -5, 'quantity' => 1, 'taxed' => 0]);
    }

    expect(fn () => Promotions::apply($world->di, $world->invoice, $world->promo, null))->toThrow(InformationException::class);
    expect((int) $world->db->fetchOne("SELECT COUNT(*) FROM invoice_item WHERE unit = 'discount'"))->toBe($discounted ? 1 : 0)
        ->and($world->db->fetchOne('SELECT promo_id FROM client_order WHERE id = 9'))->toBeNull();
})->with([
    'paid' => ['paid', false],
    'cancelled' => ['cancelled', false],
    'already discounted' => ['unpaid', true],
]);

test('a balance the invoice already received in full is paid with credit', function (): void {
    $world = promoWorld(discount: 100.0);

    Promotions::apply($world->di, $world->invoice, $world->promo, null);

    expect($world->paidWithCredits)->toBeTrue();
});

test('staff set a recurring promotion on an order for its renewals, and remove it', function (): void {
    $world = promoWorld(discount: 15.0);

    expect(Promotions::setOnOrder($world->di, $world->order, $world->promo))->toBeTrue();
    expect($world->db->fetchAssociative('SELECT promo_id, promo_recurring, discount FROM client_order WHERE id = 9'))
        ->toEqual(['promo_id' => 4, 'promo_recurring' => 1, 'discount' => 15.0]);

    // Invoices already issued do not change.
    expect((int) $world->db->fetchOne("SELECT COUNT(*) FROM invoice_item WHERE unit = 'discount'"))->toBe(0);

    Promotions::setOnOrder($world->di, $world->order, null);
    expect($world->db->fetchAssociative('SELECT promo_id, promo_recurring, discount FROM client_order WHERE id = 9'))
        ->toEqual(['promo_id' => null, 'promo_recurring' => 0, 'discount' => 0.0]);
});

test('only a recurring promotion can be set on an order', function (): void {
    $world = promoWorld(recurring: false);

    expect(fn () => Promotions::setOnOrder($world->di, $world->order, $world->promo))->toThrow(InformationException::class, 'Only a recurring promotion comes off renewals.');
});

test('setting an order\'s promotion counts one use and records it; saving the same one again changes nothing', function (): void {
    $world = promoWorld(discount: 15.0);

    Promotions::setOnOrder($world->di, $world->order, $world->promo);
    expect($world->uses)->toBe(1)
        ->and($world->redemptions)->toBe([['checkout', null, 'committed']]);

    // The order now carries it: the same promotion saved again.
    [$world->order->promo_id, $world->order->promo_recurring] = [4, 1];
    Promotions::setOnOrder($world->di, $world->order, $world->promo);
    expect($world->uses)->toBe(1)
        ->and($world->redemptions)->toHaveCount(1);
});

test('a prorated order line takes the same share of the discount; the order keeps the full-period one', function (): void {
    // An add-on of 100 a month, its first invoice prorated to 50; the promotion is 50% (50 off the full price).
    $world = promoWorld(discount: 50.0);
    $world->db->update('invoice_item', ['price' => 50], ['type' => 'order']);

    expect(Promotions::apply($world->di, $world->invoice, $world->promo, null))->toBe(25.0)
        ->and((float) $world->db->fetchOne('SELECT discount FROM client_order WHERE id = 9'))->toBe(50.0);
});
