<?php

declare(strict_types=1);

/**
 * ClientFence: with "Require Email Confirmation" on, a client whose email is not confirmed yet may
 * still order and pay (as in WHMCS); the rest of the account waits for the confirmation.
 */

use ClientFence\Security\EmailValidationRequiredException;
use Symfony\Component\HttpFoundation\Request;

/** di.php's api_identity for a signed-in client with an unconfirmed email, on the route of $path. */
function unconfirmedClientIdentity(string $path): object
{
    $di = new Pimple\Container();
    $di['loggedin_client'] = new Model_Client();
    $di['is_client_email_validated'] = $di->protect(fn (object $client): bool => false);
    $di['request'] = Request::create($path);

    $source = file_get_contents(PATH_ROOT . '/di.php');
    $start = strpos($source, '$di[\'api_identity\'] =');
    $end = strpos($source, "\n});", $start) + 4;
    eval('use ClientFence\Http\RequestFactory; use ClientFence\Security\EmailValidationRequiredException; ' . substr($source, $start, $end - $start));

    return $di['api_identity']('client');
}

test('an unconfirmed client may use the store, the checkout and the invoice payment', function (string $path): void {
    expect(unconfirmedClientIdentity($path))->toBeInstanceOf(ClientFence\Api\Identity::class);
})->with(['/api/client/cart/checkout', '/api/client/invoice/paypal_pay', '/cart', '/order', '/order/starter-hosting', '/invoice/0a1b2c', '/invoice/banklink/0a1b2c/2', '/invoice/thank-you/0a1b2c']);

test('an unconfirmed client is still refused the rest of the account', function (string $path): void {
    expect(fn () => unconfirmedClientIdentity($path))->toThrow(EmailValidationRequiredException::class);
})->with(['/api/client/support/ticket_create', '/api/client/invoice/funds_invoice', '/', '/support', '/order/service', '/order/service/manage/5', '/invoice', '/invoice/statement']);
