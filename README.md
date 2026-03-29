# WP Smart Importer — Development Repository

**VPS path:** `/home/admin/wp-smart-importer/`  
**Version:** 2.0.0

## How to get the files to your Windows machine

### Option 1 — SCP (from your Windows terminal / WSL)
```bash
scp -r user@YOUR_VPS_IP:/home/admin/wp-smart-importer/ "C:\Users\user\source\repos\WP Smart Import\"
```

### Option 2 — Git (recommended for persistent sync)
```bash
# On VPS
cd /home/admin/wp-smart-importer
git init
git add -A
git commit -m "Initial commit"
git remote add origin https://github.com/YOUR_REPO.git
git push -u origin main

# On Windows
git clone https://github.com/YOUR_REPO.git "C:\Users\user\source\repos\WP Smart Import"
```

### Option 3 — SFTP (FileZilla etc.)
Connect to VPS via SFTP, navigate to `/home/admin/wp-smart-importer/`

## File structure
```
wp-smart-importer/
├── wp-smart-importer.php          # Plugin bootstrap
├── includes/
│   ├── class-database.php         # DB schema + CRUD
│   ├── class-parser.php           # JSON/XML/CSV parsers
│   ├── class-enum-registry.php    # Known WP/WC enum values
│   ├── class-expression-evaluator.php  # Safe expression engine
│   ├── class-mapper.php           # Field mapping engine
│   ├── class-sync-lock.php        # Per-product sync lock
│   ├── class-function-editor.php  # PHP function editor (no eval)
│   ├── class-image-importer.php   # Image download + media library
│   ├── class-woo-addon.php        # WooCommerce product fields
│   ├── class-taxonomy-importer.php # Hierarchical taxonomy import
│   ├── class-dry-run.php          # Preview without DB writes
│   ├── class-importer.php         # Main import orchestrator
│   ├── class-scheduler.php        # WP-Cron scheduler
│   ├── class-admin.php            # Admin UI + AJAX
│   └── class-admin-additions.php  # AJAX for function editor/dry-run
└── admin/
    ├── css/
    │   ├── admin.css              # Main styles
    │   └── admin-v2-additions.css # v2 feature styles
    ├── js/
    │   ├── admin.js               # Main wizard JS
    │   └── admin-v2-additions.js  # v2 features JS
    └── views/
        ├── jobs-list.php
        ├── wizard.php
        ├── wizard-step4-additions.php   # Image/WooCommerce/Function Editor panels
        └── dry-run-modal.php
```
