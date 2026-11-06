/*
 * SiteX Post web installer (Phase 9). Plain JavaScript on purpose: it must work
 * before the Vue SPAs are built or configured. Security notes:
 *  - The setup token lives only in memory (never localStorage/sessionStorage/cookies).
 *  - Passwords are sent once and never displayed again; the server never echoes them.
 *  - All text is inserted with textContent (no innerHTML with server data).
 */
(function () {
  'use strict';

  var app = document.getElementById('app');
  var stepsEl = document.getElementById('steps');
  if (!app || !stepsEl) return;

  // Works whether the page is /install or /install/ (or under a sub-path).
  var API = window.location.pathname.replace(/\/install\/?$/, '') + '/install/api/';
  var tokenFile = app.getAttribute('data-token-file') || 'storage/app/installer/setup-token.txt';
  var token = null;
  var state = null;
  var current = 0;
  var envResult = null;

  var STEPS = [
    { key: 'welcome', title: 'Chào mừng' },
    { key: 'environment', title: 'Kiểm tra môi trường' },
    { key: 'database', title: 'Cơ sở dữ liệu' },
    { key: 'application', title: 'Cấu hình ứng dụng' },
    { key: 'queue', title: 'Redis & hàng đợi' },
    { key: 'admin', title: 'Super Admin' },
    { key: 'install', title: 'Cài đặt' },
  ];

  var RUN_STEPS = {
    revalidate: 'Kiểm tra lại cấu hình',
    verify_database: 'Xác minh database đích',
    write_env: 'Ghi cấu hình môi trường (.env)',
    migrate: 'Tạo bảng dữ liệu (migration)',
    seed: 'Tạo dữ liệu hệ thống mặc định',
    create_admin: 'Tạo Super Admin',
    verify: 'Kiểm tra tính toàn vẹn',
    mark_installed: 'Khóa trình cài đặt',
  };

  var CHECK_LABELS = {
    php_version: 'Phiên bản PHP', mysql_driver: 'Driver MySQL (PDO)', ext_redis: 'PHP Redis extension', ext_pcntl: 'pcntl + posix (Horizon)',
    disabled_functions: 'Hàm PHP bị vô hiệu hóa', memory_limit: 'memory_limit', composer_dependencies: 'Thư viện Composer',
    laravel_bootstrap: 'Laravel khởi động', writable_storage: 'Quyền ghi storage/', writable_storage_framework: 'Quyền ghi storage/framework',
    writable_storage_logs: 'Quyền ghi storage/logs', writable_bootstrap_cache: 'Quyền ghi bootstrap/cache', writable_installer: 'Thư mục riêng của trình cài đặt',
    env_writable: 'Ghi được file .env', config_cache: 'Cache cấu hình', disk_space: 'Dung lượng trống', https: 'HTTPS',
    web_routing: 'Định tuyến Nginx', web_root: 'Thư mục gốc web (public)', app_url: 'URL hiện tại',
  };

  // ---------- helpers ----------
  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        var v = attrs[k];
        if (v === null || v === undefined || v === false) return;
        if (k === 'class') node.className = v;
        else if (k === 'text') node.textContent = v;
        else if (k === 'style') node.style.cssText = v; // CSSOM: allowed by the page CSP (no inline style attributes)
        else if (k.indexOf('on') === 0) node.addEventListener(k.slice(2), v);
        else node.setAttribute(k, v === true ? '' : v);
      });
    }
    (children || []).forEach(function (c) {
      if (c === null || c === undefined || c === false) return;
      node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return node;
  }

  function clear(node) { while (node.firstChild) node.removeChild(node.firstChild); }

  function badge(status) {
    var map = {
      PASS: ['bg-emerald-50 text-emerald-700 ring-emerald-200', 'ĐẠT'],
      WARNING: ['bg-amber-50 text-amber-700 ring-amber-200', 'CẢNH BÁO'],
      FAIL: ['bg-red-50 text-red-700 ring-red-200', 'LỖI'],
      done: ['bg-emerald-50 text-emerald-700 ring-emerald-200', 'Xong'],
      running: ['bg-blue-50 text-blue-700 ring-blue-200', 'Đang chạy'],
      failed: ['bg-red-50 text-red-700 ring-red-200', 'Lỗi'],
      pending: ['bg-slate-100 text-slate-600 ring-slate-200', 'Chờ'],
    };
    var m = map[status] || map.pending;
    return el('span', { class: 'inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset ' + m[0], text: m[1] });
  }

  function button(label, opts) {
    opts = opts || {};
    var cls = opts.variant === 'secondary'
      ? 'inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50'
      : 'inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50';
    return el('button', { type: opts.type || 'button', class: cls, onclick: opts.onclick, 'data-testid': opts.testid, disabled: opts.disabled }, [label]);
  }

  function field(label, input, hint, errorKey) {
    var id = input.id;
    return el('div', { class: 'space-y-1' }, [
      el('label', { for: id, class: 'block text-sm font-medium text-slate-700', text: label }),
      input,
      hint ? el('p', { class: 'text-xs text-slate-500', text: hint }) : null,
      el('p', { class: 'hidden text-xs text-red-600', 'data-error-for': errorKey || input.name, role: 'alert' }),
    ]);
  }

  function input(name, attrs) {
    attrs = attrs || {};
    return el('input', Object.assign({
      id: 'f-' + name.replace(/\./g, '-'), name: name,
      class: 'block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200',
    }, attrs));
  }

  function select(name, options, value) {
    var s = el('select', { id: 'f-' + name.replace(/\./g, '-'), name: name, class: 'block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm' });
    options.forEach(function (o) {
      var opt = el('option', { value: o[0], text: o[1] });
      if (o[0] === value) opt.selected = true;
      s.appendChild(opt);
    });
    return s;
  }

  function alertBox(kind, text) {
    var cls = { error: 'border-red-200 bg-red-50 text-red-800', success: 'border-emerald-200 bg-emerald-50 text-emerald-800', info: 'border-blue-200 bg-blue-50 text-blue-800', warning: 'border-amber-200 bg-amber-50 text-amber-800' }[kind];
    return el('div', { class: 'rounded-lg border px-4 py-3 text-sm ' + cls, role: kind === 'error' ? 'alert' : 'status', text: text });
  }

  function showFieldErrors(form, details) {
    form.querySelectorAll('[data-error-for]').forEach(function (p) { p.classList.add('hidden'); p.textContent = ''; });
    Object.keys(details || {}).forEach(function (k) {
      var p = form.querySelector('[data-error-for="' + k + '"]');
      if (p) { p.textContent = details[k][0]; p.classList.remove('hidden'); }
    });
  }

  function formValues(form) {
    var out = {};
    new FormData(form).forEach(function (v, k) { out[k] = v; });
    return out;
  }

  function api(method, path, body) {
    var headers = { Accept: 'application/json', 'X-Setup-Token': token || '' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    return fetch(API + path, { method: method, headers: headers, body: body === undefined ? undefined : JSON.stringify(body), credentials: 'omit' })
      .then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (json) {
          if (!res.ok) {
            var err = (json && json.error) || {};
            var e = new Error(err.message || (json.data && json.data.message) || 'Lỗi ' + res.status);
            e.status = res.status; e.code = err.code; e.details = err.details || {}; e.data = json.data;
            throw e;
          }
          return json.data;
        });
      }, function () {
        var e = new Error('Không kết nối được máy chủ. Kiểm tra mạng hoặc cấu hình Nginx.');
        e.status = 0; throw e;
      });
  }

  function onApiError(e, form, slot) {
    if (e.status === 401) { token = null; render(); return; }
    if (e.status === 403 && e.code === 'installer_locked') { window.location.reload(); return; }
    if (form && e.details) showFieldErrors(form, e.details);
    if (slot) { clear(slot); slot.appendChild(alertBox('error', e.message)); }
  }

  function done(key) { return !!(state && state.steps && state.steps[key]); }

  function renderSteps() {
    clear(stepsEl);
    STEPS.forEach(function (s, i) {
      var active = i === current;
      var complete = s.key === 'welcome' ? token !== null && i < current : done(s.key);
      stepsEl.appendChild(el('li', {}, [
        el('button', {
          type: 'button',
          class: 'flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left ' + (active ? 'bg-blue-50 font-semibold text-blue-700' : 'text-slate-600 hover:bg-slate-50'),
          'aria-current': active ? 'step' : null,
          disabled: token === null && i > 0,
          onclick: function () { if (token !== null) { current = i; render(); } },
        }, [
          el('span', { class: 'flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold ' + (complete ? 'bg-emerald-600 text-white' : active ? 'bg-blue-600 text-white' : 'bg-slate-200 text-slate-600'), text: complete ? '✓' : String(i + 1) }),
          el('span', { text: s.title }),
        ]),
      ]));
    });
    var pct = Math.round((STEPS.filter(function (s) { return done(s.key); }).length / (STEPS.length - 2)) * 100);
    stepsEl.appendChild(el('li', { class: 'px-3 pt-4' }, [
      el('div', { class: 'h-2 rounded-full bg-slate-100', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(Math.min(pct, 100)), 'aria-label': 'Tiến độ cài đặt' }, [
        el('div', { class: 'h-2 rounded-full bg-blue-600', style: 'width:' + Math.min(pct, 100) + '%' }),
      ]),
      el('p', { class: 'mt-1 text-xs text-slate-500', text: 'Tiến độ: ' + Math.min(pct, 100) + '%' }),
    ]));
  }

  function nav(prev, next) {
    return el('div', { class: 'mt-8 flex flex-wrap justify-between gap-3 border-t border-slate-100 pt-5' }, [
      prev ? button('← Quay lại', { variant: 'secondary', onclick: function () { current--; render(); } }) : el('span'),
      next || null,
    ]);
  }

  function heading(title, sub) {
    return el('div', { class: 'mb-6' }, [
      el('h1', { class: 'text-xl font-bold sm:text-2xl', text: title }),
      sub ? el('p', { class: 'mt-1 text-sm text-slate-600', text: sub }) : null,
    ]);
  }

  function refreshState() {
    return api('GET', 'state').then(function (s) { state = s; });
  }

  // ---------- steps ----------
  function viewWelcome() {
    var slot = el('div');
    var tokenInput = input('token', { type: 'password', autocomplete: 'off', required: true, placeholder: 'Dán mã cài đặt', 'data-testid': 'setup-token' });
    var form = el('form', { class: 'space-y-4', onsubmit: function (ev) {
      ev.preventDefault();
      token = tokenInput.value.trim();
      api('GET', 'state').then(function (s) { state = s; current = 1; render(); }, function (e) { token = null; onApiError(e, null, slot); });
    } }, [
      field('Mã cài đặt (setup token)', tokenInput, 'Mã nằm trong file ' + tokenFile + ' (mở bằng aaPanel → Files), hoặc tạo mã mới: php artisan sitex:install:token'),
      slot,
      button('Bắt đầu cài đặt →', { type: 'submit', testid: 'start' }),
    ]);

    return el('div', {}, [
      heading('Chào mừng đến với SiteX Post', 'Trình cài đặt sẽ kiểm tra máy chủ, kết nối database, cấu hình ứng dụng và tạo tài khoản Super Admin.'),
      el('div', { class: 'mb-6 grid gap-3 sm:grid-cols-2' }, [
        infoCard('Bạn cần chuẩn bị', ['Database MySQL 8 trống (aaPanel → Databases → Add)', 'Redis (aaPanel → App Store → Redis) — khuyến nghị', 'Domain API đã trỏ vào backend/public, có SSL', 'Email và mật khẩu mạnh cho Super Admin']),
        infoCard('An toàn', ['Chỉ người có quyền truy cập file máy chủ mới đọc được mã cài đặt', 'Mật khẩu chỉ lưu phía máy chủ, không lưu trong trình duyệt', 'Không ghi đè database đang có dữ liệu', 'Sau khi cài xong, trình cài đặt tự khóa']),
      ]),
      form,
    ]);
  }

  function infoCard(title, items) {
    return el('div', { class: 'rounded-xl border border-slate-200 bg-slate-50 p-4' }, [
      el('p', { class: 'mb-2 text-sm font-semibold', text: title }),
      el('ul', { class: 'list-disc space-y-1 pl-5 text-sm text-slate-600' }, items.map(function (t) { return el('li', { text: t }); })),
    ]);
  }

  function viewEnvironment() {
    var box = el('div', {}, [el('p', { class: 'text-sm text-slate-500', text: 'Đang kiểm tra…' })]);
    var nextBtn = button('Tiếp tục →', { disabled: true, testid: 'next', onclick: function () { current++; render(); } });
    function load() {
      clear(box); box.appendChild(el('p', { class: 'text-sm text-slate-500', text: 'Đang kiểm tra…' }));
      api('GET', 'environment').then(function (r) {
        envResult = r; clear(box);
        box.appendChild(el('p', { class: 'mb-4 text-sm', text: 'Đạt: ' + r.summary.pass + ' · Cảnh báo: ' + r.summary.warning + ' · Lỗi: ' + r.summary.fail }));
        var list = el('ul', { class: 'divide-y divide-slate-100 rounded-xl border border-slate-200', 'data-testid': 'env-checks' });
        r.checks.forEach(function (c) {
          list.appendChild(el('li', { class: 'flex flex-col gap-1 px-4 py-3 sm:flex-row sm:items-start sm:gap-3' }, [
            badge(c.status),
            el('div', { class: 'min-w-0 flex-1' }, [
              el('p', { class: 'text-sm font-medium', text: (CHECK_LABELS[c.key] || c.key.replace(/^ext_/, 'PHP extension: ')) }),
              el('p', { class: 'break-words text-xs text-slate-500', text: c.value }),
              c.fix ? el('p', { class: 'mt-1 text-xs text-amber-800', text: 'Cách khắc phục: ' + c.fix }) : null,
            ]),
          ]));
        });
        box.appendChild(list);
        nextBtn.disabled = !r.can_continue;
        if (!r.can_continue) box.appendChild(el('div', { class: 'mt-4' }, [alertBox('error', 'Còn mục LỖI. Khắc phục theo hướng dẫn rồi bấm "Kiểm tra lại".')]));
        refreshState().then(renderSteps);
      }, function (e) { onApiError(e, null, box); });
    }
    load();
    return el('div', {}, [
      heading('Kiểm tra môi trường', 'Máy chủ cần đạt các yêu cầu dưới đây. CẢNH BÁO không chặn cài đặt nhưng nên xử lý trước khi chạy thật.'),
      el('div', { class: 'mb-4' }, [button('Kiểm tra lại', { variant: 'secondary', onclick: load })]),
      box,
      nav(true, nextBtn),
    ]);
  }

  function viewDatabase() {
    var saved = (state && state.config && state.config.database) || {};
    var slot = el('div');
    var form = el('form', { class: 'grid gap-4 sm:grid-cols-2', autocomplete: 'off' }, [
      field('Máy chủ (host)', input('host', { value: saved.host || '127.0.0.1', required: true })),
      field('Cổng (port)', input('port', { type: 'number', value: saved.port || 3306, min: 1, max: 65535, required: true })),
      field('Tên database', input('database', { value: saved.database || '', required: true, placeholder: 'sitexpost' }), 'Database phải trống. Trình cài đặt không ghi đè dữ liệu có sẵn.'),
      field('Tên đăng nhập', input('username', { value: saved.username || '', required: true, autocomplete: 'off' })),
      field('Mật khẩu', input('password', { type: 'password', autocomplete: 'new-password', placeholder: saved.password_set ? '•••••• (đã lưu — để trống để giữ nguyên)' : '' })),
    ]);
    function payload() {
      var v = formValues(form);
      var body = { host: v.host, port: Number(v.port), database: v.database, username: v.username };
      if (v.password) body.password = v.password;
      return body;
    }
    function describe(r) {
      if (!r.connected) return alertBox('error', r.message || 'Không kết nối được.');
      var lines = 'Kết nối thành công · MySQL ' + r.server_version + ' · ' + r.table_count + ' bảng.';
      if (!r.version_ok) return alertBox('error', lines + ' Cần MySQL 8.0 trở lên (MariaDB không được hỗ trợ).');
      if (!r.empty && !r.resumable) return alertBox('error', lines + ' Database đã có dữ liệu — hãy dùng database trống.');
      if (r.resumable) return alertBox('warning', lines + ' Đây là database của lần cài đặt trước chưa hoàn tất; có thể tiếp tục.');
      if (!r.can_create) return alertBox('warning', lines + ' Không xác định được quyền CREATE của user — nếu migration lỗi, cấp quyền ALL cho user trên database này.');
      return alertBox('success', lines + ' Database trống, sẵn sàng cài đặt.');
    }
    var testBtn = button('Kiểm tra kết nối', { variant: 'secondary', testid: 'db-test', onclick: function () {
      clear(slot); slot.appendChild(el('p', { class: 'text-sm text-slate-500', text: 'Đang kết nối…' }));
      api('POST', 'database/test', payload()).then(function (r) { clear(slot); slot.appendChild(describe(r)); }, function (e) { onApiError(e, form, slot); });
    } });
    var nextBtn = button('Lưu và tiếp tục →', { testid: 'next', onclick: function () {
      api('PUT', 'database', payload()).then(function () { return refreshState(); }).then(function () { current++; render(); }, function (e) { onApiError(e, form, slot); });
    } });
    return el('div', {}, [
      heading('Cơ sở dữ liệu MySQL', 'Tạo database và user trong aaPanel → Databases → Add database, rồi nhập thông tin bên dưới.'),
      form,
      el('div', { class: 'mt-4 flex flex-wrap gap-3' }, [testBtn]),
      el('div', { class: 'mt-4' }, [slot]),
      nav(true, nextBtn),
    ]);
  }

  function viewApplication() {
    var a = (state && state.config && state.config.application) || {};
    var m = (state && state.config && state.config.mail) || {};
    var origin = window.location.origin;
    var guess = function (sub) { try { var u = new URL(origin); var parts = u.hostname.split('.'); if (parts.length > 2) { parts[0] = sub; return u.protocol + '//' + parts.join('.'); } } catch (e) { /* ignore */ } return ''; };
    var slot = el('div');
    var mailer = select('mail.mailer', [['log', 'Chưa cấu hình (ghi vào log)'], ['smtp', 'SMTP']], m.mailer || 'log');
    var smtp = el('div', { class: 'grid gap-4 sm:grid-cols-2' }, [
      field('SMTP host', input('mail.host', { value: m.host || '' })),
      field('SMTP port', input('mail.port', { type: 'number', value: m.port || 587 })),
      field('Mã hóa', select('mail.encryption', [['tls', 'TLS (STARTTLS, cổng 587)'], ['ssl', 'SSL (cổng 465)'], ['none', 'Không']], m.encryption || 'tls')),
      field('Tên đăng nhập SMTP', input('mail.username', { value: m.username || '', autocomplete: 'off' })),
      field('Mật khẩu SMTP', input('mail.password', { type: 'password', autocomplete: 'new-password', placeholder: m.password_set ? '•••••• (đã lưu)' : '' })),
      field('Email người gửi', input('mail.from_address', { type: 'email', value: m.from_address || '' })),
      field('Tên người gửi', input('mail.from_name', { value: m.from_name || 'SiteX Post' })),
    ]);
    function toggle() { smtp.classList.toggle('hidden', mailer.value !== 'smtp'); }
    mailer.addEventListener('change', toggle);
    var tz = select('timezone', [['Asia/Ho_Chi_Minh', 'Asia/Ho_Chi_Minh (GMT+7)'], ['Asia/Bangkok', 'Asia/Bangkok (GMT+7)'], ['Asia/Singapore', 'Asia/Singapore (GMT+8)'], ['UTC', 'UTC']], a.timezone || 'Asia/Ho_Chi_Minh');
    var form = el('form', { class: 'space-y-6', autocomplete: 'off' }, [
      el('div', { class: 'grid gap-4 sm:grid-cols-2' }, [
        field('Tên ứng dụng', input('app_name', { value: a.app_name || 'SiteX Post', required: true })),
        field('URL API (domain hiện tại)', input('app_url', { type: 'url', value: a.app_url || origin, required: true }), 'Domain trỏ vào backend/public, ví dụ https://api.tenmien.vn'),
        field('URL ứng dụng khách hàng', input('frontend_url', { type: 'url', value: a.frontend_url || guess('app'), required: true }), 'Ví dụ https://app.tenmien.vn'),
        field('URL trang quản trị', input('admin_url', { type: 'url', value: a.admin_url || guess('admin'), required: true }), 'Ví dụ https://admin.tenmien.vn'),
        field('Ngôn ngữ mặc định', select('locale', [['vi', 'Tiếng Việt'], ['en', 'English']], a.locale || 'vi')),
        field('Múi giờ hiển thị mặc định', tz, 'Dữ liệu luôn lưu theo UTC; đây là múi giờ hiển thị mặc định cho người dùng.'),
      ]),
      el('fieldset', { class: 'space-y-4 rounded-xl border border-slate-200 p-4' }, [
        el('legend', { class: 'px-1 text-sm font-semibold', text: 'Email hệ thống' }),
        field('Phương thức gửi', mailer, 'Có thể cấu hình hoặc đổi sau trong Setup Wizard (có nút gửi thử).'),
        smtp,
      ]),
      el('p', { class: 'text-xs text-slate-500', text: 'APP_KEY được tạo ngẫu nhiên phía máy chủ; nếu .env đã có APP_KEY thì giữ nguyên.' }),
    ]);
    toggle();
    var nextBtn = button('Lưu và tiếp tục →', { testid: 'next', onclick: function () {
      var v = formValues(form);
      var body = { app_name: v.app_name, app_url: v.app_url, frontend_url: v.frontend_url, admin_url: v.admin_url, locale: v.locale, timezone: v.timezone, mail: { mailer: v['mail.mailer'] } };
      if (body.mail.mailer === 'smtp') {
        body.mail.host = v['mail.host']; body.mail.port = Number(v['mail.port']); body.mail.encryption = v['mail.encryption'];
        body.mail.username = v['mail.username'] || null; body.mail.from_address = v['mail.from_address']; body.mail.from_name = v['mail.from_name'] || null;
        if (v['mail.password']) body.mail.password = v['mail.password'];
      }
      api('PUT', 'application', body).then(function (r) {
        return refreshState().then(function () {
          if (r.warnings && r.warnings.length) {
            clear(slot);
            slot.appendChild(alertBox('warning', r.warnings.indexOf('app_url_not_https') >= 0 ? 'Đã lưu. Lưu ý: URL API chưa dùng HTTPS — production bắt buộc HTTPS.' : 'Đã lưu. Lưu ý: URL API khác domain bạn đang truy cập.'));
            slot.appendChild(el('div', { class: 'mt-3' }, [button('Tiếp tục →', { onclick: function () { current++; render(); } })]));
          } else { current++; render(); }
        });
      }, function (e) { onApiError(e, form, slot); });
    } });
    return el('div', {}, [heading('Cấu hình ứng dụng', 'Thông tin cơ bản của hệ thống. Tất cả được lưu phía máy chủ.'), form, el('div', { class: 'mt-4' }, [slot]), nav(true, nextBtn)]);
  }

  function viewQueue() {
    var q = (state && state.config && state.config.queue) || {};
    var slot = el('div');
    var driver = select('driver', [['redis', 'Redis + Horizon (khuyến nghị)'], ['database', 'Database (không cần Redis, hiệu năng thấp hơn)']], q.driver || 'redis');
    var redisBox = el('div', { class: 'grid gap-4 sm:grid-cols-3' }, [
      field('Redis host', input('redis_host', { value: q.redis_host || '127.0.0.1' })),
      field('Redis port', input('redis_port', { type: 'number', value: q.redis_port || 6379 })),
      field('Redis password', input('redis_password', { type: 'password', autocomplete: 'new-password', placeholder: q.redis_password_set ? '•••••• (đã lưu)' : 'Để trống nếu không đặt' })),
    ]);
    function toggle() { redisBox.classList.toggle('hidden', driver.value !== 'redis'); }
    driver.addEventListener('change', toggle);
    var form = el('form', { class: 'space-y-4', autocomplete: 'off' }, [field('Hàng đợi (queue) & cache', driver), redisBox]);
    toggle();
    function payload() {
      var v = formValues(form);
      var body = { driver: v.driver, redis_host: v.redis_host, redis_port: Number(v.redis_port) };
      if (v.redis_password) body.redis_password = v.redis_password;
      return body;
    }
    var testBtn = button('Kiểm tra Redis', { variant: 'secondary', testid: 'redis-test', onclick: function () {
      api('POST', 'redis/test', payload()).then(function (r) {
        clear(slot); slot.appendChild(r.connected ? alertBox('success', 'Kết nối Redis thành công' + (r.version ? ' · phiên bản ' + r.version : '') + '.') : alertBox('error', 'Không kết nối được Redis (' + r.error_code + ').'));
      }, function (e) { onApiError(e, form, slot); });
    } });
    var templates = el('div', { class: 'mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4' }, [
      el('p', { class: 'text-sm font-semibold', text: 'Worker & Scheduler (bạn tự cấu hình trong aaPanel)' }),
      el('p', { class: 'mt-1 text-sm text-slate-600', text: 'Trình cài đặt không chạy lệnh root. Tải mẫu cấu hình đã điền sẵn đường dẫn của máy chủ này rồi dán vào aaPanel → Supervisor và aaPanel → Cron (chạy mỗi phút).' }),
      el('div', { class: 'mt-3 flex flex-wrap gap-2' }, [
        ['supervisor-horizon', 'Supervisor: Horizon'], ['supervisor-queue-work', 'Supervisor: queue:work (dự phòng)'], ['cron', 'Cron: scheduler'], ['nginx-api', 'Nginx: domain API'],
      ].map(function (t) { return button('Tải ' + t[1], { variant: 'secondary', onclick: function () { download(t[0]); } }); })),
    ]);
    var nextBtn = button('Lưu và tiếp tục →', { testid: 'next', onclick: function () {
      api('PUT', 'queue', payload()).then(function () { return refreshState(); }).then(function () { current++; render(); }, function (e) { onApiError(e, form, slot); });
    } });
    return el('div', {}, [heading('Redis & hàng đợi', 'Các tác vụ AI, đăng bài, đồng bộ chạy nền qua hàng đợi. Horizon cần Redis + pcntl.'), form, el('div', { class: 'mt-4 flex gap-3' }, [testBtn]), el('div', { class: 'mt-4' }, [slot]), templates, nav(true, nextBtn)]);
  }

  function download(name) {
    fetch(API + 'templates/' + name, { headers: { 'X-Setup-Token': token || '' }, credentials: 'omit' }).then(function (res) {
      if (!res.ok) throw new Error('Không tải được mẫu.');
      var disposition = res.headers.get('Content-Disposition') || '';
      var match = /filename="([^"]+)"/.exec(disposition);
      return res.blob().then(function (b) {
        var url = URL.createObjectURL(b);
        var a = el('a', { href: url, download: match ? match[1] : name + '.txt' });
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
      });
    }).catch(function (e) { window.console && console.warn(e); });
  }

  function viewAdmin() {
    var ad = (state && state.config && state.config.admin) || {};
    var slot = el('div');
    var pw = input('password', { type: 'password', autocomplete: 'new-password', required: true, minlength: 12 });
    var meter = el('p', { class: 'text-xs text-slate-500', text: 'Tối thiểu 12 ký tự, gồm chữ hoa, chữ thường, số và ký tự đặc biệt.' });
    pw.addEventListener('input', function () {
      var v = pw.value; var score = 0;
      if (v.length >= 12) score++; if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++; if (/\d/.test(v)) score++; if (/[^A-Za-z0-9]/.test(v)) score++; if (v.length >= 16) score++;
      meter.textContent = 'Độ mạnh: ' + ['rất yếu', 'yếu', 'trung bình', 'khá', 'mạnh', 'rất mạnh'][score];
      meter.className = 'text-xs ' + (score >= 4 ? 'text-emerald-700' : score >= 3 ? 'text-amber-700' : 'text-red-600');
    });
    var form = el('form', { class: 'grid gap-4 sm:grid-cols-2', autocomplete: 'off' }, [
      field('Họ và tên', input('name', { value: ad.name || '', required: true, autocomplete: 'name' })),
      field('Email', input('email', { type: 'email', value: ad.email || '', required: true, autocomplete: 'email' })),
      el('div', { class: 'space-y-1' }, [el('label', { for: pw.id, class: 'block text-sm font-medium text-slate-700', text: 'Mật khẩu' }), pw, meter, el('p', { class: 'hidden text-xs text-red-600', 'data-error-for': 'password', role: 'alert' })]),
      field('Nhập lại mật khẩu', input('password_confirmation', { type: 'password', autocomplete: 'new-password', required: true })),
    ]);
    var nextBtn = button('Lưu và tiếp tục →', { testid: 'next', onclick: function () {
      api('PUT', 'admin', formValues(form)).then(function () { return refreshState(); }).then(function () { current++; render(); }, function (e) { onApiError(e, form, slot); });
    } });
    return el('div', {}, [
      heading('Tài khoản Super Admin', 'Tài khoản quản trị cao nhất. Không có tài khoản mặc định; mật khẩu được băm (bcrypt) phía máy chủ. Lần đăng nhập đầu tiên sẽ bắt buộc bật xác thực hai lớp (2FA).'),
      ad.email ? el('div', { class: 'mb-4' }, [alertBox('info', 'Đã lưu Super Admin ' + ad.email + '. Nhập lại để thay đổi, hoặc bấm Quay lại / chuyển bước.')]) : null,
      form, el('div', { class: 'mt-4' }, [slot]),
      nav(true, ad.email ? el('div', { class: 'flex gap-3' }, [button('Giữ thông tin đã lưu →', { variant: 'secondary', onclick: function () { current++; render(); } }), nextBtn]) : nextBtn),
    ]);
  }

  function viewInstall() {
    var missing = ['database', 'application', 'queue', 'admin'].filter(function (k) { return !done(k); });
    var progress = el('ul', { class: 'divide-y divide-slate-100 rounded-xl border border-slate-200', 'data-testid': 'run-steps' });
    var slot = el('div');
    function drawRun(run) {
      clear(progress);
      Object.keys(RUN_STEPS).forEach(function (k) {
        var s = (run && run.steps && run.steps[k]) || 'pending';
        progress.appendChild(el('li', { class: 'flex items-center gap-3 px-4 py-2.5 text-sm' }, [badge(s), el('span', { text: RUN_STEPS[k] })]));
      });
    }
    drawRun(state && state.run);
    var runBtn = button(state && state.run && state.run.status === 'failed' ? 'Thử lại cài đặt' : 'Cài đặt ngay', { testid: 'run', disabled: missing.length > 0, onclick: function () {
      runBtn.disabled = true; runBtn.textContent = 'Đang cài đặt… (có thể mất vài phút)';
      clear(slot);
      api('POST', 'run').then(function (r) {
        drawRun(r);
        clear(slot);
        slot.appendChild(alertBox('success', 'Cài đặt hoàn tất! Trình cài đặt đã bị khóa.'));
        slot.appendChild(el('div', { class: 'mt-4 space-y-2 text-sm text-slate-600' }, [
          el('p', { text: 'Bước tiếp theo:' }),
          el('ol', { class: 'list-decimal space-y-1 pl-5' }, [
            el('li', { text: 'Cấu hình Supervisor (Horizon) và Cron trong aaPanel bằng các mẫu đã tải.' }),
            el('li', { text: 'Build và triển khai frontend (dist/customer, dist/admin) theo AAPANEL-QUICK-INSTALL.md.' }),
            el('li', { text: 'Đăng nhập trang quản trị, bật 2FA, rồi hoàn tất Setup Wizard.' }),
          ]),
          r.redirect ? el('a', { class: 'mt-3 inline-flex rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white hover:bg-blue-700', href: r.redirect, 'data-testid': 'go-setup' }, ['Mở Setup Wizard →']) : null,
        ]));
        token = null;
      }, function (e) {
        runBtn.disabled = false; runBtn.textContent = 'Thử lại cài đặt';
        if (e.data) drawRun(e.data);
        onApiError(e, null, slot);
        if (e.data && e.data.error) slot.appendChild(el('pre', { class: 'mt-3 overflow-x-auto whitespace-pre-wrap rounded-lg bg-slate-900 p-3 text-xs text-slate-100', text: 'Bước lỗi: ' + e.data.failed_step + '\n' + e.data.error }));
        slot.appendChild(el('div', { class: 'mt-3' }, [button('Xem chẩn đoán', { variant: 'secondary', onclick: showDiagnostics })]));
      });
    } });
    function showDiagnostics() {
      api('GET', 'diagnostics').then(function (d) {
        slot.appendChild(el('pre', { class: 'mt-3 max-h-80 overflow-auto whitespace-pre-wrap rounded-lg bg-slate-900 p-3 text-xs text-slate-100', text: (d.log || []).join('\n') || 'Chưa có nhật ký.' }));
      }, function (e) { onApiError(e, null, slot); });
    }
    return el('div', {}, [
      heading('Cài đặt', 'Trình cài đặt sẽ kiểm tra lại cấu hình, ghi .env, tạo bảng, tạo dữ liệu hệ thống và Super Admin, rồi tự khóa.'),
      missing.length ? el('div', { class: 'mb-4' }, [alertBox('warning', 'Chưa hoàn thành: ' + missing.map(function (k) { return STEPS.filter(function (s) { return s.key === k; })[0].title; }).join(', ') + '.')]) : null,
      progress,
      el('div', { class: 'mt-5' }, [runBtn]),
      el('div', { class: 'mt-4' }, [slot]),
      nav(true, null),
    ]);
  }

  function render() {
    if (token === null) current = 0;
    renderSteps();
    clear(app);
    var views = [viewWelcome, viewEnvironment, viewDatabase, viewApplication, viewQueue, viewAdmin, viewInstall];
    app.appendChild(views[current]());
    var h = app.querySelector('h1');
    if (h) { h.setAttribute('tabindex', '-1'); h.focus({ preventScroll: true }); }
  }

  render();
})();
