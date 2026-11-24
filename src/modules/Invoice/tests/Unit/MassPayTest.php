<?php

declare(strict_types=1);

/**
 * ClientFence (CF-182): Mass Pay (MassPay.php). The invoices, lines, applied credit, balance and payments are rows in in-memory
 * SQLite; the invoice service is a small stand-in whose tryPayWithCredits() pays as the real one does (the balance less what
 * other unpaid invoices hold must cover the total; one debit; paid) and counts each payment.
 */

use ClientFence\Mod\Invoice\GatewayFees;
use ClientFence\Mod\Invoice\MassPay;
use ClientFence\Mod\Invoice\PendingPayments;

use function Tests\Helpers\container;

/**
 * Client #7 (USD). Invoices: #1 100.00 and #2 50.00, unpaid and issued, with whatever $setup adds. Gateway #3 charges 2.5%.
 */
function massPayWorld(?callable $setup = null): object
{
    $world = new stdClass();
    $world->paid = [];
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE invoice (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, serie TEXT, nr TEXT, hash TEXT, status TEXT, approved INTEGER, currency TEXT, gateway_id INTEGER, taxrate REAL DEFAULT 0, tax_adjust REAL DEFAULT 0, base_income REAL, notes TEXT, created_at TEXT, updated_at TEXT, site TEXT)',
        'CREATE TABLE client (id INTEGER PRIMARY KEY, site TEXT)',
        'CREATE TABLE invoice_item (id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER, type TEXT, task TEXT, status TEXT, title TEXT, price REAL, quantity INTEGER, charged INTEGER DEFAULT 0, taxed INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE invoice_credit (id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER, client_id INTEGER, amount REAL, created_at TEXT)',
        'CREATE TABLE client_balance (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, amount REAL, description TEXT)',
        'CREATE TABLE extension_meta (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, extension TEXT, rel_type TEXT, rel_id TEXT, meta_key TEXT, meta_value TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE "transaction" (id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER, amount REAL, currency TEXT, status TEXT, txn_status TEXT, created_at TEXT)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    foreach ([1 => 100.0, 2 => 50.0] as $id => $price) {
        $db->insert('invoice', ['id' => $id, 'client_id' => 7, 'serie' => 'INV', 'nr' => (string) $id, 'status' => 'unpaid', 'approved' => 1, 'currency' => 'USD']);
        $db->insert('invoice_item', ['invoice_id' => $id, 'type' => 'custom', 'title' => 'Hosting', 'price' => $price, 'quantity' => 1]);
    }
    $world->db = $db;

    $model = static function (string $class, array $row): object {
        $model = new $class();
        $model->loadBean(new Tests\Helpers\DummyBean());
        foreach ($row as $key => $value) {
            $model->{$key} = $value;
        }

        return $model;
    };
    $invoice = static fn (int $id): ?Model_Invoice => ($row = $db->fetchAssociative('SELECT * FROM invoice WHERE id = ?', [$id])) ? $model(Model_Invoice::class, $row) : null;
    $world->invoice = $invoice;
    $total = static fn (int $id): float => round((float) $db->fetchOne('SELECT COALESCE(SUM(price * quantity), 0) FROM invoice_item WHERE invoice_id = ?', [$id]), 2);
    $paidOn = static fn (int $id): float => round((float) $db->fetchOne('SELECT COALESCE(SUM(amount), 0) FROM invoice_credit WHERE invoice_id = ?', [$id])
        + (float) $db->fetchOne("SELECT COALESCE(SUM(amount), 0) FROM \"transaction\" WHERE invoice_id = ? AND status = 'processed'", [$id]), 2);
    $world->balance = static fn (): float => round((float) $db->fetchOne('SELECT COALESCE(SUM(amount), 0) FROM client_balance WHERE client_id = 7'), 2);

    $service = new class($db, $world, $total, $paidOn) {
        public function __construct(private $db, private $world, private $total, private $paidOn)
        {
        }

        public function getBalanceDue(Model_Invoice $invoice): float
        {
            return max(0.0, round(($this->total)((int) $invoice->id) - ($this->paidOn)((int) $invoice->id), 2));
        }

        public function isInvoiceTypeDeposit(Model_Invoice $invoice): bool
        {
            return $this->db->fetchOne("SELECT 1 FROM invoice_item WHERE invoice_id = ? AND type = 'deposit'", [$invoice->id]) !== false;
        }

        public function setInvoiceDefaults(Model_Invoice $invoice): void
        {
            // As RedBean's store(): every column the bean was loaded with is written back.
            $this->db->update('invoice', ['serie' => 'INV', 'nr' => (string) $invoice->id, 'hash' => 'hash-' . $invoice->id] + $invoice->unbox()->getProperties(), ['id' => $invoice->id]);
            $invoice->hash = 'hash-' . $invoice->id;
        }

        // As Service::tryPayWithCredits(): what other unpaid invoices hold (Service::getReservedCredit()) stays theirs.
        public function tryPayWithCredits(Model_Invoice $invoice): bool
        {
            if ($this->db->fetchOne('SELECT status FROM invoice WHERE id = ?', [$invoice->id]) !== 'unpaid') {
                return false;
            }
            $reserved = 0.0;
            foreach ($this->db->fetchFirstColumn("SELECT id FROM invoice WHERE client_id = 7 AND status = 'unpaid' AND id <> ?", [$invoice->id]) as $other) {
                $reserved += min(($this->paidOn)((int) $other), ($this->total)((int) $other));
            }
            $required = ($this->total)((int) $invoice->id);
            if (round(($this->world->balance)() - $reserved, 2) < $required) {
                return false;
            }
            $this->db->insert('client_balance', ['client_id' => 7, 'amount' => -$required, 'description' => 'Payment for invoice #' . $invoice->id . ' using account credit.']);
            $this->db->update('invoice', ['status' => 'paid'], ['id' => $invoice->id]);
            $this->world->paid[(int) $invoice->id] = ($this->world->paid[(int) $invoice->id] ?? 0) + 1;

            return true;
        }

        public function addNote(Model_Invoice $invoice, string $note): bool
        {
            return true;
        }
    };
    $items = new class($db, $model) {
        public function __construct(private $db, private $model)
        {
        }

        public function remove(Model_InvoiceItem $line): bool
        {
            return (bool) $this->db->delete('invoice_item', ['id' => $line->id]);
        }

        public function addNew(Model_Invoice $invoice, array $data): int
        {
            $this->db->insert('invoice_item', ['invoice_id' => $invoice->id, 'type' => 'custom', 'title' => $data['title'], 'price' => $data['price'], 'quantity' => 1, 'taxed' => (int) $data['taxed'], 'task' => $data['task']]);

            return (int) $this->db->lastInsertId();
        }
    };
    $currency = new class {
        public function toBaseCurrency(string $code, float $amount): float
        {
            return $amount;
        }
    };
    $gateway = $model(Model_PayGateway::class, ['id' => 3, 'name' => 'PayPal', 'config' => json_encode(['fee' => ['percent' => 2.5]])]);

    $di = container();
    $di['dbal'] = $db;
    $di['mod_service'] = $di->protect(fn (string $module, string $sub = ''): object => match (true) {
        $sub === 'InvoiceItem' => $items,
        strtolower($module) === 'currency' => $currency,
        default => $service,
    });
    $di['db'] = Mockery::mock(ClientFence\Database::class);
    $di['db']->allows('getExistingModelById')->andReturnUsing(fn (string $type, int $id): Model_Invoice => $invoice($id) ?? throw new ClientFence\InformationException('Invoice not found'));
    $di['db']->allows('load')->andReturnUsing(fn (string $type, int $id): ?object => $type === 'PayGateway' ? $gateway : $invoice($id));
    // GatewayFees::line(): the invoice's fee line.
    $di['db']->allows('findOne')->andReturnUsing(fn (string $type, string $where, array $args): ?Model_InvoiceItem => ($row = $db->fetchAssociative('SELECT * FROM invoice_item WHERE invoice_id = ? AND type = ? AND task = ?', $args)) ? $model(Model_InvoiceItem::class, $row) : null);
    $world->di = $di;
    $world->client = $model(Model_Client::class, ['id' => 7, 'currency' => 'USD']);
    if ($setup !== null) {
        $setup($db);
    }

    return $world;
}

