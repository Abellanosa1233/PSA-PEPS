(() => {
  const form = document.querySelector('[data-coe-archive-search]');
  if (!form) return;

  const search = form.elements.namedItem('q');
  const suggestions = document.getElementById('coe-archive-suggestions');
  const terms = JSON.parse(suggestions.dataset.options);
  const status = form.querySelector('[data-coe-search-status]');
  const normalize = (value) => value.trim().toLocaleLowerCase();
  let timer;
  let pending;

  function suggest() {
    const query = normalize(search.value);
    const matches = query ? terms.filter((term) => normalize(term).includes(query)) : [];
    matches.sort((a, b) => Number(normalize(b).startsWith(query)) - Number(normalize(a).startsWith(query)));
    suggestions.replaceChildren(...matches.slice(0, 8).map((value) => {
      const option = document.createElement('option');
      option.value = value;
      return option;
    }));
  }

  async function refresh() {
    const previousResults = document.getElementById('coe-archive-results');
    const controller = new AbortController();
    pending = controller;
    const url = new URL(form.action);
    url.searchParams.set('archive', '1');
    if (search.value.trim()) url.searchParams.set('q', search.value.trim());
    previousResults.setAttribute('aria-busy', 'true');
    status.textContent = 'Searching…';
    try {
      const response = await fetch(url, {
        signal: controller.signal,
        headers: { 'X-Horizon-View': 'coe-search' },
      });
      if (!response.ok) throw new Error('Archive search failed');
      const html = new DOMParser().parseFromString(await response.text(), 'text/html');
      const results = html.getElementById('coe-archive-results');
      if (!results) throw new Error('Archive results unavailable');
      if (controller.signal.aborted) return;
      previousResults.replaceWith(results);
      window.history.replaceState(null, '', url);
      status.textContent = '';
    } catch {
      if (!controller.signal.aborted) status.textContent = 'Live search is unavailable. Press Search to try again.';
    } finally {
      if (pending === controller) previousResults.removeAttribute('aria-busy');
    }
  }

  function queueSearch() {
    window.clearTimeout(timer);
    pending?.abort();
    suggest();
    timer = window.setTimeout(refresh, 220);
  }

  search.addEventListener('input', (event) => {
    if (!event.isComposing) queueSearch();
  });
  search.addEventListener('compositionend', queueSearch);
  search.addEventListener('focus', suggest);
})();
