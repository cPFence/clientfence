<?php

declare(strict_types=1);

use ClientFence\InformationException;
use ClientFence\Mod\Client\TrustScore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * A client (id 7) made $months full months ago, with $services active services, one paid invoice of $paid without a
 * due date (it counts for Total paid, not for Pays on time), and email and ID document checks.
 */
function trustScoreDb(int $months = 0, int $services = 0, float $paid = 0, bool $email = false, bool $idDocument = false): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE client (id INTEGER PRIMARY KEY, created_at TEXT, email_approved INTEGER)',
        'CREATE TABLE client_order (id INTEGER PRIMARY KEY, client_id INTEGER, status TEXT, group_master INTEGER, group_id TEXT)',
        'CREATE TABLE client_id_document (id INTEGER PRIMARY KEY, client_id INTEGER, status TEXT)',
        'CREATE TABLE client_order_hold (id INTEGER PRIMARY KEY, client_id INTEGER, reason TEXT)',
        'CREATE TABLE invoice (id INTEGER PRIMARY KEY, client_id INTEGER, status TEXT, due_at TEXT, paid_at TEXT, base_income REAL, approved INTEGER DEFAULT 1)',
        'CREATE TABLE "transaction" (id INTEGER PRIMARY KEY, invoice_id INTEGER, status TEXT)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $db->insert('client', ['id' => 7, 'created_at' => (new DateTimeImmutable("-{$months} months -3 days"))->format('Y-m-d H:i:s'), 'email_approved' => (int) $email]);
    for ($i = 0; $i < $services; ++$i) {
        $db->insert('client_order', ['client_id' => 7, 'status' => 'active', 'group_master' => 1, 'group_id' => 'g' . $i]);
    }
    if ($paid > 0) {
        $db->insert('invoice', ['client_id' => 7, 'status' => 'paid', 'paid_at' => '2020-01-01 10:00:00', 'base_income' => $paid]);
    }
    if ($idDocument) {
        $db->insert('client_id_document', ['client_id' => 7, 'status' => 'approved']);
    }

    return $db;
}

function trustScoreInvoice(Connection $db, string $status, string $due, string $paid, float $income = 10, int $approved = 1): void
{
    $db->insert('invoice', ['client_id' => 7, 'status' => $status, 'due_at' => date('Y-m-d H:i:s', strtotime($due)), 'paid_at' => date('Y-m-d H:i:s', strtotime($paid)), 'base_income' => $income, 'approved' => $approved]);
}

test('trust score counts each part from the client data', function (): void {
    $db = trustScoreDb(months: 7, services: 4, paid: 1200, email: true, idDocument: true);
    // An add-on, a suspended service, another client's service and a refused ID document do not count.
    $db->insert('client_order', ['client_id' => 7, 'status' => 'active', 'group_master' => 0, 'group_id' => 'g0']);
    $db->insert('client_order', ['client_id' => 7, 'status' => 'suspended', 'group_master' => 1]);
    $db->insert('client_order', ['client_id' => 8, 'status' => 'active', 'group_master' => 1]);
    $db->insert('client_id_document', ['client_id' => 7, 'status' => 'refused']);
    // Due in the last 12 months: two paid on time (one on the due day itself), one late; older, draft and other
    // clients' invoices do not count.
    trustScoreInvoice($db, 'paid', '-20 days', '-25 days', 0);
    trustScoreInvoice($db, 'paid', '-40 days 10:00', '-40 days 18:00', 0);
    trustScoreInvoice($db, 'paid', '-60 days', '-50 days', 0);
    trustScoreInvoice($db, 'paid', '-14 months', '-10 months', 0);
    trustScoreInvoice($db, 'unpaid', '-10 days', '-10 days', 0, 0);
    $db->insert('invoice', ['client_id' => 8, 'status' => 'paid', 'due_at' => date('Y-m-d H:i:s', strtotime('-5 days')), 'paid_at' => date('Y-m-d H:i:s'), 'base_income' => 5000]);

    $score = TrustScore::get($db, 7);

    expect($score['parts'])->toBe(['on_time' => 27, 'since' => 7, 'services' => 15, 'paid' => 11, 'verified' => 10])
        ->and($score['deductions'])->toBe(['fraud' => 0, 'refunds' => 0])
        ->and($score['total'])->toBe(70)
        ->and($score['band'])->toBe('good')
        ->and($score['counts'])->toMatchArray(['due' => 3, 'on_time' => 2, 'months' => 7, 'active' => 4, 'paid' => 1200.0]);
});

