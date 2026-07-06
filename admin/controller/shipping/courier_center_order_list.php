<?php
namespace Opencart\Admin\Controller\Extension\Couriercenter\Shipping;

class CourierCenterOrderList extends \Opencart\System\Engine\Controller {

    public function injectColumns(string &$route, array &$args, mixed &$output): void {
        // ── Manifest toolbar button ──────────────────────────────────────────
        // Always inject a "Manifest Παραλαβής" button into the orders-page toolbar
        // so the merchant reaches it from where they work daily (Sales → Orders),
        // not buried in the extension settings. js=true → plain '&' for use in JS.
        $manifest_url = $this->url->link(
            'extension/couriercenter/shipping/courier_center_manifest',
            'user_token=' . $this->session->data['user_token'],
            true
        );
        $manifest_js = json_encode($manifest_url);

        $output .= <<<JS
<script>
(function() {
  function addCCManifestBtn() {
    var bar = document.querySelector('#content .page-header .float-end');
    if (!bar || document.getElementById('cc-manifest-btn')) return;
    var a = document.createElement('a');
    a.id = 'cc-manifest-btn';
    a.href = $manifest_js;
    a.className = 'btn btn-info';
    a.setAttribute('data-bs-toggle', 'tooltip');
    a.title = 'Manifest Παραλαβής — Courier Center';
    a.innerHTML = '<i class="fa-solid fa-clipboard-list"></i> Manifest';
    bar.insertBefore(a, bar.firstChild);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addCCManifestBtn);
  } else {
    addCCManifestBtn();
  }
})();
</script>
JS;

        // ── Bulk actions toolbar ─────────────────────────────────────────────
        // Select orders via the row checkboxes, then create/print/refresh in bulk.
        $token        = $this->session->data['user_token'];
        $url_create   = json_encode($this->url->link('extension/couriercenter/shipping/courier_center.bulkCreate',       'user_token=' . $token, true));
        $url_create_bn = json_encode($this->url->link('extension/couriercenter/shipping/courier_center.bulkCreateBoxnow', 'user_token=' . $token, true));
        $url_status   = json_encode($this->url->link('extension/couriercenter/shipping/courier_center.bulkStatus',       'user_token=' . $token, true));
        $url_print    = json_encode($this->url->link('extension/couriercenter/shipping/courier_center.bulkPrint',        'user_token=' . $token, true));
        $url_print_bn = json_encode($this->url->link('extension/couriercenter/shipping/courier_center.bulkPrintBoxnow',  'user_token=' . $token, true));
        $token_ep     = json_encode((defined('HTTP_SERVER') ? HTTP_SERVER : '') . 'cc_token.php');
        $token_js     = json_encode($token);