/** A gateway pays the grouped invoice: its whole balance due to the client's balance, the fee line charged, paid (markAsPaid()). */
function massPayByGateway(object $world, Model_Invoice $group): void
{
    $db = $world->db;
    $received = $world->di['mod_service']('Invoice')->getBalanceDue($group);
    $db->insert('client_balance', ['client_id' => 7, 'amount' => $received, 'description' => 'PayPal transaction']);
    $fee = (float) $db->fetchOne("SELECT COALESCE(SUM(price * quantity), 0) FROM invoice_item WHERE invoice_id = ? AND charged = 0 AND type <> 'deposit'", [$group->id]);
    if ($fee > 0) {
        $db->insert('client_balance', ['client_id' => 7, 'amount' => -$fee, 'description' => 'PayPal fee']);
    }
    $db->update('invoice', ['status' => 'paid'], ['id' => $group->id]);
    MassPay::settle($world->di, ($world->invoice)((int) $group->id));
}

test('grouping refuses invoices that cannot be paid together, and fewer than two', function (callable $setup, array $ids, string $error): void {
    $world = massPayWorld($setup);

    expect(fn () => MassPay::create($world->di, $world->client, $ids))->toThrow(ClientFence\InformationException::class, $error)
        ->and((int) $world->db->fetchOne("SELECT COUNT(*) FROM invoice_item WHERE task LIKE 'mass_pay:%'"))->toBe(0);
})->with([
    'one invoice' => [fn ($db) => null, [1], 'Choose at least two unpaid invoices to pay together.'],
    'another client\'s' => [fn ($db) => $db->insert('invoice', ['id' => 9, 'client_id' => 8, 'status' => 'unpaid', 'approved' => 1, 'currency' => 'USD']), [1, 9], 'Invoice was not found'],
    'paid' => [fn ($db) => $db->update('invoice', ['status' => 'paid'], ['id' => 2]), [1, 2], 'cannot be paid together'],
    'draft' => [fn ($db) => $db->update('invoice', ['approved' => 0], ['id' => 2]), [1, 2], 'cannot be paid together'],
    'other currency' => [fn ($db) => $db->update('invoice', ['currency' => 'EUR'], ['id' => 2]), [1, 2], 'cannot be paid together'],
    'Add funds' => [fn ($db) => $db->update('invoice_item', ['type' => 'deposit'], ['invoice_id' => 2]), [1, 2], 'cannot be paid together'],
    'bank debit clearing' => [fn ($db) => $db->insert('"transaction"', ['invoice_id' => 2, 'amount' => 50, 'status' => 'received', 'txn_status' => 'processing', 'created_at' => date('Y-m-d H:i:s')]), [1, 2], 'cannot be paid together'],
]);

