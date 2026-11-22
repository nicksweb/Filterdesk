<?php

namespace App\Services\Notifications;

use App\Services\SettingsRepository;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Editable email wording. Each template has a subject, greeting, body and
 * button label with {placeholders}; admins change them under Settings →
 * Email templates, and anything left blank falls back to the default here.
 * Paragraphs in the body are separated by a blank line. A paragraph that
 * becomes empty after placeholders are filled (for example "{details}" with
 * nothing to add) is left out. All text is escaped when the email renders.
 */
class EmailTemplates
{
    private const COMMON = ['app_name' => 'Name of this app', 'school' => 'School name', 'name' => "Recipient's name"];

    private const REQUEST = [
        'reference' => 'Request reference, e.g. WEB-2026-00012', 'websites' => 'The approved (or requested) websites',
        'window' => 'When access applies', 'timezone' => 'Timezone of the times', 'message' => "IT's note or decision",
        'requester' => 'Who asked', 'scope' => 'Networks it is for', 'category' => 'Request category', 'reason' => 'Reason given',
    ];

    public const DEFINITIONS = [
        'request_received' => ['group' => 'Requester', 'label' => 'Request received', 'placeholders' => self::REQUEST,
            'subject' => 'Website request {reference}: received', 'greeting' => 'Hello {name},',
            'body' => "{message}\n\nWebsites: {websites}\n\nWhen: {window} ({timezone} time).", 'action' => 'View request'],
        'request_approved' => ['group' => 'Requester', 'label' => 'Approved (being applied)', 'placeholders' => self::REQUEST,
            'subject' => 'Website request {reference}: approved', 'greeting' => 'Hello {name},',
            'body' => "{message}\n\nWebsites: {websites}\n\nWhen: {window} ({timezone} time).", 'action' => 'View request'],
        'request_scheduled' => ['group' => 'Requester', 'label' => 'Approved for a later start', 'placeholders' => self::REQUEST,
            'subject' => 'Website request {reference}: approved and scheduled', 'greeting' => 'Hello {name},',
            'body' => "{message}\n\nWebsites: {websites}\n\nWhen: {window} ({timezone} time).", 'action' => 'View request'],
        'request_live' => ['group' => 'Requester', 'label' => 'Live on the network', 'placeholders' => self::REQUEST,
            'subject' => 'Website request {reference}: access is live', 'greeting' => 'Hello {name},',
            'body' => "{message}\n\nWebsites: {websites}\n\nWhen: {window} ({timezone} time).", 'action' => 'View request'],
        'request_information' => ['group' => 'Requester', 'label' => 'IT needs more information', 'placeholders' => self::REQUEST,
            'subject' => 'Website request {reference}: more information needed', 'greeting' => 'Hello {name},',
            'body' => "{message}\n\nWebsites: {websites}\n\nWhen: {window} ({timezone} time).\n\nReply from the request page so IT can continue.", 'action' => 'View request'],
        'request_declined' => ['group' => 'Requester', 'label' => 'Declined', 'placeholders' => self::REQUEST,
            'subject' => 'Website request {reference}: declined', 'greeting' => 'Hello {name},',
            'body' => "{message}\n\nWebsites: {websites}", 'action' => 'View request'],
        'request_ending' => ['group' => 'Requester', 'label' => 'Access ends soon', 'placeholders' => self::REQUEST,
            'subject' => 'Website request {reference}: access ends soon', 'greeting' => 'Hello {name},',
            'body' => "{message}\n\nWebsites: {websites}", 'action' => 'View request'],
        'request_expired' => ['group' => 'Requester', 'label' => 'Access has ended', 'placeholders' => self::REQUEST,
            'subject' => 'Website request {reference}: access has ended', 'greeting' => 'Hello {name},',
            'body' => "{message}\n\nWebsites: {websites}", 'action' => 'View request'],
        'request_revoked' => ['group' => 'Requester', 'label' => 'Access removed by IT', 'placeholders' => self::REQUEST,
            'subject' => 'Website request {reference}: access removed', 'greeting' => 'Hello {name},',
            'body' => "{message}\n\nWebsites: {websites}", 'action' => 'View request'],
        'it_activity' => ['group' => 'IT team', 'label' => 'New request, reply or assignment', 'placeholders' => self::REQUEST + ['headline' => 'What happened, e.g. "New website request"', 'details' => 'Extra lines for this event'],
            'subject' => '{reference}: {headline}', 'greeting' => '{headline}',
            'body' => "Domains: {websites}\n\nRequested by {requester} · {scope} · {category}\n\n{details}", 'action' => 'Open request'],
        'filter_sync_alert' => ['group' => 'IT team', 'label' => 'Web filter out of sync', 'placeholders' => ['details' => 'One line per filter that needs attention'],
            'subject' => 'Web filter out of sync', 'greeting' => 'A content filter needs attention', 'body' => '{details}', 'action' => 'Open policy lists'],
        'policy_digest' => ['group' => 'IT team', 'label' => 'Daily reconciliation digest', 'placeholders' => ['details' => 'Waiting requests, expiries and pending changes per filter'],
            'subject' => 'Daily web filter reconciliation', 'greeting' => 'Web filter changes to review today', 'body' => '{details}', 'action' => 'Open policy lists'],
        'exceptions_due' => ['group' => 'Requester', 'label' => 'Exceptions due for review', 'placeholders' => ['name' => 'Owner name', 'details' => 'One line per site with its list', 'expires' => 'When they expire if not reviewed'],
            'subject' => 'Website exceptions due for review', 'greeting' => 'Hello {name},',
            'body' => "These website exceptions you own are due for their regular review:\n\n{details}\n\nIf they are still needed, mark them to keep. Anything not reviewed is removed on {expires}.", 'action' => 'Review them'],
        'account_locked' => ['group' => 'Security', 'label' => 'Sign-in locked', 'placeholders' => ['email' => 'Account email', 'minutes' => 'Lock length', 'ip' => 'Address of the last attempt'],
            'subject' => 'Sign-in locked after failed attempts', 'greeting' => '',
            'body' => "Password sign-in for {email} was locked for {minutes} minutes after repeated failed attempts.\n\nLast attempt from {ip}.\n\nIf this was not you, tell IT. Administrators can turn off password sign-in for an account under Users, or turn off emergency local sign-in entirely under Settings → Authentication.", 'action' => 'Open {app_name}'],
        'test' => ['group' => 'Security', 'label' => 'Test email', 'placeholders' => ['sender' => 'Who sent the test'],
            'subject' => '{app_name} test email', 'greeting' => '', 'body' => 'This test was sent by {sender}. Email delivery is working.', 'action' => 'Open {app_name}'],
    ];

