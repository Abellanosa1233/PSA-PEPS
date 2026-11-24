const employeeDirectory = document.getElementById('employee-directory');

if (employeeDirectory) {
  const searchForm = employeeDirectory.querySelector('form');
  const search = searchForm.elements.q;
  const results = document.getElementById('employee-results');
  const count = document.getElementById('employee-count');
  const suggestions = document.getElementById('employee-suggestions');
  const employees = JSON.parse(suggestions.dataset.employees).map((employee) => ({
    ...employee,
    searchFields: [employee.name, employee.email, employee.title].map((value) => value.toLowerCase()),
  }));
  const filters = Array.from(searchForm.querySelectorAll('select[name]'));
  let resultsRequest;
  let activeOption = null;
  let suggestionsDismissed = false;

  search.setAttribute('role', 'combobox');
  search.setAttribute('aria-autocomplete', 'list');
  search.setAttribute('aria-expanded', 'false');
  search.setAttribute('aria-controls', suggestions.id);
  search.setAttribute('autocomplete', 'off');
  search.addEventListener('input', (event) => {
    if (event.isComposing) return;
    suggestionsDismissed = false;
    updateSuggestions();
  });
  search.addEventListener('compositionstart', () => {
    hideSuggestions();
  });
  search.addEventListener('compositionend', () => {
    suggestionsDismissed = false;
    updateSuggestions();
  });
  search.addEventListener('focus', () => {
    suggestionsDismissed = false;
    updateSuggestions();
  });
  search.addEventListener('blur', hideSuggestions);
  search.addEventListener('keydown', (event) => {
    if (event.isComposing) return;
    if (event.key === 'Escape' && !suggestions.hidden) {
      event.preventDefault();
      suggestionsDismissed = true;
      hideSuggestions();
    } else if (event.key === 'Enter' && activeOption) {
      event.preventDefault();
      window.location.assign(activeOption.dataset.profile);
    } else if (['ArrowDown', 'ArrowUp'].includes(event.key) && suggestions.childElementCount) {
      event.preventDefault();
      suggestionsDismissed = false;
      showSuggestions();
      const options = Array.from(suggestions.children);
      const direction = event.key === 'ArrowDown' ? 1 : -1;
      const index = activeOption ? options.indexOf(activeOption) + direction : (direction === 1 ? 0 : options.length - 1);
      setActiveOption(options[Math.max(0, Math.min(index, options.length - 1))]);
      activeOption.scrollIntoView({ block: 'nearest' });
    }
  });
  suggestions.addEventListener('mousedown', (event) => event.preventDefault());
  suggestions.addEventListener('click', (event) => {
    const option = event.target.closest('[data-profile]');
    if (option) window.location.assign(option.dataset.profile);
  });

  searchForm.addEventListener('change', (event) => {
    if (event.target.matches('select')) updateDirectory();
  });

  searchForm.addEventListener('submit', (event) => {
    event.preventDefault();
    updateDirectory();
  });

  function setActiveOption(option) {
    activeOption?.setAttribute('aria-selected', 'false');
    activeOption = option;
    if (option) {
      option.setAttribute('aria-selected', 'true');
      search.setAttribute('aria-activedescendant', option.id);
    } else {
      search.removeAttribute('aria-activedescendant');
    }
  }

  function hideSuggestions() {
    setActiveOption(null);
    suggestions.hidden = true;
    search.setAttribute('aria-expanded', 'false');
  }

  function showSuggestions() {
    if (suggestionsDismissed || document.activeElement !== search || !suggestions.childElementCount) return;
    suggestions.hidden = false;
    search.setAttribute('aria-expanded', 'true');
  }

  function updateSuggestions() {
    hideSuggestions();
    const query = search.value.trim().toLowerCase();
    const ranked = [[], [], []];
    if (query) {
      for (const employee of employees) {
        if (filters.some(({ name, value }) => value && employee[name] !== value)) continue;
        const fields = employee.searchFields;
        if (!fields.join(' ').includes(query)) continue;
        const rank = fields[0].startsWith(query) ? 0 : (fields.some((field) => field.startsWith(query)) ? 1 : 2);
        if (ranked[rank].length < 6) ranked[rank].push(employee);
      }
    }
    const options = ranked.flat().slice(0, 6).map((employee, index) => {
      const option = document.createElement('div');
      option.id = `employee-suggestion-${index}`;
      option.dataset.profile = employee.profile;
      option.setAttribute('role', 'option');
      option.setAttribute('aria-selected', 'false');
      const name = document.createElement('strong');
      name.textContent = employee.name;
      const details = document.createElement('small');
      details.textContent = `${employee.title} · ${employee.email}`;
      option.append(name, details);
      return option;
    });
    suggestions.replaceChildren(...options);
    showSuggestions();
  }

  function directoryUrl() {
    const url = new URL(searchForm.action);
    url.search = new URLSearchParams(new FormData(searchForm));
    return url;
  }

  async function fetchDirectory(url, signal) {
    const response = await fetch(url, {
      signal,
      headers: { 'X-Horizon-View': 'employee-search' },
    });
    if (!response.ok) throw new Error('Employee search failed.');
    return new DOMParser().parseFromString(await response.text(), 'text/html');
  }

  async function updateDirectory() {
    hideSuggestions();
    suggestions.replaceChildren();
    suggestionsDismissed = true;
    resultsRequest?.abort();
    const current = new AbortController();
    resultsRequest = current;
    const url = directoryUrl();
    results.setAttribute('aria-busy', 'true');

    try {
      const page = await fetchDirectory(url, current.signal);
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
      if (resultsRequest === current) results.removeAttribute('aria-busy');
    }
  }
}
