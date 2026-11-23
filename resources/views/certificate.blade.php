<x-layouts.app title="Install the school certificate">
    <div class="page-heading">
        <div>
            <p class="eyebrow">Network certificate</p>
            <h1>Install the school certificate</h1>
            <p>The school network checks secure (HTTPS) websites for safety. Your device needs to trust the school's certificate, or websites show security warnings.</p>
        </div>
    </div>

    <section class="panel form-panel">
        <div class="button-row">
            <a class="button primary" href="{{ route('certificate.download', 'crt') }}">Download the certificate (.crt)</a>
            <a class="button secondary" href="{{ route('certificate.download', 'pem') }}">PEM version</a>
        </div>
        <dl class="details">
            <div><dt>Certificate</dt><dd>{{ $certificate['subject'] }}</dd></div>
            <div><dt>Valid until</dt><dd>{{ $certificate['valid_to'] }}</dd></div>
            <div style="grid-column:1/-1"><dt>Fingerprint (SHA-256), to compare before you trust it</dt><dd class="mono" style="word-break:break-all">{{ $certificate['fingerprint'] }}</dd></div>
        </dl>

        <h3>Windows</h3>
        <ol>
            <li>Open the downloaded file and choose <strong>Install Certificate</strong>.</li>
            <li>Choose <strong>Current User</strong> (or Local Machine with admin rights), then <strong>Place all certificates in the following store</strong> → <strong>Trusted Root Certification Authorities</strong>.</li>
            <li>Finish, and restart your browser. Firefox: Settings → Privacy &amp; Security → Certificates → View Certificates → Authorities → Import.</li>
        </ol>
        <h3>macOS</h3>
        <ol>
            <li>Open the downloaded file; Keychain Access adds it to the <strong>login</strong> keychain.</li>
            <li>Double-click the certificate, open <strong>Trust</strong>, set <strong>When using this certificate</strong> to <strong>Always Trust</strong>, close and enter your password.</li>
        </ol>
        <h3>iPhone and iPad</h3>
        <ol>
            <li>Open this page in Safari and download the certificate, then <strong>Allow</strong> the profile download.</li>
            <li>Settings → <strong>Profile Downloaded</strong> → Install.</li>
            <li>Settings → General → About → <strong>Certificate Trust Settings</strong> → turn on full trust for it.</li>
        </ol>
        <h3>Android</h3>
        <ol>
            <li>Download the certificate.</li>
            <li>Settings → Security (or Security &amp; privacy) → More security settings → Encryption &amp; credentials → <strong>Install a certificate</strong> → <strong>CA certificate</strong>, and choose the file.</li>
        </ol>
        <h3>Chromebook</h3>
        <ol>
            <li>Download the certificate, then open <code>chrome://settings/certificates</code> → <strong>Authorities</strong> → <strong>Import</strong>.</li>
            <li>Tick <strong>Trust this certificate for identifying websites</strong>.</li>
        </ol>
        <p class="hint">School-managed devices usually get this automatically. If yours still shows warnings, ask IT.</p>
    </section>
</x-layouts.app>
