// Small progressive enhancements. Served from 'self' to satisfy the CSP; every
// page works without it.
(() => {
  document.addEventListener('click', event => {
    const copy = event.target.closest('[data-copy]');
    if (copy) {
      const source = document.querySelector(copy.dataset.copy);
      if (!source) return;
      const text = source.value ?? source.textContent;
      const done = () => { const label = copy.textContent; copy.textContent = 'Copied'; setTimeout(() => { copy.textContent = label; }, 1500); };
      if (navigator.clipboard?.writeText) {
        navigator.clipboard.writeText(text).then(done, () => { source.select?.(); document.execCommand('copy'); done(); });
      } else {
        source.select?.(); document.execCommand('copy'); done();
      }
      return;
    }

    const bulk = event.target.closest('[data-check-all],[data-check-none]');
    if (bulk) {
      const group = bulk.dataset.checkAll ?? bulk.dataset.checkNone;
      bulk.closest('form').querySelectorAll(`input[type="checkbox"][data-group="${group}"]`).forEach(box => { box.checked = 'checkAll' in bulk.dataset; });
      return;
    }

    const confirmable = event.target.closest('[data-confirm]');
    if (confirmable && !window.confirm(confirmable.dataset.confirm)) {
      event.preventDefault();
    }
  });

  // List scope: show the audience or policy field only when relevant.
  const syncScope = select => {
    const form = select.closest('form');
    form.querySelectorAll('[data-scope-field]').forEach(field => {
      field.hidden = field.dataset.scopeField !== select.value;
    });
  };
  document.querySelectorAll('[data-scope-select]').forEach(select => {
    syncScope(select);
    select.addEventListener('change', () => syncScope(select));
  });

  // Request form: temporary fields only for temporary access.
  const durations = document.querySelectorAll('input[name="access_duration"]');
  const temporary = document.querySelector('.temporary-fields');
  const recurring = document.querySelector('.recurring-fields');
  if (durations.length && temporary) {
    const sync = () => {
      const chosen = document.querySelector('input[name="access_duration"]:checked')?.value;
      temporary.hidden = chosen !== 'temporary';
      if (recurring) recurring.hidden = chosen !== 'recurring';
    };
    durations.forEach(input => input.addEventListener('change', sync));
    sync();
  }

  // Recurring schedule: dates I choose, or the school calendar (terms and periods).
  const modes = document.querySelectorAll('input[name="schedule[mode]"]');
  if (modes.length) {
    const calendarFields = document.querySelector('.calendar-fields');
    const dateFields = document.querySelector('.date-fields');
    const syncMode = () => {
      const calendar = document.querySelector('input[name="schedule[mode]"]:checked')?.value === 'calendar';
      if (calendarFields) calendarFields.hidden = !calendar;
      if (dateFields) dateFields.hidden = calendar;
    };
    modes.forEach(input => input.addEventListener('change', syncMode));
    syncMode();
  }

  // Print buttons (inline handlers are blocked by the content security policy).
  document.querySelectorAll('[data-print]').forEach(button => button.addEventListener('click', () => window.print()));

  // Collapsible panels with data-remember keep the state the user left them in.
  // The key includes a hash of the content, so a panel opens again when it changes.
  document.querySelectorAll('details[data-remember]').forEach(panel => {
    const key = 'details:' + panel.dataset.remember;
    try {
      const stored = localStorage.getItem(key);
      if (stored !== null) panel.open = stored === 'open';
    } catch (error) { /* storage unavailable */ }
    panel.addEventListener('toggle', () => {
      try { localStorage.setItem(key, panel.open ? 'open' : 'closed'); } catch (error) { /* storage unavailable */ }
    });
  });
})();

