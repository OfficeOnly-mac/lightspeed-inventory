<?php
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Unauthorized.' );
}
?>
<?php if ( empty( $lic_in_tab ) ) : ?><div class="wrap"><?php lic_sync_disabled_notice(); ?>
    <h1>Image Cleanup</h1>
<?php endif; ?>

    <p>Scan the media library for plugin-downloaded images that are no longer attached to any active product. Images still in use elsewhere — another product's gallery, or placed directly on a page or post — are automatically excluded from these results. Review before deleting.</p>

    <p>
        <button id="lic-ic-scan" class="button button-primary">Scan for Orphaned Images</button>
        <span id="lic-ic-scan-msg" style="margin-left:10px;font-size:13px;"></span>
    </p>

    <div id="lic-ic-results" style="display:none;margin-top:20px;">

        <!-- Summary -->
        <div id="lic-ic-summary" style="background:#f9f9f9;border:1px solid #ddd;border-radius:4px;padding:12px 16px;margin-bottom:16px;display:flex;gap:32px;flex-wrap:wrap;">
            <span><strong id="lic-ic-total">0</strong> orphaned images</span>
            <span>Tier 1 (tracked): <strong id="lic-ic-t1">0</strong></span>
            <span>Tier 2 (untracked): <strong id="lic-ic-t2">0</strong></span>
            <span>Total size: <strong id="lic-ic-size">0 MB</strong></span>
        </div>

        <!-- Bulk controls -->
        <div style="margin-bottom:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <button class="button" id="lic-ic-sel-t1">Select All Tier 1</button>
            <button class="button" id="lic-ic-sel-t2">Select All Tier 2</button>
            <button class="button" id="lic-ic-desel-all">Deselect All</button>
            <span style="flex:1;"></span>
            <button class="button button-primary" id="lic-ic-delete" disabled>Delete Selected (0)</button>
            <span id="lic-ic-delete-msg" style="font-size:13px;"></span>
        </div>

        <!-- Table -->
        <table class="widefat fixed striped" id="lic-ic-table">
            <thead>
                <tr>
                    <th style="width:32px;"><input type="checkbox" id="lic-ic-sel-all" title="Select/deselect all on this page"></th>
                    <th style="width:80px;">Preview</th>
                    <th>Filename</th>
                    <th>Source URL</th>
                    <th style="width:80px;">Size</th>
                    <th style="width:60px;">Tier</th>
                    <th style="width:70px;"></th>
                </tr>
            </thead>
            <tbody id="lic-ic-tbody"></tbody>
        </table>

        <!-- Pagination -->
        <div id="lic-ic-pagination" style="margin-top:12px;display:flex;gap:6px;align-items:center;">
            <button class="button" id="lic-ic-prev">&laquo; Prev</button>
            <span id="lic-ic-page-info" style="font-size:13px;"></span>
            <button class="button" id="lic-ic-next">Next &raquo;</button>
        </div>

        <p style="color:#888;font-size:12px;margin-top:8px;">
            <strong>Tier 1</strong> — has <code>_lic_source_url</code> meta (definitively plugin-downloaded). &nbsp;
            <strong>Tier 2</strong> — no source URL meta, parented to a product (likely pre-C-15 downloads; use caution).
        </p>
    </div>

<?php if ( empty( $lic_in_tab ) ) : ?></div><?php endif; ?>

