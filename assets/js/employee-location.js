function employeeLocationPicker(form, onChange) {
  const group = form.querySelector('[data-employee-location]');
  const location = form.elements.location;
  const province = group.querySelector('[data-province]');
  const city = group.querySelector('[data-city]');
  const barangay = group.querySelector('[data-barangay]');
  const current = group.querySelector('[data-current-location]');
  const status = group.querySelector('[data-location-message]');
  const retry = group.querySelector('[data-location-retry]');
  const cache = new Map();
  const ncr = '1300000000';
  let savedLocation = location.value;
  let revision = 0;
  let retryLoad;

  function options(select, placeholder, records = []) {
    select.replaceChildren(new Option(placeholder, ''));
    select.append(...records.map(({ name, code }) => new Option(name, code)));
    select.disabled = records.length === 0;
  }

  function message(text, error = false) {
    status.textContent = text;
    status.toggleAttribute('data-error', error);
  }

  async function load(select, placeholder, getRecords) {
    const request = ++revision;
    retry.hidden = true;
    options(select, 'Loading…');
    message('');
    try {
      const records = await getRecords();
      if (request !== revision) return;
      options(select, records.length ? placeholder : 'No locations available', records);
    } catch {
      if (request !== revision) return;
      options(select, 'Locations unavailable');
      message('Could not load Philippine locations.', true);
      retryLoad = () => load(select, placeholder, getRecords);
      retry.hidden = false;
    }
  }

  async function records(path) {
    if (!cache.has(path)) {
      const response = await fetch('https://psgc.billyjamesramos.com/api/v1/' + path, {
        signal: AbortSignal.timeout(10000),
      });
      if (!response.ok) throw new Error('Location request failed');
      const { data } = await response.json();
      cache.set(path, data.sort((a, b) => a.name.localeCompare(b.name, 'en', { numeric: true })));
    }
    return cache.get(path);
  }

  function reset() {
    savedLocation = location.value;
    current.textContent = savedLocation ? `Saved location: ${savedLocation}` : '';
    current.hidden = !savedLocation;
    options(city, 'Select a province or area first');
    options(barangay, 'Select a city or municipality first');
    load(province, 'Select province or area', async () => [
      ...await records('provinces'),
      { code: 'metro-manila', name: 'Metro Manila (NCR)' },
      { code: 'independent', name: 'Independent city or special area' },
      ...(savedLocation ? [{ code: 'clear', name: 'Clear current location' }] : []),
    ]);
  }

  function updateLocation() {
    const name = (select) => select.selectedOptions[0].textContent;
    if (barangay.value) {
      const parts = [name(barangay), name(city)];
      if (province.value !== 'independent') parts.push(name(province));
      location.value = parts.join(', ');
    } else {
      location.value = province.value ? '' : savedLocation;
    }
    onChange();
  }

  province.addEventListener('change', () => {
    revision++;
    retry.hidden = true;
    message('');
    options(city, 'Select a province or area first');
    options(barangay, 'Select a city or municipality first');
    updateLocation();
    if (!province.value || province.value === 'clear') return;
    load(city, 'Select city or municipality', async () => {
      if (province.value === 'metro-manila') {
        return records(`regions/${ncr}/cities-municipalities?province=none`);
      }
      if (province.value === 'independent') {
        const entries = await records('cities-municipalities?province=none');
        return entries.filter((entry) => entry.regionCode !== ncr);
      }
      return records(`provinces/${province.value}/cities-municipalities`);
    });
  });

  city.addEventListener('change', () => {
    revision++;
    retry.hidden = true;
    message('');
    options(barangay, 'Select a city or municipality first');
    updateLocation();
    if (city.value) {
      load(barangay, 'Select barangay', () => records(`cities-municipalities/${city.value}/barangays`));
    }
  });

  barangay.addEventListener('change', () => {
    message('');
    updateLocation();
    validate();
  });
  retry.addEventListener('click', () => retryLoad());

  function validate() {
    if (province.value && province.value !== 'clear' && !barangay.value) {
      if (retry.hidden) message('Select a city or municipality and barangay to complete the location.', true);
      return city.disabled ? province : (barangay.disabled ? city : barangay);
    }
    if (Array.from(location.value.trim()).length > 80) {
      message('Location must be 80 characters or fewer.', true);
      return barangay.disabled ? province : barangay;
    }
    return null;
  }

  const locationField = location.closest('.field');
  locationField.hidden = true;
  group.hidden = false;

  return { reset, validate, get changing() { return Boolean(province.value); } };
}