        $output .= <<<JS
<script>
(function() {
  var ccToken = $token_js;
  var urls = {create: $url_create, createbn: $url_create_bn, status: $url_status, print: $url_print, printbn: $url_print_bn};
  var tokenEndpoint = $token_ep;

  function ccSelected() {
    var cbs = document.querySelectorAll('#order input[name="selected[]"]:checked');
    return Array.prototype.map.call(cbs, function(c) { return c.value; });
  }
  function ccNeedSel() {
    var s = ccSelected();
    if (!s.length) { alert('Επίλεξε πρώτα παραγγελίες (τσέκαρε τα κουτάκια αριστερά).'); return null; }
    return s;
  }
  function withToken(u) { return u.replace(/([?&]user_token=)[^&]*/, '\$1' + encodeURIComponent(ccToken)); }

  function ccBulkPrint(kind) {
    var sel = ccNeedSel(); if (!sel) return;
    window.open(withToken(urls[kind]) + '&order_ids=' + sel.join(','), '_blank');
  }

  function ccBulkPost(kind, confirmMsg, btn, isRetry) {
    var sel = ccNeedSel(); if (!sel) return;
    if (confirmMsg && !isRetry && !confirm(confirmMsg)) return;
    var orig = btn.getAttribute('data-orig') || btn.innerHTML;
    btn.setAttribute('data-orig', orig);
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Παρακαλώ περιμένετε...';
    fetch(withToken(urls[kind]), {
      method: 'POST',
      credentials: 'include',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'order_ids=' + encodeURIComponent(sel.join(',')) + '&user_token=' + encodeURIComponent(ccToken)
    })
    .then(function(r) { return r.text(); })
    .then(function(t) {
      var p = null; try { p = JSON.parse(t); } catch(e) {}
      if (p) {
        btn.disabled = false; btn.innerHTML = orig;
        if (p.success) { alert(p.message || 'Ολοκληρώθηκε.'); location.reload(); }
        else { alert('Σφάλμα: ' + (p.error || 'Άγνωστο')); }
        return;
      }
      // Non-JSON = stale token (OC4 login page). Refresh token once and retry.
      if (isRetry) { btn.disabled = false; btn.innerHTML = orig; alert('Η σύνοδος (session) έληξε. Κάνε refresh (Ctrl+Shift+R).'); return; }
      fetch(tokenEndpoint, {credentials: 'include'})
        .then(function(r) { return r.json(); })
        .then(function(info) {
          if (info && info.logged_in && info.user_token) { ccToken = info.user_token; ccBulkPost(kind, null, btn, true); }
          else { btn.disabled = false; btn.innerHTML = orig; alert('Η σύνοδος (session) έληξε. Κάνε login ξανά.'); }
        })
        .catch(function() { btn.disabled = false; btn.innerHTML = orig; alert('Σφάλμα σύνδεσης.'); });
    })
    .catch(function(e) { btn.disabled = false; btn.innerHTML = orig; alert(e.toString()); });
  }

  function addCCBulkBar() {
    var order = document.getElementById('order');
    if (!order) return;
    var card = order.closest('.card');
    var header = card ? card.querySelector('.card-header') : null;
    if (!header || document.getElementById('cc-bulk-bar')) return;

    var bar = document.createElement('div');
    bar.id = 'cc-bulk-bar';
    bar.style.cssText = 'margin-top:8px; display:flex; flex-wrap:wrap; gap:6px; align-items:center;';
    bar.innerHTML =
      '<small style="color:#666; margin-right:4px;">Επιλεγμένες:</small>' +
      '<button type="button" class="btn btn-primary btn-sm" id="cc-b-create"><i class="fa-solid fa-truck"></i> Δημιουργία Vouchers</button>' +
      '<button type="button" class="btn btn-warning btn-sm" id="cc-b-createbn"><i class="fa-solid fa-box"></i> Δημιουργία BOX NOW</button>' +
      '<button type="button" class="btn btn-success btn-sm" id="cc-b-print"><i class="fa-solid fa-print"></i> Εκτύπωση Vouchers</button>' +
      '<button type="button" class="btn btn-warning btn-sm" id="cc-b-printbn"><i class="fa-solid fa-box"></i> Εκτύπωση BOX NOW</button>' +
      '<button type="button" class="btn btn-info btn-sm" id="cc-b-status"><i class="fa-solid fa-rotate"></i> Ενημέρωση Status</button>';
    header.appendChild(bar);

    document.getElementById('cc-b-create').addEventListener('click', function() {
      ccBulkPost('create', 'Δημιουργία voucher (Επόμενη Μέρα) για τις επιλεγμένες παραγγελίες;', this);
    });
    document.getElementById('cc-b-createbn').addEventListener('click', function() {
      ccBulkPost('createbn', 'Δημιουργία BOX NOW voucher για τις επιλεγμένες παραγγελίες που επέλεξαν BOX NOW στο checkout;', this);
    });
    document.getElementById('cc-b-status').addEventListener('click', function() {
      ccBulkPost('status', null, this);
    });
    document.getElementById('cc-b-print').addEventListener('click', function() { ccBulkPrint('print'); });
    document.getElementById('cc-b-printbn').addEventListener('click', function() { ccBulkPrint('printbn'); });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addCCBulkBar);
  } else {
    addCCBulkBar();
  }
})();
</script>
JS;

        // Extract order IDs from the HTML
        preg_match_all('/name="selected\[\]"\s+value="(\d+)"/', $output, $matches);
        $order_ids = array_unique(array_map('intval', $matches[1] ?? []));

        if (empty($order_ids)) return;

        // Fetch CC shipment data for visible orders
        $ids = implode(',', $order_ids);
        $q   = $this->db->query(
            "SELECT `order_id`, `voucher_number`, `tracking_number`, `status_code`, `status_desc`,
                    `service_type`, `is_voided`, `is_boxnow`
             FROM `" . DB_PREFIX . "cc_shipments`
             WHERE `order_id` IN ($ids)"
        );

        $shipments = [];
        foreach ($q->rows as $row) {
            $shipments[$row['order_id']] = $row;
        }

        // Shipping method per visible order (+ whether the plugin manages it), so
        // the merchant sees at a glance which orders Courier Center handles.
        $handled = $this->config->get('shipping_courier_center_handled_shipping_methods');
        $handled = is_array($handled) ? array_values(array_filter(array_map('strval', $handled))) : [];
        $mq = $this->db->query("SELECT `order_id`, `shipping_method` FROM `" . DB_PREFIX . "order` WHERE `order_id` IN ($ids)");
        $methods = [];
        foreach ($mq->rows as $row) {
            $m    = json_decode((string)$row['shipping_method'], true);
            $full = is_array($m) ? (string)($m['code'] ?? '') : '';
            $code = $full !== '' ? explode('.', $full)[0] : '';
            $methods[$row['order_id']] = [
                'name'    => is_array($m) ? (string)($m['name'] ?? '') : '',
                'managed' => empty($handled) ? true : in_array($code, $handled, true),
            ];
        }

        // Build JS to inject columns
        $tracking_tpl = (string)$this->config->get('shipping_courier_center_tracking_url')
            ?: 'https://www.courier.gr/track/result?tracknr={{tracking}}';
        $data_js    = json_encode($shipments);
        $methods_js = json_encode($methods);
        $track_js   = json_encode($tracking_tpl);
        $script   = <<<JS