<script>
jQuery(document).ready(function($) {
    var PER_PAGE   = 50;
    var allImages  = [];   // full scan results
    var currentPage = 1;

    function formatSize(bytes) {
        if (!bytes) return '—';
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
        if (bytes >= 1024)    return (bytes / 1024).toFixed(0) + ' KB';
        return bytes + ' B';
    }

    function totalPages() {
        return Math.max(1, Math.ceil(allImages.length / PER_PAGE));
    }

    function pageImages() {
        var start = (currentPage - 1) * PER_PAGE;
        return allImages.slice(start, start + PER_PAGE);
    }

    function checkedIds() {
        var ids = [];
        $('#lic-ic-tbody input[type=checkbox]:checked').each(function() {
            ids.push(parseInt($(this).val(), 10));
        });
        return ids;
    }

    function updateDeleteBtn() {
        var n = checkedIds().length;
        $('#lic-ic-delete').prop('disabled', n === 0).text('Delete Selected (' + n + ')');
    }

    function renderPage() {
        var imgs = pageImages();
        var rows = '';
        imgs.forEach(function(img) {
            var thumb = img.thumb_url
                ? '<img src="' + img.thumb_url + '" width="60" height="45" style="object-fit:cover;border-radius:2px;">'
                : '<span style="color:#aaa;font-size:11px;">No preview</span>';
            var srcUrl = img.source_url
                ? '<span title="' + $('<span>').text(img.source_url).html() + '" style="font-size:11px;">' + $('<span>').text(img.source_url.replace(/^https?:\/\/[^/]+/, '').substring(0, 40) + '…').html() + '</span>'
                : '<span style="color:#aaa;">—</span>';
            var tier_badge = img.tier === 1
                ? '<span style="background:#e6f0ff;color:#0063a5;padding:1px 5px;border-radius:3px;font-size:11px;">T1</span>'
                : '<span style="background:#fff3cd;color:#7a5a00;padding:1px 5px;border-radius:3px;font-size:11px;">T2</span>';
            var checked = img.tier === 1 ? ' checked' : '';
            rows += '<tr id="lic-ic-row-' + img.id + '">'
                + '<td><input type="checkbox" class="lic-ic-cb" value="' + img.id + '"' + checked + '></td>'
                + '<td>' + thumb + '</td>'
                + '<td style="word-break:break-all;font-size:12px;">' + $('<span>').text(img.filename).html() + '</td>'
                + '<td>' + srcUrl + '</td>'
                + '<td style="font-size:12px;">' + formatSize(img.file_size) + '</td>'
                + '<td>' + tier_badge + '</td>'
                + '<td><button class="button button-small lic-ic-ignore" data-id="' + img.id + '">Ignore</button></td>'
                + '</tr>';
        });
        $('#lic-ic-tbody').html(rows || '<tr><td colspan="7">No images on this page.</td></tr>');
        $('#lic-ic-page-info').text('Page ' + currentPage + ' of ' + totalPages() + ' (' + allImages.length + ' total)');
        $('#lic-ic-prev').prop('disabled', currentPage <= 1);
        $('#lic-ic-next').prop('disabled', currentPage >= totalPages());
        $('#lic-ic-sel-all').prop('checked', false).prop('indeterminate', false);
        updateDeleteBtn();
    }

    function showResults(data) {
        $('#lic-ic-total').text(data.total);
        $('#lic-ic-t1').text(data.tier1_count);
        $('#lic-ic-t2').text(data.tier2_count);
        $('#lic-ic-size').text(formatSize(data.total_size));
        allImages  = data.images;
        currentPage = 1;
        $('#lic-ic-results').show();
        renderPage();
    }

    // Scan
    $('#lic-ic-scan').on('click', function() {
        var $btn = $(this).prop('disabled', true).text('Scanning…');
        $('#lic-ic-scan-msg').text('Checking media library — please wait…').css('color', '#888');
        $('#lic-ic-results').hide();

        $.post(lightspeed_connector_ajax.ajaxurl, {
            action: 'lic_scan_orphaned_images',
            nonce:  lightspeed_connector_ajax.nonce
        }, function(r) {
            $btn.prop('disabled', false).text('Scan for Orphaned Images');
            if (!r.success) {
                $('#lic-ic-scan-msg').text('Scan failed: ' + (r.data || 'unknown error')).css('color', '#b32d2e');
                return;
            }
            var msg = r.data.total === 0
                ? 'No orphaned images found.'
                : r.data.total + ' orphaned image' + (r.data.total !== 1 ? 's' : '') + ' found.';
            $('#lic-ic-scan-msg').text(msg).css('color', r.data.total > 0 ? '#2a6b2a' : '#444');
            showResults(r.data);
        }).fail(function() {
            $btn.prop('disabled', false).text('Scan for Orphaned Images');
            $('#lic-ic-scan-msg').text('Request failed.').css('color', '#b32d2e');
        });
    });

    // Pagination
    $('#lic-ic-prev').on('click', function() {
        if (currentPage > 1) { currentPage--; renderPage(); }
    });
    $('#lic-ic-next').on('click', function() {
        if (currentPage < totalPages()) { currentPage++; renderPage(); }
    });

    // Select-all on current page
    $('#lic-ic-sel-all').on('change', function() {
        var checked = $(this).is(':checked');
        $('#lic-ic-tbody .lic-ic-cb').prop('checked', checked);
        updateDeleteBtn();
    });

    // Checkbox change
    $(document).on('change', '.lic-ic-cb', function() {
        var all  = $('#lic-ic-tbody .lic-ic-cb').length;
        var chkd = $('#lic-ic-tbody .lic-ic-cb:checked').length;
        var $sel = $('#lic-ic-sel-all');
        $sel.prop('checked', chkd === all).prop('indeterminate', chkd > 0 && chkd < all);
        updateDeleteBtn();
    });

    // Select All Tier 1 (across all pages)
    $('#lic-ic-sel-t1').on('click', function() {
        allImages.forEach(function(img) { if (img.tier === 1) img._checked = true; });
        $('#lic-ic-tbody .lic-ic-cb').each(function() {
            var id = parseInt($(this).val(), 10);
            var img = allImages.find(function(i) { return i.id === id; });
            if (img && img.tier === 1) $(this).prop('checked', true);
        });
        updateDeleteBtn();
    });

    // Select All Tier 2 (across all pages)
    $('#lic-ic-sel-t2').on('click', function() {
        $('#lic-ic-tbody .lic-ic-cb').each(function() {
            var id = parseInt($(this).val(), 10);
            var img = allImages.find(function(i) { return i.id === id; });
            if (img && img.tier === 2) $(this).prop('checked', true);
        });
        updateDeleteBtn();
    });

    // Deselect All
    $('#lic-ic-desel-all').on('click', function() {
        $('#lic-ic-tbody .lic-ic-cb').prop('checked', false);
        updateDeleteBtn();
    });

    // Ignore
    $(document).on('click', '.lic-ic-ignore', function() {
        var $btn = $(this).prop('disabled', true).text('…');
        var id   = parseInt($btn.data('id'), 10);
        $.post(lightspeed_connector_ajax.ajaxurl, {
            action:        'lic_ignore_orphaned_image',
            nonce:         lightspeed_connector_ajax.nonce,
            attachment_id: id
        }, function(r) {
            if (r.success) {
                $('#lic-ic-row-' + id).fadeOut(300, function() {
                    $(this).remove();
                    allImages = allImages.filter(function(i) { return i.id !== id; });
                    $('#lic-ic-total').text(allImages.length);
                    $('#lic-ic-t1').text(allImages.filter(function(i){ return i.tier===1; }).length);
                    $('#lic-ic-t2').text(allImages.filter(function(i){ return i.tier===2; }).length);
                    updateDeleteBtn();
                });
            } else {
                $btn.prop('disabled', false).text('Ignore');
            }
        });
    });

    // Delete Selected
    $('#lic-ic-delete').on('click', function() {
        var ids = checkedIds();
        if (!ids.length) return;
        if (!confirm('Permanently delete ' + ids.length + ' image' + (ids.length !== 1 ? 's' : '') + '? This cannot be undone.')) return;

        var $btn = $(this).prop('disabled', true).text('Deleting…');
        $('#lic-ic-delete-msg').text('').css('color', '');

        $.post(lightspeed_connector_ajax.ajaxurl, {
            action: 'lic_delete_orphaned_images',
            nonce:  lightspeed_connector_ajax.nonce,
            ids:    JSON.stringify(ids)
        }, function(r) {
            $btn.prop('disabled', false).text('Delete Selected (0)');
            if (!r.success) {
                $('#lic-ic-delete-msg').text(r.data || 'Delete failed.').css('color', '#b32d2e');
                return;
            }
            var d = r.data;
            var msg = d.deleted + ' deleted';
            if (d.skipped) msg += ', ' + d.skipped + ' skipped';
            if (d.errors)  msg += ', ' + d.errors + ' errors';
            $('#lic-ic-delete-msg').text(msg).css('color', d.errors ? '#b32d2e' : '#2a6b2a');

            // Remove deleted rows from the live list and re-render.
            var deletedSet = {};
            ids.forEach(function(id) { deletedSet[id] = true; });
            allImages = allImages.filter(function(img) { return !deletedSet[img.id]; });
            $('#lic-ic-total').text(allImages.length);
            $('#lic-ic-t1').text(allImages.filter(function(i){ return i.tier===1; }).length);
            $('#lic-ic-t2').text(allImages.filter(function(i){ return i.tier===2; }).length);
            if (currentPage > totalPages()) currentPage = totalPages();
            renderPage();
        }).fail(function() {
            $btn.prop('disabled', false);
            $('#lic-ic-delete-msg').text('Request failed.').css('color', '#b32d2e');
        });
    });
});
</script>
