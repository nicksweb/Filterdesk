<?php

namespace Tests\Unit;

use App\Services\Domains\DomainNormalizer;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DomainNormalizerTest extends TestCase
{
    #[DataProvider('validDomains')]
    public function test_it_normalises_domains(string $input, string $expected): void
    {
        $this->assertSame($expected, (new DomainNormalizer)->one($input));
    }

    public static function validDomains(): array
    {
        return [['Example.COM', 'example.com'], ['https://sub.example.edu.au/path', 'sub.example.edu.au']];
    }

    public function test_it_rejects_ip_literals(): void
    {
        $this->expectException(ValidationException::class);
        (new DomainNormalizer)->one('http://127.0.0.1/admin');
    }
}
