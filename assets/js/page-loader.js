(() => {
  const loader = document.getElementById('page-loader');
  let resetTimer;

  function hideLoader() {
    window.clearTimeout(resetTimer);
    loader.hidden = true;
  }

  function showLoader() {
    window.clearTimeout(resetTimer);
    loader.hidden = false;
    resetTimer = window.setTimeout(hideLoader, 15000);
  }

  window.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0 ||
        event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;

    const link = event.target.closest('a[href]');
    if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;
    if (link.matches('#open-sign-out, #cancel-sign-out')) return;

    const destination = new URL(link.href);
    if (destination.origin !== window.location.origin || destination.searchParams.has('export')) return;
    if (destination.pathname === window.location.pathname && destination.search === window.location.search) return;

    showLoader();
  });

  window.addEventListener('submit', (event) => {
    if (event.defaultPrevented) return;

    const form = event.target;
    const action = form.elements.namedItem('_action')?.value;
    if (action === 'logout') {
      const dialog = form.closest('#sign-out-dialog');
      if (dialog?.open && typeof dialog.close === 'function') dialog.close();
      showLoader();
      return;
    }

    if (action === 'login' ||
        (action === 'employee.save' && form.closest('.employee-profile-details-card'))) {
      showLoader();
    }
  });

  window.addEventListener('pageshow', hideLoader);
})();
