<?php

namespace Tests\Concerns;

use App\Enums\RequestStatus;
use App\Models\IntegrationConfig;
use App\Models\NetworkProfile;
use App\Models\Setting;
use App\Models\User;
use App\Models\WebsiteRequest;
use App\Services\Policy\PolicyEngine;
use App\Support\Reach;

trait BuildsPolicies
{
    protected function installWithPolicies(string $mode = 'read_only'): PolicyEngine
    {
        Setting::query()->create(['key' => 'installation.completed', 'value' => true]);
        IntegrationConfig::query()->create(['provider' => 'mock', 'name' => 'Simulation', 'mode' => $mode, 'enabled' => true]);
        NetworkProfile::query()->create(['key' => 'student', 'name' => 'Student Access', 'audience' => 'student', 'probe_url' => 'http://probe-student:3000', 'sort_order' => 1]);
        NetworkProfile::query()->create(['key' => 'staff', 'name' => 'Staff Access', 'audience' => 'staff', 'probe_url' => 'http://probe-staff:3000', 'sort_order' => 2]);

        $engine = app(PolicyEngine::class);
        $engine->ensureDefaults();

        return $engine;
    }

    /** @param list<string> $domains */
    protected function pendingRequest(array $domains = ['example.com'], ?Reach $scope = null, array $attributes = []): WebsiteRequest
    {
        $scope ??= Reach::types('staff', 'student');
        static $sequence = 0;
        $sequence++;

        return WebsiteRequest::query()->create($attributes + [
            'reference' => sprintf('WEB-2026-%05d', $sequence),
            'requester_id' => User::factory()->teacher()->create()->id,
            'domains' => $domains,
            'access_scope' => $scope,
            'category' => 'Teaching and learning',
            'use_case' => 'Students need this resource for a supervised classroom activity.',
            'standards_confirmed' => true,
            'status' => RequestStatus::Pending,
        ]);
    }
}
