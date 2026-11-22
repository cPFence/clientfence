<?php

declare(strict_types=1);

/*
 * MIT License
 *
 * Copyright (c) 2024 Namingo
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * SPDX-License-Identifier: MIT
 */

namespace ClientFence\Mod\Servicednshosting\Provider;

use ClientFence\InformationException;

final class Desec extends Provider
{
    private const BASE = 'https://desec.io/api/v1/domains/';

    public function createZone(string $domain): array
    {
        $answer = $this->call('POST', '', 201, ['name' => $domain]);
        $this->require(($answer['name'] ?? null) === $domain);

        return ['id' => $domain, 'nameservers' => $answer['nameservers'] ?? []];
    }

    public function deleteZone(string $zone): void
    {
        $this->call('DELETE', rawurlencode($zone) . '/', 204, allowMissing: true);
    }

    public function records(string $zone, string $domain): array
    {
        $records = [];
        $path = $this->path($zone);
        $cursor = '';
        $headers = [];
        do {
            $url = self::BASE . $path . '?cursor=' . rawurlencode($cursor);
            $answer = $this->request('GET', $url, $this->options(), 200, responseHeaders: $headers);
            $this->require(is_array($answer) && array_is_list($answer));
            foreach ($answer as $rrset) {
                foreach ($rrset['records'] as $value) {
                    $records[] = self::rrRecord($rrset['subname'], $rrset['type'], $value, $rrset['ttl']);
                }
            }
            preg_match('/<[^>]*[?&]cursor=([^>&]+)[^>]*>;\s*rel="next"/', implode(',', $headers['link'] ?? []), $next);
            $cursor = isset($next[1]) ? rawurldecode($next[1]) : null;
        } while ($cursor !== null);

        return $records;
    }

    public function addRecord(string $zone, string $domain, array $record): void
    {
        $path = $this->path($zone, $record);
        $set = $this->call('GET', $path, 200, allowMissing: true);
        $value = self::rdata($record);
        if ($set === null) {
            $this->call('POST', $this->path($zone), 201, [
                'subname' => $record['name'], 'type' => $record['type'], 'ttl' => $record['ttl'], 'records' => [$value],
            ]);
        } elseif (!in_array($value, $set['records'], true)) {
            $this->call('PATCH', $path, 200, ['ttl' => $set['ttl'], 'records' => [...$set['records'], $value]]);
        }
    }

    public function updateRecord(string $zone, string $domain, array $old, array $record): void
    {
        $path = $this->path($zone, $old);
        $values = $this->call('GET', $path, 200)['records'];
        $index = $this->recordIndex($values, $old);
        $value = self::rdata($record);
        if ($value !== $old['rdata'] && in_array($value, $values, true)) {
            throw new InformationException('This DNS record value already exists.');
        }
        $values[$index] = $value;
        $this->call('PATCH', $path, 200, ['ttl' => $record['ttl'], 'records' => $values]);
    }

    public function deleteRecord(string $zone, string $domain, array $record): void
    {
        $path = $this->path($zone, $record);
        $values = $this->call('GET', $path, 200)['records'];
        unset($values[$this->recordIndex($values, $record)]);
        if ($values === []) {
            $this->call('DELETE', $path, 204);
        } else {
            $this->call('PATCH', $path, 200, ['records' => array_values($values)]);
        }
    }

    public function dnssec(string $zone): array
    {
        $answer = $this->call('GET', rawurlencode($zone) . '/', 200);
        $ds = [];
        foreach ($answer['keys'] ?? [] as $key) {
            $ds = array_merge($ds, $key['ds'] ?? []);
        }

        return ['supported' => true, 'enabled' => true, 'can_toggle' => false, 'ds' => array_values(array_unique($ds))];
    }

    private function recordIndex(array $values, array $record): int
    {
        $index = array_search($record['rdata'], $values, true);
        if ($index === false) {
            throw new InformationException('DNS record not found. Refresh the page and try again.');
        }

        return $index;
    }

    private function path(string $zone, ?array $record = null): string
    {
        return rawurlencode($zone) . '/rrsets/'
            . ($record === null ? '' : ($record['name'] === '' ? '@' : rawurlencode($record['name'])) . '/' . $record['type'] . '/');
    }

    private function options(?array $json = null): array
    {
        $options = ['headers' => ['Authorization' => 'Token ' . $this->config['token'], 'Accept' => 'application/json']];
        if ($json !== null) {
            $options['json'] = $json;
        }

        return $options;
    }

    private function call(string $method, string $path, int $status, ?array $json = null, bool $allowMissing = false): ?array
    {
        return $this->request($method, self::BASE . $path, $this->options($json), $status, $allowMissing);
    }
}
