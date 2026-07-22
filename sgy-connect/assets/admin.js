/* Surplus GY Connect — admin interactions (health check, category mapping, import wizard, force sync). */
(function ($) {
    'use strict';

    function post(action, data) {
        return $.post(SGYConnect.ajaxUrl, $.extend({ action: action, nonce: SGYConnect.nonce }, data || {}));
    }

    // --- health check -------------------------------------------------------
    $('#sgy-health-btn').on('click', function () {
        var $out = $('#sgy-health-result').text('…');
        post('sgy_connect_health').done(function (r) {
            if (r && r.success) {
                // Build with text nodes: the message/environment come from the remote API and must never
                // be injected as HTML (avoids DOM XSS if the API host or a base override is hostile).
                var $ok = $('<span>').css('color', '#15803d')
                    .text('OK ' + (r.data.message || 'Connected') + ' (' + (r.data.environment || '') + ')');
                $out.empty().append($ok);
            } else {
                var $err = $('<span>').css('color', '#b91c1c')
                    .text('x ' + ((r && r.data && r.data.message) || 'Failed'));
                $out.empty().append($err);
            }
        }).fail(function () { $out.html('<span style="color:#b91c1c">Request failed</span>'); });
    });

    // --- category mappings --------------------------------------------------
    $('#sgy-save-mappings').on('click', function () {
        var mappings = {};
        $('.sgy-cat-map').each(function () {
            var v = $(this).val();
            if (v) { mappings[$(this).data('source')] = v; }
        });
        var $out = $('#sgy-mappings-result').text('…');
        post('sgy_connect_save_mappings', { mappings: mappings }).done(function (r) {
            $out.html(r && r.success
                ? '<span style="color:#15803d">✔ Saved ' + (r.data.saved || 0) + ' mappings</span>'
                : '<span style="color:#b91c1c">✗ ' + ((r && r.data && r.data.message) || 'Failed') + '</span>');
        });
    });

    // --- import wizard ------------------------------------------------------
    var importing = false;
    function runImport(offset) {
        post('sgy_connect_import', { offset: offset, limit: 20 }).done(function (r) {
            if (!r || !r.success) {
                $('#sgy-import-status').html('<span style="color:#b91c1c">Import failed</span>');
                importing = false;
                return;
            }
            var d = r.data;
            var pct = d.total ? Math.min(100, Math.round((d.offset / d.total) * 100)) : 100;
            $('#sgy-progress-bar').css('width', pct + '%').text(pct + '%');
            summarise(d.results);
            if (!d.done) {
                runImport(d.offset);
            } else {
                importing = false;
                $('#sgy-import-status').html('<span style="color:#15803d">' + SGYConnect.i18n.done + '</span>');
                $('#sgy-import-btn').prop('disabled', false);
            }
        }).fail(function () {
            $('#sgy-import-status').html('<span style="color:#b91c1c">Request failed</span>');
            importing = false;
        });
    }

    var created = 0, updated = 0, errored = 0;
    function summarise(results) {
        (results || []).forEach(function (row) {
            if (row.result === 'created') { created++; }
            else if (row.result === 'updated') { updated++; }
            else if (row.result === 'error') { errored++; }
        });
        $('#sgy-import-summary').html(
            '<strong>Created:</strong> ' + created + ' &nbsp; ' +
            '<strong>Updated:</strong> ' + updated + ' &nbsp; ' +
            '<strong>Errors:</strong> ' + errored
        );
    }

    $('#sgy-import-btn').on('click', function () {
        if (importing) { return; }
        importing = true; created = 0; updated = 0; errored = 0;
        $(this).prop('disabled', true);
        $('#sgy-import-progress').show();
        $('#sgy-import-status').html(SGYConnect.i18n.importing);
        runImport(0);
    });

    // --- force sync ---------------------------------------------------------
    $('#sgy-force-sync').on('click', function () {
        var $out = $('#sgy-force-result').text('…');
        post('sgy_connect_force_sync').done(function (r) {
            $out.html(r && r.success
                ? '<span style="color:#15803d">✔ Recomputed</span>'
                : '<span style="color:#b91c1c">✗ Failed</span>');
            if (r && r.success) { setTimeout(function () { location.reload(); }, 800); }
        });
    });

    // --- Import from Surplus screen -----------------------------------------
    (function () {
        var $list = $('#sgy-surplus-list');
        if (!$list.length) { return; }
        var page = 1, search = '';

        function esc(s) { return $('<div>').text(s === null || s === undefined ? '' : s).html(); }

        function money(row) {
            if (row.store_currency && row.price_converted !== null && row.price_converted !== undefined) {
                return esc(row.store_currency + ' ' + row.price_converted) + ' <span class="sgy-gyd">(GYD ' + esc(row.price) + ')</span>';
            }
            return 'GYD ' + esc(row.price);
        }

        function card(row) {
            var img = (row.images && row.images[0]) ? '<img src="' + esc(row.images[0]) + '" alt="">' : '<div class="sgy-noimg"></div>';
            var badge = row.in_woo ? '<span class="sgy-badge sgy-badge-live">In your store</span>' : '';
            var label = row.in_woo ? 'Re-import' : 'Import';
            var cls = row.in_woo ? 'button' : 'button button-primary';
            var $c = $('<div class="sgy-surplus-card"></div>').data('row', row);
            $c.html(
                '<label class="sgy-card-check"><input type="checkbox" class="sgy-surplus-cb"></label>' +
                '<div class="sgy-surplus-thumb">' + img + '</div>' +
                '<div class="sgy-surplus-meta"><strong>' + esc(row.title) + '</strong>' +
                '<div class="sgy-surplus-price">' + money(row) + '</div>' +
                '<div class="sgy-surplus-sub">' + esc(row.category_title || '—') + ' · Stock ' + esc(row.stock) + '</div>' +
                badge + '</div>' +
                '<div class="sgy-surplus-actions"><button class="' + cls + ' sgy-surplus-import">' + label + '</button> <span class="sgy-surplus-status"></span></div>'
            );
            return $c;
        }

        function updateSelected() {
            var n = $list.find('.sgy-surplus-cb:checked').length;
            $('#sgy-surplus-import-selected').prop('disabled', n === 0).text(n ? 'Import selected (' + n + ')' : 'Import selected');
        }

        function renderPager(d) {
            var $p = $('#sgy-surplus-pager').empty();
            if (!d || d.last_page <= 1) { return; }
            function btn(lbl, target, disabled) {
                var $b = $('<button class="button sgy-page-btn">' + lbl + '</button>');
                if (disabled) { $b.prop('disabled', true); } else { $b.on('click', function () { page = target; load(); window.scrollTo(0, 0); }); }
                return $b;
            }
            $p.append(btn('« First', 1, d.page <= 1)).append(' ')
              .append(btn('‹ Prev', d.page - 1, d.page <= 1))
              .append(' <span class="sgy-page-info">Page ' + d.page + ' of ' + d.last_page + ' · ' + d.total + ' products</span> ')
              .append(btn('Next ›', d.page + 1, d.page >= d.last_page)).append(' ')
              .append(btn('Last »', d.last_page, d.page >= d.last_page));
        }

        function load() {
            $list.html('<p class="sgy-loading">Loading your Surplus products…</p>');
            $('#sgy-surplus-checkall').prop('checked', false);
            post('sgy_connect_surplus_fetch', { page: page, search: search }).done(function (r) {
                if (!r || !r.success) { $list.html('<div class="notice notice-error inline"><p>' + esc(r && r.data && r.data.message) + '</p></div>'); return; }
                var d = r.data;
                var $fx = $('#sgy-surplus-fxnote');
                if (d.fx_note) { $fx.find('p').text(d.fx_note); $fx.show(); } else { $fx.hide(); }
                $list.empty();
                if (!d.products || !d.products.length) { $list.html('<p class="sgy-empty">No products found.</p>'); }
                else { d.products.forEach(function (row) { $list.append(card(row)); }); }
                renderPager(d);
                updateSelected();
            });
        }

        function importRow($card, cb) {
            var row = $card.data('row');
            var $status = $card.find('.sgy-surplus-status').text('Importing…');
            var $btn = $card.find('.sgy-surplus-import').prop('disabled', true);
            post('sgy_connect_surplus_import', { row: JSON.stringify(row) }).done(function (r) {
                $btn.prop('disabled', false);
                if (r && r.success) { $status.text('Done'); $btn.text('Re-import').removeClass('button-primary'); $card.find('.sgy-surplus-cb').prop('checked', false); }
                else { $status.text((r && r.data && r.data.message) || 'Failed'); }
                if (cb) { cb(); }
            });
        }

        $(document).on('click', '.sgy-surplus-import', function () { importRow($(this).closest('.sgy-surplus-card')); });
        $(document).on('change', '.sgy-surplus-cb', updateSelected);
        $('#sgy-surplus-checkall').on('change', function () { $list.find('.sgy-surplus-cb').prop('checked', this.checked); updateSelected(); });
        $('#sgy-surplus-search-btn').on('click', function () { search = $('#sgy-surplus-search').val(); page = 1; load(); });
        $('#sgy-surplus-search').on('keydown', function (e) { if (e.which === 13) { e.preventDefault(); search = $(this).val(); page = 1; load(); } });

        // Import selected (this page): sequential, so a modest batch is reliable in the browser.
        $('#sgy-surplus-import-selected').on('click', function () {
            var $cards = $list.find('.sgy-surplus-cb:checked').closest('.sgy-surplus-card').toArray(), i = 0;
            var $btn = $(this).prop('disabled', true);
            (function next() {
                if (i >= $cards.length) { $btn.prop('disabled', false); updateSelected(); return; }
                importRow($($cards[i]), function () { i++; next(); });
            })();
        });

        // Import ALL: hands off to the background (Action Scheduler) job on the server so thousands of
        // products import reliably without the browser tab or a single request having to stay alive.
        var pollTimer = null;
        $('#sgy-surplus-import-all').on('click', function () {
            if (!window.confirm('Import ALL your Surplus products into WooCommerce? This runs in the background and can handle thousands — you can leave this page.')) { return; }
            var $btn = $(this).prop('disabled', true);
            $('#sgy-surplus-bulk-status').text('Starting…');
            post('sgy_connect_bulk_start').done(function (r) {
                $btn.prop('disabled', false);
                if (!r || !r.success) { $('#sgy-surplus-bulk-status').text((r && r.data && r.data.message) || 'Could not start.'); return; }
                $('#sgy-surplus-bulk-status').text('');
                $('#sgy-surplus-bulkprogress').show();
                startPoll();
            });
        });
        $('#sgy-surplus-bulk-cancel').on('click', function () { post('sgy_connect_bulk_cancel').done(function () { $('#sgy-bulk-text').text('Stopping…'); }); });

        function startPoll() { if (pollTimer) { clearInterval(pollTimer); } poll(); pollTimer = setInterval(poll, 3000); }
        function poll() {
            post('sgy_connect_bulk_progress').done(function (r) {
                if (!r || !r.success) { return; }
                var j = r.data || {};
                if (j.status === 'idle') { $('#sgy-surplus-bulkprogress').hide(); if (pollTimer) { clearInterval(pollTimer); } return; }
                var total = j.total || 0, done = (j.done || 0) + (j.errors || 0);
                var pct = total ? Math.min(100, Math.round((done / total) * 100)) : 0;
                $('#sgy-bulk-bar').css('width', pct + '%');
                var txt = 'Imported ' + (j.done || 0) + ' of ' + total + (j.errors ? ' (' + j.errors + ' skipped)' : '');
                if (j.status === 'done') { txt += ' — all done.'; if (pollTimer) { clearInterval(pollTimer); } load(); }
                else if (j.status === 'cancelled') { txt = 'Import stopped.'; if (pollTimer) { clearInterval(pollTimer); } }
                else { txt += '…'; }
                $('#sgy-bulk-text').text(txt);
            });
        }

        // Resume the progress view if a bulk import is already running when the page opens.
        post('sgy_connect_bulk_progress').done(function (r) {
            if (r && r.success && r.data && r.data.status === 'running') { $('#sgy-surplus-bulkprogress').show(); startPoll(); }
        });

        load();
    })();
})(jQuery);
