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

interface DnsHostingProviderInterface
{
    /** @return array{id: string, nameservers: list<string>} */
    public function createZone(string $domain): array;

    public function deleteZone(string $zone): void;

    /** @return list<array{id: string, name: string, type: string, value: string, ttl: int, priority: ?int}> */
    public function records(string $zone, string $domain): array;

    public function addRecord(string $zone, string $domain, array $record): void;

    public function updateRecord(string $zone, string $domain, array $old, array $record): void;

    public function deleteRecord(string $zone, string $domain, array $record): void;

    public function dnssec(string $zone): array;

    public function setDnssec(string $zone, bool $enabled): void;
}
