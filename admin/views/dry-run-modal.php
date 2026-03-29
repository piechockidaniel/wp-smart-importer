<?php defined( 'ABSPATH' ) || exit; ?>
<!-- Dry Run Modal -->
<div id="wpsi-dryrun-modal" class="wpsi-modal wpsi-modal--wide" style="display:none;">
    <div class="wpsi-modal__inner">
        <div class="wpsi-dryrun-header">
            <h2>🔍 <?php esc_html_e( 'Import Preview (Dry Run)', 'wp-smart-importer' ); ?></h2>
            <div class="wpsi-dryrun-controls">
                <label>
                    <?php esc_html_e( 'Sample records:', 'wp-smart-importer' ); ?>
                    <input type="number" id="wpsi-dryrun-limit" value="5" min="1" max="20" style="width:60px;">
                </label>
                <button class="button button-primary" id="wpsi-dryrun-run">▶ Run Preview</button>
                <button class="button wpsi-modal-close">✕ Close</button>
            </div>
        </div>

        <div id="wpsi-dryrun-body">
            <p class="wpsi-dryrun-hint">
                <?php esc_html_e( 'Fetches real data from the source URL, runs the full mapping pipeline, but does NOT write anything to the database.', 'wp-smart-importer' ); ?>
            </p>
        </div>

    </div>
</div>
<div id="wpsi-dryrun-overlay" class="wpsi-modal-overlay" style="display:none;"></div>
