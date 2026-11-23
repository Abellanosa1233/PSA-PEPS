const employeeDirectory = document.getElementById('employee-directory');

if (employeeDirectory) {
  const searchForm = employeeDirectory.querySelector('form');
  const search = searchForm.elements.q;
  const results = document.getElementById('employee-results');
  const count = document.getElementById('employee-count');
  let timer;
  let request;

  search.addEventListener('input', (event) => {
    if (event.isComposing) return;
    scheduleUpdate(250);
  });

  searchForm.addEventListener('change', (event) => {
    if (event.target.matches('select')) scheduleUpdate(0);
  });

  searchForm.addEventListener('submit', (event) => {
    event.preventDefault();
    scheduleUpdate(0);
  });

  function scheduleUpdate(delay) {
    clearTimeout(timer);
    if (request) request.abort();
    timer = setTimeout(updateResults, delay);
  }

  async function updateResults() {
    const current = new AbortController();
    request = current;
    const url = new URL(searchForm.action);
    url.search = new URLSearchParams(new FormData(searchForm));
    results.setAttribute('aria-busy', 'true');

    try {
      const response = await fetch(url, {
        signal: current.signal,
        headers: { 'X-Horizon-View': 'employee-search' },
      });
      if (!response.ok) throw new Error('Employee search failed.');
      const page = new DOMParser().parseFromString(await response.text(), 'text/html');
      const nextResults = page.getElementById('employee-results');
      if (current.signal.aborted) return;
      if (!nextResults) throw new Error('Employee results unavailable.');

      results.replaceChildren(...nextResults.childNodes);
      count.textContent = page.getElementById('employee-count').textContent;
      for (const selector of ['#employee-export', '[data-add-employee]', '#add-employee form']) {
        const attribute = selector.endsWith(' form') ? 'action' : 'href';
        document.querySelector(selector).setAttribute(attribute, page.querySelector(selector).getAttribute(attribute));
      }
      history.replaceState(null, '', url);
    } catch (error) {
      if (!current.signal.aborted) count.textContent = 'Unable to update results. Press Enter in search to retry.';
    } finally {
      if (request === current) results.removeAttribute('aria-busy');
    }
  }
}
