# Staging SSH Runbook (Use Existing Keys)

This machine already has working SSH keys and aliases configured. Do **not** create or modify keys. Use the existing alias to connect and run commands.

## 1) How to connect (no setup required)

Alias: `kinsta-staging` (alt: `wc-staging`)

Connect (interactive shell):
```
ssh kinsta-staging
```
Run a single command remotely (preferred pattern for automation):
```
ssh kinsta-staging "cd public && pwd"
```
> Always start commands in `public` (the WordPress root on staging).

## 2) Where the keys/config live (read‑only)

- Windows path for keys/config on this workstation: `C:\Users\user\.ssh\`
  - SSH config with aliases: `config` (contains `Host kinsta-staging` using `IdentityFile ~/.ssh/kinsta_staging_key`)
  - Staging key file: `kinsta_staging_key` (private; do not print or share)
  - Additional keys present: `id_ed25519`, `id_ed25519.pub`, `id_ed25519_kinsta_ci`, `id_ed25519_kinsta_ci.pub`, `id_ed25519_kinsta_prod_ci`, `id_ed25519_kinsta_prod_ci.pub`
  - Host keys cache: `known_hosts`, `known_hosts.old`
> These are pre‑configured. If the alias fails, stop and ask—do **not** rotate keys.

## 3) Useful paths on the remote

- WP root: `public`
- Logs: `public/wp-content/debug.log`
- EAO plugin: `public/wp-content/plugins/enhanced-admin-order-plugin`
- YITH plugin: `public/wp-content/plugins/yith-woocommerce-points-and-rewards-premium`

## 4) One‑liners for common tasks

Check EAO version deployed:
```
ssh kinsta-staging "cd public && head -30 wp-content/plugins/enhanced-admin-order-plugin/enhanced-admin-order-plugin.php | grep -E 'Version:|EAO_PLUGIN_VERSION'"
```

Tail points-related logs for a specific order (replace ORDER_ID):
```
ssh kinsta-staging "cd public && tail -1200 wp-content/debug.log | grep -Ei 'EAO Points|EAO YITH|ORDER_ID' | tail -400"
```

Clear debug.log (only when requested):
```
ssh kinsta-staging "cd public && rm -f wp-content/debug.log"
```

Check EAO expected award meta (replace ORDER_ID):
```
ssh kinsta-staging "cd public && wp post meta get ORDER_ID _eao_points_expected_award --allow-root 2>/dev/null || echo 'no _eao_points_expected_award meta'"
```

Check WooCommerce order status (replace ORDER_ID):
```
ssh kinsta-staging "cd public && wp wc order get ORDER_ID --field=status --allow-root 2>/dev/null || wp post get ORDER_ID --field=post_status --allow-root 2>/dev/null"
```

Inspect YITH award hooks quickly:
```
ssh kinsta-staging "cd public/wp-content/plugins/yith-woocommerce-points-and-rewards-premium && grep -RIn 'add_order_points' includes | cat"
```

## 5) Do / Do not

Do:
- Use `ssh kinsta-staging "cd public && ..."` for all remote commands.
- Keep commands idempotent and read‑only unless told otherwise.
- Capture relevant log slices before clearing logs.

Do not:
- Modify `C:\Users\user\.ssh\` keys or `C:\Users\user\.ssh\config` on this workstation.
- Run destructive commands on production. This alias is for **staging**.

## 6) Quick template for diagnostics

Replace `ORDER_ID` and paste:
```
ssh kinsta-staging "cd public && \
  head -30 wp-content/plugins/enhanced-admin-order-plugin/enhanced-admin-order-plugin.php | grep -E 'Version:|EAO_PLUGIN_VERSION' && \
  echo '--- LOG SLICE ---' && tail -1200 wp-content/debug.log | grep -Ei 'EAO Points|EAO YITH|ORDER_ID' | tail -400 && \
  echo '--- EXPECTED AWARD ---' && wp post meta get ORDER_ID _eao_points_expected_award --allow-root 2>/dev/null || true && \
  echo '--- ORDER STATUS ---' && (wp wc order get ORDER_ID --field=status --allow-root 2>/dev/null || wp post get ORDER_ID --field=post_status --allow-root 2>/dev/null)"
```

> If any command errors, paste the full output into the ticket and stop for guidance.
