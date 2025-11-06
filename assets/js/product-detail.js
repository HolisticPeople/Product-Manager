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
  var statusEl = document.getElementById('hp-pm-pd-status');
  var visibilityEl = document.getElementById('hp-pm-pd-visibility');
  var brandsEl = document.getElementById('hp-pm-pd-brands');

  function setValue(el, v) { if (el) el.value = (v == null ? '' : v); }

  setValue(nameEl, original.name);
  setValue(skuEl, original.sku);
  setValue(priceEl, original.price);
  setValue(statusEl, original.status);
  setValue(visibilityEl, original.visibility);

  // Fill brands options
  if (brandsEl && Array.isArray(data.brands)) {
    data.brands.forEach(function (t) {
      var opt = document.createElement('option');
      opt.value = t.slug; opt.textContent = t.name;
      if (Array.isArray(original.brands) && original.brands.indexOf(t.slug) !== -1) opt.selected = true;
      brandsEl.appendChild(opt);
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
    if (brandsEl) {
      var sel = Array.from(brandsEl.selectedOptions).map(function (o) { return o.value; });
      var orig = Array.isArray(original.brands) ? original.brands : [];
      if (JSON.stringify(sel.slice().sort()) !== JSON.stringify(orig.slice().sort())) c.brands = sel;
    }
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

  if (stagedBody) stagedBody.addEventListener('click', function (e) {
    var rm = e.target && e.target.getAttribute('data-remove');
    if (!rm) return;
    var staged = readStaged();
    delete staged[rm];
    writeStaged(staged);
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
        if (brandsEl) {
          Array.from(brandsEl.options).forEach(function (o) { o.selected = (original.brands || []).indexOf(o.value) !== -1; });
        }
      }
      writeStaged({});
      alert(data.i18n.applied);
    })
    .catch(function () { alert('Failed to apply changes'); });
  });
});


