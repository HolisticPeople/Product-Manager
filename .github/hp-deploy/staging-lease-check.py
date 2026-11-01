#!/usr/bin/env python3
"""Host-side staging lease check for the HP-Card staging deploy.

Run on the staging host over SSH (stdin):
    ssh <staging> 'python3 - --plugin hp-card' < .github/hp-deploy/staging-lease-check.py

Reads ~/private/staging-leases/*.json (the lease format written by
HP-Codex-Skills skills/hp-staging-implementation/scripts/staging-lease.py)
and prints one JSON object. Exit 0 = free, 3 = blocked by an unexpired lease
(any owner) that names the plugin. A missing directory is free. An unreadable
lease file blocks (fail closed). Expiry allows the same 2-minute clock-skew
grace as the lease tool. Stdlib only; read-only.
"""

import argparse
import datetime as dt
import json
import os
import re
import sys

LEASE_DIR = "~/private/staging-leases"
SKEW_SECONDS = 120
PLUGIN_RE = re.compile(r"^[a-z0-9][a-z0-9_-]{0,63}$")


def parse_iso(value):
    text = str(value).strip()
    if text.endswith("Z"):
        text = text[:-1] + "+00:00"
    parsed = dt.datetime.fromisoformat(text)
    if parsed.tzinfo is None:
        raise ValueError("timestamp has no timezone")
    return parsed.astimezone(dt.timezone.utc)


def evaluate(lease_dir, plugin, now):
    blocking = []
    if os.path.isdir(lease_dir):
        for name in sorted(os.listdir(lease_dir)):
            if not name.endswith(".json") or name.startswith("."):
                continue
            path = os.path.join(lease_dir, name)
            try:
                with open(path, "r", encoding="utf-8") as handle:
                    lease = json.load(handle)
                expires = parse_iso(lease["expires_at"])
                plugins = lease.get("plugins") or []
                if not isinstance(plugins, list):
                    raise ValueError("plugins is not a list")
            except Exception as exc:  # noqa: BLE001 - fail closed on a bad lease
                blocking.append({
                    "file": name,
                    "message": f"unreadable staging lease {name} blocks deploys until inspected: {exc}",
                })
                continue
            if now > expires + dt.timedelta(seconds=SKEW_SECONDS):
                continue
            if plugin not in plugins:
                continue
            blocking.append({
                "file": name,
                "lease_id": lease.get("lease_id"),
                "owner": lease.get("owner"),
                "plugins": plugins,
                "scope": lease.get("scope"),
                "expires_at": lease.get("expires_at"),
                "message": (
                    f"{plugin} deploy blocked by staging lease {lease.get('lease_id')}: "
                    f"owner={lease.get('owner')} plugins={','.join(map(str, plugins))} "
                    f"expires_at={lease.get('expires_at')} scope={lease.get('scope')}"
                ),
            })
    return {
        "plugin": plugin,
        "now": now.strftime("%Y-%m-%dT%H:%M:%SZ"),
        "free": not blocking,
        "blocking": blocking,
    }


def main(argv=None, lease_dir=None, now=None):
    parser = argparse.ArgumentParser()
    parser.add_argument("--plugin", required=True)
    args = parser.parse_args(argv)
    if not PLUGIN_RE.match(args.plugin):
        print(json.dumps({"error": "invalid plugin slug"}))
        return 2
    result = evaluate(
        os.path.expanduser(lease_dir or LEASE_DIR),
        args.plugin,
        now or dt.datetime.now(dt.timezone.utc),
    )
    print(json.dumps(result))
    return 0 if result["free"] else 3


if __name__ == "__main__":
    sys.exit(main())
