<?php

namespace App\Services\Network;

use App\Models\WebsiteRequest;

interface NetworkProvider
{
    /** @return array<string, bool> */
    public function capabilities(): array;

    /** @return array{ok: bool, message: string, details?: array<string, mixed>} */
    public function test(): array;

    /** @return array<string, mixed> */
    public function apply(WebsiteRequest $request): array;

    public function revoke(WebsiteRequest $request): void;
}