<script>
(function() {
  var ccData     = $data_js;
  var ccMethods  = $methods_js;
  var ccTrackTpl = $track_js;

  function addCCColumns() {
    var table = document.querySelector('#form-order table.table');
    if (!table) return;

    // Add header
    var thead = table.querySelector('thead tr');
    if (thead && !thead.querySelector('.cc-col-header')) {
      var thM = document.createElement('td');
      thM.className = 'cc-col-header text-center d-none d-lg-table-cell';
      thM.style.cssText = 'white-space:nowrap; width:1px;';
      thM.innerHTML = '<small style="color:#666;">Μέθοδος</small>';
      thead.appendChild(thM);

      var th = document.createElement('td');
      th.className = 'cc-col-header text-center d-none d-lg-table-cell';
      th.style.cssText = 'white-space:nowrap; width:1px;';
      th.innerHTML = '<small style="color:#666;">CC Voucher</small>';
      thead.appendChild(th);

      var th2 = document.createElement('td');
      th2.className = 'cc-col-header text-center d-none d-lg-table-cell';
      th2.style.cssText = 'white-space:nowrap; width:1px;';
      th2.innerHTML = '<small style="color:#666;">CC Status</small>';
      thead.appendChild(th2);
    }

    // Add data rows
    var rows = table.querySelectorAll('tbody tr');
    rows.forEach(function(tr) {
      if (tr.querySelector('.cc-col-data')) return;
      var cb = tr.querySelector('input[name="selected[]"]');
      if (!cb) return;
      var orderId = parseInt(cb.value);
      var s = ccData[orderId];

      var td1 = document.createElement('td');
      td1.className = 'cc-col-data text-center d-none d-lg-table-cell';
      td1.style.cssText = 'white-space:nowrap;';

      var td2 = document.createElement('td');
      td2.className = 'cc-col-data text-center d-none d-lg-table-cell';
      td2.style.cssText = 'white-space:nowrap;';

      if (s) {
        if (s.is_voided == 1) {
          td1.innerHTML = '<span style="color:#aaa; text-decoration:line-through; font-size:11px;">' + (s.voucher_number || '') + '</span>';
          td2.innerHTML = '<span style="background:#fce8e6; color:#c0392b; padding:2px 6px; border-radius:3px; font-size:11px;">Ακυρωμένη</span>';
        } else if (s.voucher_number) {
          var icon = s.is_boxnow == 1 ? '📦 ' : '';
          var trackUrl = ccTrackTpl.replace('{{tracking}}', encodeURIComponent(s.tracking_number || s.voucher_number));
          td1.innerHTML = '<a href="' + trackUrl + '" target="_blank" rel="noopener" style="font-size:12px; white-space:nowrap;">' + icon + s.voucher_number + ' ↗</a>';

          var statusColor = '#2271b1';
          var statusBg    = '#f0f6fc';
          var code = s.status_code || '';
          if (['29','87'].includes(code))         { statusColor = '#46b450'; statusBg = '#e7f5e9'; }
          else if (['28','30'].includes(code))     { statusColor = '#dba617'; statusBg = '#fff3cd'; }
          else if (['25','99','14'].includes(code)){ statusColor = '#dc3232'; statusBg = '#fce8e6'; }

          var desc = s.status_desc || (code ? 'Κωδικός ' + code : '—');
          td2.innerHTML = '<span style="background:' + statusBg + '; color:' + statusColor + '; padding:2px 7px; border-radius:3px; font-size:11px;">' + desc + '</span>';
        } else {
          td1.innerHTML = '<span style="color:#ccc; font-size:11px;">—</span>';
          td2.innerHTML = '<span style="color:#ccc; font-size:11px;">—</span>';
        }
      } else {
        td1.innerHTML = '';
        td2.innerHTML = '';
      }

      var tdM = document.createElement('td');
      tdM.className = 'cc-col-data text-center d-none d-lg-table-cell';
      tdM.style.cssText = 'white-space:nowrap; font-size:11px;';
      var mm = ccMethods[orderId];
      if (mm) {
        tdM.innerHTML = mm.managed
          ? '<span style="color:#2c3338;">' + (mm.name || '—') + '</span>'
          : '<span style="color:#c0392b;" title="Δεν διαχειρίζεται από το plugin — δεν δημιουργείται voucher αυτόματα/μαζικά">' + (mm.name || '—') + ' ⚠️</span>';
      } else {
        tdM.innerHTML = '<span style="color:#ccc;">—</span>';
      }

      tr.appendChild(tdM);
      tr.appendChild(td1);
      tr.appendChild(td2);
    });
  }

  // Run now + observe for AJAX table reloads
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addCCColumns);
  } else {
    addCCColumns();
  }

  // MutationObserver for AJAX pagination
  var observer = new MutationObserver(function(mutations) {
    mutations.forEach(function(m) {
      if (m.type === 'childList') addCCColumns();
    });
  });
  var tbody = document.querySelector('#form-order table tbody');
  if (tbody) observer.observe(tbody, {childList: true, subtree: false});
})();
</script>
JS;

        $output .= $script;
    }
}
