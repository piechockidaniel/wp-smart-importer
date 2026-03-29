<?php
/**
 * Additional Step 4 accordion sections for v2 features.
 * These are inserted into admin/views/wizard.php Step 4 panel.
 *
 * Sections added:
 *  1. Image Importer accordion
 *  2. WooCommerce Add-On accordion (visible only when post_type = product)
 *  3. Function Editor accordion
 */
defined( 'ABSPATH' ) || exit;
?>

<!-- ─── Image Importer ────────────────────────────────────── -->
<div class="wpsi-accordion">
    <div class="wpsi-accordion__head">
        🖼️ <?php esc_html_e( 'Images', 'wp-smart-importer' ); ?>
        <span class="wpsi-acc-toggle">▼</span>
    </div>
    <div class="wpsi-accordion__body" style="display:none;">

        <div class="wpsi-img-mode-opts">
            <label class="wpsi-check-label">
                <input type="radio" name="image_mode" value="none" checked>
                <?php esc_html_e( 'No image import', 'wp-smart-importer' ); ?>
            </label>
            <label class="wpsi-check-label">
                <input type="radio" name="image_mode" value="download">
                <?php esc_html_e( 'Download images hosted elsewhere', 'wp-smart-importer' ); ?>
            </label>
            <label class="wpsi-check-label">
                <input type="radio" name="image_mode" value="media_library">
                <?php esc_html_e( 'Use images currently in Media Library', 'wp-smart-importer' ); ?>
            </label>
        </div>

        <div id="wpsi-img-download-opts" class="wpsi-indent" style="display:none; margin-top:12px;">
            <table class="form-table wpsi-form-table" style="margin-top:0;">
                <tr>
                    <th><label for="wpsi-img-url-source"><?php esc_html_e( 'Image URL field / template', 'wp-smart-importer' ); ?></label></th>
                    <td>
                        <input type="text" id="wpsi-img-url-source" class="regular-text"
                               placeholder="image_url  or  {main_image},{gallery_1},{gallery_2}">
                        <p class="description"><?php esc_html_e( 'Dot-path to URL field, or a {template} with multiple URLs.', 'wp-smart-importer' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wpsi-img-separator"><?php esc_html_e( 'URL separator', 'wp-smart-importer' ); ?></label></th>
                    <td>
                        <input type="text" id="wpsi-img-separator" style="width:60px;" value=",">
                        <p class="description"><?php esc_html_e( 'Character separating multiple image URLs in one field.', 'wp-smart-importer' ); ?></p>
                    </td>
                </tr>
            </table>

            <h4 class="wpsi-settings-section"><?php esc_html_e( 'Image Options', 'wp-smart-importer' ); ?></h4>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:6px 20px;">
                <label class="wpsi-check-label">
                    <input type="checkbox" id="wpsi-img-dedup-url" checked>
                    <?php esc_html_e( 'Search Media Library for existing images (by URL)', 'wp-smart-importer' ); ?>
                </label>
                <label class="wpsi-check-label">
                    <input type="checkbox" id="wpsi-img-dedup-filename">
                    <?php esc_html_e( 'Also match by filename', 'wp-smart-importer' ); ?>
                </label>
                <label class="wpsi-check-label">
                    <input type="checkbox" id="wpsi-img-keep-existing" checked>
                    <?php esc_html_e( 'Keep existing images on update', 'wp-smart-importer' ); ?>
                </label>
                <label class="wpsi-check-label">
                    <input type="checkbox" id="wpsi-img-set-featured" checked>
                    <?php esc_html_e( 'Set first image as Featured Image (_thumbnail_id)', 'wp-smart-importer' ); ?>
                </label>
                <label class="wpsi-check-label">
                    <input type="checkbox" id="wpsi-img-draft-no-image">
                    <?php esc_html_e( 'Create entry as Draft if no images downloaded', 'wp-smart-importer' ); ?>
                </label>
                <label class="wpsi-check-label">
                    <input type="checkbox" id="wpsi-img-skip-thumbs">
                    <?php esc_html_e( 'Skip generating thumbnails (faster import)', 'wp-smart-importer' ); ?>
                </label>
            </div>

            <h4 class="wpsi-settings-section" style="margin-top:14px;"><?php esc_html_e( 'Image Metadata', 'wp-smart-importer' ); ?></h4>
            <table class="form-table wpsi-form-table" style="margin-top:0;">
                <?php foreach ( ['title' => 'Title', 'alt' => 'Alt Text', 'caption' => 'Caption', 'description' => 'Description'] as $k => $label ) : ?>
                <tr>
                    <th><?php echo esc_html( $label ); ?></th>
                    <td>
                        <input type="text" id="wpsi-img-meta-<?php echo esc_attr($k); ?>" class="regular-text"
                               placeholder="<?php esc_attr_e( 'field path or {template}', 'wp-smart-importer' ); ?>">
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div><!-- /#wpsi-img-download-opts -->

    </div><!-- /.wpsi-accordion__body (Images) -->
</div>

<!-- ─── WooCommerce Add-On ────────────────────────────────── -->
<div class="wpsi-accordion wpsi-woo-accordion" style="display:none;">
    <div class="wpsi-accordion__head">
        🛒 <?php esc_html_e( 'WooCommerce Add-On', 'wp-smart-importer' ); ?>
        <span class="wpsi-acc-toggle">▼</span>
    </div>
    <div class="wpsi-accordion__body" style="display:none;">

        <!-- Product Type -->
        <table class="form-table wpsi-form-table" style="margin-top:0;">
            <tr>
                <th><?php esc_html_e( 'Product Type', 'wp-smart-importer' ); ?></th>
                <td>
                    <select id="wpsi-woo-product-type">
                        <option value="simple"><?php esc_html_e( 'Simple product', 'wp-smart-importer' ); ?></option>
                        <option value="variable"><?php esc_html_e( 'Variable product', 'wp-smart-importer' ); ?></option>
                        <option value="grouped"><?php esc_html_e( 'Grouped product', 'wp-smart-importer' ); ?></option>
                        <option value="external"><?php esc_html_e( 'External/Affiliate', 'wp-smart-importer' ); ?></option>
                    </select>
                </td>
            </tr>
        </table>

        <h4 class="wpsi-settings-section"><?php esc_html_e( 'Price Options', 'wp-smart-importer' ); ?></h4>
        <label class="wpsi-check-label">
            <input type="checkbox" id="wpsi-woo-strip-currency" checked>
            <?php esc_html_e( 'Remove currency symbols from price (zł, €, $, PLN…)', 'wp-smart-importer' ); ?>
        </label>
        <label class="wpsi-check-label">
            <input type="checkbox" id="wpsi-woo-fix-decimal" checked>
            <?php esc_html_e( 'Normalise decimal separator (1.234,56 → 1234.56)', 'wp-smart-importer' ); ?>
        </label>

        <h4 class="wpsi-settings-section" style="margin-top:12px;"><?php esc_html_e( 'Stock Status', 'wp-smart-importer' ); ?></h4>
        <label class="wpsi-check-label">
            <input type="checkbox" id="wpsi-woo-stock-auto" checked>
            <?php esc_html_e( 'Set automatically — derive _stock_status from _stock quantity', 'wp-smart-importer' ); ?>
        </label>
        <div id="wpsi-woo-stock-threshold-wrap" class="wpsi-indent">
            <?php esc_html_e( 'Low stock threshold:', 'wp-smart-importer' ); ?>
            <input type="number" id="wpsi-woo-stock-threshold" value="1" min="0" style="width:70px;">
            <p class="description"><?php esc_html_e( 'qty ≥ threshold → instock; qty < threshold → outofstock', 'wp-smart-importer' ); ?></p>
        </div>

        <h4 class="wpsi-settings-section" style="margin-top:12px;"><?php esc_html_e( 'Product Attributes', 'wp-smart-importer' ); ?></h4>
        <p class="description"><?php esc_html_e( 'Add attribute rows. Use pa_ prefix for taxonomy-backed attributes.', 'wp-smart-importer' ); ?></p>

        <div id="wpsi-woo-attr-header" class="wpsi-attr-header">
            <span><?php esc_html_e( 'Attribute Name', 'wp-smart-importer' ); ?></span>
            <span><?php esc_html_e( 'Value / Source', 'wp-smart-importer' ); ?></span>
            <span><?php esc_html_e( 'Flags', 'wp-smart-importer' ); ?></span>
            <span></span>
        </div>
        <div id="wpsi-woo-attrs"></div>
        <button type="button" class="button" id="wpsi-add-woo-attr">
            + <?php esc_html_e( 'Add Attribute', 'wp-smart-importer' ); ?>
        </button>

        <h4 class="wpsi-settings-section" style="margin-top:12px;"><?php esc_html_e( 'Import Options', 'wp-smart-importer' ); ?></h4>
        <label class="wpsi-check-label">
            <input type="checkbox" id="wpsi-woo-disable-auto-sku" checked>
            <?php esc_html_e( 'Disable auto SKU generation', 'wp-smart-importer' ); ?>
        </label>
        <label class="wpsi-check-label">
            <input type="checkbox" id="wpsi-woo-skip-dup-sku" checked>
            <?php esc_html_e( "Don't check for duplicate SKUs", 'wp-smart-importer' ); ?>
        </label>

    </div><!-- /.wpsi-accordion__body (WooCommerce) -->
</div>

<!-- ─── Function Editor ───────────────────────────────────── -->
<div class="wpsi-accordion">
    <div class="wpsi-accordion__head">
        ⚙️ <?php esc_html_e( 'Function Editor', 'wp-smart-importer' ); ?>
        <span class="wpsi-acc-toggle">▼</span>
    </div>
    <div class="wpsi-accordion__body" style="display:none;">

        <p class="description">
            <?php esc_html_e( 'Write PHP code that runs during each import. Use the ', 'wp-smart-importer' ); ?>
            <code>wpsi_transform_record</code>
            <?php esc_html_e( ' filter to modify mapped data before it is saved.', 'wp-smart-importer' ); ?>
        </p>

        <div class="wpsi-fn-toolbar">
            <button type="button" class="button" id="wpsi-fn-load-starter">
                📄 <?php esc_html_e( 'Load Starter Template', 'wp-smart-importer' ); ?>
            </button>
            <select id="wpsi-fn-template-select">
                <option value=""><?php esc_html_e( '— Load saved template —', 'wp-smart-importer' ); ?></option>
            </select>
            <button type="button" class="button" id="wpsi-fn-load-template" disabled>
                <?php esc_html_e( 'Load', 'wp-smart-importer' ); ?>
            </button>
            <button type="button" class="button" id="wpsi-fn-save-template">
                💾 <?php esc_html_e( 'Save as Template', 'wp-smart-importer' ); ?>
            </button>
        </div>

        <div class="wpsi-fn-editor-wrap">
            <div class="wpsi-fn-editor-header">
                <span class="wpsi-fn-line-numbers" id="wpsi-fn-lines">1</span>
                <textarea id="wpsi-fn-code" class="wpsi-fn-textarea" rows="18" spellcheck="false"
                    placeholder="<?php esc_attr_e( '// Write your PHP here — no opening <?php tag needed', 'wp-smart-importer' ); ?>"></textarea>
            </div>
        </div>

        <div class="wpsi-fn-ref">
            <strong><?php esc_html_e( 'Available variables inside the filter:', 'wp-smart-importer' ); ?></strong>
            <code>$mapped['post_data']</code> — WP post fields &nbsp;|&nbsp;
            <code>$mapped['meta']</code> — post meta &nbsp;|&nbsp;
            <code>$mapped['tax']</code> — taxonomy terms &nbsp;|&nbsp;
            <code>$record</code> — raw source record &nbsp;|&nbsp;
            <code>$post_id</code> — 0 for new, ID for update &nbsp;|&nbsp;
            <code>WPSI_JOB_ID</code>, <code>WPSI_DRY_RUN</code>
        </div>

    </div><!-- /.wpsi-accordion__body (Function Editor) -->
</div>
