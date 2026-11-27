<?php

namespace App\Services\Notifications;

use App\Services\SettingsRepository;
use App\Services\Support;
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
        'note' => "IT's own note, if they wrote one", 'outcome' => '"allowed" or "blocked", for what was asked',
        'until' => '" until Friday 12 December" for timed access, otherwise empty', 'tips' => 'What to do if it still looks blocked (your "access is on its way" steps)', 'starts' => 'When it starts, e.g. "Monday 3 March"', 'ends' => 'When it ends, e.g. "Friday 12 December at 3:00 pm"',
    ];

    public const DEFINITIONS = [
        'request_received' => ['group' => 'Requester', 'label' => 'Request received', 'placeholders' => self::REQUEST,
            'subject' => 'We have your request for {websites}', 'greeting' => 'Hello {name},',
            'body' => "IT has your request for {websites}. They'll email you when they've looked at it.", 'action' => 'View request'],
        'request_approved' => ['group' => 'Requester', 'label' => 'Approved (being set up)', 'placeholders' => self::REQUEST,
            'subject' => 'Approved: {websites}', 'greeting' => 'Hello {name},',
            'body' => "IT has approved your request for {websites}. It will be {outcome} shortly{until}.\n\n{tips}\n\n{note}", 'action' => 'View request'],
        'request_scheduled' => ['group' => 'Requester', 'label' => 'Approved for a later start', 'placeholders' => self::REQUEST,
            'subject' => 'Approved from {starts}: {websites}', 'greeting' => 'Hello {name},',
            'body' => "IT has approved your request for {websites}. It will be {outcome} from {starts}{until}.\n\n{note}", 'action' => 'View request'],
        'request_live' => ['group' => 'Requester', 'label' => 'Allowed (or blocked) now', 'placeholders' => self::REQUEST,
            'subject' => 'Now {outcome}: {websites}', 'greeting' => 'Hello {name},',
            'body' => "IT has reviewed your request for {websites}. It's now {outcome}{until}.\n\n{tips}\n\n{note}", 'action' => 'View request'],
        'request_information' => ['group' => 'Requester', 'label' => 'IT needs more information', 'placeholders' => self::REQUEST,
            'subject' => 'A question about your request for {websites}', 'greeting' => 'Hello {name},',
            'body' => "IT needs a bit more information about your request for {websites}.\n\n{note}\n\nPlease reply on the request page.", 'action' => 'Reply'],
        'request_declined' => ['group' => 'Requester', 'label' => 'Declined', 'placeholders' => self::REQUEST,
            'subject' => 'Not changed: {websites}', 'greeting' => 'Hello {name},',
            'body' => "IT has reviewed your request for {websites} and won't change it, so it stays as it is.\n\n{note}", 'action' => 'View request'],
        'request_ending' => ['group' => 'Requester', 'label' => 'Access ends soon', 'placeholders' => self::REQUEST,
            'subject' => 'Ending soon: {websites}', 'greeting' => 'Hello {name},',
            'body' => 'Your access to {websites} ends {ends}. If you still need it after that, send a new request.', 'action' => 'View request'],
        'request_expired' => ['group' => 'Requester', 'label' => 'Access has ended', 'placeholders' => self::REQUEST,
            'subject' => 'Ended: {websites}', 'greeting' => 'Hello {name},',
            'body' => 'Your access to {websites} has ended. If you need it again, send a new request.', 'action' => 'View request'],
        'request_revoked' => ['group' => 'Requester', 'label' => 'Access removed by IT', 'placeholders' => self::REQUEST,
            'subject' => 'Removed: {websites}', 'greeting' => 'Hello {name},',
            'body' => "IT has removed your access to {websites}.\n\n{note}", 'action' => 'View request'],
        'it_activity' => ['group' => 'IT team', 'label' => 'New request, reply or assignment', 'placeholders' => self::REQUEST + ['headline' => 'What happened, e.g. "New website request"', 'details' => 'Extra lines for this event'],
            'subject' => '{reference}: {headline}', 'greeting' => '{headline}',
            'body' => "Domains: {websites}\n\nRequested by {requester} · {scope} · {category}\n\n{details}", 'action' => 'Open request'],
        'filter_sync_alert' => ['group' => 'IT team', 'label' => 'Web filter out of sync', 'placeholders' => ['details' => 'One line per filter that needs attention'],
            'subject' => 'Web filter out of sync', 'greeting' => 'A content filter needs attention', 'body' => '{details}', 'action' => 'Open policy lists'],
        'policy_digest' => ['group' => 'IT team', 'label' => 'Daily reconciliation digest', 'placeholders' => ['details' => 'Waiting requests, expiries and pending changes per filter'],
            'subject' => 'Daily web filter reconciliation', 'greeting' => 'Web filter changes to review today', 'body' => '{details}', 'action' => 'Open policy lists'],
        'two_person_override' => ['group' => 'IT team', 'label' => 'Approved without a second officer', 'placeholders' => ['officer' => 'Who approved', 'subject' => 'What was approved', 'reasons' => 'Why two officers were needed', 'why' => 'The reason given'],
            'subject' => 'Approved without a second IT officer: {subject}', 'greeting' => 'Please review this approval',
            'body' => "{officer} approved {subject} alone, although it needs two IT officers ({reasons}).\n\nReason given: {why}\n\nCheck it and revoke or change it if it is not right.", 'action' => 'Review it'],
        'exceptions_due' => ['group' => 'Requester', 'label' => 'Exceptions due for review', 'placeholders' => ['name' => 'Owner name', 'details' => 'One line per site with its list', 'expires' => 'When they expire if not reviewed'],
            'subject' => 'Do you still need these websites?', 'greeting' => 'Hello {name},',
            'body' => "Some websites you asked for are due for a check that they're still needed:\n\n{details}\n\nIf you still need them, mark them to keep. Anything not marked is removed on {expires}.", 'action' => 'Check them'],
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
        // Every email to a requester ends with the helpdesk (Settings → Help and support), when there is one.
        if ((self::DEFINITIONS[$key]['group'] ?? null) === 'Requester' && ($footer = $this->helpdeskLine()) !== null) {
            $mail->line($footer);
        }

        return $mail;
    }

    /** "For anything else, contact the IT Service Desk at …", or null when no email or phone is set. */
    public function helpdeskLine(): ?string
    {
        $support = app(Support::class)->all();
        $how = $support['email'] ?: $support['phone'];

        return $how === '' ? null : 'For anything else, contact '.($support['name'] ?: 'the IT helpdesk').' at '.$how.'.';
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
        ] + ($key === 'filter_sync_alert' ? ['details' => ['Changed outside Filterdesk: Students. 2 entries added in UniFi.']] : [])
          + ($key === 'policy_digest' ? ['details' => ['1 approved request(s) are waiting to be applied in the network controller.', 'Students: allow +2 / −0, block +0 / −1.']] : []);
    }

    /** @param array<string, string> $values */
    private function fill(string $text, array $values, bool $singleLine = false): string
    {
        $filled = preg_replace_callback('/\{([a-z_]+)\}/', fn (array $match) => $values[$match[1]] ?? '', $text) ?? $text;

        return $singleLine ? trim(preg_replace('/\s+/', ' ', $filled) ?? $filled) : $filled;
    }
}