// In-place updates: a form with data-async="#region" posts in the background, then the
// region is replaced with the same region of the page the server sends back (with its
// success or error message), so the result shows without a reload. Without JavaScript
// the form posts normally. data-confirm-inline asks on the button itself, not in a dialog.
(() => {
  let activeTab = null;
  const applyTabs = root => {
    root.querySelectorAll('[data-row-tabs]').forEach(tabs => {
      const table = document.querySelector(tabs.dataset.rowTabs);
      if (!table) return;
      const current = activeTab || tabs.querySelector('.tab.active')?.dataset.tab || 'all';
      tabs.querySelectorAll('.tab').forEach(tab => tab.classList.toggle('active', tab.dataset.tab === current));
      let shown = 0;
      table.querySelectorAll('tbody tr').forEach(row => {
        const visible = current === 'all' || (current === 'attention' && row.dataset.attention === '1') || (current === 'unfiltered' && row.dataset.unfiltered === '1') || row.dataset.kind === current;
        row.hidden = !visible;
        if (visible) shown++;
      });
      const empty = tabs.closest('section')?.querySelector('.empty-tab');
      if (empty) empty.hidden = shown > 0;
    });
  };
  document.addEventListener('click', event => {
    const tab = event.target.closest('[data-row-tabs] [data-tab]');
    if (!tab) return;
    activeTab = tab.dataset.tab;
    applyTabs(document);
  });
  applyTabs(document);

  document.addEventListener('change', event => {
    const select = event.target.closest('select[data-autosubmit]');
    if (select?.form) select.form.requestSubmit();
  });

  document.addEventListener('submit', async event => {
    const form = event.target.closest('form[data-async]');
    if (!form || !window.fetch || !window.DOMParser) return;
    event.preventDefault();
    const button = event.submitter || form.querySelector('button');
    if (form.dataset.confirmInline && form.dataset.armed !== '1') {
      form.dataset.armed = '1';
      if (button) { button.dataset.label = button.textContent; button.textContent = form.dataset.confirmInline + ' Confirm'; button.classList.add('confirming'); }
      // A longer explanation (which filter, when it applies, a warning) shows beside the button while it waits.
      let detail = null;
      if (form.dataset.confirmDetail) {
        detail = document.createElement('small');
        detail.className = 'confirm-detail';
        detail.textContent = form.dataset.confirmDetail;
        form.after(detail);
      }
      setTimeout(() => { form.dataset.armed = ''; detail?.remove(); if (button?.dataset.label) { button.textContent = button.dataset.label; button.classList.remove('confirming'); } }, detail ? 12000 : 5000);
      return;
    }
    form.dataset.armed = '';
    const region = document.querySelector(form.dataset.async);
    region?.classList.add('is-busy');
    if (button) { button.disabled = true; button.textContent = 'Working…'; }
    try {
      const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' }, credentials: 'same-origin' });
      const page = new DOMParser().parseFromString(await response.text(), 'text/html');
      const fresh = page.querySelector(form.dataset.async);
      if (!region || !fresh) { window.location.reload(); return; }
      region.innerHTML = fresh.innerHTML;
      const slot = region.querySelector('[data-flash-slot]');
      if (slot) page.querySelectorAll('main .alert').forEach(alert => slot.appendChild(document.importNode(alert, true)));
      applyTabs(region);
    } catch (error) {
      form.submit();
    } finally {
      region?.classList.remove('is-busy');
    }
  });
})();

// Test sign-in: a group's role button adds "group:<name>=<role>" to the role rules box,
// replacing an earlier line for the same group.
document.addEventListener('click', event => {
  const button = event.target.closest('[data-append-rule]');
  if (!button) return;
  const box = document.querySelector(`textarea[name="${button.dataset.target || 'role_rules'}"]`);
  if (!box) return;
  const rule = button.dataset.appendRule;
  const group = rule.slice(0, rule.indexOf('=') + 1);
  const lines = box.value.split(/\r?\n/).filter(line => line.trim() !== '' && !line.trim().toLowerCase().startsWith(group.toLowerCase()));
  // A group rule goes before email_domain rules, so the more specific match wins (first match wins).
  const firstDomain = lines.findIndex(line => line.trim().toLowerCase().startsWith('email_domain:') || line.trim().toLowerCase().startsWith('domain:'));
  lines.splice(firstDomain === -1 ? lines.length : firstDomain, 0, rule);
  box.value = lines.join('\n');
  box.dispatchEvent(new Event('input', { bubbles: true }));
  button.closest('li')?.querySelectorAll('[data-append-rule]').forEach(other => other.classList.toggle('primary', other === button));
  box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
});
