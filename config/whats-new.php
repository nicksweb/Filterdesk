<?php

/*
 * "What's new" for administrators: shown on the dashboard once per release,
 * in brief, with where each new setting is. The full notes are in the
 * release on GitHub (CHANGELOG.md). Newest first. Each item: text, and
 * optionally a route name (and its parameters) for "where".
 */
return [
    '2026.12.34' => [
        ['text' => 'Emails are only sent Monday to Friday, 7 am to 5 pm, unless you change it; others wait for the next opening.', 'route' => 'admin.settings.show', 'params' => ['section' => 'email', 'find' => 'When emails are sent']],
    ],
    '2026.12.33' => [
        ['text' => 'Search all settings from the box at the top of Settings.', 'route' => 'admin.settings.show'],
        ['text' => 'Privacy policy link, shown at the foot of every page and on the request forms.', 'route' => 'admin.settings.show', 'params' => ['section' => 'general', 'find' => 'Privacy policy link']],
        ['text' => 'Fewer emails to IT: one summary, weekly by default, with system alerts held for it.', 'route' => 'admin.settings.show', 'params' => ['section' => 'email', 'find' => 'Summary for IT']],
        ['text' => 'People can choose to get only the request emails that matter (profile, or the link in the email).', 'route' => 'profile.edit'],
        ['text' => 'Addresses per UniFi IP list and the firewall rule mode are now on the Protection page.', 'route' => 'admin.policy.blocklists.index'],
        ['text' => 'Restore every content filter and firewall allow list to how it was at a date and time.', 'route' => 'admin.restore.index'],
        ['text' => 'Submit a request for someone else, even before they have signed in (Users → Submit for someone).', 'route' => 'admin.users.index'],
    ],
    '2026.12.32' => [
        ['text' => 'Test larger UniFi IP lists from the browser.', 'route' => 'admin.settings.show', 'params' => ['section' => 'network', 'find' => 'Test larger UniFi IP lists']],
    ],
    '2026.12.31' => [
        ['text' => 'Website checks without signing in, and an embeddable check.', 'route' => 'admin.settings.show', 'params' => ['section' => 'profiles', 'find' => 'Website checks']],
        ['text' => 'A short request form for students.', 'route' => 'admin.settings.show', 'params' => ['section' => 'approval', 'find' => 'Request form']],
        ['text' => 'Never IT or admin: addresses and groups the role rules never promote.', 'route' => 'admin.settings.show', 'params' => ['section' => 'authentication', 'find' => 'Never IT or admin']],
        ['text' => 'Firewall rules: IP lists only.', 'route' => 'admin.policy.blocklists.index'],
    ],
];
