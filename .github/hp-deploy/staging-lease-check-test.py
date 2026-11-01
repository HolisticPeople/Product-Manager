#!/usr/bin/env python3
"""Stdlib tests for .github/hp-deploy/staging-lease-check.py."""

import contextlib
import datetime as dt
import importlib.util
import io
import json
import os
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parent / "staging-lease-check.py"
spec = importlib.util.spec_from_file_location("staging_lease_check", SCRIPT)
check = importlib.util.module_from_spec(spec)
spec.loader.exec_module(check)

NOW = dt.datetime(2026, 10, 30, 12, 0, 0, tzinfo=dt.timezone.utc)


def iso(delta_minutes):
    return (NOW + dt.timedelta(minutes=delta_minutes)).strftime("%Y-%m-%dT%H:%M:%SZ")


class StagingLeaseCheckTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.dir = os.path.join(self.tmp.name, "staging-leases")

    def tearDown(self):
        self.tmp.cleanup()

    def write(self, name, payload):
        os.makedirs(self.dir, exist_ok=True)
        text = payload if isinstance(payload, str) else json.dumps(payload)
        Path(self.dir, name).write_text(text, encoding="utf-8")

    def lease(self, lease_id, plugins, expires_in, owner="zen-lane"):
        self.write(f"{lease_id}.json", {
            "lease_id": lease_id, "owner": owner, "plugins": plugins,
            "scope": "browser QA", "created_at": iso(-10), "expires_at": iso(expires_in),
        })

    def run_check(self, plugin="hp-card"):
        out = io.StringIO()
        with contextlib.redirect_stdout(out):
            code = check.main(["--plugin", plugin], lease_dir=self.dir, now=NOW)
        return code, json.loads(out.getvalue())

    def test_missing_directory_is_free(self):
        self.assertEqual(self.run_check()[0], 0)

    def test_active_lease_naming_plugin_blocks_with_details(self):
        self.lease("l1", ["hp-zen", "hp-card"], 30)
        code, out = self.run_check()
        self.assertEqual(code, 3)
        msg = out["blocking"][0]["message"]
        for part in ("owner=zen-lane", "plugins=hp-zen,hp-card", f"expires_at={iso(30)}"):
            self.assertIn(part, msg)

    def test_any_owner_blocks(self):
        self.lease("l1", ["hp-card"], 30, owner="card-lane")
        self.assertEqual(self.run_check()[0], 3)

    def test_other_plugins_do_not_block(self):
        self.lease("l1", ["hp-zen", "hp-core"], 30)
        self.assertEqual(self.run_check()[0], 0)

    def test_expired_beyond_skew_is_free_within_skew_blocks(self):
        self.lease("old", ["hp-card"], -3)
        self.assertEqual(self.run_check()[0], 0)
        self.lease("grace", ["hp-card"], -1)
        self.assertEqual(self.run_check()[0], 3)

    def test_unreadable_lease_fails_closed(self):
        self.write("broken.json", "{oops")
        code, out = self.run_check()
        self.assertEqual(code, 3)
        self.assertIn("unreadable", out["blocking"][0]["message"])

    def test_temp_and_subdirectory_files_ignored(self):
        os.makedirs(os.path.join(self.dir, "archive"))
        Path(self.dir, "archive", "x.json").write_text("{oops")
        self.write(".tmp-123-abcd", "{oops")
        self.write(".lock", "")
        self.assertEqual(self.run_check()[0], 0)

    def test_invalid_plugin_slug(self):
        out = io.StringIO()
        with contextlib.redirect_stdout(out):
            self.assertEqual(check.main(["--plugin", "../x"], lease_dir=self.dir, now=NOW), 2)


if __name__ == "__main__":
    unittest.main(verbosity=2)
