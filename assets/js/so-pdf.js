// Render the current SO pages directly to a downloadable, print-sized PDF.
(function () {
  var encoder = new TextEncoder();
  var pageWidth = 595.276, pageHeight = 841.89;
  var pixelsPerPoint = 96 / 72, rasterScale = 2;
  function escapeXml(text) {
    return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function styleTree(source, target) {
    if (source.nodeType === 1 && target.nodeType === 1) {
      var computed = window.getComputedStyle(source), style = '';
      for (var i = 0; i < computed.length; i++) {
        var property = computed[i];
        if (property === 'background-image' || property === 'cursor' || property === 'animation' || property === 'transition') continue;
        var value = computed.getPropertyValue(property);
        if (value) style += property + ':' + value + ';';
      }
      target.setAttribute('style', style);
    }
    var sourceChildren = source.childNodes, targetChildren = target.childNodes;
    for (var j = 0; j < Math.min(sourceChildren.length, targetChildren.length); j++) styleTree(sourceChildren[j], targetChildren[j]);
  }
  function prepareSheet(sheet) {
    var clone = sheet.cloneNode(true), rect = sheet.getBoundingClientRect();
    styleTree(sheet, clone);
    clone.querySelectorAll('.so-remove,.so-add-participant').forEach(function (control) { control.remove(); });
    clone.querySelectorAll('[contenteditable]').forEach(function (field) {
      field.removeAttribute('contenteditable'); field.removeAttribute('spellcheck');
      field.style.setProperty('outline', 'none', 'important');
      field.style.setProperty('background', 'transparent', 'important');
      field.style.setProperty('cursor', 'auto', 'important');
    });
    clone.querySelectorAll('select').forEach(function (select) {
      var span = document.createElement('span'); span.textContent = select.value;
      span.setAttribute('style', select.getAttribute('style') || ''); select.replaceWith(span);
    });
    clone.style.setProperty('margin', '0', 'important');
    clone.style.setProperty('box-shadow', 'none', 'important');
    clone.style.setProperty('overflow', 'hidden', 'important');
    clone.style.setProperty('width', rect.width + 'px', 'important');
    clone.style.setProperty('height', rect.height + 'px', 'important');
    clone.style.setProperty('min-height', rect.height + 'px', 'important');
    clone.setAttribute('xmlns', 'http://www.w3.org/1999/xhtml');
    var images = Array.prototype.slice.call(clone.querySelectorAll('img'));
    return Promise.all(images.map(function (image) {
      return new Promise(function (resolve) {
        function convert() {
          try {
            if (!image.naturalWidth) return resolve();
            var canvas = document.createElement('canvas');
            canvas.width = image.naturalWidth; canvas.height = image.naturalHeight;
            canvas.getContext('2d').drawImage(image, 0, 0);
            image.src = canvas.toDataURL('image/png');
          } catch (error) { /* Keep the original same-origin image if conversion is unavailable. */ }
          resolve();
        }
        if (image.complete && image.naturalWidth) convert();
        else { image.addEventListener('load', convert, { once: true }); image.addEventListener('error', resolve, { once: true }); }
      });
    })).then(function () {
      var xhtml = new XMLSerializer().serializeToString(clone);
      var svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xhtml="http://www.w3.org/1999/xhtml" width="' + rect.width + '" height="' + rect.height + '"><foreignObject x="0" y="0" width="100%" height="100%">' + xhtml + '</foreignObject></svg>';
      return { svg: svg, width: rect.width, height: rect.height, participant: sheet.classList.contains('participants-sheet') };
    });
  }
  function renderCanvas(page) {
    return new Promise(function (resolve, reject) {
      var image = new Image();
      image.onload = function () {
        var canvas = document.createElement('canvas');
        canvas.width = Math.ceil(page.width * rasterScale); canvas.height = Math.ceil(page.height * rasterScale);
        var context = canvas.getContext('2d'); context.fillStyle = '#ffffff'; context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(image, 0, 0, canvas.width, canvas.height);
        try { resolve(canvas); } catch (error) { reject(error); }
      };
      image.onerror = function () { reject(new Error('This browser could not render the SO page for PDF export.')); };
      image.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(page.svg);
    });
  }
  function bytesFromBase64(data) {
    var raw = atob(data), bytes = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
    return bytes;
  }
  function pdfBlob(canvases) {
    var objects = [], pageRefs = [];
    objects[1] = encoder.encode('<< /Type /Catalog /Pages 2 0 R >>');
    canvases.forEach(function (canvas, index) {
      var pageId = 3 + index * 3, contentId = pageId + 1, imageId = pageId + 2, imageName = 'Im' + (index + 1);
      var jpg = bytesFromBase64(canvas.toDataURL('image/jpeg', 0.94).split(',')[1]);
      var imageHeight = Math.min(pageHeight, canvas.height / (rasterScale * pixelsPerPoint));
      var content = encoder.encode('q\n' + pageWidth + ' 0 0 ' + imageHeight + ' 0 ' + (pageHeight - imageHeight) + ' cm\n/' + imageName + ' Do\nQ');
      var contentHead = encoder.encode('<< /Length ' + content.length + ' >>\nstream\n'), contentTail = encoder.encode('\nendstream');
      var imageHead = encoder.encode('<< /Type /XObject /Subtype /Image /Width ' + canvas.width + ' /Height ' + canvas.height + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' + jpg.length + ' >>\nstream\n');
      var imageTail = encoder.encode('\nendstream');
      objects[pageId] = encoder.encode('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + pageWidth + ' ' + pageHeight + '] /Resources << /XObject << /' + imageName + ' ' + imageId + ' 0 R >> >> /Contents ' + contentId + ' 0 R >>');
      objects[contentId] = new Blob([contentHead, content, contentTail]);
      objects[imageId] = new Blob([imageHead, jpg, imageTail]);
      pageRefs.push(pageId + ' 0 R');
    });
    objects[2] = encoder.encode('<< /Type /Pages /Kids [' + pageRefs.join(' ') + '] /Count ' + canvases.length + ' >>');
    return Promise.all(objects.map(function (object) { return object instanceof Blob ? object.arrayBuffer().then(function (buffer) { return new Uint8Array(buffer); }) : object; })).then(function (resolved) {
      var chunks = [encoder.encode('%PDF-1.4\n%\xE2\xE3\xCF\xD3\n')], offsets = [0], position = chunks[0].length;
      resolved.forEach(function (body, index) {
        if (!body) return;
        offsets[index + 1] = position;
        var head = encoder.encode((index + 1) + ' 0 obj\n'), tail = encoder.encode('\nendobj\n');
        chunks.push(head, body, tail); position += head.length + body.length + tail.length;
      });
      var xrefOffset = position, xref = 'xref\n0 ' + (resolved.length + 1) + '\n0000000000 65535 f \n';
      for (var n = 1; n <= resolved.length; n++) xref += String(offsets[n] || 0).padStart(10, '0') + ' 00000 n \n';
      xref += 'trailer\n<< /Size ' + (resolved.length + 1) + ' /Root 1 0 R >>\nstartxref\n' + xrefOffset + '\n%%EOF';
      chunks.push(encoder.encode(xref));
      return new Blob(chunks, { type: 'application/pdf' });
    });
  }
  function savePdf(form, button) {
    var sheets = Array.prototype.slice.call(form.querySelectorAll('.so-sheet'));
    var overflow = sheets.some(function (sheet) { return !sheet.classList.contains('participants-sheet') && sheet.scrollHeight > sheet.clientHeight + 2; });
    if (overflow) {
      window.alert('Some text extends beyond the A4 page. Reduce the text or paragraph spacing before saving the PDF.');
      return;
    }
    button.disabled = true; button.textContent = '…';
    Promise.all(sheets.map(prepareSheet)).then(function (pages) {
      return Promise.all(pages.map(renderCanvas));
    }).then(function (canvases) {
      var perPagePixels = Math.ceil(pageHeight * pixelsPerPoint * rasterScale), pdfPages = [];
      canvases.forEach(function (canvas) {
        for (var top = 0; top < canvas.height; top += perPagePixels) {
          var slice = document.createElement('canvas'); slice.width = canvas.width; slice.height = Math.min(perPagePixels, canvas.height - top);
          slice.getContext('2d').drawImage(canvas, 0, top, canvas.width, slice.height, 0, 0, slice.width, slice.height);
          pdfPages.push(slice);
        }
      });
      return pdfBlob(pdfPages);
    }).then(function (blob) {
      var title = (form.querySelector('.so-no') || {}).textContent || 'Special-Order';
      var name = title.replace(/[^a-z0-9_-]+/gi, '-').replace(/^-|-$/g, '') || 'Special-Order';
      var url = URL.createObjectURL(blob), link = document.createElement('a');
      link.href = url; link.download = name + '.pdf'; document.body.appendChild(link); link.click(); link.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 30000);
    }).catch(function (error) {
      window.alert(error && error.message ? error.message : 'The PDF could not be created in this browser.');
    }).finally(function () { button.disabled = false; button.textContent = 'PDF'; });
  }
  document.querySelectorAll('form[data-so-editor]').forEach(function (form) {
    form.querySelectorAll('[data-so-export-pdf]').forEach(function (button) {
      button.addEventListener('click', function () { savePdf(form, button); });
    });
  });
}());
