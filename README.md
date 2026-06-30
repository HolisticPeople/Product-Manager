# Products Manager

Lightweight WooCommerce admin helper that adds a blue **Products** quick-access button next to the _Inventory_ action in the admin toolbar. The plugin only loads on wp-admin to avoid front-end overhead.

## Development

1. Clone the repository into `wp-content/plugins/products-manager`.
2. Use the `dev` branch for staging-ready work; merge to `main` only when production-ready.
3. Run `composer install` or `npm install` if and when those toolchains are introduced (none required yet).

## Deployment

- Pushes to `dev` trigger the staging deploy workflow.
- Manual `workflow_dispatch` runs can target either staging or production.
- Production deploys are manual-only (run the workflow from GitHub).

### Required GitHub secrets

This repository reuses the HolisticPeople organization secrets:

| Secret | Purpose |
| ------ | ------- |
| `KINSTA_HOST`, `KINSTA_PORT`, `KINSTA_USER`, `KINSTA_SSH_KEY` | Staging SSH connection |
| `KINSTA_PLUGINS_BASE` | Base staging plugins directory (workflow appends `/products-manager`) |
| `KINSTAPROD_HOST`, `KINSTAPROD_PORT`, `KINSTAPROD_USER`, `KINSTAPROD_SSH_KEY` | Production SSH connection |
| `KINSTAPROD_PLUGINS_BASE` | Base production plugins directory (workflow appends `/products-manager`) |

Ensure the associated public SSH keys are installed on both Kinsta environments.

## Runtime Requirements

- PHP 8.5+

## Release Notes

### 2.0.9

- Move Old2New product replacement packets and `[old2new_product_block]` ownership into Product Manager.
- Add the Old2New admin tab with editable packet records, product cards, status, redirect type, and stock labels.

### 2.0.8

- Cover list-backed product search inputs without explicit input types and stabilize wrapped product-detail tabs under HP-Zen styling.

### 2.0.7

- Align Product Manager product-edit tabs, inputs, selects, and filter controls with the HP-Zen admin runtime instead of hard-coded light backgrounds.

### 2.0.6

- Guard legacy ERP schema installs with a stored schema version to avoid repeated `dbDelta()` ALTER work on admin requests.

### 2.0.5

- Prevent the Products Manager filter form from leaving the admin page when search is submitted with Enter.

### 2.0.4

- Keep the Products toolbar shortcut positioned after the renamed Inventory button while preserving rollout compatibility with the old Create New Order label.

### 2.0.3

- Bound Product Manager apply-endpoint enum fields to known WooCommerce status, visibility, backorder, and tax-status values.
- Retire legacy ERP movement persistence and rebuild endpoints once HP Inventory marks Product-Manager demand history as migrated.

### 2.0.2

- Opts Product Manager list and product detail admin screens into the shared HP-Zen admin runtime.

### 2.0.1

- Harden Product Manager admin rendering so catalog and ERP values are escaped or assigned through text/attribute APIs instead of concatenated into HTML.

### 2.0.0

- Major production promotion for the PHP 8.5 baseline and staged Product Manager compatibility work.
