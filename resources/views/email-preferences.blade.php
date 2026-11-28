<x-layouts.app title="Email preferences">
    <div class="auth-wrap"><section class="auth-card">
        <p class="eyebrow">Email preferences</p>
        <h1>Emails about your requests</h1>
        <p>For {{ $user->email }}.</p>
        <form method="post" action="{{ request()->fullUrl() }}">@csrf @method('PUT')
            <label class="choice"><input type="radio" name="email_updates" value="essential" @checked($user->email_updates === 'essential')><span><strong>Only what matters</strong><small>When a request is decided (allowed, blocked or declined), IT has a question, a later start is booked, or access is removed.</small></span></label>
            <label class="choice"><input type="radio" name="email_updates" value="all" @checked($user->email_updates !== 'essential')><span><strong>Every update</strong><small>Also when a request is received, approved before it works, ends soon and has ended.</small></span></label>
            <div class="form-actions"><button class="button primary">Save</button></div>
        </form>
    </section></div>
</x-layouts.app>
