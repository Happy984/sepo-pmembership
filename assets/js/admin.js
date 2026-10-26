/* Sepo Membership — dashboard helpers: show-hide panels, repeatable rows, confirmations, resource kind. No dependencies. */
(function () {
  'use strict';
  var T = window.SepoMembershipAdmin || {};

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-smem-toggle]');
    if (t) {
      e.preventDefault();
      var box = document.getElementById(t.getAttribute('data-smem-toggle'));
      if (box) {
        box.hidden = !box.hidden;
        if (!box.hidden) { var f = box.querySelector('input:not([type="hidden"]), select'); if (f) { f.focus(); } }
      }
      return;
    }
    var c = e.target.closest('[data-smem-confirm]');
    if (c && !window.confirm(T.confirmDelete)) { e.preventDefault(); return; }
    var add = e.target.closest('[data-smem-add-row]');
    if (add) {
      var wrap = add.closest('[data-smem-repeat]');
      var tpl = wrap && wrap.querySelector('[data-smem-template]');
      if (!tpl) { return; }
      var row = tpl.cloneNode(true);
      var key = 'n' + Date.now();
      row.removeAttribute('data-smem-template');
      row.hidden = false;
      Array.prototype.forEach.call(row.querySelectorAll('[name]'), function (el) {
        el.name = el.name.replace('__i__', key);
        el.disabled = false;
      });
      tpl.parentNode.insertBefore(row, tpl);
      var first = row.querySelector('input');
      if (first) { first.focus(); }
      return;
    }
    var rm = e.target.closest('[data-smem-remove-row]');
    if (rm) {
      var r = rm.closest('.smem-methodrow');
      if (r) { r.remove(); }
    }
  });

  // Resource file: a private upload, or a file chosen from the media library.
  function showSource() {
    var on = document.querySelector('[data-smem-source]:checked');
    var v = on ? on.value : 'private';
    Array.prototype.forEach.call(document.querySelectorAll('[data-smem-src]'), function (d) { d.hidden = d.getAttribute('data-smem-src') !== v; });
  }
  Array.prototype.forEach.call(document.querySelectorAll('[data-smem-source]'), function (r) { r.addEventListener('change', showSource); });
  var mediaBox = document.querySelector('[data-smem-media]');
  if (mediaBox) {
    var frame = null;
    var idField = mediaBox.querySelector('[data-smem-media-id]');
    var nameEl = mediaBox.querySelector('[data-smem-media-name]');
    var clearBtn = mediaBox.querySelector('[data-smem-media-clear]');
    var esc = function (t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; };
    mediaBox.querySelector('[data-smem-media-pick]').addEventListener('click', function (e) {
      e.preventDefault();
      if (!window.wp || !window.wp.media) { return; }
      if (!frame) {
        frame = window.wp.media({ title: T.pickTitle, button: { text: T.useFile }, multiple: false });
        frame.on('select', function () {
          var a = frame.state().get('selection').first().toJSON();
          idField.value = a.id;
          nameEl.innerHTML = '<strong>' + esc(a.filename || a.title || '') + '</strong>' + (a.filesizeHumanReadable ? ' · ' + esc(a.filesizeHumanReadable) : '');
          clearBtn.hidden = false;
        });
      }
      frame.open();
    });
    clearBtn.addEventListener('click', function (e) {
      e.preventDefault();
      idField.value = 0;
      nameEl.innerHTML = '<span class="smem-muted">' + esc(T.noFile) + '</span>';
      clearBtn.hidden = true;
    });
  }

  var rmPrivate = document.querySelector('[data-smem-remove-private]');
  if (rmPrivate) {
    rmPrivate.addEventListener('click', function (e) {
      e.preventDefault();
      if (!window.confirm(T.confirmRemoveFile)) { return; }
      rmPrivate.disabled = true;
      var body = new FormData();
      body.append('action', 'smem_remove_resource_file');
      body.append('post', rmPrivate.getAttribute('data-smem-remove-private'));
      body.append('nonce', rmPrivate.getAttribute('data-nonce'));
      fetch(window.ajaxurl || '/wp-admin/admin-ajax.php', { method: 'POST', credentials: 'same-origin', body: body })
        .then(function (r) { return r.json(); })
        .then(function (r) {
          if (!r || !r.success) { throw new Error((r && r.data && r.data.message) || T.removeFailed); }
          var line = document.querySelector('[data-smem-private-file]');
          if (line) { line.remove(); }
          var done = document.querySelector('[data-smem-removed]');
          if (done) { done.hidden = false; }
        })
        .catch(function (err) { rmPrivate.disabled = false; window.alert(err.message || T.removeFailed); });
    });
  }

  var kinds = document.querySelectorAll('[data-smem-kind]');
  function showKind() {
    var on = document.querySelector('[data-smem-kind]:checked');
    var v = on ? on.value : 'download';
    Array.prototype.forEach.call(document.querySelectorAll('[data-smem-for]'), function (row) { row.hidden = row.getAttribute('data-smem-for') !== v; });
  }
  Array.prototype.forEach.call(kinds, function (k) { k.addEventListener('change', showKind); });
  if (kinds.length) { showKind(); }
})();
