<?php

declare(strict_types=1);

use ClientFence\Mod\Support\Mail\Dkim;
use Symfony\Component\Mime\Crypto\DkimSigner;
use Symfony\Component\Mime\Email;

/**
 * An RSA key pair: [private key PEM, the p= value of its DNS record].
 *
 * @return array{0: string, 1: string}
 */
function dkimRsaKey(int $bits = 2048): array
{
    static $keys = [];
    if (!isset($keys[$bits])) {
        $key = openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $public = openssl_pkey_get_details($key)['key'];
        $keys[$bits] = [$private, preg_replace('/-----[^-]+-----|\s+/', '', $public)];
    }

    return $keys[$bits];
}

/** An email signed by Symfony's DKIM signer (an implementation independent of Dkim). */
function dkimSigned(string $from, string $domain, string $canon = 'relaxed', string $body = "Please change my nameservers.\r\n"): string
{
    $email = (new Email())->from($from)->to('tickets@clientfence.test')->subject('Re: [Ticket #12] Down')->text($body);
    $signer = new DkimSigner(dkimRsaKey()[0], $domain, 'sel', ['header_canon' => $canon, 'body_canon' => $canon]);

    return $signer->sign($email)->toString();
}

/** A verifier whose DNS knows the given records. */
function dkimVerifier(array $records): Dkim
{
    return new Dkim(fn (string $name): ?string => $records[$name] ?? null);
}

function dkimRecords(string $domain = 'client.test'): array
{
    return ['sel._domainkey.' . $domain => 'v=DKIM1; k=rsa; p=' . dkimRsaKey()[1]];
}

test('a message signed by its From domain is verified', function (string $canon): void {
    expect(dkimVerifier(dkimRecords())->verify(dkimSigned('alice@client.test', 'client.test', $canon), 'alice@client.test'))->toBe([true, '']);
})->with(['relaxed', 'simple']);

test('a signature from a parent domain counts, never from another or a child domain', function (): void {
    expect(dkimVerifier(dkimRecords())->verify(dkimSigned('alice@mail.client.test', 'client.test'), 'alice@mail.client.test'))->toBe([true, '']);

    [$ok, $why] = dkimVerifier(dkimRecords('attacker.test'))->verify(dkimSigned('alice@client.test', 'attacker.test'), 'alice@client.test');
    expect($ok)->toBeFalse()->and($why)->toBe('it has no DKIM signature from client.test');

    expect(dkimVerifier(dkimRecords('x.client.test'))->verify(dkimSigned('alice@client.test', 'x.client.test'), 'alice@client.test')[0])->toBeFalse()
        ->and(Dkim::aligned('test', 'client.test'))->toBeFalse()
        ->and(Dkim::aligned('lient.test', 'client.test'))->toBeFalse();
});

test('a changed message, a second From or a forged result header is not verified', function (): void {
    $raw = dkimSigned('alice@client.test', 'client.test');
    $verifier = dkimVerifier(dkimRecords());

    expect($verifier->verify(str_replace('nameservers', 'password', $raw), 'alice@client.test'))
        ->toBe([false, 'its DKIM signature from client.test does not match the message body'])
        ->and($verifier->verify(str_replace('Subject: Re: [Ticket #12] Down', 'Subject: Re: [Ticket #99] Down', $raw), 'alice@client.test'))
        ->toBe([false, 'its DKIM signature from client.test does not verify'])
        ->and($verifier->verify("From: alice@client.test\r\n" . $raw, 'alice@client.test'))->toBe([false, 'the email has 2 From headers'])
        ->and($verifier->verify("Authentication-Results: mx.host.test; dkim=pass header.d=client.test; dmarc=pass\r\nFrom: alice@client.test\r\nSubject: hi\r\n\r\nhello\r\n", 'alice@client.test'))
        ->toBe([false, 'the email has no DKIM signature']);
});

