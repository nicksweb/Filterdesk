<?php

namespace Tests\Unit;

use App\Services\Auth\UserProvisioningService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RoleMappingTest extends TestCase
{
    public function test_rules_map_domains_and_groups_with_first_match_winning(): void
    {
        $service = app(UserProvisioningService::class);
        $configuration = [
            'role_rules' => UserProvisioningService::parseRules("group:IT-Staff=it\nemail_domain:students.example.edu.au=student"),
            'default_role' => 'teacher',
        ];

        $this->assertSame('student', $service->roleFor(['email' => 'kid@students.example.edu.au'], $configuration));
        $this->assertSame('it', $service->roleFor(['email' => 'tech@students.example.edu.au', 'groups' => ['it-staff']], $configuration));
        $this->assertSame('teacher', $service->roleFor(['email' => 'teacher@example.edu.au'], $configuration));
        $this->assertNull($service->roleFor(['email' => 'x@example.com'], ['default_role' => 'deny']));
    }

    public function test_invalid_rules_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        UserProvisioningService::parseRules('domain:example.com=superuser');
    }
}
