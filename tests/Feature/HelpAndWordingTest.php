<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Support;
use App\Services\Wording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/**
 * Words people see can be the school's own, the sign-in page can carry a
 * logo, staff and students get a How to page and the helpdesk's details,
 * and a hidden setup list can be brought back.
 */
class HelpAndWordingTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->installWithPolicies();
        $this->admin = User::factory()->admin()->create();
    }

    private function general(array $extra = []): array
    {
        return $extra + ['school_name' => 'Example College', 'timezone' => 'Australia/Brisbane', 'app_url' => 'https://filterdesk.example.test'];
    }

    public function test_wording_and_the_sign_in_logo_can_be_changed(): void
    {
        $this->get(route('login'))->assertOk()->assertSee(Wording::FIELDS['login_intro']['default'])->assertDontSee('Secure school access')->assertDontSee('auth-logo', false);

        $logo = UploadedFile::fake()->createWithContent('logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
        $this->actingAs($this->admin)->put(route('admin.settings.update', 'general'), $this->general(['logo' => $logo, 'wording' => ['login_title' => 'Kia ora', 'dashboard_eyebrow' => 'Web access']]))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertSee('Web access')->assertDontSee('School IT self-service');

        auth()->logout();
        $this->get(route('login'))->assertOk()->assertSee('Kia ora')->assertSee('auth-logo', false);
        $this->get(route('brand.logo'))->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_staff_see_the_help_page_and_the_helpdesk(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.update', 'support'), [
            'name' => 'IT Service Desk', 'email' => 'help@example.edu', 'phone' => '07 0000 0000', 'article_url' => 'https://intranet.example.edu/it/websites', 'article_label' => 'Websites at school',
        ])->assertSessionHasNoErrors();
        $this->assertSame('help@example.edu', app(Support::class)->all()['email']);

        $teacher = User::factory()->teacher()->create();
        $this->actingAs($teacher)->get(route('help'))->assertOk()->assertSee('Still blocked after it was allowed?')->assertSee('private (incognito) window')->assertSee('Websites at school');
        $this->actingAs($teacher)->get(route('checks.create'))->assertOk()->assertSee('Need help?')->assertSee('help@example.edu')
            ->assertDontSee('empty DNS cache')->assertSee(Wording::FIELDS['check_hint']['default']);
        $this->actingAs($teacher)->get(route('dashboard'))->assertOk()->assertSee('IT Service Desk');

        $this->actingAs($this->admin)->put(route('admin.settings.update', 'support'), ['article_url' => 'javascript:alert(1)'])->assertSessionHasErrors('article_url');
    }

    public function test_a_hidden_setup_list_can_be_shown_again_and_a_step_marked_done_undone(): void
    {
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertSee('Finish setting up');
        $this->actingAs($this->admin)->post(route('setup.done'), ['item' => 'all']);
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertDontSee('Finish setting up')->assertSee('Show the setup list again');

        $this->actingAs($this->admin)->post(route('setup.done'), ['item' => 'show']);
        $this->actingAs($this->admin)->post(route('setup.done'), ['item' => 'categories']);
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertSee('Finish setting up')->assertSee('Undo');
        $this->actingAs($this->admin)->post(route('setup.done'), ['item' => 'categories', 'undo' => '1']);
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertDontSee('>Undo<', false);
    }
}
