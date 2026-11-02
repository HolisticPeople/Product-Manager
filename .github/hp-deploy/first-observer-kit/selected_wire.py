"""Closed selected wire construction shared by the finite caller and inverses.

No I/O, credentials in argv, native data, bootstrap or HTTP dispatch here.
"""
import re
from urllib.parse import urlencode


WIRE_ACTIONS = {"swap": "selected-swap-v1", "rollback": "selected-rollback-v1",
                "invalidate": "selected-invalidate-v1"}
GUARD_BUILD_ID = "hp-selected-guard-20261102-c"


def mutation_request(slug, token, action, operation, selected_digest):
    if (not isinstance(slug, str) or not re.fullmatch(r"[a-z0-9][a-z0-9-]{1,80}", slug)
            or not isinstance(token, str) or not re.fullmatch(r"[a-f0-9]{64}", token)
            or not isinstance(operation, str) or not re.fullmatch(r"[A-Za-z0-9_-]{1,100}", operation)
            or not isinstance(selected_digest, str) or not re.fullmatch(r"[a-f0-9]{64}", selected_digest)
            or not isinstance(action, str) or action not in WIRE_ACTIONS):
        raise ValueError("invalid selected mutation wire scope")
    return urlencode({"slug": slug, "token": token, "action": WIRE_ACTIONS[action],
                      "operation": operation, "guardBuildId": GUARD_BUILD_ID, "selectedDigest": selected_digest}).encode("ascii")


def probe_request():
    return urlencode({"slug": "hp-deploy-probe", "token": "0" * 64,
                      "action": "guard-probe"}).encode("ascii")


def legacy_request(slug, token, operation, selected_digest):
    mutation_request(slug, token, "swap", operation, selected_digest)  # shared scalar scope validation only
    if not re.fullmatch(r"[a-f0-9]{32}", operation):
        raise ValueError("finite fenced legacy operation required")
    return urlencode({"slug": slug, "token": token, "action": "legacy-swap-fenced-v1",
                      "operation": operation, "guardBuildId": GUARD_BUILD_ID, "selectedDigest": selected_digest}).encode("ascii")
