<?php

/*
| Built-in blocklist presets. Domain lists use hagezi's "wildcard domains"
| format: each line is a domain that also stands for all of its subdomains.
| Sizes are approximate (late 2026) and change daily.
| Source and licence (GPL-3.0): https://github.com/hagezi/dns-blocklists
*/

$hagezi = 'https://raw.githubusercontent.com/hagezi/dns-blocklists/main';
$abuseipdb = 'https://raw.githubusercontent.com/borestad/blocklist-abuseipdb/main';

return [
    // Where the presets come from. Lists are downloaded at run time from
    // the publisher; nothing from them is bundled with this application.
    'publishers' => [
        'hagezi' => [
            'name' => 'hagezi DNS Blocklists',
            'url' => 'https://github.com/hagezi/dns-blocklists',
            'maintainer' => 'hagezi and contributors',
            'licence' => 'GPL-3.0',
            'licence_url' => 'https://github.com/hagezi/dns-blocklists/blob/main/LICENSE',
            'summary' => 'Community-maintained DNS blocklists built from many upstream sources, cleaned of false positives and updated several times a day. Widely used with Pi-hole, AdGuard, NextDNS and similar resolvers.',
            'format' => 'Wildcard domains: each line is a domain that also stands for all of its subdomains.',
        ],
        'borestad' => [
            'name' => 'blocklist-abuseipdb',
            'url' => 'https://github.com/borestad/blocklist-abuseipdb',
            'maintainer' => 'borestad, with data from AbuseIPDB (shared with permission)',
            'licence' => 'No licence stated; the data is AbuseIPDB\'s',
            'licence_url' => 'https://www.abuseipdb.com',
            'summary' => 'The IPv4 addresses most reported to AbuseIPDB (~100% confidence) over the last 30 days, updated several times a day. Please support AbuseIPDB. The publisher recommends 30 days or less to avoid false positives, and blocking on the incoming (WAN) side.',
            'format' => 'One IPv4 address per line with a comment. These lists are far larger than one UniFi IP list holds (20,000), so Filterdesk processes them: private and reserved addresses and anything on a firewall allow list are dropped, neighbouring addresses are merged into subnets, optionally a /24 with several reported addresses becomes the whole /24, and the result is capped, densest ranges first.',
        ],
    ],

    'presets' => [
        'hagezi-bypass' => ['name' => 'hagezi: DNS bypass (DoH, VPN, TOR, proxy)', 'type' => 'domains', 'url' => "{$hagezi}/wildcard/doh-vpn-proxy-bypass-onlydomains.txt", 'size' => 17000, 'publisher' => 'hagezi', 'group' => 'Bypass', 'description' => 'Stops devices getting round the DNS filter with encrypted DNS, VPNs, TOR and proxies. The usual first list for schools.'],
        'hagezi-doh' => ['name' => 'hagezi: Encrypted DNS servers only', 'type' => 'domains', 'url' => "{$hagezi}/wildcard/doh-onlydomains.txt", 'size' => 3600, 'publisher' => 'hagezi', 'group' => 'Bypass', 'description' => 'Smaller subset of the bypass list: DNS-over-HTTPS and DNS-over-TLS providers only.'],
        'hagezi-doh-ips' => ['name' => 'hagezi: Encrypted DNS server IPs', 'type' => 'ips', 'url' => "{$hagezi}/ips/doh.txt", 'size' => 1500, 'publisher' => 'hagezi', 'group' => 'Bypass', 'description' => 'IP addresses of DoH/DoT servers, for a firewall block. Catches devices with a hard-coded DNS server address.'],
        'hagezi-tif-mini' => ['name' => 'hagezi: Threat intelligence (mini)', 'type' => 'domains', 'url' => "{$hagezi}/wildcard/tif.mini-onlydomains.txt", 'size' => 210000, 'publisher' => 'hagezi', 'group' => 'Security', 'description' => 'Malware, phishing and scam domains. Very large: better suited to a DNS resolver than a content filter.'],
        'abuseipdb-30d' => ['name' => 'AbuseIPDB: most reported IPs, 30 days', 'type' => 'ips', 'url' => "{$abuseipdb}/abuseipdb-s100-30d.ipv4", 'size' => 160000, 'publisher' => 'borestad', 'group' => 'Security',
            'max_entries' => 40000, 'options' => ['collapse' => 3],
            'description' => 'Addresses reported for abuse (scanning, brute force, spam) in the last 30 days, the publisher\'s recommended period. About 160,000 addresses, processed to fit UniFi (up to 40,000 entries by default).'],
        'hagezi-tif-ips' => ['name' => 'hagezi: Threat intelligence IPs', 'type' => 'ips', 'url' => "{$hagezi}/ips/tif.txt", 'size' => 36000, 'publisher' => 'hagezi', 'group' => 'Security', 'description' => 'Malicious IP addresses for a firewall block. Larger than most controller IP groups allow; the list is capped.'],
        'hagezi-fake' => ['name' => 'hagezi: Fake shops and scams', 'type' => 'domains', 'url' => "{$hagezi}/wildcard/fake-onlydomains.txt", 'size' => 11000, 'publisher' => 'hagezi', 'group' => 'Security', 'description' => 'Fake online shops, scams and fraud.'],
        'hagezi-dyndns' => ['name' => 'hagezi: Dynamic DNS', 'type' => 'domains', 'url' => "{$hagezi}/wildcard/dyndns-onlydomains.txt", 'size' => 1500, 'publisher' => 'hagezi', 'group' => 'Security', 'description' => 'Dynamic DNS services often used by malware and to reach home servers.'],
        'hagezi-urlshortener' => ['name' => 'hagezi: Link shorteners', 'type' => 'domains', 'url' => "{$hagezi}/wildcard/urlshortener-onlydomains.txt", 'size' => 10000, 'publisher' => 'hagezi', 'group' => 'Security', 'description' => 'URL shorteners that hide where a link goes.'],
        'hagezi-social' => ['name' => 'hagezi: Social networks', 'type' => 'domains', 'url' => "{$hagezi}/wildcard/social-onlydomains.txt", 'size' => 8200, 'publisher' => 'hagezi', 'group' => 'Content', 'description' => 'Social networks. Usually for student networks only.'],
        'hagezi-gambling-mini' => ['name' => 'hagezi: Gambling (mini)', 'type' => 'domains', 'url' => "{$hagezi}/wildcard/gambling.mini-onlydomains.txt", 'size' => 55000, 'publisher' => 'hagezi', 'group' => 'Content', 'description' => 'Gambling. Large: check your controller accepts lists this size.'],
        'hagezi-piracy' => ['name' => 'hagezi: Piracy', 'type' => 'domains', 'url' => "{$hagezi}/wildcard/anti.piracy-onlydomains.txt", 'size' => 62000, 'publisher' => 'hagezi', 'group' => 'Content', 'description' => 'File sharing, warez and streaming piracy. Large.'],
        'hagezi-nosafesearch' => ['name' => 'hagezi: Search engines without SafeSearch', 'type' => 'domains', 'url' => "{$hagezi}/wildcard/nosafesearch-onlydomains.txt", 'size' => 200, 'publisher' => 'hagezi', 'group' => 'Content', 'description' => 'Search engines that cannot enforce SafeSearch.'],
        'hagezi-popupads' => ['name' => 'hagezi: Pop-up ads', 'type' => 'domains', 'url' => "{$hagezi}/wildcard/popupads-onlydomains.txt", 'size' => 72000, 'publisher' => 'hagezi', 'group' => 'Ads', 'description' => 'Pop-up and pop-under ad networks. Large.'],
        'hagezi-light' => ['name' => 'hagezi: Multi Light (ads, tracking, malware)', 'type' => 'domains', 'url' => "{$hagezi}/wildcard/light-onlydomains.txt", 'size' => 92000, 'publisher' => 'hagezi', 'group' => 'Ads', 'description' => 'General ad, tracking and malware protection. Large: better suited to a DNS resolver.'],
    ],

    // Feeds larger than this are cut short and marked "truncated".
    'max_entries_limit' => 250000,

    // Lines read from one IP feed before processing (the 30-day AbuseIPDB list has about 160,000).
    'max_ip_lines' => 250000,
];