test('the grouped invoice lists what each invoice owes now, untaxed, without its fee; a second click gets it again', function (): void {
    $world = massPayWorld(function ($db): void {
        // #1: 30.00 paid by bank transfer and 10.00 of credit applied; #2 carries a 2.00 fee of its own gateway.
        $db->insert('"transaction"', ['invoice_id' => 1, 'amount' => 30, 'status' => 'processed']);
        $db->insert('invoice_credit', ['invoice_id' => 1, 'client_id' => 7, 'amount' => 10]);
        $db->insert('invoice_item', ['invoice_id' => 2, 'type' => 'custom', 'task' => GatewayFees::TASK, 'title' => 'Card fee', 'price' => 2, 'quantity' => 1]);
        // Not groupable, so Pay all leaves it out: a draft.
        $db->insert('invoice', ['id' => 5, 'client_id' => 7, 'status' => 'unpaid', 'approved' => 0, 'currency' => 'USD']);
    });

    $group = MassPay::create($world->di, $world->client, [], true);
    $lines = $world->db->fetchAllAssociative('SELECT type, task, title, price, taxed, charged FROM invoice_item WHERE invoice_id = ? ORDER BY id', [$group->id]);

    expect(MassPay::lines($world->di, (int) $group->id))->toBe([1 => 60.0, 2 => 50.0])
        ->and(array_column($lines, 'title'))->toBe(['Invoice INV00001', 'Invoice INV00002'])
        ->and(array_unique(array_column($lines, 'type')))->toBe(['deposit'])
        ->and(array_map('intval', array_column($lines, 'taxed')))->toBe([0, 0])
        ->and($world->di['mod_service']('Invoice')->getBalanceDue($group))->toBe(110.0)
        ->and(MassPay::is($world->di, (int) $group->id))->toBeTrue()
        ->and(MassPay::is($world->di, 1))->toBeFalse()
        ->and((int) MassPay::create($world->di, $world->client, [2, 1])->id)->toBe((int) $group->id)
        // ClientFence: the new invoice's event, once; the second click makes none (ClientFence\Site).
        ->and($world->di['events_manager']->events)->toBe([['event' => 'onAfterNewInvoice', 'params' => ['id' => (int) $group->id], 'isolated' => true]]);
});

