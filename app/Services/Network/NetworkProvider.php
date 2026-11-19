<?php

namespace App\Services\Network;

use App\Models\WebsiteRequest;

interface NetworkProvider
{
    /** @return array<string, bool> */
    public function capabilities(): array;

    /** @return array{ok: bool, message: string, details?: array<string, mixed>} */
    public function test(): array;

    /** How domain entries are matched and what syntax the provider accepts. */
    public function domainRules(): DomainRules;

    /**
     * Sites and networks available for mapping, where the provider can list them.
     *
     * @return array{sites: list<array{id: string, name: string}>, networks: list<array{id: string, name: string, vlan?: int|null}>, message?: string}
     */
    public function discover(?string $siteId = null): array;

    /** @return array<string, mixed> */
    public function apply(WebsiteRequest $request): array;

    public function revoke(WebsiteRequest $request): void;
}
