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
})(jQuery);