test('the grouped invoice is on the site of the invoices it pays, also once its defaults are saved; invoices of two sites are not paid together, and Pay all pays those of the site the client is on (ClientFence\\Site)', function (): void {
    // Client #7 is on site B: invoice #1 has no site of its own (its client's), #2 is on B.
    $world = massPayWorld(function ($db): void {
        $db->insert('client', ['id' => 7, 'site' => 'shop/b']);
        $db->update('invoice', ['site' => 'shop/b'], ['id' => 2]);
    });
    $di = $world->di;
    $services = $di['mod_service'];
    $extension = Mockery::mock();
    $extension->allows('getCoreAndActiveModules')->andReturn(['shop']);
    // The site module answers for its sites b and c; shop/gone is a site it no longer serves.
    $shop = new class {
        public function siteByKey(string $key): ?ClientFence\Site
        {
            return in_array($key, ['b', 'c'], true) ? new ClientFence\Site('https://' . $key . '.example.test/', $key . '.example.test', $key) : null;
        }
    };
    $di['mod_service'] = $di->protect(fn (string $module, string $sub = ''): object => match (strtolower($module)) {
        'extension' => $extension,
        'shop' => $shop,
        default => $services($module, $sub),
    });
    $mod = Mockery::mock(ClientFence\Module::class);
    $mod->allows('hasManifest')->andReturn(true);
    $mod->allows('getManifest')->andReturn(['sites' => true]);
    $mod->allows('cannotRun')->andReturn(null);
    $di['mod'] = $di->protect(fn (): object => $mod);
    $onC = static fn (callable $fn): mixed => ClientFence\Site::within($di, new ClientFence\Site('https://c.example.test/', 'c.example.test', 'shop/c'), $fn);
    $onB = static fn (callable $fn): mixed => ClientFence\Site::within($di, new ClientFence\Site('https://b.example.test/', 'b.example.test', 'shop/b'), $fn);

    // Paid from a request on site C.
    $group = $onC(fn (): Model_Invoice => MassPay::create($di, $world->client, [1, 2]));
    // #2 moves to C, and #9 is on C too.
    $world->db->update('invoice', ['site' => 'shop/c'], ['id' => 2]);
    $world->db->insert('invoice', ['id' => 9, 'client_id' => 7, 'serie' => 'INV', 'nr' => '9', 'status' => 'unpaid', 'approved' => 1, 'currency' => 'USD', 'site' => 'shop/c']);
    $world->db->insert('invoice_item', ['invoice_id' => 9, 'type' => 'custom', 'title' => 'Hosting', 'price' => 20, 'quantity' => 1]);
    // The list offers Pay all on C (#2, #9), not on B (#1; the grouped invoice is not counted) nor on the primary site.
    $offered = [$onC(fn (): bool => MassPay::payAllHere($di, 7)), $onB(fn (): bool => MassPay::payAllHere($di, 7)), MassPay::payAllHere($di, 7)];
    $payAllOnC = $onC(fn (): Model_Invoice => MassPay::create($di, $world->client, [], true));

    expect($world->db->fetchOne('SELECT site FROM invoice WHERE id = ?', [$group->id]))->toBe('shop/b')
        ->and($offered)->toBe([true, false, false])
        ->and(fn () => $onC(fn (): Model_Invoice => MassPay::create($di, $world->client, [1, 2])))->toThrow(ClientFence\InformationException::class, 'These invoices are from different sites: pay the invoices of one site together.')
        // Pay all on C: #2 and #9; #1 stays unpaid for B.
        ->and(MassPay::lines($di, (int) $payAllOnC->id))->toBe([2 => 50.0, 9 => 20.0])
        ->and($world->db->fetchOne('SELECT site FROM invoice WHERE id = ?', [$payAllOnC->id]))->toBe('shop/c')
        // Pay all on B: only #1 is left there.
        ->and(fn () => $onB(fn (): Model_Invoice => MassPay::create($di, $world->client, [], true)))->toThrow(ClientFence\InformationException::class, 'Choose at least two unpaid invoices to pay together.');

    // A site its module no longer serves resolves to the primary site, where its clients now are: Pay all there pays them.
    foreach ([20 => 30, 21 => 40] as $id => $price) {
        $world->db->insert('invoice', ['id' => $id, 'client_id' => 7, 'serie' => 'INV', 'nr' => (string) $id, 'status' => 'unpaid', 'approved' => 1, 'currency' => 'USD', 'site' => 'shop/gone']);
        $world->db->insert('invoice_item', ['invoice_id' => $id, 'type' => 'custom', 'title' => 'Hosting', 'price' => $price, 'quantity' => 1]);
    }
    expect(MassPay::lines($di, (int) MassPay::create($di, $world->client, [], true)->id))->toBe([20 => 30.0, 21 => 40.0]);

    // Picked by hand, an invoice with no site and one of the site no longer served are both on the primary site.
    $world->db->insert('client', ['id' => 8, 'site' => null]);
    $world->db->update('invoice', ['client_id' => 8, 'site' => null], ['id' => 20]);
    $world->db->update('invoice', ['client_id' => 8], ['id' => 21]);
    $world->client->id = 8;
    expect(MassPay::lines($di, (int) MassPay::create($di, $world->client, [20, 21])->id))->toBe([20 => 30.0, 21 => 40.0]);
});

