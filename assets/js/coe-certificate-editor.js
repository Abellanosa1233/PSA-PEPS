(() => {
  const editor = document.getElementById('certificate-editor');
  const section = document.getElementById('certificate-section');
  const alignment = document.getElementById('certificate-align');
  const horizontal = document.getElementById('certificate-x');
  const vertical = document.getElementById('certificate-y');
  const fontSize = document.getElementById('certificate-font-size');
  const imageTarget = document.getElementById('certificate-image-target');
  const imageFile = document.getElementById('certificate-image-file');
  const imageLayer = document.getElementById('certificate-image-layer');
  const imageHorizontal = document.getElementById('certificate-image-x');
  const imageVertical = document.getElementById('certificate-image-y');
  const resetImage = document.getElementById('certificate-reset-image');
  const logoSize = document.getElementById('certificate-logo-size');
  const logoSizesKey = 'hrms-certificate-logo-sizes';
  const logos = [...document.querySelectorAll('.letterhead > img, .letter-footer > img')];
  const message = document.getElementById('certificate-branding-message');
  const formatButtons = [...editor.querySelectorAll('[data-format]')];
  const certificate = document.getElementById('certificate-document');
  const editableAreas = [...certificate.querySelectorAll('.editable')];
  const undoButton = document.getElementById('certificate-undo');
  const redoButton = document.getElementById('certificate-redo');
  const preview = document.getElementById('certificate-preview');
  const previewContent = document.getElementById('certificate-preview-content');
  const previewStatus = document.getElementById('certificate-preview-status');
  const history = [];
  let historyIndex = -1;
  let typingTimer;
  let activeArea;
  let savedRange;
  let selectedImage;
  let imageDestination;

  function movableImage() {
    const target = imageTarget.value;
    const image = target === 'free' || target === 'inline'
      ? (selectedImage?.dataset.imagePlacement === target ? selectedImage : null)
      : document.getElementById(target);
    return image?.isConnected && !image.hidden ? image : null;
  }

  function floatingPosition(image) {
    return {
      x: (parseFloat(image.style.left) || 0) * imageLayer.clientWidth / 100,
      y: (parseFloat(image.style.top) || 0) * imageLayer.clientHeight / 100,
    };
  }

  function placeFloatingImage(image, x, y) {
    const width = imageLayer.clientWidth;
    const height = imageLayer.clientHeight;
    image.style.left = Math.max(0, Math.min(width - image.offsetWidth, x)) / width * 100 + '%';
    image.style.top = Math.max(0, Math.min(height - image.offsetHeight, y)) / height * 100 + '%';
  }

  function centerFloatingImage(image) {
    placeFloatingImage(image, (imageLayer.clientWidth - image.offsetWidth) / 2, (imageLayer.clientHeight - image.offsetHeight) / 2);
  }

  function logoSizePercent(logo) {
    return Math.round((parseFloat(logo.style.getPropertyValue('--logo-scale')) || 1) * 100);
  }

  function setLogoSize(logo, size) {
    const percent = Math.round(Math.max(25, Math.min(175, Number(size) || 100)));
    logo.style.setProperty('--logo-scale', String(percent / 100));
  }

  function imageOffset(image, axis) {
    return parseFloat(image?.style.getPropertyValue('--image-' + axis)) || 0;
  }

  function logoMovementBounds(logo) {
    const page = imageLayer.getBoundingClientRect();
    const rect = logo.getBoundingClientRect();
    const x = imageOffset(logo, 'x');
    const y = imageOffset(logo, 'y');
    return {
      minX: Math.min(0, Math.ceil(page.left - rect.left + x)),
      maxX: Math.max(0, Math.floor(page.right - rect.right + x)),
      minY: Math.min(0, Math.ceil(page.top - rect.top + y)),
      maxY: Math.max(0, Math.floor(page.bottom - rect.bottom + y)),
    };
  }

  function syncImagePosition() {
    const image = movableImage();
    const free = image && imageTarget.value === 'free';
    const logo = image && logos.includes(image);
    const bounds = logo ? logoMovementBounds(image) : null;
    imageHorizontal.min = free ? 0 : bounds?.minX ?? -160;
    imageVertical.min = free ? 0 : bounds?.minY ?? -160;
    imageHorizontal.max = free ? imageLayer.clientWidth - image.offsetWidth : bounds?.maxX ?? 160;
    imageVertical.max = free ? imageLayer.clientHeight - image.offsetHeight : bounds?.maxY ?? 160;
    imageHorizontal.disabled = imageVertical.disabled = resetImage.disabled = !image;
    logoSize.disabled = !logo;
    logoSize.value = logo ? logoSizePercent(image) : 100;
    const position = free ? floatingPosition(image) : {
      x: imageOffset(image, 'x'),
      y: imageOffset(image, 'y'),
    };
    imageHorizontal.value = Math.round(position.x);
    imageVertical.value = Math.round(position.y);
  }

  function selectImage(image) {
    selectedImage?.removeAttribute('data-selected');
    selectedImage = image;
    selectedImage?.setAttribute('data-selected', '');
    syncImagePosition();
  }

  function addFloatingImage(image) {
    image.alt = 'Certificate image';
    image.dataset.imagePlacement = 'free';
    image.draggable = false;
    image.tabIndex = 0;
    imageLayer.append(image);
    centerFloatingImage(image);
    imageTarget.value = 'free';
    selectImage(image);
    bindFloatingImage(image);
  }

  function bindFloatingImage(image) {
    let drag;
    image.addEventListener('focus', () => {
      imageTarget.value = 'free';
      selectImage(image);
    });
    image.addEventListener('pointerdown', (event) => {
      if (event.button !== 0) return;
      event.preventDefault();
      image.focus({ preventScroll: true });
      const bounds = image.getBoundingClientRect();
      drag = { x: event.clientX - bounds.left, y: event.clientY - bounds.top };
      image.setPointerCapture(event.pointerId);
    });
    image.addEventListener('pointermove', (event) => {
      if (!drag || !image.hasPointerCapture(event.pointerId)) return;
      const bounds = imageLayer.getBoundingClientRect();
      placeFloatingImage(image, event.clientX - bounds.left - drag.x, event.clientY - bounds.top - drag.y);
      syncImagePosition();
    });
    image.addEventListener('lostpointercapture', () => {
      drag = null;
      checkpoint();
    });
    image.addEventListener('keydown', (event) => {
      const direction = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[event.key];
      if (!direction) return;
      event.preventDefault();
      const position = floatingPosition(image);
      const step = event.shiftKey ? 10 : 1;
      placeFloatingImage(image, position.x + direction[0] * step, position.y + direction[1] * step);
      syncImagePosition();
      checkpoint();
    });
  }

  function activate(area) {
    if (area === activeArea) return;
    activeArea?.removeAttribute('data-active');
    selectImage(null);
    activeArea = area;
    activeArea.setAttribute('data-active', '');
    savedRange = null;
    section.value = area.id;
    alignment.value = area.style.textAlign || 'left';
    horizontal.value = parseInt(area.style.getPropertyValue('--text-x'), 10) || 0;
    vertical.value = parseInt(area.style.getPropertyValue('--text-y'), 10) || 0;
    fontSize.value = Math.round(parseFloat(getComputedStyle(area).fontSize));
  }

  function showRange(range) {
    const selection = window.getSelection();
    activeArea.focus({ preventScroll: true });
    const destination = range?.cloneRange() ?? document.createRange();
    if (!range || !activeArea.contains(destination.commonAncestorContainer)) {
      destination.selectNodeContents(activeArea);
      destination.collapse(false);
    }
    selection.removeAllRanges();
    selection.addRange(destination);
  }

  document.addEventListener('selectionchange', () => {
    const selection = window.getSelection();
    if (!selection.rangeCount) return;
    const range = selection.getRangeAt(0);
    if (!activeArea.contains(range.commonAncestorContainer)) return;
    savedRange = range.cloneRange();
    for (const button of formatButtons) {
      button.setAttribute('aria-pressed', String(document.queryCommandState(button.dataset.format)));
    }
  });

  for (const area of editableAreas) {
    area.addEventListener('focus', () => activate(area));
    area.addEventListener('click', (event) => {
      if (event.target.closest('img')) imageTarget.value = 'inline';
      selectImage(event.target.closest('img'));
    });
    area.addEventListener('paste', (event) => {
      event.preventDefault();
      document.execCommand('insertText', false, event.clipboardData.getData('text/plain'));
    });
    area.addEventListener('drop', (event) => event.preventDefault());
    area.addEventListener('input', () => {
      window.clearTimeout(typingTimer);
      typingTimer = window.setTimeout(checkpoint, 350);
    });
  }

  section.addEventListener('change', () => {
    activate(document.getElementById(section.value));
    showRange(null);
  });
  for (const button of formatButtons) {
    button.addEventListener('pointerdown', (event) => event.preventDefault());
    button.addEventListener('click', () => {
      showRange(savedRange);
      document.execCommand(button.dataset.format);
      button.setAttribute('aria-pressed', String(document.queryCommandState(button.dataset.format)));
    });
  }
  alignment.addEventListener('change', () => { activeArea.style.textAlign = alignment.value; });
  fontSize.addEventListener('change', () => {
    if (fontSize.value !== '') {
      const size = Math.round(Math.max(6, Math.min(48, Number(fontSize.value))));
      activeArea.style.fontSize = size + 'px';
    }
    fontSize.value = Math.round(parseFloat(getComputedStyle(activeArea).fontSize));
  });
  document.getElementById('certificate-reset-font').addEventListener('click', () => {
    activeArea.style.fontSize = '';
    fontSize.value = Math.round(parseFloat(getComputedStyle(activeArea).fontSize));
  });
  for (const [input, property] of [[horizontal, '--text-x'], [vertical, '--text-y']]) {
    input.addEventListener('input', () => {
      const offset = Math.max(-96, Math.min(96, Number(input.value) || 0));
      activeArea.style.setProperty(property, offset + 'px');
    });
    input.addEventListener('change', () => { input.value = parseInt(activeArea.style.getPropertyValue(property), 10) || 0; });
  }
  document.getElementById('certificate-reset-position').addEventListener('click', () => {
    activeArea.style.textAlign = '';
    activeArea.style.removeProperty('--text-x');
    activeArea.style.removeProperty('--text-y');
    alignment.value = 'left';
    horizontal.value = 0;
    vertical.value = 0;
  });
  document.getElementById('certificate-clear-text').addEventListener('click', () => {
    const range = document.createRange();
    range.selectNodeContents(activeArea);
    showRange(range);
    document.execCommand('delete');
    selectImage(null);
  });

  document.getElementById('certificate-add-image').addEventListener('click', () => {
    imageDestination = { target: imageTarget.value, area: activeArea, range: savedRange };
    imageFile.value = '';
    imageFile.click();
  });
  imageFile.addEventListener('change', async () => {
    const file = imageFile.files[0];
    if (!file) return;
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) {
      message.textContent = 'Choose a PNG, JPEG, or WebP image.';
      return;
    }
    const { target, area, range } = imageDestination;
    const url = URL.createObjectURL(file);
    const image = new Image();
    image.src = url;
    try {
      await image.decode();
      if (imageFile.files[0] !== file) {
        URL.revokeObjectURL(url);
        return;
      }
      if (target === 'inline') {
        activate(area);
        showRange(range);
        if (!document.execCommand('insertImage', false, url)) throw new Error('Image insertion failed');
        const inserted = [...area.querySelectorAll('img')].find((item) => item.src === url);
        if (inserted) {
          inserted.alt = 'Certificate image';
          inserted.dataset.imagePlacement = 'inline';
          imageTarget.value = 'inline';
          selectImage(inserted);
        }
      } else if (target === 'free') {
        addFloatingImage(image);
      } else {
        const logo = document.getElementById(target);
        logo.src = url;
        logo.hidden = false;
      }
      syncImagePosition();
      checkpoint();
      message.textContent = 'Image added to this print copy.';
    } catch {
      URL.revokeObjectURL(url);
      message.textContent = 'The image could not be added. Try a different image file.';
    }
  });
  document.getElementById('certificate-remove-image').addEventListener('click', () => {
    const image = movableImage();
    if (!image) {
      message.textContent = 'Click an inserted image to select it first.';
      return;
    }
    if (imageTarget.value === 'free') {
      image.remove();
      selectImage(null);
    } else if (imageTarget.value === 'inline') {
      const range = document.createRange();
      range.selectNode(image);
      showRange(range);
      document.execCommand('delete');
      selectImage(null);
    } else {
      image.hidden = true;
    }
    message.textContent = 'Image removed from this print copy.';
    syncImagePosition();
  });

  imageTarget.addEventListener('change', syncImagePosition);
  for (const logo of logos) {
    logo.draggable = false;
    logo.tabIndex = 0;
    let drag;
    function selectLogo() {
      imageTarget.value = logo.id;
      selectImage(logo);
    }
    logo.addEventListener('focus', selectLogo);
    logo.addEventListener('click', selectLogo);
    logo.addEventListener('pointerdown', (event) => {
      if (event.button !== 0) return;
      event.preventDefault();
      logo.focus({ preventScroll: true });
      selectLogo();
      drag = {
        x: event.clientX - imageOffset(logo, 'x'),
        y: event.clientY - imageOffset(logo, 'y'),
        bounds: logoMovementBounds(logo),
      };
      logo.setPointerCapture(event.pointerId);
    });
    logo.addEventListener('pointermove', (event) => {
      if (!drag || !logo.hasPointerCapture(event.pointerId)) return;
      const x = Math.max(drag.bounds.minX, Math.min(drag.bounds.maxX, event.clientX - drag.x));
      const y = Math.max(drag.bounds.minY, Math.min(drag.bounds.maxY, event.clientY - drag.y));
      logo.style.setProperty('--image-x', x + 'px');
      logo.style.setProperty('--image-y', y + 'px');
      syncImagePosition();
    });
    logo.addEventListener('lostpointercapture', () => {
      drag = null;
      checkpoint();
    });
  }
  for (const [input, property] of [[imageHorizontal, '--image-x'], [imageVertical, '--image-y']]) {
    input.addEventListener('input', () => {
      const image = movableImage();
      if (!image) return;
      if (imageTarget.value === 'free') {
        const position = floatingPosition(image);
        if (input === imageHorizontal) position.x = Number(input.value) || 0;
        else position.y = Number(input.value) || 0;
        placeFloatingImage(image, position.x, position.y);
        return;
      }
      const offset = Math.max(Number(input.min), Math.min(Number(input.max), Number(input.value) || 0));
      image.style.setProperty(property, offset + 'px');
    });
    input.addEventListener('change', syncImagePosition);
  }
  resetImage.addEventListener('click', () => {
    const image = movableImage();
    if (!image) return;
    if (imageTarget.value === 'free') centerFloatingImage(image);
    else {
      image.style.removeProperty('--image-x');
      image.style.removeProperty('--image-y');
    }
    syncImagePosition();
  });
  logoSize.addEventListener('change', () => {
    const logo = movableImage();
    if (logos.includes(logo) && logoSize.value !== '') setLogoSize(logo, logoSize.value);
    syncImagePosition();
  });

  function withoutSelection(element) {
    const copy = element.cloneNode(true);
    copy.querySelectorAll('[data-selected]').forEach((image) => image.removeAttribute('data-selected'));
    return copy.innerHTML;
  }

  function capture() {
    return JSON.stringify({
      text: editableAreas.map((area) => [withoutSelection(area), area.getAttribute('style')]),
      logos: logos.map((logo) => [logo.getAttribute('src'), logo.hidden, logo.getAttribute('style')]),
      images: withoutSelection(imageLayer),
    });
  }

  function syncHistoryButtons() {
    undoButton.disabled = historyIndex <= 0;
    redoButton.disabled = historyIndex >= history.length - 1;
  }

  function checkpoint() {
    window.clearTimeout(typingTimer);
    const snapshot = capture();
    if (history[historyIndex] === snapshot) return;
    history.splice(historyIndex + 1, history.length, snapshot);
    if (history.length > 50) history.shift();
    historyIndex = history.length - 1;
    syncHistoryButtons();
  }

  function applySnapshot(snapshot) {
    const state = JSON.parse(snapshot);
    function style(element, value) {
      if (value === null) element.removeAttribute('style');
      else element.setAttribute('style', value);
    }
    selectImage(null);
    state.text.forEach(([html, css], index) => {
      editableAreas[index].innerHTML = html;
      style(editableAreas[index], css);
    });
    state.logos.forEach(([src, hidden, css], index) => {
      logos[index].setAttribute('src', src);
      logos[index].hidden = hidden;
      style(logos[index], css);
    });
    imageLayer.innerHTML = state.images;
    imageLayer.querySelectorAll('img').forEach(bindFloatingImage);
    const area = activeArea;
    activeArea = null;
    activate(area);
    syncHistoryButtons();
  }

  const defaults = capture();
  try {
    const savedSizes = JSON.parse(localStorage.getItem(logoSizesKey)) || {};
    for (const logo of logos) {
      if (typeof savedSizes[logo.id] === 'number') setLogoSize(logo, savedSizes[logo.id]);
    }
  } catch {
    // Default sizes are used when browser storage is unavailable.
  }
  checkpoint();
  editor.addEventListener('pointerdown', checkpoint);
  editor.addEventListener('click', checkpoint);
  editor.addEventListener('change', checkpoint);
  undoButton.addEventListener('click', () => {
    checkpoint();
    if (historyIndex > 0) applySnapshot(history[--historyIndex]);
  });
  redoButton.addEventListener('click', () => {
    checkpoint();
    if (historyIndex < history.length - 1) applySnapshot(history[++historyIndex]);
  });
  document.getElementById('certificate-restore-defaults').addEventListener('click', () => {
    checkpoint();
    applySnapshot(defaults);
    checkpoint();
    message.textContent = 'Default layout restored for this print copy.';
  });
  document.addEventListener('keydown', (event) => {
    if ((!event.ctrlKey && !event.metaKey) || event.altKey || event.key.toLowerCase() !== 'z') return;
    if (!event.target.closest('.editable') && !editor.contains(event.target)) return;
    if (event.target.matches('input, select')) return;
    event.preventDefault();
    checkpoint();
    (event.shiftKey ? redoButton : undoButton).click();
  });
  document.getElementById('certificate-save-logo-sizes').addEventListener('click', () => {
    try {
      const sizes = Object.fromEntries(logos.map((logo) => [logo.id, logoSizePercent(logo)]));
      localStorage.setItem(logoSizesKey, JSON.stringify(sizes));
      message.textContent = 'Logo sizes saved for future certificates in this browser.';
    } catch {
      message.textContent = 'Browser storage is unavailable. These sizes apply to this print copy.';
    }
  });

  function checkPreview(page) {
    const bounds = page.getBoundingClientRect();
    const style = getComputedStyle(page);
    const pageBottom = bounds.top + 14 * 96;
    const printable = {
      top: bounds.top + parseFloat(style.paddingTop),
      right: bounds.right - parseFloat(style.paddingRight),
      bottom: pageBottom - parseFloat(style.paddingBottom),
      left: bounds.left + parseFloat(style.paddingLeft),
    };
    let outside = false;
    let spills = bounds.bottom > pageBottom + 2;
    function inspect(rect) {
      if (!rect.width || !rect.height) return;
      if (rect.top < printable.top - 2 || rect.right > printable.right + 2 ||
          rect.bottom > printable.bottom + 2 || rect.left < printable.left - 2) outside = true;
      if (rect.bottom > pageBottom + 2) spills = true;
    }
    for (const image of page.querySelectorAll('img:not(.watermark)')) inspect(image.getBoundingClientRect());
    const text = document.createTreeWalker(page, NodeFilter.SHOW_TEXT);
    const range = document.createRange();
    while (text.nextNode()) {
      if (!text.currentNode.textContent.trim()) continue;
      range.selectNodeContents(text.currentNode);
      for (const rect of range.getClientRects()) inspect(rect);
    }
    previewStatus.toggleAttribute('data-warning', outside || spills);
    if (spills && outside) previewStatus.textContent = 'Some content is outside the printable area and may spill onto a second page.';
    else if (spills) previewStatus.textContent = 'This certificate may spill onto a second Legal page.';
    else if (outside) previewStatus.textContent = 'Some text or images are outside the printable area and may be clipped.';
    else previewStatus.textContent = 'This layout fits on one Legal page.';
  }

  document.getElementById('certificate-preview-open').addEventListener('click', async () => {
    const page = certificate.cloneNode(true);
    page.classList.add('preview-page');
    page.removeAttribute('id');
    for (const element of page.querySelectorAll('[id], [contenteditable], [tabindex], [data-active], [data-selected]')) {
      for (const name of ['id', 'contenteditable', 'tabindex', 'data-active', 'data-selected']) {
        element.removeAttribute(name);
      }
    }
    previewContent.replaceChildren(page);
    previewStatus.removeAttribute('data-warning');
    previewStatus.textContent = 'Checking the Legal layout…';
    preview.showModal();
    checkPreview(page);
    await Promise.allSettled([...page.querySelectorAll('img:not([hidden])')].map((image) => image.decode()));
    if (preview.open && page.isConnected) checkPreview(page);
  });
  preview.addEventListener('close', () => {
    if (!preview.open) previewContent.replaceChildren();
  });
  document.getElementById('certificate-preview-print').addEventListener('click', () => {
    preview.close();
    window.print();
  });
  document.getElementById('print-certificate').addEventListener('click', () => window.print());
  activate(document.getElementById(section.value));
})();
