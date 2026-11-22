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

final class PowerDNS extends Provider
{
    public function createZone(string $domain): array
    {
        // A zone under another zone on this server answers for that part of it, whoever made the zone above.
        $labels = explode('.', $domain);
        for ($i = 1; $i < count($labels) - 1; ++$i) {
            if ($this->call('GET', '?zone=' . rawurlencode(implode('.', array_slice($labels, $i)) . '.'), 200) !== []) {
                throw new InformationException('A zone above this one is already on the DNS server.');
            }
        }
        $nameservers = array_map(static fn (string $name): string => $name . '.', $this->config['nameservers']);
        $zone = $this->call('POST', '', 201, [
            'name' => $domain . '.', 'kind' => 'Master', 'masters' => [], 'nameservers' => $nameservers, 'dnssec' => false,
        ]);
        $this->require(($zone['id'] ?? '') !== '');

        if ($this->config['soa_email'] !== '') {
            foreach ($this->rrsets($zone['id']) as $rrset) {
                if ($rrset['type'] !== 'SOA' || empty($rrset['records'][0]['content'])) {
                    continue;
                }
                [$local, $host] = explode('@', $this->config['soa_email'], 2);
                $parts = preg_split('/\s+/', trim($rrset['records'][0]['content']));
                $parts[0] = $nameservers[0];
                $parts[1] = str_replace('.', '\\.', $local) . '.' . $host . '.';
                $rrset['records'][0]['content'] = implode(' ', $parts);
                $this->replace($zone['id'], $rrset, $rrset['records'], $rrset['ttl']);
                break;
            }
        }

        return ['id' => $zone['id'], 'nameservers' => $nameservers];
    }

    public function deleteZone(string $zone): void
    {
        $this->call('DELETE', '/' . rawurlencode($zone), 204, allowMissing: true);
    }

    public function records(string $zone, string $domain): array
    {
        $records = [];
        foreach ($this->rrsets($zone) as $rrset) {
            foreach ($rrset['records'] as $record) {
                if (!$record['disabled']) {
                    $records[] = self::rrRecord(self::relative($rrset['name'], $domain), $rrset['type'], $record['content'], $rrset['ttl']);
                }
            }
        }

        return $records;
    }

    public function addRecord(string $zone, string $domain, array $record): void
    {
        $rrset = $this->findSet($zone, $domain, $record);
        $content = self::rdata($record);
        if (!in_array($content, array_column($rrset['records'], 'content'), true)) {
            $rrset['records'][] = ['content' => $content, 'disabled' => false];
            $this->replace($zone, $rrset, $rrset['records'], $rrset['ttl']);
        }
    }

    public function updateRecord(string $zone, string $domain, array $old, array $record): void
    {
        $rrset = $this->findSet($zone, $domain, $old);
        $index = $this->recordIndex($rrset, $old);
        $content = self::rdata($record);
        if ($content !== $old['rdata'] && in_array($content, array_column($rrset['records'], 'content'), true)) {
            throw new InformationException('This DNS record value already exists.');
        }
        $rrset['records'][$index]['content'] = $content;
        $this->replace($zone, $rrset, $rrset['records'], $record['ttl']);
    }

    public function deleteRecord(string $zone, string $domain, array $record): void
    {
        $rrset = $this->findSet($zone, $domain, $record);
        unset($rrset['records'][$this->recordIndex($rrset, $record)]);
        $this->replace($zone, $rrset, array_values($rrset['records']), $rrset['ttl']);
    }

    public function dnssec(string $zone): array
    {
        $ds = [];
        foreach ($this->call('GET', $this->keysPath($zone), 200) as $key) {
            if ($key['active'] && ($key['published'] ?? true)) {
                $ds = array_merge($ds, $key['ds'] ?? []);
            }
        }

        return ['supported' => true, 'enabled' => $ds !== [], 'can_toggle' => true, 'ds' => array_values(array_unique($ds))];
    }

    public function setDnssec(string $zone, bool $enabled): void
    {
        $path = $this->keysPath($zone);
        if ($enabled) {
            $this->call('POST', $path, 201, ['active' => true, 'keytype' => 'ksk']);

            return;
        }
        foreach ($this->call('GET', $path, 200) as $key) {
            $this->call('DELETE', $path . '/' . rawurlencode((string) $key['id']), 204);
        }
    }

    private function keysPath(string $zone): string
    {
        return '/' . rawurlencode($zone) . '/cryptokeys';
    }

    private function rrsets(string $zone): array
    {
        $answer = $this->call('GET', '/' . rawurlencode($zone), 200);
        $this->require(is_array($answer['rrsets'] ?? null));

        return $answer['rrsets'];
    }

    private function findSet(string $zone, string $domain, array $record): array
    {
        $name = self::fqdn($record['name'], $domain) . '.';
        foreach ($this->rrsets($zone) as $rrset) {
            if ($rrset['name'] === $name && $rrset['type'] === $record['type']) {
                return $rrset;
            }
        }

        return ['name' => $name, 'type' => $record['type'], 'ttl' => $record['ttl'], 'records' => []];
    }

    private function recordIndex(array $rrset, array $record): int
    {
        foreach ($rrset['records'] as $i => $value) {
            if (!$value['disabled'] && $value['content'] === $record['rdata']) {
                return $i;
            }
        }

        throw new InformationException('DNS record not found. Refresh the page and try again.');
    }

    private function replace(string $zone, array $rrset, array $records, int $ttl): void
    {
        // PowerDNS can replace an RRset in one write, preserving its sibling values and comments.
        $this->call('PATCH', '/' . rawurlencode($zone), 204, ['rrsets' => [[
            'name' => $rrset['name'], 'type' => $rrset['type'], 'ttl' => $ttl,
            'changetype' => $records === [] ? 'DELETE' : 'REPLACE', 'records' => $records,
        ]]]);
    }

    private function call(string $method, string $path, int $status, ?array $json = null, bool $allowMissing = false): ?array
    {
        $options = ['headers' => ['X-API-Key' => $this->config['token']]];
        if ($json !== null) {
            $options['json'] = $json;
        }

        return $this->request($method, $this->config['api_url'] . '/api/v1/servers/localhost/zones' . $path, $options, $status, $allowMissing);
    }
}
