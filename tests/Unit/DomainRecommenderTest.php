<?php

namespace Tests\Unit;

use App\Services\Domains\PublicSuffixList;
use App\Services\Network\DomainRules;
use App\Services\Policy\DomainRecommender;
use App\Services\Policy\PolicyListParser;
use Tests\TestCase;

class DomainRecommenderTest extends TestCase
{
    public function test_registrable_domains_respect_multi_part_suffixes(): void
    {
        $psl = new PublicSuffixList;
        $this->assertSame('example.edu.au', $psl->registrableDomain('learn.example.edu.au'));
        $this->assertSame('example.co.uk', $psl->registrableDomain('a.b.example.co.uk'));
        $this->assertSame('example.com', $psl->registrableDomain('example.com'));
        $this->assertNull($psl->registrableDomain('edu.au'));
        $this->assertTrue($psl->isPublicSuffix('com.au'));
    }

    public function test_recommendations_add_apex_and_failed_page_hosts(): void
    {
        $recommender = new DomainRecommender(new PublicSuffixList, new PolicyListParser);
        $items = collect($recommender->recommend(
            ['learn.example.edu.au'],
            [
                ['host' => 'static.example.edu.au', 'failed_on' => ['student']],
                ['host' => 'fonts.gstatic.com', 'failed_on' => []],
                ['host' => 'api.vendor.io', 'failed_on' => ['student', 'staff']],
            ],
            new DomainRules,
        ))->keyBy('domain');

        $this->assertTrue($items['learn.example.edu.au']['selected']);
        $this->assertSame('apex', $items['example.edu.au']['kind']);
        $this->assertTrue($items['static.example.edu.au']['selected']);
        $this->assertFalse($items['fonts.gstatic.com']['selected']);
        $this->assertTrue($items['api.vendor.io']['selected']);
        $this->assertSame('third_party', $items['api.vendor.io']['kind']);
    }

    public function test_already_allowed_hosts_are_not_preselected(): void
    {
        $recommender = new DomainRecommender(new PublicSuffixList, new PolicyListParser);
        $items = collect($recommender->recommend(['example.com'], [], new DomainRules(entriesIncludeSubdomains: true), ['example.com']))->keyBy('domain');

        $this->assertFalse($items['example.com']['selected']);
        $this->assertFalse($items['www.example.com']['selected']);
        $this->assertStringContainsString('Already allowed', $items['www.example.com']['reason']);
    }

    public function test_parser_strips_wildcards_ports_and_paths_for_dns_filtering(): void
    {
        $parsed = (new PolicyListParser)->parseDetailed("*.Example.com:443/path\nhttps://a.example.org/x\n.leading.example.net");

        $this->assertSame(['a.example.org', 'example.com', 'leading.example.net'], $parsed['entries']);
        $this->assertNotEmpty($parsed['notes']);
    }
}
