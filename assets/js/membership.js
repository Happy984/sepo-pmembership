/*
 * Sepo Membership — plans and joining, my membership, giving, and the members
 * area with its discussions and suggestions. No dependencies.
 */
(function () {
  'use strict';

  var cfg = window.SepoMembershipConfig || {};
  var T = cfg.i18n || {};
  var ICON = cfg.icons || {};

  function qa(root, sel) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function fmt(s) {
    var args = Array.prototype.slice.call(arguments, 1);
    var i = 0;
    return String(s || '').replace(/%(\d\$)?[sd]/g, function (m, pos) {
      if (pos) { return String(args[parseInt(pos, 10) - 1]); }
      return String(args[i++]);
    });
  }
  function icon(name) { return ICON[name] || ''; }
  function money(n) {
    var v = Number(n) || 0;
    var whole = Math.abs(v - Math.round(v)) < 0.005;
    var txt;
    try { txt = v.toLocaleString(cfg.locale || undefined, { minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: whole ? 0 : 2 }); } catch (e) { txt = String(v); }
    return (cfg.currency || '') + ' ' + txt;
  }
  function scopeOf(el) { return (el && el.closest && el.closest('.smem-scope')) || document.querySelector('.smem-scope'); }

  // ------------------------------------------------------------ dark mode

  /** A declared theme marker wins; otherwise the page background decides. */
  function isDark() {
    var nodes = [document.documentElement, document.body];
    var attrs = ['data-theme', 'data-bs-theme', 'data-color-scheme', 'data-mode', 'data-color-mode'];
    for (var i = 0; i < nodes.length; i++) {
      var n = nodes[i];
      if (!n) { continue; }
      for (var j = 0; j < attrs.length; j++) {
        var v = (n.getAttribute(attrs[j]) || '').toLowerCase();
        if (v === 'dark') { return true; }
        if (v === 'light') { return false; }
      }
      if (/(^|\s)(dark|dark-mode|theme-dark|is-dark-theme|wp-dark-mode-active)(\s|$)/.test(n.className || '')) { return true; }
    }
    var bg = '';
    nodes.forEach(function (n) {
      if (!n) { return; }
      var c = getComputedStyle(n).backgroundColor;
      if (c && !/rgba\(\s*0,\s*0,\s*0,\s*0\s*\)|transparent/.test(c)) { bg = c; }
    });
    var m = bg.match(/rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/);
    return !!m && (0.2126 * m[1] + 0.7152 * m[2] + 0.0722 * m[3]) / 255 < 0.4;
  }
  /** The colour actually painted behind an element: the nearest ancestor with a solid background. */
  function backdrop(el) {
    for (var n = el.parentElement; n; n = n.parentElement) {
      var c = getComputedStyle(n).backgroundColor;
      var m = c && c.match(/rgba?\(([^)]+)\)/);
      if (!m) { continue; }
      var parts = m[1].split(/[,\s/]+/).filter(Boolean);
      if (parts.length < 4 || parseFloat(parts[3]) >= 0.98) { return c; }
    }
    return '';
  }
  function paintMode() {
    var dark = isDark();
    qa(document, '.smem-scope').forEach(function (s) {
      s.classList.toggle('smem-dark', dark);
      var bg = dark ? backdrop(s) : '';
      if (bg) { s.style.setProperty('--smem-page-bg', bg); } else { s.style.removeProperty('--smem-page-bg'); }
    });
  }

  // ------------------------------------------------------------ requests

  /** body: a plain object (sent as JSON) or a FormData (sent as is, for files). */
  function request(method, path, body) {
    var headers = { 'Accept': 'application/json' };
    var payload;
    if (body instanceof FormData) { payload = body; } else if (body) { headers['Content-Type'] = 'application/json'; payload = JSON.stringify(body); }
    if (cfg.nonce) { headers['X-WP-Nonce'] = cfg.nonce; }
    return fetch(String(cfg.api || '').replace(/\/$/, '') + path, {
      method: method, credentials: 'same-origin', headers: headers, body: payload
    }).then(function (res) {
      return res.text().then(function (text) {
        var data = null;
        try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
        if (!data) { throw new Error(T.network); }
        if (!res.ok || data.code) {
          var err = new Error(data.message || T.network);
          err.status = res.status;
          throw err;
        }
        return data;
      });
    }, function () { throw new Error(T.network); });
  }
  function post(path, body) { return request('POST', path, body || {}); }
  function get(path) { return request('GET', path); }
  function qs(o) {
    return Object.keys(o).filter(function (k) { return o[k] !== '' && o[k] != null; }).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(o[k]); }).join('&');
  }

  // ------------------------------------------------------------ toast and dialogs

  var toastTimer = 0;
  function toast(root, msg, kind) {
    var el = root && root.querySelector('[data-smem-toast]');
    if (!el) { return; }
    el.textContent = msg;
    el.className = 'smem-toast' + (kind ? ' smem-toast--' + kind : '');
    el.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.hidden = true; }, 4500);
  }

  /**
   * The page's own dialog. o: title, html or node (body), buttons [{label,
   * kind, value, onClick(btn, close), submit}]. Resolves with the pressed
   * button's value, or null when dismissed. o.onOpen(panel, close) runs once shown.
   */
  function dialog(root, o) {
    var modal = root.querySelector('[data-smem-modal]');
    if (!modal) { return Promise.resolve(null); }
    var panel = modal.querySelector('.smem-modal__panel');
    var body = modal.querySelector('[data-smem-modal-body]');
    var foot = modal.querySelector('[data-smem-modal-foot]');
    var title = modal.querySelector('[data-smem-modal-title]');
    var titleId = 'smem-dlg-' + Date.now();
    title.id = titleId;
    title.textContent = o.title || '';
    panel.setAttribute('aria-labelledby', titleId);
    body.innerHTML = '';
    if (o.node) { body.appendChild(o.node); } else { body.innerHTML = o.html || ''; }
    foot.innerHTML = (o.buttons || []).map(function (b, i) {
      return '<button type="button" class="smem-btn' + (b.kind ? ' smem-btn--' + b.kind : '') + '" data-smem-dlg="' + i + '">' + esc(b.label) + '</button>';
    }).join('');
    var before = document.activeElement;
    modal.hidden = false;
    document.documentElement.classList.add('smem-modal-open');

    return new Promise(function (resolve) {
      var done = false;
      function close(v) {
        if (done) { return; }
        done = true;
        modal.hidden = true;
        document.documentElement.classList.remove('smem-modal-open');
        document.removeEventListener('keydown', onKey, true);
        modal.removeEventListener('click', onClick);
        body.innerHTML = '';
        if (before && before.focus && document.contains(before)) { before.focus(); }
        resolve(v === undefined ? null : v);
      }
      function onClick(e) {
        if (e.target === modal || e.target.closest('[data-smem-modal-close]')) { close(null); return; }
        var b = e.target.closest('[data-smem-dlg]');
        if (b) {
          var spec = o.buttons[parseInt(b.getAttribute('data-smem-dlg'), 10)];
          if (spec && typeof spec.onClick === 'function') { spec.onClick(b, close); return; }
          close(spec ? spec.value : null);
        }
      }
      function onKey(e) {
        if (e.key === 'Escape') { e.stopPropagation(); close(null); return; }
        if (e.key === 'Tab') {
          var f = qa(panel, 'button:not([disabled]), [href], input:not([type="hidden"]):not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])').filter(function (n) { return n.offsetParent !== null || n.type === 'radio'; });
          if (!f.length) { return; }
          var first = f[0];
          var last = f[f.length - 1];
          if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
          else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
      }
      modal.addEventListener('click', onClick);
      document.addEventListener('keydown', onKey, true);
      var focusEl = panel.querySelector('[autofocus]') || panel.querySelector('input[type="text"]:not([type="hidden"]), textarea') || foot.querySelector('.smem-btn--primary') || panel;
      setTimeout(function () { focusEl.focus(); }, 20);
      if (typeof o.onOpen === 'function') { o.onOpen(panel, close); }
    });
  }

  function confirmDialog(root, o) {
    return dialog(root, {
      title: o.title,
      html: '<p class="smem-modal__text">' + esc(o.body || '') + '</p>',
      buttons: [{ label: o.no || T.cancel, value: false, kind: 'quiet' }, { label: o.yes, kind: o.danger ? 'danger' : 'primary', value: true }]
    }).then(function (v) { return v === true; });
  }

  function busy(btn, on, label) {
    if (!btn) { return; }
    if (on) {
      btn.setAttribute('data-label', btn.innerHTML);
      btn.disabled = true;
      btn.innerHTML = '<span class="smem-spin" aria-hidden="true"></span>' + esc(label || T.sending);
    } else {
      btn.disabled = false;
      if (btn.hasAttribute('data-label')) { btn.innerHTML = btn.getAttribute('data-label'); btn.removeAttribute('data-label'); }
    }
  }

  function fieldError(field, msg) {
    if (!field) { return; }
    field.classList.add('is-error');
    var e = field.querySelector('.smem-field__err');
    if (!e) { e = document.createElement('span'); e.className = 'smem-field__err'; e.setAttribute('role', 'alert'); field.appendChild(e); }
    e.textContent = msg;
  }
  function clearErrors(form) {
    qa(form, '.is-error').forEach(function (n) { n.classList.remove('is-error'); });
    qa(form, '.smem-field__err').forEach(function (n) { n.remove(); });
    var fe = form.querySelector('[data-form-error]');
    if (fe) { fe.hidden = true; fe.textContent = ''; }
  }
  function formError(form, msg) {
    var fe = form.querySelector('[data-form-error]');
    if (!fe) {
      fe = document.createElement('div');
      fe.className = 'smem-form__error';
      fe.setAttribute('data-form-error', '');
      fe.setAttribute('role', 'alert');
      form.insertBefore(fe, form.firstChild);
    }
    fe.textContent = msg;
    fe.hidden = false;
    fe.scrollIntoView({ block: 'nearest' });
  }

  // ------------------------------------------------------------ payment fields

  /** Method choice, details, payment link, copy button and proof files inside a form. */
  function initPayFields(form, amount, reference) {
    var radios = qa(form, 'input[name="method"]');
    function current() { var r = radios.filter(function (x) { return x.checked; })[0]; return r || null; }
    function show() {
      var c = current();
      qa(form, '[data-method-detail]').forEach(function (d) { d.hidden = !c || d.getAttribute('data-method-detail') !== c.value; });
      var proof = form.querySelector('[data-proof-field]');
      if (proof) { proof.hidden = !!c && c.getAttribute('data-type') === 'link' && cfg.proof !== 'required'; }
      updateLinks();
    }
    function updateLinks() {
      var a = typeof amount === 'function' ? amount() : amount;
      var ref = form.querySelector('[name="reference"]');
      qa(form, '[data-pay-link]').forEach(function (l) {
        var url = String(l.getAttribute('data-pay-link') || '');
        url = url.replace(/\{amount\}/g, encodeURIComponent(String(a || ''))).replace(/\{currency\}/g, encodeURIComponent(cfg.currency || ''))
          .replace(/\{reference\}/g, encodeURIComponent((ref && ref.value) || reference || ''));
        if (/^https?:\/\//i.test(url)) { l.href = url; l.removeAttribute('aria-disabled'); } else { l.removeAttribute('href'); l.setAttribute('aria-disabled', 'true'); }
      });
    }
    radios.forEach(function (r) { r.addEventListener('change', show); });
    qa(form, '[data-smem-copy]').forEach(function (b) {
      b.addEventListener('click', function () {
        var txt = b.parentNode.querySelector('.smem-paydetail__text');
        if (!txt || !navigator.clipboard) { return; }
        navigator.clipboard.writeText(txt.textContent).then(function () {
          var was = b.innerHTML;
          b.innerHTML = icon('check') + esc(T.copied);
          setTimeout(function () { b.innerHTML = was; }, 1600);
        });
      });
    });
    var file = form.querySelector('input[type="file"][name="proof[]"]');
    if (file) {
      file.addEventListener('change', function () {
        var drop = form.querySelector('label.smem-drop[for="' + file.id + '"]');
        var lab = drop && drop.querySelector('[data-drop-label]');
        if (!drop || !lab) { return; }
        if (!lab.hasAttribute('data-empty')) { lab.setAttribute('data-empty', lab.textContent); }
        var n = file.files ? file.files.length : 0;
        lab.textContent = n === 0 ? lab.getAttribute('data-empty') : (n === 1 ? file.files[0].name : fmt(T.filesChosen, n));
        drop.classList.toggle('is-filled', n > 0);
        var f = file.closest('.smem-field');
        if (f) { f.classList.remove('is-error'); var e = f.querySelector('.smem-field__err'); if (e) { e.remove(); } }
      });
    }
    var ref = form.querySelector('[name="reference"]');
    if (ref) { ref.addEventListener('input', updateLinks); }
    show();
    return { update: updateLinks, method: current };
  }

  /** Client checks before sending: proof when it is required, and file sizes. */
  function checkProof(form) {
    var file = form.querySelector('input[type="file"][name="proof[]"]');
    if (!file) { return true; }
    var field = file.closest('.smem-field');
    var m = form.querySelector('input[name="method"]:checked');
    var n = file.files ? file.files.length : 0;
    if (cfg.proof === 'required' && m && m.getAttribute('data-type') === 'manual' && n === 0) {
      fieldError(field, T.needProof);
      return false;
    }
    var max = (cfg.proofMax || 5) * 1048576;
    for (var i = 0; i < n; i++) {
      if (file.files[i].size > max) { fieldError(field, fmt(T.tooBig, file.files[i].name, cfg.proofMax || 5)); return false; }
    }
    return true;
  }

  function thanks(title, text, extra) {
    return '<div class="smem-thanks">' + icon('check') + '<h3>' + esc(title) + '</h3><p>' + esc(text) + '</p>' + (extra || '') + '</div>';
  }

  // ------------------------------------------------------------ plans and joining

  function initPlans(root) {
    var opts = qa(root, '.smem-periods__opt');
    function setPeriod(p, focus) {
      root.setAttribute('data-period', p);
      opts.forEach(function (o) {
        var on = o.getAttribute('data-period') === p;
        o.setAttribute('aria-checked', on ? 'true' : 'false');
        o.tabIndex = on ? 0 : -1;
        if (on && focus) { o.focus(); }
      });
      qa(root, '.smem-plan').forEach(function (card) {
        var prices = {};
        try { prices = JSON.parse(card.getAttribute('data-prices') || '{}'); } catch (e) { prices = {}; }
        var pr = prices[p];
        card.querySelector('[data-amount]').textContent = pr ? pr.money : '—';
        card.querySelector('[data-per]').textContent = pr ? pr.per : '';
        card.querySelector('[data-na]').hidden = !!pr;
        var btn = card.querySelector('[data-join]');
        if (btn) { btn.disabled = !pr; }
      });
    }
    opts.forEach(function (o, i) {
      o.tabIndex = o.getAttribute('aria-checked') === 'true' ? 0 : -1;
      o.addEventListener('click', function () { setPeriod(o.getAttribute('data-period')); });
      o.addEventListener('keydown', function (e) {
        var d = e.key === 'ArrowRight' || e.key === 'ArrowDown' ? 1 : (e.key === 'ArrowLeft' || e.key === 'ArrowUp' ? -1 : 0);
        if (!d) { return; }
        e.preventDefault();
        var n = opts[(i + d + opts.length) % opts.length];
        setPeriod(n.getAttribute('data-period'), true);
      });
    });
    qa(root, '[data-join-guest]').forEach(function (a) {
      a.addEventListener('click', function () {
        var p = root.getAttribute('data-period');
        try {
          var u = new URL(a.href);
          var back = new URL(u.searchParams.get('redirect_to') || location.href);
          back.searchParams.set('smem_period', p);
          u.searchParams.set('redirect_to', back.toString());
          a.href = u.toString();
        } catch (e) { /* keep the link as written */ }
      });
    });

    // Back from signing in with a plan chosen: open it.
    try {
      var u = new URL(location.href);
      var want = u.searchParams.get('smem_join');
      if (want && cfg.loggedIn) {
        u.searchParams.delete('smem_join');
        history.replaceState(null, '', u.toString());
        var b = root.querySelector('[data-join="' + want.replace(/[^a-z0-9_-]/gi, '') + '"]:not([disabled])');
        if (b) { setTimeout(function () { b.click(); }, 120); }
      }
    } catch (e) { /* older browser */ }
  }

  /** A plan button anywhere in a scope: [data-join] with data-period/data-renew. */
  function startJoin(btn) {
    var root = scopeOf(btn);
    var level = btn.getAttribute('data-join');
    var period = btn.getAttribute('data-period') || root.getAttribute('data-period') || 'monthly';
    var renew = btn.getAttribute('data-renew') === '1';
    var card = btn.closest('.smem-plan');
    var label = btn.getAttribute('data-label') || (card && card.getAttribute('data-label')) || level;

    function go() {
      busy(btn, true, T.loading);
      return post('/join', { level: level, period: period, renew: renew ? 1 : 0 }).then(function (r) {
        busy(btn, false);
        if (r.effect !== 'payment') {
          toast(root, r.message, 'good');
          setTimeout(function () { location.reload(); }, 900);
          return;
        }
        payDialog(root, { level: level, period: period, renew: renew, label: label, amount: r.amount, money: r.money, upgrade: btn.getAttribute('data-upgrade') === '1' });
      }, function (e) { busy(btn, false); toast(root, e.message, 'bad'); });
    }
    if (btn.getAttribute('data-downgrade') === '1') {
      confirmDialog(root, { title: fmt(T.switchTitle, label), body: fmt(T.switchText, label), yes: T.confirmSwitch }).then(function (ok) { if (ok) { go(); } });
      return;
    }
    go();
  }

  function payDialog(root, o) {
    var tpl = root.querySelector('template[data-smem-payform]');
    if (!tpl) { return; }
    var node = document.importNode(tpl.content, true);
    var form = node.querySelector('form');
    form.querySelector('[name="level"]').value = o.level;
    form.querySelector('[name="period"]').value = o.period;
    form.querySelector('[name="renew"]').value = o.renew ? '1' : '0';
    var periodText = '';
    var card = root.querySelector('.smem-plan[data-plan="' + o.level + '"]');
    if (card) {
      try { var pr = JSON.parse(card.getAttribute('data-prices') || '{}')[o.period]; periodText = pr && pr.per ? pr.per : ''; } catch (e) { periodText = ''; }
    }
    form.querySelector('[data-pay-summary]').innerHTML = '<span class="smem-summary__what">' + esc(T.youPay) + '<br><strong>' + esc(o.label) + '</strong> ' + esc(periodText) + '</span>'
      + '<span class="smem-summary__amount">' + esc(o.money) + '</span>';
    var titleFmt = o.renew ? T.renewTitle : (o.upgrade ? T.upgradeTitle : T.joinTitle);

    dialog(root, {
      title: fmt(titleFmt, o.label),
      node: node,
      buttons: [
        { label: T.cancel, kind: 'quiet', value: null },
        { label: T.sendPayment, kind: 'primary', onClick: function (btn, close) { send(btn, close); } }
      ],
      onOpen: function (panel) {
        var f = panel.querySelector('form');
        var pf = initPayFields(f, o.amount);
        function relabel() {
          var m = pf.method();
          var b = panel.querySelector('.smem-modal__foot .smem-btn--primary');
          if (b && !b.disabled) { b.textContent = m && m.getAttribute('data-type') === 'link' ? T.paid : T.sendPayment; }
        }
        qa(f, 'input[name="method"]').forEach(function (r) { r.addEventListener('change', relabel); });
        relabel();
        f.addEventListener('submit', function (e) { e.preventDefault(); });
      }
    });

    function send(btn, close) {
      var f = root.querySelector('[data-smem-modal] form');
      if (!f) { return; }
      clearErrors(f);
      if (!checkProof(f)) { return; }
      var fd = new FormData(f);
      busy(btn, true);
      post('/join', fd).then(function (r) {
        var body = root.querySelector('[data-smem-modal-body]');
        var foot = root.querySelector('[data-smem-modal-foot]');
        body.innerHTML = thanks(T.thanks, r.message, cfg.account ? '<a class="smem-btn smem-btn--primary" href="' + esc(cfg.account) + '">' + esc(T.close) + '</a>' : '');
        foot.innerHTML = '';
        var dlg = root.querySelector('[data-smem-modal]');
        dlg.addEventListener('click', function reload(e) {
          if (e.target === dlg || e.target.closest('[data-smem-modal-close]')) { dlg.removeEventListener('click', reload); location.reload(); }
        });
        var cta = body.querySelector('.smem-btn');
        if (cta) { cta.focus(); }
      }, function (e) { busy(btn, false); formError(f, e.message); });
    }
  }

  // ------------------------------------------------------------ my membership

  function initAccount(root) {
    var cancel = root.querySelector('[data-smem-cancel]');
    if (cancel) {
      cancel.addEventListener('click', function () {
        confirmDialog(root, { title: T.cancelTitle, body: fmt(T.cancelText, cancel.getAttribute('data-until') || ''), yes: T.cancelYes, no: T.keep, danger: true }).then(function (ok) {
          if (!ok) { return; }
          busy(cancel, true, T.saving);
          post('/cancel').then(function (r) { toast(root, r.message, 'good'); setTimeout(function () { location.reload(); }, 1000); }, function (e) { busy(cancel, false); toast(root, e.message, 'bad'); });
        });
      });
    }
    var resume = root.querySelector('[data-smem-resume]');
    if (resume) {
      resume.addEventListener('click', function () {
        busy(resume, true, T.saving);
        post('/resume').then(function (r) { toast(root, r.message, 'good'); setTimeout(function () { location.reload(); }, 900); }, function (e) { busy(resume, false); toast(root, e.message, 'bad'); });
      });
    }
    qa(root, '[data-smem-withdraw]').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = 'smem-why-' + Date.now();
        dialog(root, {
          title: T.withdrawTitle,
          html: '<form class="smem-withdrawform" novalidate><p class="smem-modal__text">' + esc(T.withdrawText) + '</p>'
            + '<div class="smem-field"><label class="smem-label" for="' + id + '">' + esc(T.withdrawWhy) + '</label>'
            + '<textarea class="smem-input smem-textarea" id="' + id + '" name="reason" rows="4" maxlength="1000" autofocus></textarea>'
            + '<span class="smem-help">' + esc(T.withdrawHelp) + '</span></div></form>',
          buttons: [{ label: T.cancel, kind: 'quiet', value: null }, {
            label: T.withdrawSend, kind: 'danger', onClick: function (btn, close) {
              var f = root.querySelector('[data-smem-modal] form');
              var ta = f.querySelector('textarea');
              clearErrors(f);
              if (ta.value.trim().length < 5) { fieldError(ta.closest('.smem-field'), T.withdrawNeed); ta.focus(); return; }
              busy(btn, true);
              post('/payments/' + b.getAttribute('data-smem-withdraw') + '/withdraw', { reason: ta.value }).then(function (r) {
                close(true);
                toast(root, r.message, 'good');
                setTimeout(function () { location.reload(); }, 1200);
              }, function (e) { busy(btn, false); formError(f, e.message); });
            }
          }],
          onOpen: function (panel) { panel.querySelector('form').addEventListener('submit', function (e) { e.preventDefault(); }); }
        });
      });
    });
    qa(root, '[data-smem-keep]').forEach(function (b) {
      b.addEventListener('click', function () {
        busy(b, true, T.saving);
        post('/payments/' + b.getAttribute('data-smem-keep') + '/keep').then(function (r) { toast(root, r.message, 'good'); setTimeout(function () { location.reload(); }, 900); }, function (e) { busy(b, false); toast(root, e.message, 'bad'); });
      });
    });
    qa(root, '[data-smem-proof]').forEach(function (input) {
      input.setAttribute('accept', 'image/*,.pdf,.doc,.docx,.odt');
      input.addEventListener('change', function () {
        if (!input.files || !input.files.length) { return; }
        var max = (cfg.proofMax || 5) * 1048576;
        for (var i = 0; i < input.files.length; i++) {
          if (input.files[i].size > max) { toast(root, fmt(T.tooBig, input.files[i].name, cfg.proofMax || 5), 'bad'); input.value = ''; return; }
        }
        var fd = new FormData();
        for (var j = 0; j < input.files.length; j++) { fd.append('proof[]', input.files[j]); }
        var label = root.querySelector('label[for="' + input.id + '"]');
        if (label) { label.setAttribute('aria-busy', 'true'); }
        post('/payments/' + input.getAttribute('data-smem-proof') + '/proof', fd).then(function (r) {
          toast(root, r.message, 'good');
          setTimeout(function () { location.reload(); }, 900);
        }, function (e) { toast(root, e.message, 'bad'); input.value = ''; if (label) { label.removeAttribute('aria-busy'); } });
      });
    });
    qa(root, '[data-smem-pref]').forEach(function (sw) {
      sw.addEventListener('change', function () {
        var body = {};
        body[sw.name] = sw.checked ? 1 : 0;
        sw.disabled = true;
        post('/' + sw.getAttribute('data-smem-pref'), body).then(function (r) { sw.disabled = false; toast(root, r.message, 'good'); }, function (e) { sw.disabled = false; sw.checked = !sw.checked; toast(root, e.message, 'bad'); });
      });
    });
  }

  // ------------------------------------------------------------ giving

  function initGive(form) {
    var amountField = form.querySelector('[name="amount"]');
    var other = form.querySelector('[data-other]');
    var otherInput = form.querySelector('[data-other-input]');
    var label = form.querySelector('[data-give-label]');
    var monthlyHelp = form.querySelector('[data-monthly-help]');
    function amount() {
      var p = form.querySelector('input[name="preset"]:checked');
      if (!p) { return 0; }
      return p.value === 'other' ? (parseFloat(otherInput.value) || 0) : (parseFloat(p.value) || 0);
    }
    var pf = initPayFields(form, amount);
    function refresh() {
      var p = form.querySelector('input[name="preset"]:checked');
      other.hidden = !(p && p.value === 'other');
      var a = amount();
      amountField.value = a > 0 ? String(a) : '';
      var monthly = (form.querySelector('input[name="recurring"]:checked') || {}).value === '1';
      if (monthlyHelp) { monthlyHelp.hidden = !monthly; }
      label.textContent = a > 0 ? fmt(monthly ? T.giveMonthly : T.give, money(a)) : fmt(T.give, '').trim();
      pf.update();
    }
    qa(form, 'input[name="preset"], input[name="recurring"]').forEach(function (r) {
      r.addEventListener('change', function () {
        refresh();
        if (r.name === 'preset' && r.value === 'other') { otherInput.focus(); }
      });
    });
    otherInput.addEventListener('input', refresh);
    refresh();

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      clearErrors(form);
      var a = amount();
      if (!(a > 0)) { fieldError(other.hidden ? form.querySelector('.smem-amounts') : other, T.enterAmount); return; }
      var name = form.querySelector('[name="name"]');
      var email = form.querySelector('[name="email"]');
      if (name && !name.value.trim()) { fieldError(name.closest('.smem-field'), T.enterName); name.focus(); return; }
      if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) { fieldError(email.closest('.smem-field'), T.enterName); email.focus(); return; }
      if (!checkProof(form)) { return; }
      var btn = form.querySelector('[data-give-submit]');
      var fd = new FormData(form);
      fd.delete('preset');
      busy(btn, true);
      post('/donate', fd).then(function (r) {
        var box = document.createElement('div');
        box.className = 'smem-card';
        box.innerHTML = thanks(T.thanks, r.message, '<button type="button" class="smem-btn smem-btn--outline" data-give-again>' + esc(T.giveAgain) + '</button>');
        form.hidden = true;
        form.parentNode.insertBefore(box, form.nextSibling);
        box.querySelector('[data-give-again]').addEventListener('click', function () { location.reload(); });
        box.setAttribute('tabindex', '-1');
        box.focus();
      }, function (err) { busy(btn, false); formError(form, err.message); });
    });
  }

  // ------------------------------------------------------------ members area

  function initArea(root) {
    var tabs = qa(root, '.smem-tab');
    var loaded = {};
    function show(name, focus, push) {
      tabs.forEach(function (t) {
        var on = t.getAttribute('data-tab') === name;
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        t.tabIndex = on ? 0 : -1;
        if (on && focus) { t.focus(); }
      });
      qa(root, '[data-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== name; });
      if (push) {
        try { var u = new URL(location.href); u.searchParams.set('smem_tab', name); u.searchParams.delete('smem_item'); history.replaceState(null, '', u.toString()); } catch (e) { /* keep address */ }
      }
      var panel = root.querySelector('[data-panel="' + name + '"]');
      var board = panel && panel.querySelector('[data-board]');
      if (board && !loaded[name]) { loaded[name] = true; initBoard(root, board); }
    }
    tabs.forEach(function (t, i) {
      t.addEventListener('click', function () { show(t.getAttribute('data-tab'), false, true); });
      t.addEventListener('keydown', function (e) {
        var d = e.key === 'ArrowRight' ? 1 : (e.key === 'ArrowLeft' ? -1 : 0);
        if (e.key === 'Home') { d = -i; } else if (e.key === 'End') { d = tabs.length - 1 - i; }
        if (!d) { return; }
        e.preventDefault();
        show(tabs[(i + d + tabs.length) % tabs.length].getAttribute('data-tab'), true, true);
      });
    });
    var start = root.getAttribute('data-tab');
    if (!tabs.length) {
      qa(root, '[data-board]').forEach(function (b) { initBoard(root, b); });
    } else {
      show(start, false, false);
    }
  }

  var STATUS = { open: 'statusOpen', planned: 'statusPlanned', done: 'statusDone', declined: 'statusDeclined' };

  function initBoard(root, board) {
    var kind = board.getAttribute('data-board');
    var isS = kind === 'suggestion';
    var area = root.getAttribute('data-area');
    var manage = root.getAttribute('data-manage') === '1';
    var list = board.querySelector('[data-board-list]');
    var pager = board.querySelector('[data-board-pager]');
    var thread = board.querySelector('[data-board-thread]');
    var bar = board.querySelector('.smem-board__bar');
    var sortSel = board.querySelector('[data-board-sort]');
    var statusSel = board.querySelector('[data-board-status]');
    var state = { page: 1, pages: 1 };

    function voteBtn(it) {
      var closed = it.status === 'done' || it.status === 'declined';
      return '<button type="button" class="smem-vote" data-vote="' + it.id + '" aria-pressed="' + (it.voted ? 'true' : 'false') + '"' + (it.mine || closed ? ' disabled' : '')
        + ' aria-label="' + esc(T.vote + ': ' + it.title) + '">' + icon('up') + '<span class="smem-vote__n">' + it.votes + '</span><span class="smem-vote__l">' + esc(it.voted ? T.voted : T.votes) + '</span></button>';
    }
    function statusPill(it) { return isS ? '<span class="smem-status smem-status--' + esc(it.status) + '">' + esc(T[STATUS[it.status]] || it.statusLabel) + '</span>' : ''; }
    function replies(n) { return n === 0 ? T.noReplies : (n === 1 ? T.oneReply : fmt(T.replies, n)); }

    function row(it) {
      return '<article class="smem-item' + (isS ? ' smem-item--s' : '') + (it.pinned ? ' is-pinned' : '') + '">'
        + (isS ? voteBtn(it) : '')
        + '<button type="button" class="smem-item__open" data-open="' + it.id + '"><span class="smem-item__title">' + esc(it.title) + '</span>'
        + '<span class="smem-item__meta">' + (it.pinned ? '<span>' + icon('pin') + ' ' + esc(T.pinned) + '</span>' : '') + statusPill(it)
        + '<span>' + esc(fmt(T.by, it.mine ? T.you : it.author)) + ' · <time datetime="' + new Date(it.created * 1000).toISOString() + '" title="' + esc(it.date) + '">' + esc(it.when) + '</time></span>'
        + (isS ? '<span>' + icon('chat') + ' ' + esc(replies(it.replies)) + '</span>' : '') + '</span></button>'
        + (isS ? '' : '<span class="smem-item__replies">' + icon('chat') + ' ' + it.replies + '<span class="screen-reader-text"> ' + esc(replies(it.replies)) + '</span></span>')
        + '</article>';
    }

    function load() {
      list.innerHTML = '<p class="smem-empty">' + esc(T.loading) + '</p>';
      get('/board?' + qs({ area: area, kind: kind, sort: sortSel ? sortSel.value : '', status: statusSel ? statusSel.value : '', page: state.page })).then(function (r) {
        state.pages = r.pages;
        if (!r.items.length) {
          list.innerHTML = '<p class="smem-empty">' + esc(statusSel && statusSel.value ? T.emptyFilter : (isS ? T.emptyIdeas : T.emptyPosts)) + '</p>';
        } else {
          list.innerHTML = r.items.map(row).join('');
        }
        pager.hidden = r.pages < 2;
        pager.innerHTML = r.pages < 2 ? '' : '<button type="button" class="smem-btn smem-btn--sm smem-btn--outline" data-page="-1"' + (state.page <= 1 ? ' disabled' : '') + '>' + esc(T.prev) + '</button>'
          + '<span>' + esc(fmt(T.pageOf, state.page, r.pages)) + '</span>'
          + '<button type="button" class="smem-btn smem-btn--sm smem-btn--outline" data-page="1"' + (state.page >= r.pages ? ' disabled' : '') + '>' + esc(T.next) + '</button>';
      }, function (e) { list.innerHTML = '<p class="smem-empty">' + esc(e.message) + '</p>'; });
    }

    function showList() {
      thread.hidden = true;
      thread.innerHTML = '';
      list.hidden = false;
      bar.hidden = false;
      pager.hidden = state.pages < 2;
      try { var u = new URL(location.href); u.searchParams.delete('smem_item'); history.replaceState(null, '', u.toString()); } catch (e) { /* keep address */ }
    }

    function post_(it, lead) {
      var tools = '';
      if (it.canEdit) {
        tools = '<div class="smem-post__tools"><button type="button" class="smem-linkbtn smem-linkbtn--muted" data-edit="' + it.id + '">' + icon('edit') + esc(T.edit) + '</button>'
          + '<button type="button" class="smem-linkbtn smem-linkbtn--danger" data-remove="' + it.id + '"' + (lead ? ' data-lead="1"' : '') + '>' + icon('trash') + esc(T.remove) + '</button></div>';
      }
      return '<article class="smem-post' + (lead ? ' smem-post--lead' + (isS ? ' has-vote' : '') : '') + '" id="smem-post-' + it.id + '">'
        + (it.avatar ? '<img class="smem-post__avatar" src="' + esc(it.avatar) + '" alt="" width="40" height="40">' : '<span class="smem-post__avatar"></span>')
        + '<div class="smem-post__main">'
        + '<div class="smem-post__head"><span class="smem-post__author">' + esc(it.author) + '</span><time datetime="' + new Date(it.created * 1000).toISOString() + '" title="' + esc(it.date) + '">' + esc(it.when) + '</time>'
        + (it.edited ? '<span>· ' + esc(T.edited) + '</span>' : '') + (lead ? statusPill(it) : '') + '</div>'
        + (lead ? '<h3 class="smem-post__title">' + esc(it.title) + '</h3>' : '')
        + '<div class="smem-post__body" data-body>' + it.html + '</div>' + tools + '</div>'
        + (lead && isS ? voteBtn(it) : '') + '</article>';
    }

    function modTools(it) {
      if (!manage) { return ''; }
      var h = '<div class="smem-modtools">';
      if (isS) {
        h += '<label for="smem-mod-' + it.id + '">' + esc(T.status) + '</label><select class="smem-select smem-select--sm" id="smem-mod-' + it.id + '" data-mod-status>'
          + ['open', 'planned', 'done', 'declined'].map(function (s) { return '<option value="' + s + '"' + (it.status === s ? ' selected' : '') + '>' + esc(T[STATUS[s]]) + '</option>'; }).join('') + '</select>';
      }
      h += '<button type="button" class="smem-btn smem-btn--sm smem-btn--outline" data-mod-pin="' + (it.pinned ? '0' : '1') + '">' + icon('pin') + esc(it.pinned ? T.unpin : T.pin) + '</button>';
      return h + '</div>';
    }

    function openThread(id, scroll) {
      list.hidden = true;
      pager.hidden = true;
      bar.hidden = true;
      thread.hidden = false;
      thread.innerHTML = '<p class="smem-empty">' + esc(T.loading) + '</p>';
      get('/board/' + id + '?' + qs({ area: area })).then(function (r) {
        var it = r.item;
        thread.setAttribute('data-thread', it.id);
        thread.innerHTML = '<button type="button" class="smem-btn smem-btn--sm smem-btn--quiet smem-thread__back" data-back>' + icon('back') + esc(isS ? T.allIdeas : T.allPosts) + '</button>'
          + post_(it, true) + modTools(it)
          + '<section class="smem-replies" aria-label="' + esc(replies(r.replies.length)) + '"><h4 class="smem-replies__title">' + esc(replies(r.replies.length)) + '</h4>'
          + r.replies.map(function (x) { return post_(x, false); }).join('')
          + '<form class="smem-replyform" data-reply-form><label class="screen-reader-text" for="smem-reply-' + it.id + '">' + esc(T.reply) + '</label>'
          + '<textarea class="smem-input smem-textarea" id="smem-reply-' + it.id + '" name="body" rows="3" maxlength="5000" placeholder="' + esc(T.writeReply) + '" required></textarea>'
          + '<button type="submit" class="smem-btn smem-btn--primary smem-btn--sm">' + esc(T.reply) + '</button></form></section>';
        try { var u = new URL(location.href); u.searchParams.set('smem_item', it.id); history.replaceState(null, '', u.toString()); } catch (e) { /* keep address */ }
        if (scroll) { thread.scrollIntoView({ block: 'start' }); }
        var back = thread.querySelector('[data-back]');
        if (back) { back.focus({ preventScroll: !scroll }); }
      }, function (e) {
        thread.innerHTML = '<button type="button" class="smem-btn smem-btn--sm smem-btn--quiet smem-thread__back" data-back>' + icon('back') + esc(isS ? T.allIdeas : T.allPosts) + '</button><p class="smem-empty">' + esc(e.message) + '</p>';
      });
    }

    function composer(title, item) {
      var lead = !item || item.kind !== 'reply';
      var id = 'smem-c-' + Date.now();
      var html = '<form class="smem-form" novalidate>'
        + (lead ? '<div class="smem-field"><label class="smem-label" for="' + id + '-t">' + esc(T.title) + '</label><input class="smem-input" id="' + id + '-t" name="title" maxlength="140" type="text" value="' + esc(item ? item.title : '') + '" autofocus></div>' : '')
        + '<div class="smem-field"><label class="smem-label" for="' + id + '-b">' + esc(T.details) + (lead ? ' <span class="smem-optional">' + esc(T.optional) + '</span>' : '') + '</label><textarea class="smem-input smem-textarea" id="' + id + '-b" name="body" rows="6" maxlength="5000"' + (lead ? '' : ' autofocus') + '>' + esc(item ? item.body : '') + '</textarea></div>'
        + '</form>';
      return new Promise(function (resolve) {
        dialog(root, {
          title: title,
          html: html,
          buttons: [{ label: T.cancel, kind: 'quiet', value: null }, {
            label: item ? T.save : T.post, kind: 'primary', onClick: function (btn, close) {
              var f = root.querySelector('[data-smem-modal] form');
              clearErrors(f);
              var t = f.querySelector('[name="title"]');
              var b = f.querySelector('[name="body"]');
              if (t && !t.value.trim()) { fieldError(t.closest('.smem-field'), T.title); t.focus(); return; }
              if (!t && !b.value.trim()) { fieldError(b.closest('.smem-field'), T.writeReply); b.focus(); return; }
              busy(btn, true, T.saving);
              var path = item ? '/board/' + item.id : '/board';
              post(path, { area: area, kind: kind, title: t ? t.value : '', body: b.value }).then(function (r) { close(r.item); }, function (e) { busy(btn, false); formError(f, e.message); });
            }
          }],
          onOpen: function (panel) {
            var f = panel.querySelector('form');
            f.addEventListener('submit', function (e) { e.preventDefault(); });
          }
        }).then(resolve);
      });
    }

    function itemFrom(id) {
      return get('/board/' + id + '?' + qs({ area: area }));
    }

    board.addEventListener('click', function (e) {
      var t = e.target.closest('button');
      if (!t || !board.contains(t)) { return; }
      if (t.hasAttribute('data-board-new')) {
        composer(isS ? T.newSuggestion : T.newPost, null).then(function (it) {
          if (!it) { return; }
          openThread(it.id, true);
        });
      } else if (t.hasAttribute('data-open')) {
        openThread(t.getAttribute('data-open'), true);
      } else if (t.hasAttribute('data-back')) {
        showList();
        load();
      } else if (t.hasAttribute('data-page')) {
        state.page = Math.max(1, Math.min(state.pages, state.page + parseInt(t.getAttribute('data-page'), 10)));
        load();
        board.scrollIntoView({ block: 'start' });
      } else if (t.hasAttribute('data-vote')) {
        t.disabled = true;
        post('/board/' + t.getAttribute('data-vote') + '/vote', { area: area }).then(function (r) {
          t.disabled = false;
          t.setAttribute('aria-pressed', r.voted ? 'true' : 'false');
          t.querySelector('.smem-vote__n').textContent = r.votes;
          t.querySelector('.smem-vote__l').textContent = r.voted ? T.voted : T.votes;
        }, function (er) { t.disabled = false; toast(root, er.message, 'bad'); });
      } else if (t.hasAttribute('data-edit')) {
        var id = t.getAttribute('data-edit');
        var tid = thread.getAttribute('data-thread');
        itemFrom(tid).then(function (r) {
          var it = String(r.item.id) === id ? r.item : r.replies.filter(function (x) { return String(x.id) === id; })[0];
          if (!it) { return; }
          composer(T.edit, it).then(function (saved) { if (saved) { openThread(tid, false); } });
        }, function (er) { toast(root, er.message, 'bad'); });
      } else if (t.hasAttribute('data-remove')) {
        var lead = t.getAttribute('data-lead') === '1';
        confirmDialog(root, { title: T.removeTitle, body: lead ? T.removeThread : T.removeReply, yes: T.remove, danger: true }).then(function (ok) {
          if (!ok) { return; }
          request('DELETE', '/board/' + t.getAttribute('data-remove') + '?' + qs({ area: area })).then(function (r) {
            toast(root, r.message, 'good');
            if (lead) { showList(); load(); } else { openThread(thread.getAttribute('data-thread'), false); }
          }, function (er) { toast(root, er.message, 'bad'); });
        });
      } else if (t.hasAttribute('data-mod-pin')) {
        post('/board/' + thread.getAttribute('data-thread') + '/moderate', { area: area, pinned: t.getAttribute('data-mod-pin') }).then(function () {
          openThread(thread.getAttribute('data-thread'), false);
        }, function (er) { toast(root, er.message, 'bad'); });
      }
    });
    board.addEventListener('change', function (e) {
      if (e.target === sortSel || e.target === statusSel) { state.page = 1; load(); return; }
      if (e.target.hasAttribute('data-mod-status')) {
        post('/board/' + thread.getAttribute('data-thread') + '/moderate', { area: area, status: e.target.value }).then(function () {
          openThread(thread.getAttribute('data-thread'), false);
        }, function (er) { toast(root, er.message, 'bad'); });
      }
    });
    board.addEventListener('submit', function (e) {
      var f = e.target.closest('[data-reply-form]');
      if (!f) { return; }
      e.preventDefault();
      var ta = f.querySelector('textarea');
      if (!ta.value.trim()) { ta.focus(); return; }
      var btn = f.querySelector('button[type="submit"]');
      busy(btn, true);
      post('/board/' + thread.getAttribute('data-thread') + '/reply', { area: area, body: ta.value }).then(function (r) {
        openThread(thread.getAttribute('data-thread'), false);
        setTimeout(function () { var n = document.getElementById('smem-post-' + r.item.id); if (n) { n.scrollIntoView({ block: 'center' }); } }, 300);
      }, function (er) { busy(btn, false); toast(root, er.message, 'bad'); });
    });

    var deep = parseInt(root.getAttribute('data-item') || '0', 10);
    var panel = board.closest('[data-panel]');
    if (deep > 0 && panel && !panel.hidden) {
      root.setAttribute('data-item', '0');
      openThread(deep, true);
    } else {
      load();
    }
  }

  // ------------------------------------------------------------ start

  function init(scope) {
    qa(scope, '.smem-scope').concat(scope.classList && scope.classList.contains('smem-scope') ? [scope] : []).forEach(function (root) {
      if (root.getAttribute('data-smem-ready') === '1') { return; }
      root.setAttribute('data-smem-ready', '1');
      var view = root.getAttribute('data-smem-view');
      if (view === 'plans') { initPlans(root); }
      if (view === 'account') { initAccount(root); }
      if (view === 'area') { initArea(root); }
      qa(root, 'form[data-smem-give]').forEach(initGive);
      root.addEventListener('click', function (e) {
        var b = e.target.closest('[data-join]');
        if (b && root.contains(b) && !b.disabled) { e.preventDefault(); startJoin(b); }
      });
    });
  }

  function start() {
    paintMode();
    init(document);
    var mo = new MutationObserver(paintMode);
    var opts = { attributes: true, attributeFilter: ['class', 'data-theme', 'data-bs-theme', 'data-color-scheme', 'data-mode', 'data-color-mode', 'style'] };
    mo.observe(document.documentElement, opts);
    if (document.body) { mo.observe(document.body, opts); }
    if (window.matchMedia) {
      var mq = window.matchMedia('(prefers-color-scheme: dark)');
      if (mq.addEventListener) { mq.addEventListener('change', function () { setTimeout(paintMode, 50); }); }
    }
  }

  window.SepoMembership = { init: function (el) { paintMode(); init(el || document); }, paintMode: paintMode };

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