    public const FIELDS = ['subject', 'greeting', 'body', 'action'];

    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return array{subject: string, greeting: string, body: string, action: string} */
    public function template(string $key): array
    {
        $default = self::DEFINITIONS[$key] ?? throw new \InvalidArgumentException("Unknown email template {$key}.");
        $custom = (array) (((array) $this->settings->get('email_templates', []))[$key] ?? []);

        return collect(self::FIELDS)->mapWithKeys(fn (string $field) => [$field => filled($custom[$field] ?? null) ? (string) $custom[$field] : (string) $default[$field]])->all();
    }

    public function customised(string $key): bool
    {
        return array_filter((array) (((array) $this->settings->get('email_templates', []))[$key] ?? [])) !== [];
    }

    /**
     * Build the email. Placeholders not given are left out; {details} may be
     * a list, which becomes one paragraph per item.
     *
     * @param  array<string, string|list<string>|null>  $values
     */
    public function mail(string $key, array $values, ?string $url = null): MailMessage
    {
        ['subject' => $subject, 'greeting' => $greeting, 'body' => $body, 'action' => $action] = $this->template($key);
        $values += [
            'app_name' => (string) config('app.name'),
            'school' => (string) ($this->settings->get('school.name') ?? ''),
        ];
        $flat = collect($values)->map(fn ($value) => is_array($value) ? implode("\n\n", $value) : (string) $value)->all();

        $mail = (new MailMessage)->subject($this->fill($subject, $flat, singleLine: true));
        if (trim($greeting) !== '') {
            $mail->greeting($this->fill($greeting, $flat, singleLine: true));
        }
        foreach (preg_split('/\R{2,}/', $this->fill($body, $flat)) ?: [] as $paragraph) {
            if (trim($paragraph) !== '') {
                $mail->line(trim($paragraph));
            }
        }
        if ($url) {
            $mail->action($this->fill($action, $flat, singleLine: true) ?: 'Open', $url);
        }

        return $mail;
    }

    /**
     * Sample values for the preview on the settings page.
     *
     * @return array<string, string|list<string>>
     */
    public function sample(string $key): array
    {
        return [
            'name' => 'Alex Teacher', 'reference' => 'WEB-2026-00012', 'websites' => 'learn.example.org, cdn.example.net',
            'window' => '3 Mar 9:00 am – 7 Mar 3:00 pm', 'timezone' => 'Australia/Brisbane', 'message' => 'Approved for the Year 9 science unit.',
            'requester' => 'Alex Teacher', 'scope' => 'Staff and Students', 'category' => 'Teaching and learning', 'reason' => 'Year 9 science, unit 3',
            'headline' => 'New website request', 'details' => ['Reason: Year 9 science, unit 3'],
            'email' => 'alex@example.org', 'minutes' => '15', 'ip' => '10.0.0.5', 'sender' => 'Sam Admin',
        ] + ($key === 'filter_sync_alert' ? ['details' => ['Changed outside IT Requests: Students. 2 entries added in UniFi.']] : [])
          + ($key === 'policy_digest' ? ['details' => ['1 approved request(s) are waiting to be applied in the network controller.', 'Students: allow +2 / −0, block +0 / −1.']] : []);
    }

    /** @param array<string, string> $values */
    private function fill(string $text, array $values, bool $singleLine = false): string
    {
        $filled = preg_replace_callback('/\{([a-z_]+)\}/', fn (array $match) => $values[$match[1]] ?? '', $text) ?? $text;

        return $singleLine ? trim(preg_replace('/\s+/', ' ', $filled) ?? $filled) : $filled;
    }
}