test('without a site module the invoice list offers Pay all as before, and nothing is read for it (ClientFence\\Site)', function (): void {
    $world = massPayWorld();
    // Any query fails.
    $world->di['dbal'] = Mockery::mock(Doctrine\DBAL\Connection::class);

    expect(MassPay::payAllHere($world->di, 7))->toBeTrue();
});

test('paying the grouped invoice pays each invoice once and leaves the balance where it was', function (): void {
    $world = massPayWorld();
    $group = MassPay::create($world->di, $world->client, [1, 2]);

    massPayByGateway($world, $group);
    MassPay::settle($world->di, ($world->invoice)((int) $group->id));

    expect($world->paid)->toBe([1 => 1, 2 => 1])
        ->and($world->db->fetchFirstColumn('SELECT status FROM invoice WHERE id IN (1, 2, ?) ORDER BY id', [$group->id]))->toBe(['paid', 'paid', 'paid'])
        ->and(($world->balance)())->toBe(0.0);
});

test('an invoice paid before the grouped one is not paid again: its amount stays account credit', function (): void {
    $world = massPayWorld();
    $group = MassPay::create($world->di, $world->client, [1, 2]);
    $world->db->update('invoice', ['status' => 'paid'], ['id' => 2]);

    expect(fn () => MassPay::check($world->di, $group))->toThrow(ClientFence\InformationException::class, 'was paid or changed since');

    massPayByGateway($world, $group);

    expect($world->paid)->toBe([1 => 1])
        ->and(($world->balance)())->toBe(50.0);
});

test('one payment pays one fee: the grouped invoice\'s, never the invoices\' own', function (): void {
    $world = massPayWorld(fn ($db) => $db->insert('invoice_item', ['invoice_id' => 2, 'type' => 'custom', 'task' => GatewayFees::TASK, 'title' => 'Card fee', 'price' => 2, 'quantity' => 1]));
    $group = MassPay::create($world->di, $world->client, [1, 2]);
    // The client pays it with PayPal (2.5%): Add funds invoices carry no fee, a Mass Pay one does.
    GatewayFees::apply($world->di, $group, 3);

    expect($world->di['mod_service']('Invoice')->getBalanceDue($group))->toBe(153.75);

    massPayByGateway($world, $group);

    $fees = $world->db->fetchAllAssociative('SELECT invoice_id, price FROM invoice_item WHERE task = ?', [GatewayFees::TASK]);
    expect($fees)->toBe([['invoice_id' => (int) $group->id, 'price' => 3.75]])
        ->and($world->paid)->toBe([1 => 1, 2 => 1])
        ->and(($world->balance)())->toBe(0.0);
});

test('while the grouped invoice\'s bank debit clears, the invoices it lists wait for it too, and its payment still pays them', function (): void {
    $world = massPayWorld();
    $group = MassPay::create($world->di, $world->client, [1, 2]);
    $world->db->insert('"transaction"', ['invoice_id' => $group->id, 'amount' => 150, 'currency' => 'USD', 'status' => 'received', 'txn_status' => 'processing', 'created_at' => date('Y-m-d H:i:s')]);
    $clearing = fn (): array => array_map('intval', $world->db->fetchFirstColumn('SELECT i.id FROM invoice i WHERE ' . PendingPayments::sql('i.id') . ' ORDER BY i.id'));

    // As an invoice with its own debit clearing: no saved-card charge, credit payment, reminder or suspension meanwhile.
    expect(PendingPayments::forInvoice($world->di, 1))->toMatchArray(['amount' => 150])
        ->and($clearing())->toBe([1, 2, (int) $group->id]);

    massPayByGateway($world, $group);

    expect($world->paid)->toBe([1 => 1, 2 => 1])
        ->and(PendingPayments::forInvoice($world->di, 1))->toBeNull()
        ->and($clearing())->toBe([(int) $group->id]);
});