test('a signature with a missing, revoked or weak key is not verified', function (): void {
    $raw = dkimSigned('alice@client.test', 'client.test');

    expect(dkimVerifier([])->verify($raw, 'alice@client.test'))->toBe([false, 'its DKIM signature from client.test has no published key'])
        ->and(dkimVerifier(['sel._domainkey.client.test' => 'v=DKIM1; p='])->verify($raw, 'alice@client.test'))->toBe([false, 'its DKIM signature from client.test has a revoked key'])
        ->and(dkimVerifier(['sel._domainkey.client.test' => 'v=DKIM1; t=y; p=' . dkimRsaKey()[1]])->verify($raw, 'alice@client.test'))->toBe([false, 'its DKIM signature from client.test comes from a domain that is testing DKIM'])
        ->and(dkimVerifier(['sel._domainkey.client.test' => 'v=DKIM1; p=bm90IGEga2V5'])->verify($raw, 'alice@client.test'))->toBe([false, 'its DKIM signature from client.test has an unusable key']);

    // A key shorter than 1024 bits (RFC 8301).
    $email = (new Email())->from('alice@client.test')->to('tickets@clientfence.test')->subject('s')->text("x\r\n");
    $weak = (new DkimSigner(dkimRsaKey(768)[0], 'client.test', 'sel'))->sign($email)->toString();
    expect(dkimVerifier(['sel._domainkey.client.test' => 'v=DKIM1; p=' . dkimRsaKey(768)[1]])->verify($weak, 'alice@client.test'))
        ->toBe([false, 'its DKIM signature from client.test has an unusable key']);
});

test('a signature that covers only part of the body or has expired is not verified', function (): void {
    $raw = dkimSigned('alice@client.test', 'client.test');
    // l= limits the signed body: anything after it could be added by anyone.
    $email = (new Email())->from('alice@client.test')->to('tickets@clientfence.test')->subject('s')->text("Signed part.\r\n");
    $short = (new DkimSigner(dkimRsaKey()[0], 'client.test', 'sel', ['header_canon' => 'relaxed', 'body_canon' => 'relaxed', 'body_show_length' => true]))->sign($email)->toString();
    $expired = (new DkimSigner(dkimRsaKey()[0], 'client.test', 'sel', ['header_canon' => 'relaxed', 'body_canon' => 'relaxed', 'signature_expiration_delay' => -60]))->sign($email)->toString();
    $verifier = dkimVerifier(dkimRecords());

    expect($verifier->verify($short, 'alice@client.test'))->toBe([true, ''])
        ->and($verifier->verify($short . "Added by someone else.\r\n", 'alice@client.test'))->toBe([false, 'its DKIM signature from client.test does not cover the whole message'])
        ->and($verifier->verify($expired, 'alice@client.test'))->toBe([false, 'its DKIM signature from client.test has expired'])
        ->and($verifier->verify($raw, 'alice@client.test'))->toBe([true, '']);
});

test('an ed25519 signature is verified', function (): void {
    $pair = sodium_crypto_sign_keypair();
    $headers = "from:alice@client.test\r\nsubject:hi\r\n";
    $body = "hello\r\n";
    $tag = 'v=1; a=ed25519-sha256; c=relaxed/relaxed; d=client.test; s=ed; h=from:subject; bh=' . base64_encode(hash('sha256', $body, true)) . '; b=';
    $signature = base64_encode(sodium_crypto_sign_detached(hash('sha256', $headers . 'dkim-signature:' . $tag, true), sodium_crypto_sign_secretkey($pair)));
    $raw = 'DKIM-Signature: ' . $tag . $signature . "\r\nFrom: alice@client.test\r\nSubject: hi\r\n\r\n" . $body;
    $records = ['ed._domainkey.client.test' => 'v=DKIM1; k=ed25519; p=' . base64_encode(sodium_crypto_sign_publickey($pair))];

    expect(dkimVerifier($records)->verify($raw, 'alice@client.test'))->toBe([true, ''])
        ->and(dkimVerifier($records)->verify(str_replace('Subject: hi', 'Subject: ho', $raw), 'alice@client.test'))->toBe([false, 'its DKIM signature from client.test does not verify']);
});
