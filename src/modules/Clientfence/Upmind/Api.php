<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Upmind;

use ClientFence\InformationException;

/**
 * ClientFence: Upmind's admin API, read only (the Upmind import, Snapshot.php). A token made in
 * Upmind under Settings > API Tokens; Upmind binds it to the IP addresses it may be used from.
 */
final class Api
{
    public const BASE = 'https://api.upmind.io/api/admin/';

    public function __construct(private readonly \Pimple\Container $di, #[\SensitiveParameter] private readonly string $token)
    {
    }

    /**
     * One page of a list: [rows, total].
     *
     * @param array<string, scalar> $query
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function page(string $path, int $offset, int $limit, array $query = []): array
    {
        $reply = $this->call($path, ['limit' => $limit, 'offset' => $offset] + $query);

        return [array_values((array) ($reply['data'] ?? [])), (int) ($reply['total'] ?? 0)];
    }

    /**
     * Every row of a short list (countries, currencies, a client's addresses).
     *
     * @param array<string, scalar> $query
     *
     * @return list<array<string, mixed>>
     */
    public function all(string $path, array $query = []): array
    {
        $rows = [];
        do {
            [$page, $total] = $this->page($path, count($rows), 100, $query);
            $rows = array_merge($rows, $page);
        } while ($page !== [] && count($rows) < $total);

        return $rows;
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<string, mixed> one record
     */
    public function one(string $path, array $query = []): array
    {
        return (array) ($this->call($path, $query)['data'] ?? []);
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<string, mixed>
     */
    private function call(string $path, array $query): array
    {
        try {
            $response = $this->di['http_client']->request('GET', self::BASE . $path, [
                'timeout' => 60,
                'headers' => ['Authorization' => 'Bearer ' . $this->token, 'Accept' => 'application/json'],
                'query' => $query,
            ]);
            $status = $response->getStatusCode();
            $reply = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new InformationException('Upmind did not answer: :error', [':error' => mb_substr($e->getMessage(), 0, 300)]);
        }
        if ($status === 401 || $status === 403) {
            throw new InformationException('Upmind refused the API token (:status). Check the token, its permissions and its IP whitelist in Upmind under Settings > API Tokens.', [':status' => $status]);
        }
        if ($status !== 200 || ($reply['status'] ?? '') !== 'ok') {
            throw new InformationException('Upmind answered :status for :path: :error', [':status' => $status, ':path' => $path, ':error' => mb_substr((string) ($reply['error']['message'] ?? ''), 0, 300)]);
        }

        return $reply;
    }
}
