{{-- How to get a Cloudflare API token for website categories (Settings and the installer). --}}
<details class="token-help">
    <summary>How to get a Cloudflare token (about 3 minutes, free)</summary>
    <ol>
        <li>Sign in at <a href="https://dash.cloudflare.com/" target="_blank" rel="noopener">dash.cloudflare.com</a>, or create a free account. No website or payment is needed.</li>
        <li>Open <strong>My Profile → API Tokens</strong> and choose <strong>Create Token → Create Custom Token</strong>.</li>
        <li>Give it a name such as <em>Filterdesk categories</em>, and add the permission <strong>Account → Radar → Read</strong>. For sites outside Cloudflare's popularity rankings (most smaller sites), also add <strong>Account → Intel → Read</strong>.</li>
        <li>Under <strong>Account Resources</strong>, choose your account. Leave everything else as it is, then <strong>Continue to summary → Create Token</strong>.</li>
        <li>Copy the token straight away (Cloudflare shows it only once) and paste it here. With Intel, also paste your <strong>Account ID</strong>: it's in the dashboard address after <code>dash.cloudflare.com/</code>, or on the account's overview page.</li>
    </ol>
    <p class="hint">Cloudflare's guides: <a href="https://developers.cloudflare.com/fundamentals/api/get-started/create-token/" target="_blank" rel="noopener">creating an API token</a> · <a href="https://developers.cloudflare.com/radar/get-started/first-request/" target="_blank" rel="noopener">Radar</a> · <a href="https://developers.cloudflare.com/security-center/intel-apis/" target="_blank" rel="noopener">Intel</a> · <a href="https://developers.cloudflare.com/fundamentals/account/find-account-and-zone-ids/" target="_blank" rel="noopener">finding the account ID</a>. The token only needs read access. Then use <em>Try a domain</em> (or <em>Test lookup</em> in the installer) to check it works.</p>
</details>
