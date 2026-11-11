<?php

declare(strict_types=1);

/**
 * ClientFence: the client IP seat rules of licences and API keys (IpSeats, CF-56).
 */

use ClientFence\InformationException;
use ClientFence\IpSeats;

/** Seats with these product settings and IPs; `$added`: ip => seconds ago (IPs not named count as added long ago). */
function ipSeatsFixture(array $product, array $ips = [], array $added = [], array $state = []): IpSeats
{
    $now = time();
    $state['added'] = array_map(static fn (int $ago): int => $now - $ago, $added);

    return IpSeats::of($product, true, $ips, json_encode(['ip_state' => $state]));
}

test('a client adds IP addresses into free places only, single addresses only', function (): void {
    $seats = ipSeatsFixture(['ip_seats' => 2]);

    expect($seats->act(IpSeats::ADD, '2001:DB8::1', '', time())['done'])->toBeTrue()
        ->and($seats->ips)->toBe(['2001:db8::1'])
        ->and(fn () => $seats->act(IpSeats::ADD, '2001:db8::1', '', time()))->toThrow(InformationException::class, 'already on the list')
        ->and(fn () => $seats->act(IpSeats::ADD, '10.0.0.0/8', '', time()))->toThrow(InformationException::class, 'not a single IP address');

    $seats->act(IpSeats::ADD, '198.51.100.2', '', time());
    expect(fn () => $seats->act(IpSeats::ADD, '198.51.100.3', '', time()))->toThrow(InformationException::class, 'All 2 places are in use');
});

test('a removal is one change, free for an IP added in the last 24 hours, and the cap refuses the next one', function (): void {
    $seats = ipSeatsFixture(['ip_seats' => 3, 'ip_changes' => 1], ['198.51.100.1', '198.51.100.2', '198.51.100.3'], ['198.51.100.3' => 3600]);

    $seats->act(IpSeats::REMOVE, '198.51.100.1', '', time());
    expect($seats->view(time())['changes_left'])->toBe(0)
        ->and(fn () => $seats->act(IpSeats::REMOVE, '198.51.100.2', '', time()))->toThrow(InformationException::class, 'You have used your 1 IP changes for 30 days. The next one is free on');

    $seats->act(IpSeats::REMOVE, '198.51.100.3', '', time());
    expect($seats->ips)->toBe(['198.51.100.2'])
        ->and($seats->view(time())['next_free_change'])->not->toBeNull();
});

test('an IP a check has used is not free to remove, even within 24 hours', function (): void {
    $seats = ipSeatsFixture(['ip_seats' => 2, 'ip_changes' => 1], ['198.51.100.1'], ['198.51.100.1' => 60]);
    $seats->check('198.51.100.1', time());
    $seats->check('198.51.100.2', time());

    $seats->act(IpSeats::REMOVE, '198.51.100.2', '', time());
    expect($seats->view(time())['changes_used'])->toBe(1)
        ->and(fn () => $seats->act(IpSeats::REMOVE, '198.51.100.1', '', time()))->toThrow(InformationException::class, 'You have used your 1 IP changes');
});

test('a reset clears the list and counts as one change', function (): void {
    $seats = ipSeatsFixture(['ip_changes' => 1], ['198.51.100.1']);

    expect($seats->act(IpSeats::RESET, '', '', time()))->toMatchArray(['event' => 'applied', 'done' => true])
        ->and($seats->ips)->toBe([])
        ->and(fn () => $seats->act(IpSeats::RESET, '', '', time()))->toThrow(InformationException::class, 'You have used your 1 IP changes');
});

test('with 0 IP changes only staff remove or reset, while adding into a free place still works', function (): void {
    $seats = ipSeatsFixture(['ip_seats' => 2, 'ip_changes' => '0'], ['198.51.100.1'], ['198.51.100.1' => 60]);

    expect(fn () => $seats->act(IpSeats::REMOVE, '198.51.100.1', '', time()))->toThrow(InformationException::class, 'Only our staff')
        ->and(fn () => $seats->act(IpSeats::RESET, '', '', time()))->toThrow(InformationException::class, 'Only our staff')
        ->and($seats->act(IpSeats::ADD, '198.51.100.2', '', time())['done'])->toBeTrue();
});

test('a check binds a new IP until the places are full, and only with binding on', function (): void {
    $seats = ipSeatsFixture(['ip_seats' => 2], ['198.51.100.1']);

    expect($seats->check('198.51.100.1', time()))->toBeTrue()
        ->and($seats->check('198.51.100.2', time()))->toBeTrue()
        ->and($seats->check('198.51.100.3', time()))->toBeNull()
        ->and($seats->check('198.51.100.1', time()))->toBeFalse()
        ->and($seats->ips)->toBe(['198.51.100.1', '198.51.100.2'])
        ->and(ipSeatsFixture(['ip_seats' => 2, 'ip_bind' => '0'])->check('198.51.100.1', time()))->toBeNull();
});

test('a list over the limit keeps passing, binds nothing, and its removals are free', function (): void {
    $seats = ipSeatsFixture(['ip_seats' => 1, 'ip_changes' => 1], ['198.51.100.1', '198.51.100.2']);

    expect($seats->check('198.51.100.2', time()))->toBeTrue()
        ->and($seats->check('198.51.100.3', time()))->toBeNull()
        ->and(fn () => $seats->act(IpSeats::ADD, '198.51.100.3', '', time()))->toThrow(InformationException::class, 'All 1 places are in use');

    $seats->act(IpSeats::REMOVE, '198.51.100.1', '', time());
    expect($seats->view(time())['changes_used'])->toBe(0);
});

