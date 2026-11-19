<?php

namespace Tests\Unit;

use App\Services\Policy\PolicyListParser;
use PHPUnit\Framework\TestCase;

class PolicyListParserTest extends TestCase
{
    public function test_it_normalises_unifi_pasted_domain_entries(): void
    {
        $entries = (new PolicyListParser)->parse(<<<'LIST'
www\.usenet-crawler.com
https://tailscale.com/download
_https._tcp.pkgs.tailscale.com
tailscale.com
LIST);

        $this->assertSame([
            '_https._tcp.pkgs.tailscale.com',
            'tailscale.com',
            'www.usenet-crawler.com',
        ], $entries);
    }
}