test('trust score gives 20 of 40 for paying on time to a client without invoices and caps the months at 20', function (): void {
    $score = TrustScore::get(trustScoreDb(months: 30), 7);

    expect($score['parts'])->toBe(['on_time' => 20, 'since' => 20, 'services' => 0, 'paid' => 0, 'verified' => 0])
        ->and($score['counts']['due'])->toBe(0);
});

test('trust score counts an unpaid invoice past its due date as late', function (): void {
    $db = trustScoreDb(months: 30);
    trustScoreInvoice($db, 'unpaid', '-10 days', '-10 days', 0);
    // Not late yet: due today or later; a canceled invoice and a draft never fell due.
    trustScoreInvoice($db, 'unpaid', 'today 00:00', 'today', 0);
    trustScoreInvoice($db, 'unpaid', '+5 days', '+5 days', 0);
    trustScoreInvoice($db, 'canceled', '-10 days', '-10 days', 0);
    trustScoreInvoice($db, 'unpaid', '-10 days', '-10 days', 0, 0);

    $score = TrustScore::get($db, 7);
    expect($score['parts']['on_time'])->toBe(0)
        ->and($score['counts'])->toMatchArray(['due' => 1, 'on_time' => 0]);

    // With one invoice paid on time beside it: half.
    trustScoreInvoice($db, 'paid', '-20 days', '-25 days', 0);
    expect(TrustScore::get($db, 7)['parts']['on_time'])->toBe(20);
});

test('trust score steps total paid in the default currency', function (float $paid, int $points): void {
    expect(TrustScore::get(trustScoreDb(paid: $paid), 7)['parts']['paid'])->toBe($points);
})->with([[99.99, 0], [100.0, 3], [499.99, 3], [500.0, 7], [1000.0, 11], [1999.99, 11], [2000.0, 15]]);

test('trust score bands the total', function (int $months, int $services, float $paid, bool $email, bool $idDocument, int $total, string $band): void {
    $score = TrustScore::get(trustScoreDb($months, $services, $paid, $email, $idDocument), 7);

    expect($score['total'])->toBe($total)
        ->and($score['band'])->toBe($band);
})->with([
    'very good at 80' => [20, 3, 2000.0, true, true, 80, 'very_good'],
    'good at 60' => [20, 3, 0.0, true, false, 60, 'good'],
    'fair at 40' => [20, 0, 0.0, false, false, 40, 'fair'],
    'watch below 40' => [19, 0, 0.0, false, false, 39, 'watch'],
]);

test('trust score takes off a fraud hold, refunded invoices and reversed payments, never below 0', function (): void {
    $db = trustScoreDb(months: 20, services: 1);
    $db->insert('client_order_hold', ['client_id' => 7, 'reason' => 'fraud']);
    $db->insert('client_order_hold', ['client_id' => 7, 'reason' => 'fraud']);
    $db->insert('client_order_hold', ['client_id' => 7, 'reason' => 'network']);
    // The refunded invoice counts; the refund invoice made for it (negative income) does not.
    $db->insert('invoice', ['id' => 50, 'client_id' => 7, 'status' => 'refunded', 'base_income' => 30]);
    $db->insert('invoice', ['id' => 51, 'client_id' => 7, 'status' => 'refunded', 'base_income' => -30]);
    $db->insert('invoice', ['id' => 52, 'client_id' => 7, 'status' => 'unpaid']);
    $db->executeStatement('INSERT INTO "transaction" (invoice_id, status) VALUES (52, ?), (52, ?)', ['reversed', 'processed']);

    $score = TrustScore::get($db, 7);

    // 20 + 20 + 5 = 45, minus 20 and 2 x 5.
    expect($score['deductions'])->toBe(['fraud' => 20, 'refunds' => 10])
        ->and($score['total'])->toBe(15)
        ->and($score['counts'])->toMatchArray(['refunded' => 1, 'reversed' => 1]);

    foreach (range(53, 56) as $id) {
        $db->insert('invoice', ['id' => $id, 'client_id' => 7, 'status' => 'refunded', 'base_income' => 10]);
    }
    expect(TrustScore::get($db, 7)['total'])->toBe(0);
});

test('trust score refuses an unknown client', function (): void {
    expect(fn (): array => TrustScore::get(trustScoreDb(), 99))->toThrow(InformationException::class, 'Client not found');
});