test('with approval a change waits, one at a time, until staff approve or refuse it', function (): void {
    $seats = ipSeatsFixture(['ip_seats' => 2, 'ip_approval' => '1'], ['198.51.100.1']);

    expect($seats->act(IpSeats::ADD, '198.51.100.2', '', time()))->toMatchArray(['event' => 'asked', 'done' => false])
        ->and($seats->ips)->toBe(['198.51.100.1'])
        ->and($seats->view(time())['pending'])->toMatchArray(['type' => 'add', 'ip' => '198.51.100.2'])
        ->and(fn () => $seats->act(IpSeats::REMOVE, '198.51.100.1', '', time()))->toThrow(InformationException::class, 'already waiting for approval');

    expect($seats->act('approve', '', '', time()))->toMatchArray(['event' => 'approved', 'done' => true])
        ->and($seats->ips)->toBe(['198.51.100.1', '198.51.100.2'])
        ->and($seats->pending)->toBeNull();

    $seats->act(IpSeats::REMOVE, '198.51.100.1', '', time());
    expect($seats->act('refuse', '', ' Not yours ', time()))->toMatchArray(['event' => 'refused', 'done' => false, 'reason' => 'Not yours'])
        ->and($seats->ips)->toBe(['198.51.100.1', '198.51.100.2'])
        ->and(fn () => $seats->act('withdraw', '', '', time()))->toThrow(InformationException::class, 'no IP address request waiting');
});

test('an approval that can no longer be applied is refused with the reason', function (): void {
    $seats = ipSeatsFixture(['ip_seats' => 2, 'ip_approval' => '1'], ['198.51.100.1']);
    $seats->act(IpSeats::ADD, '198.51.100.2', '', time());
    $seats->check('198.51.100.3', time());

    expect($seats->act('approve', '', '', time()))->toMatchArray(['event' => 'refused', 'done' => false])
        ->and($seats->ips)->toBe(['198.51.100.1', '198.51.100.3']);
});

test('staff changes never count, staff may exceed the limit and clear the count', function (): void {
    $seats = ipSeatsFixture(['ip_seats' => 1, 'ip_changes' => 2], [], [], ['changes' => [time() - 60]]);

    $seats->setList(['198.51.100.1', '198.51.100.2'], time());
    expect($seats->ips)->toBe(['198.51.100.1', '198.51.100.2'])
        ->and($seats->view(time())['changes_used'])->toBe(1);

    $seats->act('clear', '', '', time());
    $saved = IpSeats::of(['ip_seats' => 1, 'ip_changes' => 2], true, $seats->ips, $seats->config('{"length": 32}'));
    expect($saved->view(time()))->toMatchArray(['changes_used' => 0, 'changes_left' => 2])
        ->and($saved->view(time())['ips'][0]['added_at'])->not->toBeNull()
        ->and(json_decode($seats->config('{"length": 32}'), true)['length'])->toBe(32);
});

test('an approval counts the change as it is then: an IP a check used while it waited is not free', function (): void {
    $seats = ipSeatsFixture(['ip_seats' => 1, 'ip_changes' => 2, 'ip_approval' => '1'], ['198.51.100.1'], ['198.51.100.1' => 60]);
    $seats->act(IpSeats::REMOVE, '198.51.100.1', '', time());
    $seats->check('198.51.100.1', time());

    expect($seats->act('approve', '', '', time())['done'])->toBeTrue()
        ->and($seats->view(time())['changes_used'])->toBe(1);
});

test('one IPv6 address in any spelling is one place, from staff, clients and checks', function (): void {
    $seats = ipSeatsFixture(['ip_seats' => 1]);
    $seats->setList(['2001:0DB8:0:0:0:0:0:1'], time());

    expect($seats->ips)->toBe(['2001:db8::1'])
        ->and($seats->check('2001:db8::1', time()))->toBeTrue()
        ->and(fn () => $seats->act(IpSeats::ADD, '2001:db8:0::1', '', time()))->toThrow(InformationException::class, 'already on the list');
});

test('an approval that would pass the change cap is refused', function (): void {
    $seats = ipSeatsFixture(['ip_seats' => 2, 'ip_changes' => 1, 'ip_approval' => '1'], ['198.51.100.1', '198.51.100.2'], ['198.51.100.2' => 60], ['changes' => [time() - 3600]]);
    $seats->act(IpSeats::REMOVE, '198.51.100.2', '', time());

    expect($seats->act('approve', '', '', time() + 86400))->toMatchArray(['event' => 'refused', 'done' => false])
        ->and($seats->ips)->toBe(['198.51.100.1', '198.51.100.2']);
});

test('after the allowance is lowered, the next free change waits until enough changes leave the 30 days', function (): void {
    $now = time();
    $seats = ipSeatsFixture(['ip_seats' => 2, 'ip_changes' => 1], ['198.51.100.1'], [], ['changes' => [$now - 20 * 86400, $now - 10 * 86400]]);

    expect($seats->view($now)['next_free_change'])->toBe(date('Y-m-d H:i:s', $now + 20 * 86400));
});


test('an approval that became a refusal tells staff why instead of "Done"', function (): void {
    $seats = ipSeatsFixture(['ip_seats' => 2, 'ip_approval' => '1'], ['198.51.100.1']);
    $seats->act(IpSeats::ADD, '198.51.100.2', '', time());
    $seats->check('198.51.100.3', time());

    expect(fn () => IpSeats::approved('approve', $seats->act('approve', '', '', time())))->toThrow(InformationException::class, 'so it was refused and the client was told: All 2 places are in use')
        ->and(IpSeats::approved('refuse', ['done' => false]))->toBeTrue()
        ->and(IpSeats::approved(IpSeats::ADD, ['done' => false]))->toBeTrue();
});
