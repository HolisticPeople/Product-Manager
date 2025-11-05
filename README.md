# Products Manager

Lightweight WooCommerce admin helper that adds a blue **Products** quick-access button next to the _Create New Order_ action on the Orders Summary screen. The plugin only loads on wp-admin to avoid front-end overhead.

## Development

1. Clone the repository into `wp-content/plugins/products-manager`.
2. Use the `dev` branch for staging-ready work; merge to `main` only when production-ready.
3. Run `composer install` or `npm install` if and when those toolchains are introduced (none required yet).

## Deployment

- Pushes to `dev` trigger the staging deploy workflow.
- Manual `workflow_dispatch` runs can target either staging or production.
- Production deploys are manual-only (run the workflow from GitHub).

### Required GitHub secrets

| Secret | Purpose |
| ------ | ------- |
| `KINSTA_PM_HOST` / `KINSTA_PM_PORT` / `KINSTA_PM_USER` | SSH connection info for the staging server |
| `KINSTA_PM_SSH_KEY` | Private key for staging deployment |
| `KINSTA_PM_PLUGIN_PATH` | Remote plugin directory for staging (e.g. `/www/holisticpeople/shared/plugins/products-manager`) |
| `KINSTAPROD_PM_HOST` / `KINSTAPROD_PM_PORT` / `KINSTAPROD_PM_USER` | SSH connection info for production |
| `KINSTAPROD_PM_SSH_KEY` | Private key for production deployment |
| `KINSTAPROD_PM_PLUGIN_PATH` | Remote plugin directory for production |

Be sure to add the corresponding public key to each Kinsta instance and allow SSH access.
