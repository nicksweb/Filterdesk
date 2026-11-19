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
  if (durations.length && temporary) {
    const sync = () => { temporary.hidden = document.querySelector('input[name="access_duration"]:checked')?.value !== 'temporary'; };
    durations.forEach(input => input.addEventListener('change', sync));
    sync();
  }
})();
