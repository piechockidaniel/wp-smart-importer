/**
 * WP Smart Importer — Admin JS v2 additions
 * Handles: Image config, WooCommerce Add-On, Function Editor, Dry Run
 */
(function ($) {
    'use strict';

    $(function () {
        bindImageSection();
        bindWooAddon();
        bindFunctionEditor();
        bindDryRun();
        bindPostTypeWatcher();
    });

    /* =========================================================
       Post type watcher — show/hide WooCommerce accordion
       ========================================================= */
    function bindPostTypeWatcher() {
        function checkWoo() {
            const pt = $('#wpsi-post-type').val();
            $('.wpsi-woo-accordion').toggle(pt === 'product' || pt === 'product_variation');
        }
        $('#wpsi-post-type').on('change', checkWoo);
        checkWoo();
    }

    /* =========================================================
       Image Section
       ========================================================= */
    function bindImageSection() {
        $(document).on('change', 'input[name="image_mode"]', function () {
            $('#wpsi-img-download-opts').toggle($(this).val() !== 'none');
        });
    }

    function collectImageConfig() {
        const mode = $('input[name="image_mode"]:checked').val() || 'none';
        const dedup = $('#wpsi-img-dedup-filename').is(':checked') ? 'filename'
                    : $('#wpsi-img-dedup-url').is(':checked') ? 'url' : 'none';
        return {
            mode             : mode,
            url_source       : $('#wpsi-img-url-source').val().trim(),
            url_separator    : $('#wpsi-img-separator').val() || ',',
            dedup            : dedup,
            set_featured     : $('#wpsi-img-set-featured').is(':checked'),
            keep_existing    : $('#wpsi-img-keep-existing').is(':checked'),
            draft_if_no_image: $('#wpsi-img-draft-no-image').is(':checked'),
            skip_thumbnails  : $('#wpsi-img-skip-thumbs').is(':checked'),
            meta: {
                title      : $('#wpsi-img-meta-title').val().trim(),
                alt        : $('#wpsi-img-meta-alt').val().trim(),
                caption    : $('#wpsi-img-meta-caption').val().trim(),
                description: $('#wpsi-img-meta-description').val().trim(),
            },
        };
    }

    function restoreImageConfig(cfg) {
        if (!cfg) return;
        $(`input[name="image_mode"][value="${cfg.mode||'none'}"]`).prop('checked', true).trigger('change');
        $('#wpsi-img-url-source').val(cfg.url_source || '');
        $('#wpsi-img-separator').val(cfg.url_separator || ',');
        $('#wpsi-img-dedup-url').prop('checked', (cfg.dedup||'url') === 'url');
        $('#wpsi-img-dedup-filename').prop('checked', cfg.dedup === 'filename');
        $('#wpsi-img-set-featured').prop('checked', cfg.set_featured !== false);
        $('#wpsi-img-keep-existing').prop('checked', cfg.keep_existing !== false);
        $('#wpsi-img-draft-no-image').prop('checked', !!cfg.draft_if_no_image);
        $('#wpsi-img-skip-thumbs').prop('checked', !!cfg.skip_thumbnails);
        const m = cfg.meta || {};
        ['title','alt','caption','description'].forEach(k => $(`#wpsi-img-meta-${k}`).val(m[k]||''));
    }
    window.wpsiCollectImageConfig  = collectImageConfig;
    window.wpsiRestoreImageConfig  = restoreImageConfig;

    /* =========================================================
       WooCommerce Add-On
       ========================================================= */
    function bindWooAddon() {
        $(document).on('click', '#wpsi-add-woo-attr', function () {
            addWooAttrRow();
        });
        $(document).on('click', '.wpsi-woo-attr-del', function () {
            $(this).closest('.wpsi-attr-row').remove();
        });
    }

    function addWooAttrRow(rule) {
        rule = rule || {};
        const idx = Date.now();
        const srcFields = (window.wpsiState && window.wpsiState.sourceFields) || [];
        let srcOpts = '<option value="">— source field —</option>';
        srcFields.forEach(f => { srcOpts += `<option value="${escHtml(f)}" ${f===rule.value?'selected':''}>${escHtml(f)}</option>`; });

        const row = `<div class="wpsi-attr-row" data-idx="${idx}">
            <input type="text" class="wpsi-attr-name" placeholder="pa_brand  or  _alg_ean" value="${escHtml(rule.name||'')}">
            <div class="wpsi-attr-val-wrap">
                <select class="wpsi-attr-src-type">
                    <option value="field" ${(rule.value_source||'field')==='field'?'selected':''}>field</option>
                    <option value="static" ${rule.value_source==='static'?'selected':''}>static</option>
                    <option value="template" ${rule.value_source==='template'?'selected':''}>template</option>
                    <option value="expression" ${rule.value_source==='expression'?'selected':''}>expression</option>
                </select>
                <select class="wpsi-attr-src-field">${srcOpts}</select>
                <input type="text" class="wpsi-attr-src-static" style="display:none;" placeholder="Static value" value="${escHtml(rule.value||'')}">
            </div>
            <div class="wpsi-attr-flags">
                <label><input type="checkbox" class="wpsi-attr-in-var" ${rule.in_variations?'checked':''}> In Variations</label>
                <label><input type="checkbox" class="wpsi-attr-visible" ${rule.is_visible!==false?'checked':''}> Visible</label>
                <label><input type="checkbox" class="wpsi-attr-taxonomy" ${rule.is_taxonomy!==false?'checked':''}> Taxonomy</label>
                <label><input type="checkbox" class="wpsi-attr-autocreate" ${rule.auto_create!==false?'checked':''}> Auto-Create</label>
            </div>
            <button type="button" class="wpsi-woo-attr-del wpsi-btn-del-row" title="Remove">✕</button>
        </div>`;

        $('#wpsi-woo-attrs').append(row);
        const $row = $(`[data-idx="${idx}"]`);
        $row.find('.wpsi-attr-src-type').on('change', function () {
            const t = $(this).val();
            $row.find('.wpsi-attr-src-field').toggle(t === 'field');
            $row.find('.wpsi-attr-src-static').toggle(t !== 'field');
        }).trigger('change');
    }

    function collectWooConfig() {
        const attrs = [];
        $('#wpsi-woo-attrs .wpsi-attr-row').each(function () {
            const $row   = $(this);
            const srcType = $row.find('.wpsi-attr-src-type').val();
            attrs.push({
                name         : $row.find('.wpsi-attr-name').val().trim(),
                value_source : srcType,
                value        : srcType === 'field'
                    ? $row.find('.wpsi-attr-src-field').val()
                    : $row.find('.wpsi-attr-src-static').val(),
                in_variations: $row.find('.wpsi-attr-in-var').is(':checked'),
                is_visible   : $row.find('.wpsi-attr-visible').is(':checked'),
                is_taxonomy  : $row.find('.wpsi-attr-taxonomy').is(':checked'),
                auto_create  : $row.find('.wpsi-attr-autocreate').is(':checked'),
            });
        });
        return {
            product_type    : $('#wpsi-woo-product-type').val(),
            price_cleanup   : { strip_currency: $('#wpsi-woo-strip-currency').is(':checked'), fix_decimal: $('#wpsi-woo-fix-decimal').is(':checked') },
            stock_auto      : $('#wpsi-woo-stock-auto').is(':checked'),
            stock_threshold : parseInt($('#wpsi-woo-stock-threshold').val(), 10) || 1,
            disable_auto_sku: $('#wpsi-woo-disable-auto-sku').is(':checked'),
            skip_dup_sku    : $('#wpsi-woo-skip-dup-sku').is(':checked'),
            attributes      : attrs,
        };
    }

    function restoreWooConfig(cfg) {
        if (!cfg) return;
        $('#wpsi-woo-product-type').val(cfg.product_type || 'simple');
        $('#wpsi-woo-strip-currency').prop('checked', cfg.price_cleanup?.strip_currency !== false);
        $('#wpsi-woo-fix-decimal').prop('checked', cfg.price_cleanup?.fix_decimal !== false);
        $('#wpsi-woo-stock-auto').prop('checked', cfg.stock_auto !== false);
        $('#wpsi-woo-stock-threshold').val(cfg.stock_threshold || 1);
        $('#wpsi-woo-disable-auto-sku').prop('checked', cfg.disable_auto_sku !== false);
        $('#wpsi-woo-skip-dup-sku').prop('checked', cfg.skip_dup_sku !== false);
        $('#wpsi-woo-attrs').empty();
        (cfg.attributes || []).forEach(attr => addWooAttrRow(attr));
    }
    window.wpsiCollectWooConfig  = collectWooConfig;
    window.wpsiRestoreWooConfig  = restoreWooConfig;

    /* =========================================================
       Function Editor
       ========================================================= */
    function bindFunctionEditor() {
        const $ta    = $('#wpsi-fn-code');
        const $lines = $('#wpsi-fn-lines');

        // Line numbers
        $ta.on('input keyup scroll', function () {
            const lines = $(this).val().split('\n').length;
            let html = '';
            for (let i = 1; i <= lines; i++) html += i + '\n';
            $lines.text(html);
            // Sync scroll
            $lines.scrollTop($ta.scrollTop());
        }).trigger('input');

        // Tab key → insert spaces
        $ta.on('keydown', function (e) {
            if (e.key === 'Tab') {
                e.preventDefault();
                const el  = this;
                const s   = el.selectionStart;
                const end = el.selectionEnd;
                el.value  = el.value.substring(0, s) + '    ' + el.value.substring(end);
                el.selectionStart = el.selectionEnd = s + 4;
                $(this).trigger('input');
            }
        });

        // Load starter
        $('#wpsi-fn-load-starter').on('click', function () {
            if ($ta.val().trim() && !confirm('Replace current code with the starter template?')) return;
            $.post(wpsiData.ajaxUrl, { action:'wpsi_load_function', nonce:wpsiData.nonce, job_id:0 })
            .done(res => { if (res.success) { $ta.val(res.data.code).trigger('input'); } });
        });

        // Template dropdown
        $('#wpsi-fn-template-select').on('change', function () {
            $('#wpsi-fn-load-template').prop('disabled', !$(this).val());
        });
        $('#wpsi-fn-load-template').on('click', function () {
            const name = $('#wpsi-fn-template-select').val();
            if (!name) return;
            $.post(wpsiData.ajaxUrl, { action:'wpsi_load_function_templates', nonce:wpsiData.nonce })
            .done(res => {
                if (res.success && res.data.templates[name]) {
                    $ta.val(res.data.templates[name]).trigger('input');
                }
            });
        });

        // Save as template
        $('#wpsi-fn-save-template').on('click', function () {
            const name = prompt('Template name:');
            if (!name) return;
            $.post(wpsiData.ajaxUrl, {
                action: 'wpsi_save_function_template', nonce: wpsiData.nonce,
                template_name: name, code: $ta.val()
            }).done(res => {
                if (res.success) {
                    refreshTemplateDropdown(res.data.templates);
                    alert('Template saved: ' + name);
                }
            });
        });

        // Load templates into dropdown
        refreshTemplatesOnOpen();
    }

    function refreshTemplatesOnOpen() {
        $.post(wpsiData.ajaxUrl, { action:'wpsi_load_function_templates', nonce:wpsiData.nonce })
        .done(res => { if (res.success) refreshTemplateDropdown(res.data.templates); });
    }

    function refreshTemplateDropdown(templates) {
        const $sel = $('#wpsi-fn-template-select');
        $sel.find('option:not(:first)').remove();
        Object.keys(templates).forEach(name => {
            $sel.append(`<option value="${escHtml(name)}">${escHtml(name)}</option>`);
        });
    }

    window.wpsiGetFunctionCode    = () => $('#wpsi-fn-code').val();
    window.wpsiSetFunctionCode    = (code) => { $('#wpsi-fn-code').val(code).trigger('input'); };

    /* =========================================================
       Dry Run
       ========================================================= */
    function bindDryRun() {
        // Open modal (from jobs list or wizard)
        $(document).on('click', '.wpsi-btn-dryrun', function () {
            const jobId = $(this).data('id') || 0;
            $('#wpsi-dryrun-modal, #wpsi-dryrun-overlay').show();
            $('#wpsi-dryrun-run').data('job-id', jobId).trigger('click');
        });

        $('#wpsi-dryrun-run').on('click', function () {
            const jobId = $(this).data('job-id') || 0;
            const limit = parseInt($('#wpsi-dryrun-limit').val(), 10) || 5;
            const $body = $('#wpsi-dryrun-body');

            $body.html('<span class="wpsi-spinner"></span> Running preview against live source…');

            let postData = { action:'wpsi_dry_run', nonce:wpsiData.nonce, job_id:jobId, limit:limit };

            // If no jobId, collect from wizard
            if (!jobId && typeof window.wpsiCollectJobForDryRun === 'function') {
                postData.job = JSON.stringify(window.wpsiCollectJobForDryRun());
            }

            $.post(wpsiData.ajaxUrl, postData)
            .done(function (res) {
                if (!res.success) { $body.html(`<div class="wpsi-notice wpsi-notice--error">❌ ${escHtml(res.data.message)}</div>`); return; }
                $body.html(buildDryRunHtml(res.data));
            })
            .fail(() => { $body.html('<div class="wpsi-notice wpsi-notice--error">❌ Network error.</div>'); });
        });

        $(document).on('click', '#wpsi-dryrun-overlay', function () {
            $('#wpsi-dryrun-modal, #wpsi-dryrun-overlay').hide();
        });
    }

    function buildDryRunHtml(data) {
        const s = data.stats || {};
        const statKeys = ['total','create','update','skip','locked','unchanged','error'];
        let html = '<div class="wpsi-dryrun-stats">';
        statKeys.forEach(k => {
            const n = s[k] || 0;
            html += `<div class="wpsi-dryrun-stat ${k}"><div class="n">${n}</div><div class="l">${k.charAt(0).toUpperCase()+k.slice(1)}</div></div>`;
        });
        html += `<div class="wpsi-dryrun-stat"><div class="n">${s.total || 0}</div><div class="l">in source</div></div>`;
        html += '</div>';

        if (data.errors && data.errors.length) {
            html += `<div class="wpsi-notice wpsi-notice--error">${data.errors.map(escHtml).join('<br>')}</div>`;
        }

        html += '<div class="wpsi-dryrun-records">';
        (data.results || []).forEach(r => {
            html += buildRecordCard(r);
        });
        html += '</div>';
        return html;
    }

    function buildRecordCard(r) {
        const badge = `<span class="wpsi-action-badge wpsi-action-badge--${r.action}">${r.action}</span>`;
        let html = `<div class="wpsi-dryrun-record">
            <div class="wpsi-dryrun-record__head">
                <span>#${r.record_num}</span> ${badge}
                <span>UID: <code>${escHtml(r.uid||'—')}</code></span>
                ${r.existing_id ? `<span style="color:#2271b1">→ update post #${r.existing_id}</span>` : ''}
                ${r.error ? `<span style="color:#d63638">⚠ ${escHtml(r.error)}</span>` : ''}
            </div>`;

        if (r.action !== 'error') {
            html += '<div class="wpsi-dryrun-record__body">';

            // Post data
            html += '<div class="wpsi-dryrun-section"><h5>Post Fields</h5>';
            Object.entries(r.post_data || {}).forEach(([k,v]) => {
                if (k==='ID') return;
                html += `<div class="wpsi-dryrun-kv"><span class="k">${escHtml(k)}</span><span class="v">${escHtml(String(v||'').substring(0,80))}</span></div>`;
            });
            html += '</div>';

            // Meta
            html += '<div class="wpsi-dryrun-section"><h5>Meta Fields</h5>';
            const metaEntries = Object.entries(r.meta || {});
            if (!metaEntries.length) { html += '<em style="color:#94a3b8">none</em>'; }
            metaEntries.slice(0,10).forEach(([k,v]) => {
                html += `<div class="wpsi-dryrun-kv"><span class="k">${escHtml(k)}</span><span class="v">${escHtml(String(v||'').substring(0,60))}</span></div>`;
            });
            if (metaEntries.length > 10) html += `<em style="color:#94a3b8">…+${metaEntries.length-10} more</em>`;
            html += '</div>';

            html += '</div>'; // record body

            // Images
            if ((r.images || []).length) {
                html += `<div style="padding:6px 12px; font-size:11px; border-top:1px solid #f0f0f0; color:#2271b1;">
                    🖼️ Images (${r.images.length}): ${r.images.map(u => `<a href="${escHtml(u)}" target="_blank" rel="noopener">${escHtml(u.substring(0,50))}…</a>`).join(', ')}
                </div>`;
            }

            // Taxonomies
            const taxEntries = Object.entries(r.tax || {});
            if (taxEntries.length) {
                html += `<div style="padding:6px 12px; font-size:11px; border-top:1px solid #f0f0f0;">
                    🏷️ Taxonomies: ${taxEntries.map(([t,v]) => `<strong>${escHtml(t)}</strong>: ${escHtml(Array.isArray(v)?v.join(', '):String(v))}`).join(' | ')}
                </div>`;
            }
        }

        html += '</div>';
        return html;
    }

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

})(jQuery);
