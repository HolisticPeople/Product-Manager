document.addEventListener('DOMContentLoaded', function () {
  if (typeof HPProductDetailData === 'undefined') return;
  var data = HPProductDetailData;
  var original = JSON.parse(JSON.stringify(data.product));
  var productId = original.id;
  var storageKey = 'hp_pm_staged_' + productId;
  // Do not persist staged changes across refreshes
  try { localStorage.removeItem(storageKey); } catch (e) {}

  // Bind fields
  var nameEl = document.getElementById('hp-pm-pd-name');
  var skuEl = document.getElementById('hp-pm-pd-sku');
  var priceEl = document.getElementById('hp-pm-pd-price');
  var salePriceEl = document.getElementById('hp-pm-pd-sale-price');
  var statusEl = document.getElementById('hp-pm-pd-status');
  var visibilityEl = document.getElementById('hp-pm-pd-visibility');
  var costEl = document.getElementById('hp-pm-pd-cost');
  var weightEl = document.getElementById('hp-pm-pd-weight');
  var lengthEl = document.getElementById('hp-pm-pd-length');
  var widthEl = document.getElementById('hp-pm-pd-width');
  var heightEl = document.getElementById('hp-pm-pd-height');
  var shipClassEl = document.getElementById('hp-pm-pd-ship-class');
  var imgEl = document.getElementById('hp-pm-pd-image');
  var galleryEl = document.getElementById('hp-pm-pd-gallery');
  var editLink = document.getElementById('hp-pm-pd-edit');
  var viewLink = document.getElementById('hp-pm-pd-view');

  function setValue(el, v) { if (el) el.value = (v == null ? '' : v); }

  setValue(nameEl, original.name);
  setValue(skuEl, original.sku);
  setValue(priceEl, original.price);
  setValue(salePriceEl, original.sale_price);
  setValue(statusEl, original.status);
  setValue(visibilityEl, original.visibility);
  setValue(costEl, original.cost);
  setValue(weightEl, original.weight);
  setValue(lengthEl, original.length);
  setValue(widthEl, original.width);
  setValue(heightEl, original.height);
  if (imgEl) imgEl.src = original.image || '';
  if (editLink) editLink.href = original.editLink || '#';
  if (viewLink) viewLink.href = original.viewLink || '#';

  if (shipClassEl && Array.isArray(data.shippingClasses)) {
    data.shippingClasses.forEach(function (t) {
      var opt = document.createElement('option');
      opt.value = t.slug; opt.textContent = t.name;
      if (original.shipping_class === t.slug) opt.selected = true;
      shipClassEl.appendChild(opt);
    });
  }

  // Tokens (brands/categories/tags)
  function setupTokens(prefix, list, current) {
    var tokens = document.getElementById('hp-pm-pd-' + prefix + '-tokens');
    var input = document.getElementById('hp-pm-pd-' + prefix + '-input');
    var datalist = document.getElementById('hp-pm-' + (prefix === 'categories' ? 'cats' : prefix) + '-list');
    if (!tokens || !input || !datalist) return { get: function(){ return []; }, set: function(){} };

    datalist.innerHTML = '';
    (list || []).forEach(function (t) { var o = document.createElement('option'); o.value = t.slug; o.label = t.name; datalist.appendChild(o); });

    function render(arr) {
      tokens.innerHTML = '';
      (arr || []).forEach(function (slug) {
        var label = slug; var f = (list || []).find(function (x){ return x.slug === slug; }); if (f) label = f.name;
        var span = document.createElement('span');
        span.className = 'hp-pm-token'; span.dataset.slug = slug;
        span.innerHTML = '<span>' + label + '</span><span class="x" title="Remove">×</span>';
        tokens.appendChild(span);
      });
    }
    function get() { return Array.from(tokens.querySelectorAll('.hp-pm-token')).map(function(n){return n.dataset.slug;}); }

    render(current || []);

    tokens.addEventListener('click', function (e) {
      if (e.target && e.target.classList.contains('x')) {
        var slug = e.target.parentElement.dataset.slug;
        render(get().filter(function (s){ return s!==slug; }));
      }
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && input.value.trim() !== '') {
        e.preventDefault();
        var cur = get(); var slug = input.value.trim();
        if (cur.indexOf(slug) === -1) cur.push(slug);
        render(cur); input.value = '';
      }
    });

    return { get: get, set: render };
  }
  var brandsTk = setupTokens('brands', data.brands || [], original.brands || []);
  var catsTk = setupTokens('categories', data.categories || [], original.categories || []);
  var tagsTk = setupTokens('tags', data.tags || [], original.tags || []);

  // Gallery
  var currentImageId = original.image_id || null;
  var currentGallery = (original.gallery_ids || []).slice();
  var galleryThumbs = {}; (original.gallery || []).forEach(function (g){ galleryThumbs[g.id] = g.url; });
  var galleryDirty = false; var imageDirty = false;
  var dragId = null; var dragSource = null; // 'gallery' or 'main'

  function renderMain() {
    if (!imgEl) return;
    var url = '';
    if (currentImageId && galleryThumbs[currentImageId]) url = galleryThumbs[currentImageId];
    else if (original.image) url = original.image;
    imgEl.src = url || '';
  }

  function renderGallery() {
    if (!galleryEl) return;
    galleryEl.innerHTML = '';
    currentGallery.forEach(function (id) {
      var url = galleryThumbs[id] || '';
      var d = document.createElement('div'); d.className = 'hp-pm-thumb'; d.draggable = true; d.dataset.id = id;
      d.innerHTML = '<img src="' + url + '" alt=""><span class="hp-pm-thumb-remove">×</span>';
      galleryEl.appendChild(d);
    });
    var plus = document.createElement('div'); plus.className = 'hp-pm-thumb hp-pm-thumb-add'; plus.textContent = '+'; galleryEl.appendChild(plus);
  }
  renderGallery();

  if (galleryEl) {
    galleryEl.addEventListener('click', function (e) {
      if (e.target && e.target.classList.contains('hp-pm-thumb-remove')) {
        var id = parseInt(e.target.parentElement.dataset.id, 10);
        currentGallery = currentGallery.filter(function (x){ return x !== id; });
        galleryDirty = true; renderGallery();
      } else if (e.target && e.target.classList.contains('hp-pm-thumb-add')) {
        if (typeof wp !== 'undefined' && wp.media) {
          var frame = wp.media({ multiple: true });
          frame.on('select', function(){
            var sel = frame.state().get('selection');
            sel.each(function(att){
              var json = att.toJSON ? att.toJSON() : att.attributes || {};
              var id = json.id; var sizes = json.sizes || {}; var url = (sizes.thumbnail && sizes.thumbnail.url) || json.url;
              if (id) {
                galleryThumbs[id] = url || '';
                if (currentGallery.indexOf(id) === -1) currentGallery.push(id);
              }
            });
            galleryDirty = true; renderGallery();
          });
          frame.open();
        }
      }
    });
    galleryEl.addEventListener('dragstart', function (e){ var p = e.target.closest('.hp-pm-thumb'); if (!p) return; dragId = parseInt(p.dataset.id,10); dragSource = 'gallery'; });
    galleryEl.addEventListener('dragover', function (e){ e.preventDefault(); });
    galleryEl.addEventListener('drop', function (e){
      e.preventDefault();
      var t = e.target.closest('.hp-pm-thumb');
      // Swap main -> gallery if dragging main onto a thumb
      if (dragSource === 'main') {
        if (!t || !currentImageId) { dragSource = null; dragId = null; return; }
        var tid = parseInt(t.dataset.id,10); if (isNaN(tid)) { dragSource=null; dragId=null; return; }
        var prevMain = currentImageId;
        // Ensure we have a thumb URL for the previous main before demoting it
        if (!galleryThumbs[prevMain]) { galleryThumbs[prevMain] = (imgEl && imgEl.src) ? imgEl.src : (original.image || ''); }
        var idx = currentGallery.indexOf(tid);
        if (idx > -1) {
          // Remove any existing prevMain to avoid duplicates, then replace target with prevMain
          var existing = currentGallery.indexOf(prevMain);
          if (existing > -1) currentGallery.splice(existing, 1);
          currentGallery.splice(idx, 1, prevMain);
          currentImageId = tid;
          imageDirty = true; galleryDirty = true;
          renderMain(); renderGallery();
        }
        dragSource = null; dragId = null; return;
      }
      // Reorder within gallery when dragging a thumb onto another thumb
      if (!t || !dragId) { dragSource = null; dragId = null; return; }
      var tid = parseInt(t.dataset.id,10); if (isNaN(tid)) { dragSource=null; dragId=null; return; }
      var arr = currentGallery.slice(); var from = arr.indexOf(dragId), to = arr.indexOf(tid);
      arr.splice(to,0, arr.splice(from,1)[0]); currentGallery = arr; galleryDirty = true; renderGallery();
      dragSource = null; dragId = null;
    });
  }

  if (imgEl && typeof wp !== 'undefined' && wp.media) {
    imgEl.style.cursor = 'pointer';
    imgEl.addEventListener('click', function(){
      var frame = wp.media({ multiple: false });
      frame.on('select', function(){
        var att = frame.state().get('selection').first(); if (!att) return;
        var json = att.toJSON ? att.toJSON() : att.attributes || {};
        currentImageId = json.id || null; var sizes = json.sizes || {}; var url = (sizes.thumbnail && sizes.thumbnail.url) || json.url || '';
        if (url) imgEl.src = url; imageDirty = true;
      });
      frame.open();
    });
  }

  // Drag/drop between main image and gallery
  if (imgEl) {
    imgEl.draggable = true;
    imgEl.addEventListener('dragstart', function(){ if (!currentImageId) return; dragSource = 'main'; });
    imgEl.addEventListener('dragover', function(e){ e.preventDefault(); });
    imgEl.addEventListener('drop', function(e){
      e.preventDefault();
      // If dragging a gallery thumb onto main, promote it and demote previous main into gallery
      if (dragSource === 'gallery' && dragId) {
        var prevMain = currentImageId;
        // Ensure we have a thumb URL for the previous main before demoting it
        if (prevMain && !galleryThumbs[prevMain]) { galleryThumbs[prevMain] = (imgEl && imgEl.src) ? imgEl.src : (original.image || ''); }
        var idx = currentGallery.indexOf(dragId);
        if (idx > -1) currentGallery.splice(idx, 1);
        if (prevMain) {
          var existing = currentGallery.indexOf(prevMain);
          if (existing > -1) currentGallery.splice(existing, 1);
          currentGallery.splice(idx > -1 ? idx : 0, 0, prevMain);
        }
        currentImageId = dragId;
        imageDirty = true; galleryDirty = true;
        renderMain(); renderGallery();
      }
      dragSource = null; dragId = null;
    });
  }

  // Staging helpers
  var stageBtn = document.getElementById('hp-pm-stage-btn');
  var applyBtn = document.getElementById('hp-pm-apply-btn');
  var discardBtn = document.getElementById('hp-pm-discard-btn');
  var stagedTitle = document.getElementById('hp-pm-staged-title');
  var stagedTable = document.getElementById('hp-pm-staged-table');
  var stagedBody = stagedTable ? stagedTable.querySelector('tbody') : null;

  if (stageBtn) stageBtn.textContent = data.i18n.stageBtn;
  if (applyBtn) applyBtn.textContent = data.i18n.applyAll;
  if (discardBtn) discardBtn.textContent = data.i18n.discardAll;
  if (stagedTitle) stagedTitle.textContent = data.i18n.stagedChanges;

  function readStaged() { try { return JSON.parse(localStorage.getItem(storageKey) || '{}'); } catch (e) { return {}; } }
  function writeStaged(obj) { localStorage.setItem(storageKey, JSON.stringify(obj || {})); renderStaged(); }

  function gatherChanges() {
    var c = {};
    if (nameEl && nameEl.value !== original.name) c.name = nameEl.value;
    if (skuEl && skuEl.value !== original.sku) c.sku = skuEl.value;
    if (priceEl && priceEl.value !== String(original.price || '')) c.price = priceEl.value;
    if (salePriceEl && salePriceEl.value !== String(original.sale_price || '')) c.sale_price = salePriceEl.value;
    if (statusEl && statusEl.value !== original.status) c.status = statusEl.value;
    if (visibilityEl && visibilityEl.value !== original.visibility) c.visibility = visibilityEl.value;

    var selBrands = brandsTk.get ? brandsTk.get() : [];
    if (JSON.stringify((selBrands.slice().sort())) !== JSON.stringify((original.brands || []).slice().sort())) c.brands = selBrands;
    var selCats = catsTk.get ? catsTk.get() : [];
    if (JSON.stringify((selCats.slice().sort())) !== JSON.stringify((original.categories || []).slice().sort())) c.categories = selCats;
    var selTags = tagsTk.get ? tagsTk.get() : [];
    if (JSON.stringify((selTags.slice().sort())) !== JSON.stringify((original.tags || []).slice().sort())) c.tags = selTags;

    if (costEl && costEl.value !== String(original.cost || '')) c.cost = costEl.value;
    if (weightEl && weightEl.value !== String(original.weight || '')) c.weight = weightEl.value;
    if (lengthEl && lengthEl.value !== String(original.length || '')) c.length = lengthEl.value;
    if (widthEl && widthEl.value !== String(original.width || '')) c.width = widthEl.value;
    if (heightEl && heightEl.value !== String(original.height || '')) c.height = heightEl.value;
    if (shipClassEl && shipClassEl.value !== String(original.shipping_class || '')) c.shipping_class = shipClassEl.value;

    // Images: include if dirty or different
    if (imageDirty || (currentImageId !== (original.image_id || null))) c.image_id = currentImageId;
    if (galleryDirty || JSON.stringify(currentGallery) !== JSON.stringify(original.gallery_ids || [])) c.gallery_ids = currentGallery;
    return c;
  }

  function renderStaged() {
    var staged = readStaged(); if (!stagedBody || !stagedTable) return;
    stagedBody.innerHTML = ''; var keys = Object.keys(staged);
    stagedTable.style.display = keys.length ? '' : 'none'; applyBtn.disabled = keys.length === 0;
    keys.forEach(function (k) {
      var tr = document.createElement('tr'); var fromVal = original[k]; var toVal = staged[k];
      if (Array.isArray(fromVal)) fromVal = fromVal.join(', '); if (Array.isArray(toVal)) toVal = toVal.join(', ');
      tr.innerHTML = '<td>' + k + '</td><td>' + (fromVal == null ? '' : fromVal) + '</td><td>' + (toVal == null ? '' : toVal) + '</td>' +
        '<td><button type="button" class="button-link" data-remove="' + k + '">Remove</button></td>';
      stagedBody.appendChild(tr);
    });
  }

  renderStaged();

  if (stageBtn) stageBtn.addEventListener('click', function () {
    var changes = gatherChanges(); if (Object.keys(changes).length === 0) return;
    var staged = readStaged(); Object.assign(staged, changes); writeStaged(staged);
  });

  if (stagedBody) stagedBody.addEventListener('click', function (e) {
    var rm = e.target && e.target.getAttribute('data-remove'); if (!rm) return;
    var staged = readStaged(); delete staged[rm]; writeStaged(staged);
    // Revert token fields and simple fields to current snapshot
    if (rm === 'brands' && brandsTk.set) brandsTk.set(original.brands || []);
    if (rm === 'categories' && catsTk.set) catsTk.set(original.categories || []);
    if (rm === 'tags' && tagsTk.set) tagsTk.set(original.tags || []);
    if (rm === 'name') setValue(nameEl, original.name);
    if (rm === 'sku') setValue(skuEl, original.sku);
    if (rm === 'price') setValue(priceEl, original.price);
    if (rm === 'sale_price') setValue(salePriceEl, original.sale_price);
    if (rm === 'status') setValue(statusEl, original.status);
    if (rm === 'visibility') setValue(visibilityEl, original.visibility);
    if (rm === 'cost') setValue(costEl, original.cost);
    if (rm === 'weight') setValue(weightEl, original.weight);
    if (rm === 'length') setValue(lengthEl, original.length);
    if (rm === 'width') setValue(widthEl, original.width);
    if (rm === 'height') setValue(heightEl, original.height);
    if (rm === 'shipping_class') setValue(shipClassEl, original.shipping_class || '');
    if (rm === 'image_id') { currentImageId = original.image_id || null; if (imgEl) imgEl.src = original.image || ''; imageDirty = false; }
    if (rm === 'gallery_ids') { currentGallery = (original.gallery_ids || []).slice(); renderGallery(); }
  });

  if (discardBtn) discardBtn.addEventListener('click', function () {
    // Clear staged and reload fresh snapshot from server to fully revert UI
    writeStaged({});
    fetch(data.restBase, { headers: { 'X-WP-Nonce': data.nonce } })
      .then(function (r) { return r.json(); })
      .then(function (payload) {
        if (!payload || !payload.id) return;
        original = payload;
        setValue(nameEl, original.name); setValue(skuEl, original.sku);
        setValue(priceEl, original.price); setValue(salePriceEl, original.sale_price);
        setValue(statusEl, original.status); setValue(visibilityEl, original.visibility);
        brandsTk.set && brandsTk.set(original.brands || []);
        catsTk.set && catsTk.set(original.categories || []);
        tagsTk.set && tagsTk.set(original.tags || []);
        setValue(costEl, original.cost); setValue(weightEl, original.weight);
        setValue(lengthEl, original.length); setValue(widthEl, original.width);
        setValue(heightEl, original.height); setValue(shipClassEl, original.shipping_class || '');
        currentImageId = original.image_id || null;
        if (imgEl) imgEl.src = original.image || '';
        currentGallery = (original.gallery_ids || []).slice();
        galleryThumbs = {}; (original.gallery || []).forEach(function (g){ galleryThumbs[g.id] = g.url; });
        renderGallery(); imageDirty = false; galleryDirty = false;
        showNotice('Changes discarded', 'info');
      })
      .catch(function(){ /* ignore */ });
  });

  // Inline notice (non-blocking success message)
  var actionsWrap = document.querySelector('.hp-pm-staging-actions');
  var noticeEl = document.createElement('div');
  noticeEl.id = 'hp-pm-notice';
  noticeEl.style.cssText = 'margin-top:8px; display:none; padding:6px 10px; border-radius:4px; background:#e7f7eb; color:#14532d; border:1px solid #c7eed8;';
  if (actionsWrap && actionsWrap.parentNode) { actionsWrap.parentNode.insertBefore(noticeEl, actionsWrap.nextSibling); }
  function showNotice(msg, type) {
    if (!noticeEl) return;
    noticeEl.textContent = msg || '';
    noticeEl.style.display = msg ? '' : 'none';
    if (msg) setTimeout(function(){ noticeEl.style.display = 'none'; }, 2500);
  }

  if (applyBtn) applyBtn.addEventListener('click', function () {
    var staged = readStaged(); if (Object.keys(staged).length === 0) { alert(data.i18n.nothingToApply); return; }
    fetch(data.restBase + '/apply', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce }, body: JSON.stringify({ changes: staged }) })
    .then(function (r) {
      return r.json().then(function (body) {
        if (!r.ok) {
          var msg = (body && (body.message || body.data && body.data.message)) || 'Apply failed';
          throw new Error(msg);
        }
        return body;
      });
    })
    .then(function (payload) {
      if (payload && payload.id) {
        original = payload;
        setValue(nameEl, original.name); setValue(skuEl, original.sku); setValue(priceEl, original.price); setValue(salePriceEl, original.sale_price);
        setValue(statusEl, original.status); setValue(visibilityEl, original.visibility);
        brandsTk.set && brandsTk.set(original.brands || []); catsTk.set && catsTk.set(original.categories || []); tagsTk.set && tagsTk.set(original.tags || []);
        setValue(costEl, original.cost); setValue(weightEl, original.weight); setValue(lengthEl, original.length); setValue(widthEl, original.width); setValue(heightEl, original.height); setValue(shipClassEl, original.shipping_class || '');
        if (imgEl) imgEl.src = original.image || ''; currentImageId = original.image_id || null; currentGallery = (original.gallery_ids || []).slice(); galleryThumbs = {}; (original.gallery || []).forEach(function (g){ galleryThumbs[g.id] = g.url; }); renderGallery(); imageDirty=false; galleryDirty=false;
      }
      writeStaged({}); showNotice(data.i18n.applied, 'success');
    })
    .catch(function (e) { alert('Failed to apply changes: ' + (e && e.message ? e.message : '')); });
  });

  // --- ERP Debug buttons (visible only when ?hp_pm_debug=1) ---
  var dbgBase = data.restBase.replace(/\/product\/\d+$/, '');
  function postDebug(payload){
    return fetch(dbgBase + '/erp/debug', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
      body: JSON.stringify(payload)
    }).then(function(r){ return r.json(); });
  }
  var btnSet = document.getElementById('hp-pm-debug-setstock');
  var btnReduce = document.getElementById('hp-pm-debug-reduce');
  var btnRestore = document.getElementById('hp-pm-debug-restore');
  var btnShow = document.getElementById('hp-pm-debug-showlogs');
  if (btnSet) btnSet.addEventListener('click', function(){
    var q = prompt('QOH to log (set_stock)?', '0'); var qoh = parseInt(q,10); if (isNaN(qoh)) qoh = 0;
    postDebug({ action:'set_stock', product_id: productId, qoh: qoh }).then(function(res){ showNotice('Logged set_stock', 'info'); console.log(res); });
  });
  if (btnReduce) btnReduce.addEventListener('click', function(){
    var q = prompt('Qty to reduce?', '1'); var qty = parseInt(q,10); if (isNaN(qty) || qty <= 0) qty = 1;
    postDebug({ action:'reduce', product_id: productId, qty: qty }).then(function(res){ showNotice('Logged reduce_order_stock', 'info'); console.log(res); });
  });
  if (btnRestore) btnRestore.addEventListener('click', function(){
    var q = prompt('Qty to restore?', '1'); var qty = parseInt(q,10); if (isNaN(qty) || qty <= 0) qty = 1;
    postDebug({ action:'restore', product_id: productId, qty: qty }).then(function(res){ showNotice('Logged restore_order_stock', 'info'); console.log(res); });
  });
  if (btnShow) btnShow.addEventListener('click', function(){
    var url = dbgBase + '/erp/logs?limit=25&product_id=' + encodeURIComponent(productId);
    fetch(url, { headers: { 'X-WP-Nonce': data.nonce } })
      .then(function(r){ return r.json(); })
      .then(function(res){ console.log('logs:', res); showNotice('Fetched ' + (res && res.count || 0) + ' product logs (see console).', 'info'); })
      .catch(function(){ /* ignore */ });
  });

  // --- ERP Movements (from logs) ---
  (function initErpLogs(){
    var table = document.getElementById('hp-pm-erp-table');
    if (!table) return;
    // Sales chart (last 90 days)
    var salesCanvas = document.getElementById('hp-pm-erp-sales-chart');
    var salesChart = null;
    function renderSales(days){
      if (!(salesCanvas && window.Chart)) return;
      var urlDaily = dbgBase + '/product/' + encodeURIComponent(productId) + '/sales/daily?days=' + encodeURIComponent(days || 90);
      fetch(urlDaily, { headers: { 'X-WP-Nonce': data.nonce } })
        .then(function(r){ return r.json(); })
        .then(function(series){
          var labels = (series && series.labels) || [];
          var values = (series && series.values) || [];
          var points = labels.map(function(d, i){ return { x: d, y: values[i] || 0 }; });
          if (!salesChart) {
            var ctx = salesCanvas.getContext('2d');
            salesChart = new Chart(ctx, {
              type: 'bar',
              data: { datasets: [{ label: 'Sales (qty)', data: points, parsing: { xAxisKey: 'x', yAxisKey: 'y' }, backgroundColor: 'rgba(0, 124, 186, 0.5)', borderColor: 'rgba(0, 124, 186, 1)', borderWidth: 1 }] },
              options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                  x: { type: 'time', time: { unit: 'day', stepSize: 1, tooltipFormat: 'PP' }, ticks: { source: 'auto', autoSkip: true, maxRotation: 0 } },
                  y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } }
                },
                plugins: { legend: { display: false } }
              }
            });
          } else {
            salesChart.data.datasets[0].data = points;
            salesChart.update();
          }
        })
        .catch(function(){ /* ignore */ });
    }
    // Initial render 90d
    renderSales(90);
    // Range selector buttons
    Array.prototype.forEach.call(document.querySelectorAll('.hp-pm-erp-range'), function(btn){
      btn.addEventListener('click', function(){ var d = parseInt(btn.getAttribute('data-days'),10) || 90; renderSales(d); });
    });
    var tbody = table.querySelector('tbody');
    var statsTotal = document.getElementById('hp-pm-erp-total');
    var stats90 = document.getElementById('hp-pm-erp-90');
    var stats30 = document.getElementById('hp-pm-erp-30');
    var stats7 = document.getElementById('hp-pm-erp-7');
    function render(payload){
        var rows = (payload && payload.rows) || [];
        if (tbody) {
          tbody.innerHTML = '';
          rows.forEach(function(m){
            var tr = document.createElement('tr');
            var orderCell = (m.order_id ? ('#' + m.order_id) : '') + (m.customer_name ? (' — ' + m.customer_name) : '');
            tr.innerHTML = '<td>' + (m.created_at || '') + '</td>' +
                           '<td>' + (m.movement_type || '') + '</td>' +
                           '<td>' + (m.qty == null ? '' : m.qty) + '</td>' +
                           '<td>' + orderCell + '</td>' +
                           '<td>' + (m.qoh_after == null ? '' : m.qoh_after) + '</td>' +
                           '<td>' + (m.source || '') + '</td>';
            tbody.appendChild(tr);
          });
        }
        if (payload && payload.stats) {
          if (statsTotal) statsTotal.textContent = payload.stats.total_sales || 0;
          if (stats90) stats90.textContent = payload.stats.sales_90 || 0;
          if (stats30) stats30.textContent = payload.stats.sales_30 || 0;
          if (stats7) stats7.textContent = payload.stats.sales_7 || 0;
        }
    }

    // Try DB movements first, then fall back to logs if empty
    var urlDb = dbgBase + '/product/' + encodeURIComponent(productId) + '/movements?limit=200';
    fetch(urlDb, { headers: { 'X-WP-Nonce': data.nonce } })
      .then(function(r){ return r.json(); })
      .then(function(payload){
        if (payload && payload.rows && payload.rows.length) { render(payload); }
        else {
          var urlLogs = dbgBase + '/product/' + encodeURIComponent(productId) + '/movements/logs?limit=200';
          fetch(urlLogs, { headers: { 'X-WP-Nonce': data.nonce } })
            .then(function(r){ return r.json(); })
            .then(render)
            .catch(function(){ /* ignore */ });
        }
      })
      .catch(function(){ /* ignore */ });

    var persistBtn = document.getElementById('hp-pm-erp-persist');
    if (persistBtn) persistBtn.addEventListener('click', function(){
      if (!confirm('Persist movements from logs for this product?')) return;
      var url2 = dbgBase + '/product/' + encodeURIComponent(productId) + '/movements/persist-from-logs';
      fetch(url2, { method: 'POST', headers: { 'X-WP-Nonce': data.nonce } })
        .then(function(r){ return r.json(); })
        .then(function(res){ console.log('persist:', res); showNotice('Persisted ' + (res && res.written || 0) + ' rows', 'info'); })
        .catch(function(){ /* ignore */ });
    });

    var rebuildAllBtn = document.getElementById('hp-pm-erp-rebuild-all');
    var progressWrap = document.getElementById('hp-pm-erp-rebuild-progress');
    var progressFill = document.getElementById('hp-pm-erp-rebuild-progress-fill');
    var progressLabel = document.getElementById('hp-pm-erp-rebuild-progress-label');
    function setProg(done, total){
      var pct = total>0? Math.floor(done*100/total):0;
      if (progressFill) progressFill.style.width = pct + '%';
      if (progressLabel) progressLabel.textContent = (done + '/' + total + ' (' + pct + '%)');
    }
    if (rebuildAllBtn) rebuildAllBtn.addEventListener('click', function(){
      var base = dbgBase;
      rebuildAllBtn.disabled = true; if (progressWrap) progressWrap.style.display = '';
      fetch(base + '/movements/rebuild-all/start', { method: 'POST', headers: { 'X-WP-Nonce': data.nonce } })
        .then(function(r){ return r.json(); })
        .then(function(st){ setProg(st.processed||0, st.total||0); step(); })
        .catch(function(){ rebuildAllBtn.disabled = false; });
      function step(){
        fetch(base + '/movements/rebuild-all/step', { method: 'POST', headers: { 'X-WP-Nonce': data.nonce } })
          .then(function(r){ return r.json(); })
          .then(function(st){
            setProg(st.processed||0, st.total||0);
            if (st.status === 'done' || (st.processed>=st.total)) { rebuildAllBtn.disabled = false; loadFromDb(); return; }
            setTimeout(step, 200);
          })
          .catch(function(){ rebuildAllBtn.disabled = false; });
      }
      function loadFromDb(){
        var urlDb2 = dbgBase + '/product/' + encodeURIComponent(productId) + '/movements?limit=200';
        fetch(urlDb2, { headers: { 'X-WP-Nonce': data.nonce } })
          .then(function(r){ return r.json(); })
          .then(render);
      }
    });
  })();
});


