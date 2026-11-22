<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\RequestDecisionNotification;
use App\Services\Notifications\EmailTemplates;
use App\Services\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

class EmailTemplateTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    public function test_admins_customise_and_reset_email_wording_and_emails_use_it(): void
    {
        Notification::fake();
        $this->installWithPolicies();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.settings.show', 'templates'))->assertOk()
            ->assertSee('Request received')->assertSee('Preview with sample values')->assertSee('WEB-2026-00012');

        $this->actingAs($admin)->put(route('admin.settings.update', 'templates'), ['templates' => [
            'request_declined' => ['subject' => 'Sorry, {reference} was not approved', 'greeting' => 'Hi {name}', 'body' => "<b>{message}</b>\n\nAsk {school} IT if unsure.", 'action' => 'See why'],
            'request_live' => EmailTemplates::DEFINITIONS['request_live'],
        ]])->assertSessionHas('success');
        $this->assertSame(['request_declined'], array_keys(app(SettingsRepository::class)->get('email_templates')), 'Unchanged templates are not stored');

        $request = $this->pendingRequest(['games.example']);
        $request->update(['status' => 'rejected']);
        $mail = (new RequestDecisionNotification($request->fresh(), 'Not suitable for class.'))->toMail($request->requester);
        $this->assertSame("Sorry, {$request->reference} was not approved", $mail->subject);
        $this->assertSame('Hi '.$request->requester->name, $mail->greeting);
        $this->assertSame('<b>Not suitable for class.</b>', $mail->introLines[0], 'Kept as text; the mail view escapes it');
        $this->assertSame('See why', $mail->actionText);
        $this->assertStringNotContainsString('<b>', (string) $mail->render());

        $this->actingAs($admin)->put(route('admin.settings.update', 'templates'), ['templates' => ['request_declined' => ['reset' => '1']]]);
        $default = (new RequestDecisionNotification($request->fresh(), 'Not suitable.'))->toMail($request->requester);
        $this->assertSame("Website request {$request->reference}: declined", $default->subject);
    }
}
