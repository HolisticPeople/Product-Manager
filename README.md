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

This repository reuses the HolisticPeople organization secrets:

| Secret | Purpose |
| ------ | ------- |
| `KINSTA_HOST`, `KINSTA_PORT`, `KINSTA_USER`, `KINSTA_SSH_KEY` | Staging SSH connection |
| `KINSTA_PLUGINS_BASE` | Base staging plugins directory (workflow appends `/products-manager`) |
| `KINSTAPROD_HOST`, `KINSTAPROD_PORT`, `KINSTAPROD_USER`, `KINSTAPROD_SSH_KEY` | Production SSH connection |
| `KINSTAPROD_PLUGINS_BASE` | Base production plugins directory (workflow appends `/products-manager`) |

Ensure the associated public SSH keys are installed on both Kinsta environments.
