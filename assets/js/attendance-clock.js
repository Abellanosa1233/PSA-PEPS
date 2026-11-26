(() => {
  const clocks = document.querySelectorAll('[data-attendance-clock]');
  if (!clocks.length) return;

  const startedAt = performance.now();

  // Advance the server's local time while the page is open.
  function refresh() {
    const elapsedSeconds = (performance.now() - startedAt) / 1000;
    for (const clock of clocks) {
      const minute = Math.floor((Number(clock.dataset.clockSeconds) + elapsedSeconds) / 60);
      for (const control of clock.querySelectorAll('[data-clock-from]')) {
        const available = minute >= Number(control.dataset.clockFrom) && minute < Number(control.dataset.clockUntil);
        const late = available && minute >= Number(control.dataset.clockLate);
        control.hidden = !available;
        control.toggleAttribute('data-available', available);
        control.querySelector('button').classList.toggle('is-late', late);
        control.querySelector('[data-clock-late-label]').hidden = !late;
      }
    }
  }

  refresh();
  window.setInterval(refresh, 1000);
  window.addEventListener('pageshow', refresh);
})();
