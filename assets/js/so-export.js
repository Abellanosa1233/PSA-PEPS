// Export the current SO editor content as a genuine, editable OOXML Word file.
(function () {
  var MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
  var enc = new TextEncoder();
  var imageMap = new WeakMap();
  var imageFiles = [];
  function xml(value) {
    return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&apos;');
  }
  function rPr(style) {
    var p = '';
    if (style.bold) p += '<w:b/>';
    if (style.italic) p += '<w:i/>';
    if (style.underline) p += '<w:u w:val="single"/>';
    if (style.color) p += '<w:color w:val="' + style.color + '"/>';
    if (style.highlight) p += '<w:shd w:val="clear" w:color="auto" w:fill="' + style.highlight + '"/>';
    if (style.href) p += '<w:u w:val="single"/><w:color w:val="0563C1"/>';
    return p ? '<w:rPr>' + p + '</w:rPr>' : '';
  }
  function hexColor(value) {
    var match = String(value || '').match(/^#?([0-9a-f]{6})$/i);
    if (match) return match[1].toUpperCase();
    match = String(value || '').match(/^rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)$/i);
    return match ? match.slice(1).map(function (v) { return (+v).toString(16).padStart(2, '0'); }).join('').toUpperCase() : '';
  }
  function inline(node, inherited) {
    if (node.nodeType === 3) {
      if (!node.nodeValue) return '';
      return '<w:r>' + rPr(inherited) + '<w:t xml:space="preserve">' + xml(node.nodeValue) + '</w:t></w:r>';
    }
    if (node.nodeType !== 1) return '';
    var tag = node.tagName.toLowerCase();
    if (tag === 'img') {
      var image = imageMap.get(node);
      if (!image) return '';
      var cx = Math.round(image.width * 9525), cy = Math.round(image.height * 9525);
      return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' + cx + '" cy="' + cy + '"/><wp:docPr id="' + image.id + '" name="SO image ' + image.id + '"/><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="' + image.id + '" name="SO image ' + image.id + '"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="' + image.rel + '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' + cx + '" cy="' + cy + '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
    }
    if (['input', 'button', 'select', 'script', 'style'].includes(tag) || node.classList.contains('so-remove')) return '';
    if (tag === 'br') return '<w:r>' + rPr(inherited) + '<w:br/></w:r>';
    if (node.classList.contains('so-box')) {
      var checked = node.querySelector('input:checked');
      return '<w:r><w:t>' + (checked ? '☒' : '☐') + '</w:t></w:r>';
    }
    var next = Object.assign({}, inherited);
    if (tag === 'b' || tag === 'strong') next.bold = true;
    if (tag === 'i' || tag === 'em') next.italic = true;
    if (tag === 'u' || tag === 'a') next.underline = true;
    if (tag === 'a') next.href = node.getAttribute('href') || '';
    var color = hexColor(node.style && node.style.color);
    var highlight = hexColor(node.style && node.style.backgroundColor);
    if (color) next.color = color;
    if (highlight) next.highlight = highlight;
    return Array.prototype.map.call(node.childNodes, function (child) { return inline(child, next); }).join('');
  }
  function paragraph(node, options) {
    options = options || {};
    var inherited = node.tagName === 'TH' ? { bold: true } : {};
    var runs = Array.prototype.map.call(node.childNodes, function (child) { return inline(child, inherited); }).join('');
    if (options.prefix) runs = '<w:r><w:t>' + xml(options.prefix) + '</w:t></w:r>' + runs;
    if (!runs) return '';
    var align = options.align || (node.style && node.style.textAlign) || '';
    var alignMap = { left: 'left', center: 'center', right: 'right', justify: 'both' };
    var props = alignMap[align] ? '<w:jc w:val="' + alignMap[align] + '"/>' : '';
    if (options.keepNext) props += '<w:keepNext/>';
    return '<w:p>' + (props ? '<w:pPr>' + props + '</w:pPr>' : '') + runs + '</w:p>';
  }
  function cell(node) {
    var paras = [];
    Array.prototype.forEach.call(node.childNodes, function (child) {
      if (child.nodeType === 1 && /^(P|DIV)$/.test(child.tagName) && child.children.length) {
        var p = paragraph(child); if (p) paras.push(p);
      }
    });
    if (!paras.length) paras.push(paragraph(node) || '<w:p/>');
    return '<w:tc><w:tcPr><w:tcW w:w="1800" w:type="dxa"/></w:tcPr>' + paras.join('') + '</w:tc>';
  }
  function table(node) {
    var tableRows = Array.prototype.slice.call(node.querySelectorAll('tr'));
    var colCount = Math.max(1, Math.max.apply(Math, tableRows.map(function (tr) { return tr.children.length; })));
    var rows = tableRows.map(function (tr) {
      var cells = Array.prototype.map.call(tr.children, cell).join('');
      return '<w:tr>' + cells + '</w:tr>';
    }).join('');
    if (!rows) return '';
    return '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/><w:tblBorders>' +
      ['top', 'left', 'bottom', 'right', 'insideH', 'insideV'].map(function (edge) { return '<w:' + edge + ' w:val="single" w:sz="4" w:space="0" w:color="333333"/>'; }).join('') +
      '</w:tblBorders></w:tblPr><w:tblGrid>' + new Array(colCount + 1).join('<w:gridCol w:w="1800"/>') + '</w:tblGrid>' + rows + '</w:tbl>';
  }
  function textOnlyParagraph(node) {
    var clone = node.cloneNode(true);
    clone.querySelectorAll('img,input,button,select,.so-remove,.so-add-participant').forEach(function (item) { item.remove(); });
    return paragraph(clone);
  }
  function blocks(node) {
    if (node.nodeType === 3) return node.nodeValue.trim() ? '<w:p><w:r><w:t xml:space="preserve">' + xml(node.nodeValue.trim()) + '</w:t></w:r></w:p>' : '';
    if (node.nodeType !== 1) return '';
    var tag = node.tagName.toLowerCase();
    if (['script', 'style', 'button', 'input', 'select'].includes(tag) || node.classList.contains('so-remove') || node.classList.contains('so-add-participant')) return '';
    if (tag === 'table') return table(node);
    if (tag === 'ul' || tag === 'ol') return Array.prototype.map.call(node.children, function (item, index) { return paragraph(item, { prefix: tag === 'ul' ? '• ' : (index + 1) + '. ' }); }).join('');
    if (tag === 'li') return paragraph(node, { prefix: '• ' });
    if (node.classList.contains('letterhead')) {
      var head = node.querySelector('.lh-text');
      var pictures = Array.prototype.map.call(node.querySelectorAll('img'), function (img) { return '<w:p>' + inline(img, {}) + '</w:p>'; }).join('');
      return pictures + (head ? Array.prototype.map.call(head.children, function (line) { return textOnlyParagraph(line); }).join('') : '');
    }
    if (node.classList.contains('lines')) {
      var children = Array.prototype.filter.call(node.children, function (child) { return !child.matches('input,button,select'); });
      var output = '';
      for (var i = 0; i < children.length; i += 3) {
        var label = children[i], colon = children[i + 1], value = children[i + 2];
        if (!value) continue;
        output += '<w:p><w:pPr><w:tabs><w:tab w:val="left" w:pos="3600"/></w:tabs></w:pPr>' +
          (label ? Array.prototype.map.call(label.childNodes, function (n) { return inline(n, { bold: true }); }).join('') : '') +
          (colon ? inline(colon, {}) : '') + '<w:r><w:tab/></w:r>' +
          Array.prototype.map.call(value.childNodes, function (n) { return inline(n, {}); }).join('') + '</w:p>';
      }
      return output;
    }
    if (node.classList.contains('foot')) {
      var footPicture = node.querySelector('img');
      return (footPicture ? '<w:p>' + inline(footPicture, {}) + '</w:p>' : '') + textOnlyParagraph(node.querySelector('.foot-text') || node);
    }
    if (node.classList.contains('signatory') || node.classList.contains('prepared')) return Array.prototype.map.call(node.children, function (line) { return textOnlyParagraph(line); }).join('');
    if (tag === 'p' || node.classList.contains('so-no') || node.classList.contains('ref') || node.classList.contains('memo-title') || node.classList.contains('list-title') || node.classList.contains('day')) return paragraph(node, { align: node.classList.contains('list-title') ? 'center' : '' });
    if (tag === 'img') return '';
    var hasBlocks = Array.prototype.some.call(node.children, function (child) {
      return /^(P|DIV|TABLE|HEADER|FOOTER|ARTICLE)$/.test(child.tagName);
    });
    if (!hasBlocks && (node.textContent || '').trim()) return paragraph(node);
    return Array.prototype.map.call(node.childNodes, blocks).join('');
  }
  function documentXml(form) {
    var sheets = form.querySelectorAll('.so-sheet');
    var content = '';
    Array.prototype.forEach.call(sheets, function (sheet, index) {
      if (index) content += '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
      Array.prototype.forEach.call(sheet.childNodes, function (child) { content += blocks(child); });
    });
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
      '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><w:body>' + content +
      '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="850" w:right="850" w:bottom="850" w:left="850" w:header="360" w:footer="360" w:gutter="0"/></w:sectPr></w:body></w:document>';
  }
  function crc32(bytes) {
    var crc = -1;
    for (var i = 0; i < bytes.length; i++) {
      crc ^= bytes[i];
      for (var j = 0; j < 8; j++) crc = (crc >>> 1) ^ (-(crc & 1) & 0xedb88320);
    }
    return (crc ^ -1) >>> 0;
  }
  function u16(view, offset, value) { view.setUint16(offset, value, true); }
  function u32(view, offset, value) { view.setUint32(offset, value >>> 0, true); }
  function zip(files) {
    var localParts = [], centralParts = [], offset = 0;
    files.forEach(function (file) {
      var name = enc.encode(file.name), data = typeof file.data === 'string' ? enc.encode(file.data) : new Uint8Array(file.data), crc = crc32(data);
      var local = new Uint8Array(30 + name.length + data.length), lv = new DataView(local.buffer);
      u32(lv, 0, 0x04034b50); u16(lv, 4, 20); u16(lv, 6, 0x800); u16(lv, 8, 0);
      u32(lv, 14, crc); u32(lv, 18, data.length); u32(lv, 22, data.length); u16(lv, 26, name.length); u16(lv, 28, 0);
      local.set(name, 30); local.set(data, 30 + name.length); localParts.push(local);
      var central = new Uint8Array(46 + name.length), cv = new DataView(central.buffer);
      u32(cv, 0, 0x02014b50); u16(cv, 4, 20); u16(cv, 6, 20); u16(cv, 8, 0x800); u16(cv, 10, 0);
      u32(cv, 16, crc); u32(cv, 20, data.length); u32(cv, 24, data.length); u16(cv, 28, name.length);
      u16(cv, 30, 0); u16(cv, 32, 0); u16(cv, 34, 0); u32(cv, 38, 0); u32(cv, 42, offset);
      central.set(name, 46); centralParts.push(central); offset += local.length;
    });
    var centralSize = centralParts.reduce(function (sum, part) { return sum + part.length; }, 0);
    var end = new Uint8Array(22), ev = new DataView(end.buffer);
    u32(ev, 0, 0x06054b50); u16(ev, 8, files.length); u16(ev, 10, files.length); u32(ev, 12, centralSize); u32(ev, 16, offset);
    return new Blob(localParts.concat(centralParts, [end]), { type: MIME });
  }
  function download(form) {
    var contentTypes = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>';
    var rootRels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>';
    var docRels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' + imageFiles.map(function (image) { return '<Relationship Id="' + image.rel + '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/' + image.name + '"/>'; }).join('') + '</Relationships>';
    var styles = '<?xml version="1.0" encoding="UTF-8"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman"/><w:sz w:val="22"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman"/><w:sz w:val="22"/></w:rPr></w:style></w:styles>';
    var files = [
      { name: '[Content_Types].xml', data: contentTypes },
      { name: '_rels/.rels', data: rootRels },
      { name: 'word/document.xml', data: documentXml(form) },
      { name: 'word/_rels/document.xml.rels', data: docRels },
      { name: 'word/styles.xml', data: styles }
    ];
    imageFiles.forEach(function (image) { files.push({ name: 'word/media/' + image.name, data: image.bytes }); });
    var blob = zip(files);
    var number = (form.querySelector('.so-no') || {}).textContent || 'Special-Order';
    var safeName = number.replace(/[^a-z0-9_-]+/gi, '-').replace(/^-|-$/g, '') || 'Special-Order';
    var url = URL.createObjectURL(blob), link = document.createElement('a');
    link.href = url; link.download = safeName + '.docx'; document.body.appendChild(link); link.click(); link.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 30000);
  }
  function prepareImages(form) {
    imageMap = new WeakMap(); imageFiles = [];
    var images = Array.prototype.slice.call(form.querySelectorAll('.so-sheet img'));
    return Promise.all(images.map(function (img, index) {
      return new Promise(function (resolve) {
        if (!img.complete || !img.naturalWidth) {
          img.addEventListener('load', function () { resolve(encodeImage(img, index)); }, { once: true });
          img.addEventListener('error', function () { resolve(); }, { once: true });
          return;
        }
        resolve(encodeImage(img, index));
      });
    }));
  }
  function encodeImage(img, index) {
    try {
      var canvas = document.createElement('canvas'), width = img.naturalWidth, height = img.naturalHeight;
      if (!width || !height) return;
      var limit = img.classList.contains('foot') || img.closest('.foot') ? 150 : 180;
      var scale = Math.min(1, limit / Math.max(width, height));
      canvas.width = Math.max(1, Math.round(width * scale)); canvas.height = Math.max(1, Math.round(height * scale));
      canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
      var dataUrl = canvas.toDataURL('image/png'), bytes = Uint8Array.from(atob(dataUrl.split(',')[1]), function (c) { return c.charCodeAt(0); });
      var image = { id: index + 1, rel: 'rId' + (index + 2), name: 'image' + (index + 1) + '.png', width: canvas.width, height: canvas.height, bytes: bytes };
      imageMap.set(img, image); imageFiles.push(image);
    } catch (error) { /* A blocked or unsupported image is omitted; document text still exports. */ }
  }
  document.querySelectorAll('form[data-so-editor]').forEach(function (form) {
    form.querySelectorAll('[data-so-export-docx]').forEach(function (button) {
      button.addEventListener('click', function () {
        button.disabled = true; button.textContent = '…';
        prepareImages(form).then(function () { download(form); }).catch(function () { download(form); }).finally(function () { button.disabled = false; button.textContent = 'DOCX'; });
      });
    });
  });
}());
