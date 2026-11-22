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
