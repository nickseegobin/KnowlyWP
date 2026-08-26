/**
 * Knowly Lottie Library
 *
 * Handles the Library admin page (grid, upload, delete, preview) and exposes
 * window.KnowlyLottiePicker.open(callback) for the Quest editor's Browse button.
 */
(function () {
  'use strict';

  var ajax_url = (window.KnLottieData && KnLottieData.ajax_url) || ajaxurl;
  var nonce    = (window.KnLottieData && KnLottieData.nonce)    || '';

  // ── Utilities ───────────────────────────────────────────────────────────────

  function esc(str) {
    var d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
  }

  function fmt_size(bytes) {
    bytes = parseInt(bytes, 10) || 0;
    if (bytes < 1024)       return bytes + ' B';
    if (bytes < 1024*1024)  return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024*1024)).toFixed(2) + ' MB';
  }

  function fmt_date(dt) {
    if (!dt) return '';
    return new Date(dt).toLocaleDateString(undefined, { year:'numeric', month:'short', day:'numeric' });
  }

  // ── AJAX helpers ────────────────────────────────────────────────────────────

  function post(action, data, cb) {
    data.action = action;
    data.nonce  = nonce;
    var fd = new FormData();
    Object.keys(data).forEach(function(k) { fd.append(k, data[k]); });
    var xhr = new XMLHttpRequest();
    xhr.open('POST', ajax_url);
    xhr.onload = function() {
      try { cb(null, JSON.parse(xhr.responseText)); }
      catch(e) { cb(e); }
    };
    xhr.onerror = function() { cb(new Error('Network error')); };
    xhr.send(fd);
  }

  // ── Lottie preview ──────────────────────────────────────────────────────────

  var observer;
  var pending = {}; // id → {container, url}

  function init_observer() {
    if (!window.IntersectionObserver) return;
    observer = new IntersectionObserver(function(entries) {
      entries.forEach(function(e) {
        if (!e.isIntersecting) return;
        var id = e.target.dataset.previewId;
        if (!id || !pending[id]) return;
        observer.unobserve(e.target);
        load_preview(id, pending[id].container, pending[id].url);
        delete pending[id];
      });
    }, { threshold: 0.1 });
  }

  function schedule_preview(id, container, url) {
    if (!window.lottie) { container.style.background = '#e5e7eb'; return; }
    if (observer) {
      pending[id] = { container: container, url: url };
      container.dataset.previewId = id;
      observer.observe(container);
    } else {
      load_preview(id, container, url);
    }
  }

  function load_preview(id, container, url) {
    if (!window.lottie) return;
    // Fetch JSON first: lets us (a) use animationData instead of path so no double
    // network hit, and (b) extract composition markers to display as badges.
    fetch(url)
      .then(function(r) { return r.json(); })
      .then(function(data) {
        try {
          window.lottie.loadAnimation({
            container:     container,
            renderer:      'svg',
            loop:          true,
            autoplay:      true,
            animationData: data,
          });
        } catch(e) {
          container.style.background = '#fee2e2';
        }
        // Overlay marker chips at the bottom of the preview box
        var markers = Array.isArray(data.markers) ? data.markers : [];
        if (markers.length) {
          var chips = markers.map(function(m) {
            var label = esc(m.cm || '');
            return '<span style="'
              + 'display:inline-block;background:rgba(59,130,246,.85);color:#fff;'
              + 'font-size:10px;font-weight:700;border-radius:3px;padding:1px 5px;'
              + 'margin:2px 2px 0 0;line-height:1.5;font-family:monospace;"'
              + '>' + label + '</span>';
          }).join('');
          var bar = document.createElement('div');
          bar.style.cssText = 'position:absolute;bottom:4px;left:4px;right:4px;display:flex;flex-wrap:wrap;pointer-events:none;';
          bar.innerHTML = chips;
          container.appendChild(bar);
        }
      })
      .catch(function() {
        container.style.background = '#fee2e2';
      });
  }

  // ── Card template ────────────────────────────────────────────────────────────

  function card_html(item, picker_mode) {
    var use_url = item.proxy_url || item.file_url;
    var action_btn = picker_mode
      ? '<button class="button button-primary kn-pick-btn" style="flex:1;" data-url="' + esc(use_url) + '">Select</button>'
      : '<button class="button button-small kn-copy-btn" style="flex:1;" data-url="' + esc(use_url) + '">Copy URL</button>'
        + '<button class="button button-small kn-del-btn" style="color:#dc2626;border-color:#fca5a5;" data-id="' + esc(item.id) + '">Delete</button>';

    return '<div class="kn-lottie-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;display:flex;flex-direction:column;">'
      + '<div class="kn-preview-box" style="width:100%;height:170px;background:#f8fafc;position:relative;cursor:pointer;"></div>'
      + '<div style="padding:10px 12px 4px;">'
      +   '<p style="margin:0 0 2px;font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(item.name) + '">' + esc(item.name) + '</p>'
      +   '<p style="margin:0;font-size:11px;color:#9ca3af;">' + fmt_size(item.file_size) + ' &middot; ' + fmt_date(item.uploaded_at) + '</p>'
      + '</div>'
      + '<div style="padding:8px 12px 12px;display:flex;gap:6px;">' + action_btn + '</div>'
      + '</div>';
  }

  // ── Grid render ─────────────────────────────────────────────────────────────

  function render_grid(grid_el, items, picker_mode) {
    if (!items || items.length === 0) {
      grid_el.innerHTML = '<p style="color:#9ca3af;font-size:13px;grid-column:1/-1;padding:32px 0;text-align:center;">'
        + '📭 No files yet. Upload a Lottie JSON to get started.</p>';
      return;
    }

    grid_el.innerHTML = items.map(function(item) {
      return card_html(item, picker_mode);
    }).join('');

    // Schedule previews for each card
    var cards = grid_el.querySelectorAll('.kn-lottie-card');
    items.forEach(function(item, idx) {
      var box = cards[idx] && cards[idx].querySelector('.kn-preview-box');
      if (box) schedule_preview(item.id, box, item.proxy_url || item.file_url);
    });
  }

  // ── Load list ────────────────────────────────────────────────────────────────

  function load_list(grid_el, count_el, picker_mode, cb) {
    grid_el.innerHTML = '<p style="color:#9ca3af;font-size:13px;grid-column:1/-1;padding:32px 0;text-align:center;">Loading…</p>';
    post('knowly_lottie_list', {}, function(err, res) {
      if (err || !res || !res.success) {
        grid_el.innerHTML = '<p style="color:#dc2626;font-size:13px;grid-column:1/-1;">Failed to load library.</p>';
        return;
      }
      var items = res.data || [];
      if (count_el) count_el.textContent = items.length + ' file' + (items.length !== 1 ? 's' : '');
      render_grid(grid_el, items, !!picker_mode);
      if (cb) cb(items);
    });
  }

  // ── Copy to clipboard ────────────────────────────────────────────────────────

  function copy_url(url, btn) {
    if (navigator.clipboard) {
      navigator.clipboard.writeText(url).then(function() {
        var orig = btn.textContent;
        btn.textContent = '✓ Copied';
        setTimeout(function() { btn.textContent = orig; }, 1800);
      });
    } else {
      var ta = document.createElement('textarea');
      ta.value = url; document.body.appendChild(ta);
      ta.select(); document.execCommand('copy');
      document.body.removeChild(ta);
      var orig2 = btn.textContent;
      btn.textContent = '✓ Copied';
      setTimeout(function() { btn.textContent = orig2; }, 1800);
    }
  }

  // ── Delete ──────────────────────────────────────────────────────────────────

  function delete_item(id, grid_el, count_el) {
    if (!confirm('Delete this file from the server? This cannot be undone.')) return;
    post('knowly_lottie_delete', { id: id }, function(err, res) {
      if (err || !res || !res.success) {
        alert('Delete failed. Check the console for details.');
        return;
      }
      load_list(grid_el, count_el, false);
    });
  }

  // ── Upload ───────────────────────────────────────────────────────────────────

  function upload_file(file, grid_el, count_el, status_el, progress_el, bar_el) {
    if (!file) return;

    status_el.textContent = 'Uploading…';
    if (bar_el) { bar_el.style.display = 'flex'; progress_el.style.width = '0'; }

    var fd = new FormData();
    fd.append('action',       'knowly_lottie_upload');
    fd.append('nonce',        nonce);
    fd.append('lottie_file',  file);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', ajax_url);

    xhr.upload.onprogress = function(e) {
      if (e.lengthComputable && progress_el) {
        progress_el.style.width = Math.round((e.loaded / e.total) * 100) + '%';
      }
    };

    xhr.onload = function() {
      if (bar_el) bar_el.style.display = 'none';
      try {
        var res = JSON.parse(xhr.responseText);
        if (res.success) {
          status_el.style.color = '#16a34a';
          status_el.textContent = '✓ ' + res.data.name + ' uploaded';
          setTimeout(function() { status_el.textContent = ''; status_el.style.color = ''; }, 3000);
          load_list(grid_el, count_el, false);
        } else {
          var msg = (res.data && res.data.message) || 'Upload failed.';
          status_el.style.color = '#dc2626';
          status_el.textContent = '✗ ' + msg;
        }
      } catch(e) {
        status_el.style.color = '#dc2626';
        status_el.textContent = '✗ Unexpected server response.';
      }
    };

    xhr.onerror = function() {
      if (bar_el) bar_el.style.display = 'none';
      status_el.style.color = '#dc2626';
      status_el.textContent = '✗ Network error.';
    };

    xhr.send(fd);
  }

  // ── Library page init ────────────────────────────────────────────────────────

  function init_library_page() {
    var grid      = document.getElementById('kn-lottie-grid');
    var count_el  = document.getElementById('kn-lottie-count');
    var file_inp  = document.getElementById('kn-lottie-file-input');
    var status_el = document.getElementById('kn-lottie-upload-status');
    var bar_el    = document.getElementById('kn-lottie-upload-bar');
    var progress  = document.getElementById('kn-lottie-upload-progress');

    if (!grid) return; // not on library page

    init_observer();
    load_list(grid, count_el, false);

    // Upload trigger
    if (file_inp) {
      file_inp.addEventListener('change', function() {
        var file = file_inp.files[0];
        if (!file) return;
        upload_file(file, grid, count_el, status_el, progress, bar_el);
        file_inp.value = ''; // reset so same file can be re-uploaded if needed
      });
    }

    // Grid delegation
    grid.addEventListener('click', function(e) {
      var copy_btn = e.target.closest('.kn-copy-btn');
      var del_btn  = e.target.closest('.kn-del-btn');
      if (copy_btn) copy_url(copy_btn.dataset.url, copy_btn);
      if (del_btn)  delete_item(del_btn.dataset.id, grid, count_el);
    });
  }

  // ── Picker modal (for Quest editor) ─────────────────────────────────────────

  window.KnowlyLottiePicker = {
    open: function(callback) {
      // Build overlay
      var overlay = document.createElement('div');
      overlay.id = 'kn-lottie-picker-overlay';
      overlay.style.cssText = [
        'position:fixed;inset:0;z-index:999999',
        'background:rgba(0,0,0,.55)',
        'display:flex;align-items:center;justify-content:center',
      ].join(';');

      var modal = document.createElement('div');
      modal.style.cssText = [
        'background:#fff;border-radius:12px',
        'width:min(860px,95vw);max-height:80vh',
        'display:flex;flex-direction:column',
        'overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.3)',
      ].join(';');

      // Header
      var header = document.createElement('div');
      header.style.cssText = 'padding:16px 20px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;gap:12px;flex-shrink:0;';
      header.innerHTML = '<span style="font-weight:700;font-size:15px;">🎬 Lottie Library</span>'
        + '<span id="kn-picker-count" style="font-size:12px;color:#9ca3af;"></span>'
        + '<button id="kn-picker-close" style="margin-left:auto;background:none;border:none;cursor:pointer;font-size:20px;color:#6b7280;line-height:1;" title="Close">&times;</button>';

      // Grid container
      var body = document.createElement('div');
      body.style.cssText = 'overflow-y:auto;flex:1;padding:16px;';

      var grid = document.createElement('div');
      grid.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px;';
      grid.innerHTML = '<p style="color:#9ca3af;font-size:13px;grid-column:1/-1;padding:24px 0;text-align:center;">Loading…</p>';

      body.appendChild(grid);
      modal.appendChild(header);
      modal.appendChild(body);
      overlay.appendChild(modal);
      document.body.appendChild(overlay);

      var count_el = header.querySelector('#kn-picker-count');

      init_observer();
      load_list(grid, count_el, true);

      // Close handlers
      function close() {
        document.body.removeChild(overlay);
      }

      header.querySelector('#kn-picker-close').addEventListener('click', close);
      overlay.addEventListener('click', function(e) {
        if (e.target === overlay) close();
      });
      document.addEventListener('keydown', function esc_key(e) {
        if (e.key === 'Escape') { close(); document.removeEventListener('keydown', esc_key); }
      });

      // Card click → select
      grid.addEventListener('click', function(e) {
        var btn = e.target.closest('.kn-pick-btn');
        if (!btn) return;
        var url = btn.dataset.url;
        if (url && callback) callback(url);
        close();
      });
    },
  };

  // ── Boot ────────────────────────────────────────────────────────────────────

  document.addEventListener('DOMContentLoaded', function() {
    init_library_page();
  });

})();
