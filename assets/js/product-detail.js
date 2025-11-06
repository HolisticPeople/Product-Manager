document.addEventListener('DOMContentLoaded', function () {
  if (typeof HPProductDetailData === 'undefined') return;
  var data = HPProductDetailData;
  var original = JSON.parse(JSON.stringify(data.product));
  var productId = original.id;
  var storageKey = 'hp_pm_staged_' + productId;

  // Bind fields
  var nameEl = document.getElementById('hp-pm-pd-name');
  var skuEl = document.getElementById('hp-pm-pd-sku');
  var priceEl = document.getElementById('hp-pm-pd-price');
  var salePriceEl = document.getElementById('hp-pm-pd-sale-price');
  var statusEl = document.getElementById('hp-pm-pd-status');
  var visibilityEl = document.getElementById('hp-pm-pd-visibility');
  var brandsEl = document.getElementById('hp-pm-pd-brands');
  var catsEl = document.getElementById('hp-pm-pd-categories');
  var tagsEl = document.getElementById('hp-pm-pd-tags');
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
  setValue(statusEl, original.status);
  setValue(visibilityEl, original.visibility);
  setValue(salePriceEl, original.sale_price);
  setValue(costEl, original.cost);
  setValue(weightEl, original.weight);
  setValue(lengthEl, original.length);
  setValue(widthEl, original.width);
  setValue(heightEl, original.height);
  if (imgEl) imgEl.src = original.image || '';
  if (editLink) editLink.href = original.editLink || '#';
  if (viewLink) viewLink.href = original.viewLink || '#';

  // Fill brands options
  if (brandsEl && Array.isArray(data.brands)) {
    data.brands.forEach(function (t) {
      var opt = document.createElement('option');
      opt.value = t.slug; opt.textContent = t.name;
      if (Array.isArray(original.brands) && original.brands.indexOf(t.slug) !== -1) opt.selected = true;
      brandsEl.appendChild(opt);
    });
  }
  if (catsEl && Array.isArray(data.categories)) {
    data.categories.forEach(function (t) {
      var opt = document.createElement('option');
      opt.value = t.slug; opt.textContent = t.name;
      if (Array.isArray(original.categories) && original.categories.indexOf(t.slug) !== -1) opt.selected = true;
      catsEl.appendChild(opt);
    });
  }
  if (tagsEl && Array.isArray(data.tags)) {
    data.tags.forEach(function (t) {
      var opt = document.createElement('option');
      opt.value = t.slug; opt.textContent = t.name;
      if (Array.isArray(original.tags) && original.tags.indexOf(t.slug) !== -1) opt.selected = true;
      tagsEl.appendChild(opt);
    });
  }
  if (shipClassEl && Array.isArray(data.shippingClasses)) {
    data.shippingClasses.forEach(function (t) {
      var opt = document.createElement('option');
      opt.value = t.slug; opt.textContent = t.name;
      if (original.shipping_class === t.slug) opt.selected = true;
      shipClassEl.appendChild(opt);
    });
  }

  // Tokenized pickers for brands/categories/tags (optional UI instead of selects)
  function setupTokens(prefix, list) {
    var tokens = document.getElementById('hp-pm-pd-' + prefix + '-tokens');
    var input = document.getElementById('hp-pm-pd-' + prefix + '-input');
    var datalist = document.getElementById('hp-pm-' + (prefix === 'categories' ? 'cats' : prefix) + '-list');
    if (!tokens || !input || !datalist) return { get: function(){ return []; }, set: function(){} };

    // populate datalist
    datalist.innerHTML = '';
    (list || []).forEach(function (t) { var o = document.createElement('option'); o.value = t.slug; o.label = t.name; datalist.appendChild(o); });

    function render(arr) {
      tokens.innerHTML = '';
      (arr || []).forEach(function (slug) {
        var label = slug;
        var f = (list || []).find(function (x){ return x.slug === slug; });
        if (f) label = f.name;
        var span = document.createElement('span');
        span.className = 'hp-pm-token';
        span.dataset.slug = slug;
        span.innerHTML = '<span>' + label + '</span><span class="x" title="Remove">×</span>';
        tokens.appendChild(span);
      });
    }
    function get() { return Array.from(tokens.querySelectorAll('.hp-pm-token')).map(function(n){return n.dataset.slug;}); }

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

  var brandsTk = setupTokens('brands', data.brands || []);
  var catsTk = setupTokens('categories', data.categories || []);
  var tagsTk = setupTokens('tags', data.tags || []);
  if (brandsTk.set) brandsTk.set(original.brands || []);
  if (catsTk.set) catsTk.set(original.categories || []);
  if (tagsTk.set) tagsTk.set(original.tags || []);

  // Simple gallery rendering (IDs only); requires WP media for add
  var currentImageId = original.image_id || null;
  var currentGallery = (original.gallery_ids || []).slice();
  function renderGallery() {
    if (!galleryEl) return;
    galleryEl.innerHTML = '';
    currentGallery.forEach(function (id) {
      var meta = (original.gallery || []).find(function (g){ return g.id == id; });
      var url = meta ? meta.url : '';
      var d = document.createElement('div');
      d.className = 'hp-pm-thumb'; d.draggable = true; d.dataset.id = id;
      d.innerHTML = '<img src="' + url + '"><span class="hp-pm-thumb-remove">×</span>';
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
        renderGallery();
      } else if (e.target && e.target.classList.contains('hp-pm-thumb-add')) {
        if (typeof wp !== 'undefined' && wp.media) {
          var frame = wp.media({ multiple: true });
          frame.on('select', function(){
            var sel = frame.state().get('selection');
            sel.each(function(att){ var id = att.get('id'); if (currentGallery.indexOf(id) === -1) currentGallery.push(id); });
            renderGallery();
          });
          frame.open();
        }
      }
    });
    var dragId = null;
    galleryEl.addEventListener('dragstart', function (e){ var p = e.target.closest('.hp-pm-thumb'); if (!p) return; dragId = parseInt(p.dataset.id,10); });
    galleryEl.addEventListener('dragover', function (e){ e.preventDefault(); });
    galleryEl.addEventListener('drop', function (e){ e.preventDefault(); var t = e.target.closest('.hp-pm-thumb'); if (!t || !dragId) return; var tid = parseInt(t.dataset.id,10); if (isNaN(tid)) return; var arr = currentGallery.slice(); var from = arr.indexOf(dragId), to = arr.indexOf(tid); arr.splice(to,0, arr.splice(from,1)[0]); currentGallery = arr; renderGallery(); });
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

  function readStaged() {
    try { return JSON.parse(localStorage.getItem(storageKey) || '{}'); } catch (e) { return {}; }
  }
  function writeStaged(obj) {
    localStorage.setItem(storageKey, JSON.stringify(obj || {}));
    renderStaged();
  }

  function gatherChanges() {
    var c = {};
    if (nameEl && nameEl.value !== original.name) c.name = nameEl.value;
    if (skuEl && skuEl.value !== original.sku) c.sku = skuEl.value;
    if (priceEl && priceEl.value !== String(original.price || '')) c.price = priceEl.value;
    if (statusEl && statusEl.value !== original.status) c.status = statusEl.value;
    if (visibilityEl && visibilityEl.value !== original.visibility) c.visibility = visibilityEl.value;
    // prefer tokens if present
    var tBrands = document.getElementById('hp-pm-pd-brands-tokens');
    var tCats = document.getElementById('hp-pm-pd-categories-tokens');
    var tTags = document.getElementById('hp-pm-pd-tags-tokens');
    function tokensToArray(id){ var el=document.getElementById(id); return el? Array.from(el.querySelectorAll('.hp-pm-token')).map(function(n){return n.dataset.slug;}):[]; }
    if (tBrands) {
      var sel = tokensToArray('hp-pm-pd-brands-tokens');
      var orig = Array.isArray(original.brands) ? original.brands : [];
      if (JSON.stringify(sel.slice().sort()) !== JSON.stringify(orig.slice().sort())) c.brands = sel;
    } else if (brandsEl) {
      var selB = Array.from(brandsEl.selectedOptions).map(function (o) { return o.value; });
      var origB = Array.isArray(original.brands) ? original.brands : [];
      if (JSON.stringify(selB.slice().sort()) !== JSON.stringify(origB.slice().sort())) c.brands = selB;
    }
    if (tCats) {
      var csel = tokensToArray('hp-pm-pd-categories-tokens');
      var corig = Array.isArray(original.categories) ? original.categories : [];
      if (JSON.stringify(csel.slice().sort()) !== JSON.stringify(corig.slice().sort())) c.categories = csel;
    } else if (catsEl) {
      var csel2 = Array.from(catsEl.selectedOptions).map(function (o) { return o.value; });
      var corig2 = Array.isArray(original.categories) ? original.categories : [];
      if (JSON.stringify(csel2.slice().sort()) !== JSON.stringify(corig2.slice().sort())) c.categories = csel2;
    }
    if (tTags) {
      var tsel = tokensToArray('hp-pm-pd-tags-tokens');
      var torig = Array.isArray(original.tags) ? original.tags : [];
      if (JSON.stringify(tsel.slice().sort()) !== JSON.stringify(torig.slice().sort())) c.tags = tsel;
    } else if (tagsEl) {
      var tsel2 = Array.from(tagsEl.selectedOptions).map(function (o) { return o.value; });
      var torig2 = Array.isArray(original.tags) ? original.tags : [];
      if (JSON.stringify(tsel2.slice().sort()) !== JSON.stringify(torig2.slice().sort())) c.tags = tsel2;
    }
    if (costEl && costEl.value !== String(original.cost || '')) c.cost = costEl.value;
    if (weightEl && weightEl.value !== String(original.weight || '')) c.weight = weightEl.value;
    if (lengthEl && lengthEl.value !== String(original.length || '')) c.length = lengthEl.value;
    if (widthEl && widthEl.value !== String(original.width || '')) c.width = widthEl.value;
    if (heightEl && heightEl.value !== String(original.height || '')) c.height = heightEl.value;
    if (shipClassEl && shipClassEl.value !== String(original.shipping_class || '')) c.shipping_class = shipClassEl.value;
    return c;
  }

  function renderStaged() {
    var staged = readStaged();
    if (!stagedBody || !stagedTable) return;
    stagedBody.innerHTML = '';
    var keys = Object.keys(staged);
    stagedTable.style.display = keys.length ? '' : 'none';
    applyBtn.disabled = keys.length === 0;
    keys.forEach(function (k) {
      var tr = document.createElement('tr');
      var fromVal = original[k];
      var toVal = staged[k];
      if (Array.isArray(fromVal)) fromVal = fromVal.join(', ');
      if (Array.isArray(toVal)) toVal = toVal.join(', ');
      tr.innerHTML = '<td>' + k + '</td><td>' + (fromVal == null ? '' : fromVal) + '</td><td>' + (toVal == null ? '' : toVal) + '</td>' +
        '<td><button type="button" class="button-link" data-remove="' + k + '">Remove</button></td>';
      stagedBody.appendChild(tr);
    });
  }

  renderStaged();

  if (stageBtn) stageBtn.addEventListener('click', function () {
    var changes = gatherChanges();
    if (Object.keys(changes).length === 0) return;
    var staged = readStaged();
    Object.assign(staged, changes);
    writeStaged(staged);
  });

  function revertFieldToOriginal(key) {
    switch (key) {
      case 'name': setValue(nameEl, original.name); break;
      case 'sku': setValue(skuEl, original.sku); break;
      case 'price': setValue(priceEl, original.price); break;
      case 'status': setValue(statusEl, original.status); break;
      case 'visibility': setValue(visibilityEl, original.visibility); break;
      case 'brands': renderTokens('hp-pm-pd-brands-tokens', original.brands || []); break;
      case 'categories': renderTokens('hp-pm-pd-categories-tokens', original.categories || []); break;
      case 'tags': renderTokens('hp-pm-pd-tags-tokens', original.tags || []); break;
      case 'sale_price': setValue(salePriceEl, original.sale_price); break;
      case 'cost': setValue(costEl, original.cost); break;
      case 'weight': setValue(weightEl, original.weight); break;
      case 'length': setValue(lengthEl, original.length); break;
      case 'width': setValue(widthEl, original.width); break;
      case 'height': setValue(heightEl, original.height); break;
      case 'shipping_class': if (shipClassEl) setValue(shipClassEl, original.shipping_class || ''); break;
    }
  }

  if (stagedBody) stagedBody.addEventListener('click', function (e) {
    var rm = e.target && e.target.getAttribute('data-remove');
    if (!rm) return;
    var staged = readStaged();
    delete staged[rm];
    writeStaged(staged);
    revertFieldToOriginal(rm);
  });

  if (discardBtn) discardBtn.addEventListener('click', function () {
    writeStaged({});
  });

  if (applyBtn) applyBtn.addEventListener('click', function () {
    var staged = readStaged();
    if (Object.keys(staged).length === 0) { alert(data.i18n.nothingToApply); return; }
    fetch(data.restBase + '/apply', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
      body: JSON.stringify({ changes: staged })
    })
    .then(function (r) { if (!r.ok) throw new Error('Network'); return r.json(); })
    .then(function (payload) {
      if (payload && payload.id) {
        original = payload; // fresh snapshot
        // Reset form values to applied snapshot
        setValue(nameEl, original.name);
        setValue(skuEl, original.sku);
        setValue(priceEl, original.price);
        setValue(statusEl, original.status);
        setValue(visibilityEl, original.visibility);
        renderTokens('hp-pm-pd-brands-tokens', original.brands || []);
        renderTokens('hp-pm-pd-categories-tokens', original.categories || []);
        renderTokens('hp-pm-pd-tags-tokens', original.tags || []);
        setValue(salePriceEl, original.sale_price);
        setValue(costEl, original.cost);
        setValue(weightEl, original.weight);
        setValue(lengthEl, original.length);
        setValue(widthEl, original.width);
        setValue(heightEl, original.height);
        setValue(shipClassEl, original.shipping_class || '');
      }
      writeStaged({});
      alert(data.i18n.applied);
    })
    .catch(function () { alert('Failed to apply changes'); });
  });
});


