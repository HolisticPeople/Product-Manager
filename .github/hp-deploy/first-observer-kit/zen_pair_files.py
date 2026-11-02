"""Finite pair filesystem operations. No WordPress/bootstrap/network entry point.

These functions perform real filesystem work when an independently selected
caller invokes them. The command-line module has no execute mode. The candidate
MU guard must use the same selected-phase.lock before accepting any mutator.
"""
from contextlib import contextmanager
import fcntl
import fnmatch
import hashlib
import json
import math
import os
from pathlib import Path
import re
import stat
from selected_wire import GUARD_BUILD_ID


class PairRefusal(ValueError):
    pass


def decoded(raw):
    if not isinstance(raw, bytes) or len(raw) > 4 * 1024 * 1024:
        raise PairRefusal("bounded UTF-8 JSON bytes required")
    def pairs(rows):
        result = {}
        for key, value in rows:
            if key in result:
                raise PairRefusal("duplicate selected JSON key")
            result[key] = value
        return result
    def constant(value):
        raise PairRefusal("nonfinite selected JSON number")
    def finite(value):
        number = float(value)
        if not math.isfinite(number):
            raise PairRefusal("nonfinite selected JSON number")
        return number
    try:
        return json.loads(raw.decode("utf-8", errors="strict"), object_pairs_hook=pairs,
                          parse_constant=constant, parse_float=finite)
    except (ValueError, UnicodeError, RecursionError) as error:
        raise PairRefusal("invalid selected JSON") from error


def encoded(value):
    return json.dumps(value, sort_keys=True, separators=(",", ":"), allow_nan=False).encode()


def same(left, right):
    # Preserve scalar types, list order and exact object keysets.
    return encoded(left) == encoded(right)


def sha(data):
    return hashlib.sha256(data).hexdigest()


def sync_directory(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def regular_bytes(path, limit=None):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
            raise PairRefusal("not a singly linked regular file: " + str(path))
        with os.fdopen(fd, "rb", closefd=False) as stream:
            data = stream.read() if limit is None else stream.read(limit + 1)
        if limit is not None and len(data) > limit:
            raise PairRefusal("private file exceeds accepted bound")
        after = os.fstat(fd)
        if (info.st_ino, info.st_size, info.st_mtime_ns, info.st_mode) != (after.st_ino, after.st_size, after.st_mtime_ns, after.st_mode):
            raise PairRefusal("file changed during capture")
        return data, stat.S_IMODE(info.st_mode)
    finally:
        os.close(fd)


def private_directory(path):
    path = Path(path)
    info = path.lstat()
    if (path.resolve(strict=True) != path or not stat.S_ISDIR(info.st_mode)
            or stat.S_IMODE(info.st_mode) != 0o700 or info.st_uid != os.geteuid()):
        raise PairRefusal("canonical private0700 owned directory required")
    return path


def private_bytes(path):
    path = Path(path)
    private_directory(path.parent)
    data, mode = regular_bytes(path, limit=4 * 1024 * 1024)
    if mode != 0o600 or path.lstat().st_uid != os.geteuid():
        raise PairRefusal("private0600 owned file required")
    return data


def exclusive_file(path, data, mode=0o600):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
    with os.fdopen(fd, "wb") as stream:
        os.fchmod(stream.fileno(), mode)
        stream.write(data)
        stream.flush()
        os.fsync(stream.fileno())
    sync_directory(Path(path).parent)


def replace_json(path, value):
    temporary = Path(str(path) + ".tmp")
    exclusive_file(temporary, encoded(value) + b"\n")
    os.replace(temporary, path)
    sync_directory(Path(path).parent)


class PairFiles:
    """Exact supported public/wp-content layout and unchanged rsync exclusions."""
    EXCLUDED_DIRS = {".git", ".github", "node_modules", "vendor", "Backups",
                     "Plans and reports", "Ref Plugins", "WPCode snippets", "scripts"}
    EXCLUDED_FILES = {".DS_Store", "Thumbs.db"}

    def __init__(self, wp_root, slug="hp-zen", theme_slug="hp-zen-theme"):
        if (not isinstance(slug, str) or not re.fullmatch(r"[a-z0-9][a-z0-9-]{1,80}", slug)
                or (slug == "hp-zen" and theme_slug != "hp-zen-theme")
                or (slug != "hp-zen" and theme_slug is not None)):
            raise PairRefusal("closed plugin/theme ownership scope required")
        self.slug, self.theme_slug = slug, theme_slug
        self.root = Path(wp_root)
        if not self.root.is_absolute() or self.root.resolve(strict=True) != self.root:
            raise PairRefusal("root is not canonical")
        for relative in ("wp-load.php", "wp-settings.php"):
            regular_bytes(self.root / relative)
        for relative in ("wp-content", "wp-content/plugins", "wp-content/themes"):
            self.directory(self.root / relative)
        self.content = self.root / "wp-content"
        self.plugin = self.content / "plugins" / slug
        self.theme = None if theme_slug is None else self.content / "themes" / theme_slug
        self.state = self.content / "hp-deploy" / slug
        self._lock_fd = None

    @staticmethod
    def directory(path):
        path = Path(path)
        if not path.is_dir() or path.resolve(strict=True) != path:
            raise PairRefusal("missing/aliased directory: " + str(path))
        return path

    @classmethod
    def excluded(cls, relative, is_directory=False):
        parts = Path(relative).parts
        if any(p in cls.EXCLUDED_DIRS for p in (parts if is_directory else parts[:-1])):
            return True
        name = parts[-1]
        return (name in cls.EXCLUDED_FILES or fnmatch.fnmatchcase(name, "*.log")
                or fnmatch.fnmatchcase(name, "*.zip"))

    @classmethod
    def scan(cls, path, missing=False):
        path = Path(path)
        if not path.exists() and not path.is_symlink() and missing:
            return None
        cls.directory(path)
        result = {}
        for parent, directories, files in os.walk(path, followlinks=False):
            kept = []
            for name in sorted(directories):
                directory = Path(parent) / name
                relative = directory.relative_to(path).as_posix()
                if cls.excluded(relative, True):
                    continue
                cls.directory(directory)
                kept.append(name)
            directories[:] = kept
            for name in sorted(files):
                file = Path(parent) / name
                relative = file.relative_to(path).as_posix()
                if cls.excluded(relative):
                    continue
                data, mode = regular_bytes(file)
                if mode not in (0o644, 0o755):
                    raise PairRefusal("unexpected payload mode")
                result[relative] = {"mode": mode, "type": "blob", "sha256": sha(data),
                                    "gitBlobSHA1": hashlib.sha1(b"blob " + str(len(data)).encode() + b"\0" + data).hexdigest(),
                                    "bytes": len(data)}
        return result

    def read_pair(self):
        return {"current": {"plugin": self.scan(self.plugin), "theme": None if self.theme is None else self.scan(self.theme)},
                "previousPlugin": self.scan(self.state / "prev", missing=True),
                "nextPlugin": self.scan(self.state / "next", missing=True),
                "rolledBack": self.scan(self.state / "rolled-back", missing=True)}

    def copy_image(self, source, destination, expected):
        """Copy only selected code, never excluded/private/generated files."""
        if not same(self.scan(source), expected):
            raise PairRefusal("source image drift")
        destination = Path(destination)
        destination.mkdir(mode=0o700)  # exclusive; incomplete images are retained
        sync_directory(destination.parent)
        for relative, entry in sorted(expected.items()):
            if (Path(relative).is_absolute() or ".." in Path(relative).parts
                    or "\\" in relative or self.excluded(relative)):
                raise PairRefusal("unsafe/unshipped image path")
            data, mode = regular_bytes(Path(source) / relative)
            if sha(data) != entry["sha256"] or len(data) != entry["bytes"] or mode != entry["mode"]:
                raise PairRefusal("source changed during image capture")
            target = destination / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            exclusive_file(target, data, mode)
        if not same(self.scan(destination), expected):
            raise PairRefusal("copied recovery image mismatch")
        sync_directory(destination)

    def prepare_state(self):
        for path in (self.content / "hp-deploy", self.state):
            if not path.exists():
                path.mkdir(mode=0o700)
                sync_directory(path.parent)
            self.directory(path)

    @contextmanager
    def phase_lock(self, create=False):
        if self._lock_fd is not None:
            raise PairRefusal("phase lock is not reentrant")
        if create:
            # Explicit setup primitive only. Normal selected callers require
            # separately adopted kit state and cannot create it before native
            # admission. This flag is not exposed by any runtime CLI/RPC.
            self.prepare_state()
        else:
            self.directory(self.content / "hp-deploy")
            self.directory(self.state)
        path = self.content / "hp-deploy/selected-phase.lock"
        fd = os.open(path, os.O_RDWR | os.O_NOFOLLOW | (os.O_CREAT if create else 0), 0o600)
        try:
            info = os.fstat(fd)
            if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1
                    or stat.S_IMODE(info.st_mode) != 0o600 or info.st_uid != os.geteuid()):
                raise PairRefusal("invalid physical phase lock")
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            self._lock_fd = fd
            self.require_lock()
            yield
        finally:
            if self._lock_fd == fd:
                self._lock_fd = None
            os.close(fd)

    def require_lock(self):
        if self._lock_fd is None:
            raise PairRefusal("shared physical phase lock is required")
        # Ownership comes from the successful phase_lock flock, not these
        # metadata fields. This only refuses later descriptor/path replacement.
        actual = os.fstat(self._lock_fd)
        path = self.content / "hp-deploy/selected-phase.lock"
        named = path.lstat()
        if (path.resolve(strict=True) != path or not stat.S_ISREG(actual.st_mode)
                or actual.st_nlink != 1 or actual.st_uid != os.geteuid()
                or stat.S_IMODE(actual.st_mode) != 0o600
                or (actual.st_dev, actual.st_ino) != (named.st_dev, named.st_ino)):
            raise PairRefusal("actual owned phase resource/path changed")


class PairAttempt:
    """Real durable on-target intent/receipts, serialized by the shared kit lock.

    Caller must hold PairFiles.phase_lock for every operation, and the candidate
    MU handler must honor that same lock. This does not qualify other writers or
    native startup. There is no token/boolean that makes those facts true.
    """
    def __init__(self, files, operation, private_root, attempt=None, *, approved_base=None):
        if not re.fullmatch(r"[a-zA-Z0-9_-]{1,100}", operation):
            raise PairRefusal("bad operation identity")
        self.files = files
        self.operation = operation
        self.private_root = Path(private_root)
        if approved_base is None:
            raise PairRefusal("independently approved private base is required")
        self.approved_base = private_directory(approved_base)
        if (not self.private_root.is_absolute() or self.private_root.resolve(strict=True) != self.private_root
                or self.private_root == files.root or files.root in self.private_root.parents
                or stat.S_IMODE(self.private_root.stat().st_mode) != 0o700):
            raise PairRefusal("private state must be canonical0700 outside the public WordPress root")
        private_directory(self.private_root)
        if (self.approved_base == files.root or files.root in self.approved_base.parents
                or (self.private_root != self.approved_base and self.approved_base not in self.private_root.parents)):
            raise PairRefusal("private root outside independently approved base")
        self.operation_dir = self.private_root / operation
        if self.operation_dir.exists() or self.operation_dir.is_symlink():
            private_directory(self.operation_dir)
        self.selected_bytes = None
        self.selected_digest = None
        selected_path = self.operation_dir / "selected.json"
        if selected_path.exists() or selected_path.is_symlink():
            self.selected_bytes = private_bytes(selected_path)
            self.selected_digest = sha(self.selected_bytes)
            original = decoded(self.selected_bytes)
            if not isinstance(original, dict):
                raise PairRefusal("immutable selection must be an object")
            if attempt is None:
                attempt = original.get("attempt")
            elif attempt != original.get("attempt"):
                raise PairRefusal("attempt cannot re-identify the captured selection")
        if not isinstance(attempt, str) or not re.fullmatch(r"[a-z0-9-]{1,64}", attempt):
            raise PairRefusal("one immutable selected attempt is required")
        self.attempt = attempt
        self.directory = self.operation_dir / attempt
        if self.directory.exists() or self.directory.is_symlink():
            private_directory(self.directory)

    def recovery_binding(self, child):
        self.require_owner()
        selected = decoded(self.selected_bytes)
        state = decoded(private_bytes(self.operation_dir / "state.json"))
        if state.get("state") != "FAILED_KNOWN_PARTIAL" or state.get("phase") not in selected["phases"]:
            raise PairRefusal("only an owned sealed known partial can admit a separately selected recovery")
        index = selected["phases"].index(state["phase"])
        previous = None
        for offset, phase in enumerate(selected["phases"][:index + 1]):
            previous = self.verify_phase(phase, offset, previous,
                "FAILED_KNOWN_PARTIAL" if offset == index else "PHASE_VERIFIED")
        readback = private_bytes(self.directory / (state["phase"] + ".readback.json"))
        if not same(self.files.read_pair(), decoded(readback)):
            raise PairRefusal("known partial physical pair changed; recovery cannot adopt it")
        recovery = selected.get("recovery")
        if (not isinstance(recovery, dict) or set(recovery) != {"operation", "attempt", "target"}
                or recovery["operation"] == self.operation or child.get("operation") != recovery["operation"]
                or child.get("attempt") != recovery["attempt"] or child.get("slug") != self.files.slug
                or child.get("contentRoot") != selected["contentRoot"] or child.get("siteOrigin") != selected.get("siteOrigin")
                or child.get("themeSlug") != selected.get("themeSlug") or child.get("action") != "swap"
                or child.get("source", {}).get("helperSha256") != selected.get("source", {}).get("helperSha256")
                or not same(child.get("nativeScope"), selected.get("nativeScope"))
                or not same(child.get("nativeBefore"), selected.get("nativeBefore"))
                or not same(child.get("nativeReader"), selected.get("nativeReader"))
                or any(not same(child.get("caller", {}).get(key), selected.get("caller", {}).get(key))
                       for key in ("nativeBeforeByContext", "cliNative", "webTransport", "environment"))
                or not same(child.get("caller", {}).get("finalFrame", {}).get("current"), recovery["target"])):
            raise PairRefusal("recovery child/source/native/both-image target differs from original selection")
        first = child.get("phases", [None])[0]
        if not same(child.get("caller", {}).get("phaseFrames", {}).get(first, {}).get("before"), decoded(readback)):
            raise PairRefusal("recovery child before-frame is not the original known partial")
        binding = {"operation": self.operation, "attempt": self.attempt, "selectedDigest": self.selected_digest,
                   "phase": state["phase"], "classificationSHA256": previous, "readbackSHA256": sha(readback)}
        if not same(child.get("parent"), binding):
            raise PairRefusal("recovery parent raw/classification binding changed")
        return binding

    def claim(self, selected, *, parent=None):
        # Every caller and MU mutator must acquire the shared physical lock.
        self.files.require_lock()
        owner = self.files.content / "hp-deploy/selected-phase-owner.json"
        if parent is None:
            if owner.exists() or owner.is_symlink():
                raise FileExistsError("existing global owner cannot be adopted")
        elif parent.files is not self.files or parent.private_root != self.private_root:
            raise PairRefusal("recovery requires same actual owned target/private root")
        else:
            parent.recovery_binding(selected)
        root = self.operation_dir
        if not root.exists():
            root.mkdir(mode=0o700)
            sync_directory(root.parent)
        private_directory(root)
        selection_bytes = encoded(selected) + b"\n"
        if self.selected_bytes is not None and self.selected_bytes != selection_bytes:
            raise PairRefusal("captured selection cannot be replaced during claim")
        if selected.get("attempt") != self.attempt or selected.get("operation") != self.operation or selected.get("slug") != self.files.slug:
            raise PairRefusal("selected attempt/operation changed")
        phases = selected.get("phases")
        if not isinstance(phases, list) or not phases or len(phases) > 32 or len(set(phases)) != len(phases) or any(not isinstance(p, str) or not re.fullmatch(r"[a-z][a-z0-9_-]{0,80}", p) for p in phases):
            raise PairRefusal("explicit ordered unique phase graph required")
        selected_path = self.operation_dir / "selected.json"
        if selected_path.exists():
            if regular_bytes(selected_path)[0] != selection_bytes:
                raise PairRefusal("prepared selection bytes changed")
        else:
            exclusive_file(selected_path, selection_bytes)
        self.directory.mkdir(mode=0o700)
        sync_directory(root)
        exclusive_file(self.directory / "attempt-reservation.json", encoded({"operation": self.operation, "attempt": self.attempt, "selectedDigest": sha(selection_bytes)}) + b"\n")
        if (self.operation_dir / "state.json").exists():
            raise PairRefusal("existing child state cannot be re-identified")
        exclusive_file(self.operation_dir / "state.json", encoded({"state": "CLAIM_INFLIGHT" if parent is not None else "PREPARED", "phase": None}) + b"\n")
        self.selected_bytes = selection_bytes
        self.selected_digest = sha(selection_bytes)
        new_owner = {"operation": self.operation, "slug": self.files.slug, "privateRoot": str(self.private_root), "selectedDigest": self.selected_digest}
        if parent is None:
            exclusive_file(owner, encoded(new_owner) + b"\n")
        else:
            binding = parent.recovery_binding(selected)  # fresh before ownership change
            transfer = {"parent": binding, "child": {"operation": self.operation, "attempt": self.attempt, "selectedDigest": self.selected_digest}}
            exclusive_file(parent.directory / "recovery-transfer-intent.json", encoded(transfer) + b"\n")
            exclusive_file(self.directory / "recovery-transfer.json", encoded(transfer) + b"\n")
            replace_json(owner, new_owner)
            replace_json(parent.operation_dir / "state.json", {"state": "RECOVERY_TRANSFERRED", "phase": binding["phase"]})
            replace_json(self.operation_dir / "state.json", {"state": "PREPARED", "phase": None})

    def require_next_phase(self, phase):
        self.require_owner()
        state = private_bytes(self.operation_dir / "state.json")
        state = decoded(state)
        if state["state"] not in ("PREPARED", "PHASE_VERIFIED"):
            raise PairRefusal("unknown/inflight/existing effect cannot replay")
        if not re.fullmatch(r"[a-z][a-z0-9_-]{0,80}", phase):
            raise PairRefusal("bad finite phase")
        selected = decoded(private_bytes(self.operation_dir / "selected.json"))
        phases = selected["phases"]
        index = 0 if state["state"] == "PREPARED" else phases.index(state["phase"]) + 1
        if index >= len(phases) or phases[index] != phase:
            raise PairRefusal("phase skipped/reordered/replayed")
        previous = None
        for offset, prior in enumerate(phases[:index]):
            previous = self.verify_phase(prior, offset, previous, "PHASE_VERIFIED")
        return index, previous

    def before_effect(self, phase):
        index, previous = self.require_next_phase(phase)
        intent = {"operation": self.operation, "attempt": self.attempt, "selectedDigest": self.selected_digest,
            "phase": phase, "index": index, "previousClassificationSHA256": previous}
        from catalog_plugin_observations import require_code_gate
        gate = require_code_gate(self, phase)
        if gate is not None: intent["catalogAdmissionSHA256"] = gate
        exclusive_file(self.directory / (phase + ".intent.json"), encoded(intent) + b"\n")
        replace_json(self.operation_dir / "state.json", {"state": "INFLIGHT", "phase": phase})

    def caught(self, phase, returncode, stdout, stderr, timeout=False):
        self.require_owner()
        state = decoded(private_bytes(self.operation_dir / "state.json"))
        if state != {"state": "INFLIGHT", "phase": phase}:
            raise PairRefusal("raw result has no preceding owned phase")
        # Persist exact bytes before interpreting either command or readback.
        exclusive_file(self.directory / (phase + ".stdout"), stdout)
        exclusive_file(self.directory / (phase + ".stderr"), stderr)
        exclusive_file(self.directory / (phase + ".result.json"), encoded({"returncode": returncode, "timeout": timeout}) + b"\n")
        replace_json(self.operation_dir / "state.json", {"state": "RAW_RETAINED", "phase": phase})

    def classify(self, phase, outcome, observed):
        self.require_owner()
        state = decoded(private_bytes(self.operation_dir / "state.json"))
        if state != {"state": "RAW_RETAINED", "phase": phase}:
            raise PairRefusal("raw result must precede classification")
        if outcome not in ("PHASE_VERIFIED", "FAILED_KNOWN_PARTIAL", "UNKNOWN"):
            raise PairRefusal("invalid classification")
        raw = decoded(private_bytes(self.directory / (phase + ".result.json")))
        if outcome == "PHASE_VERIFIED" and (type(raw["returncode"]) is not int or raw["returncode"] != 0 or raw["timeout"] is not False):
            raise PairRefusal("required failure/unknown cannot be success")
        if outcome == "FAILED_KNOWN_PARTIAL" and (type(raw["returncode"]) is not int or raw["returncode"] == 0 or raw["timeout"] is not False):
            raise PairRefusal("unknown cannot become known partial")
        exclusive_file(self.directory / (phase + ".readback.json"), encoded(observed) + b"\n")
        self.seal_phase(phase, outcome)
        replace_json(self.operation_dir / "state.json", {"state": outcome, "phase": phase})

    def seal_phase(self, phase, outcome):
        self.require_owner()
        selected = decoded(self.selected_bytes)
        index = selected["phases"].index(phase)
        previous = None
        for offset, prior in enumerate(selected["phases"][:index]):
            previous = self.verify_phase(prior, offset, previous, "PHASE_VERIFIED")
        intent = private_bytes(self.directory / (phase + ".intent.json"))
        required = {"operation": self.operation, "attempt": self.attempt, "selectedDigest": self.selected_digest,
                    "phase": phase, "index": index, "previousClassificationSHA256": previous}
        from catalog_plugin_observations import require_code_gate
        gate = require_code_gate(self, phase)
        if gate is not None: required["catalogAdmissionSHA256"] = gate
        if not same(decoded(intent), required):
            raise PairRefusal("phase intent/source/predecessor changed")
        files = {suffix: sha(private_bytes(self.directory / (phase + "." + suffix)))
                 for suffix in ("intent.json", "result.json", "stdout", "stderr", "readback.json")}
        exclusive_file(self.directory / (phase + ".classification.json"), encoded(dict(required,
            state=outcome, filesSHA256=files)) + b"\n")

    def verify_phase(self, phase, index, previous, outcome):
        self.require_owner()
        raw = private_bytes(self.directory / (phase + ".classification.json"))
        value = decoded(raw)
        required = {"operation": self.operation, "attempt": self.attempt, "selectedDigest": self.selected_digest,
                    "phase": phase, "index": index, "previousClassificationSHA256": previous,
                    "state": outcome}
        from catalog_plugin_observations import require_code_gate
        gate = require_code_gate(self, phase)
        if gate is not None: required["catalogAdmissionSHA256"] = gate
        if (not isinstance(value, dict) or set(value) != set(required) | {"filesSHA256"}
                or not same({k: value.get(k) for k in required}, required)
                or not isinstance(value["filesSHA256"], dict)
                or set(value["filesSHA256"]) != {"intent.json", "result.json", "stdout", "stderr", "readback.json"}):
            raise PairRefusal("prior owned phase classification unavailable or changed")
        for suffix, digest in value["filesSHA256"].items():
            if sha(private_bytes(self.directory / (phase + "." + suffix))) != digest:
                raise PairRefusal("prior original phase evidence changed")
        if not same(decoded(private_bytes(self.directory / (phase + ".intent.json"))),
                    {k: v for k, v in required.items() if k != "state"}):
            raise PairRefusal("prior phase intent chain changed")
        return sha(raw)

    def classify_observed_web_failure(self, phase, observed, response):
        """Actual cache failure is not a fabricated nonzero curl exit.

        This narrow path requires the independently recorded MU classification,
        its same one-shot HTTP response, original binding, and exact after-map.
        It grants no recovery or replay. The literal command exit stays intact.
        """
        self.require_owner()
        selected = decoded(self.selected_bytes)
        state = decoded(private_bytes(self.operation_dir / "state.json"))
        raw = decoded(private_bytes(self.directory / (phase + ".result.json")))
        actual_mu = decoded(private_bytes(self.directory / "web-classification.json"))
        actual_response = decoded(private_bytes(self.directory / "web-http/response"))
        after = selected.get("caller", {}).get("phaseFrames", {}).get(phase, {}).get("after")
        if (state != {"state": "RAW_RETAINED", "phase": phase} or selected.get("webPhase") != phase
                or type(raw.get("returncode")) is not int or raw["returncode"] != 0 or raw.get("timeout") is not False
                or not same(response, actual_mu) or not same(response, actual_response)
                or not isinstance(response, dict) or response.get("ok") is not False
                or response.get("state") != "FAILED_KNOWN_PARTIAL" or response.get("error") != "opcache_not_qualified"
                or response.get("guardProtocol") != ("hp-deploy-legacy-fence-v1" if selected.get("schema") == "hp-deploy-legacy-fence-v1" else "hp-deploy-selected-v1")
                or response.get("guardBuildId") != GUARD_BUILD_ID
                or response.get("operation") != self.operation or response.get("selectedDigest") != self.selected_digest
                or response.get("guardVersion") != "1.1.0" or response.get("slug") != self.files.slug
                or response.get("action") != selected.get("action")
                or response.get("version") != selected.get("caller", {}).get("pluginVersion")
                or private_bytes(self.directory / (phase + ".stdout")) != b"409"
                or private_bytes(self.directory / (phase + ".stderr")) != b""
                or type(response.get("failed")) is not int or response["failed"] < 0
                or type(response.get("invalidated")) is not int or response["invalidated"] < 0
                or type(response.get("swapped")) is not bool
                or response["swapped"] != (selected.get("action") in ("swap", "rollback"))
                or not (response.get("opcache") == "unavailable"
                        or (response.get("opcache") == "enabled" and response["failed"] > 0))
                or after is None or not same(observed, after) or not same(self.files.read_pair(), after)):
            raise PairRefusal("actual bound MU required-effect failure/after-frame unavailable")
        exclusive_file(self.directory / (phase + ".readback.json"), encoded(observed) + b"\n")
        exclusive_file(self.directory / (phase + ".required-effect-failure.json"), encoded(response) + b"\n")
        self.seal_phase(phase, "FAILED_KNOWN_PARTIAL")
        replace_json(self.operation_dir / "state.json", {"state": "FAILED_KNOWN_PARTIAL", "phase": phase})

    def require_owner(self):
        self.files.require_lock()
        owner_path = self.files.content / "hp-deploy/selected-phase-owner.json"
        owner_bytes, owner_mode = regular_bytes(owner_path, limit=4 * 1024 * 1024)
        if owner_mode != 0o600 or owner_path.lstat().st_uid != os.geteuid():
            raise PairRefusal("opaque owner record mode/UID changed")
        owner = decoded(owner_bytes)
        selected = private_bytes(self.operation_dir / "selected.json")
        if self.selected_bytes is None or selected != self.selected_bytes or sha(selected) != self.selected_digest:
            raise PairRefusal("original immutable selected bytes/digest changed")
        if owner != {"operation": self.operation, "slug": self.files.slug, "privateRoot": str(self.private_root), "selectedDigest": self.selected_digest}:
            raise PairRefusal("foreign or changed owned selection")
        reservation = decoded(private_bytes(self.directory / "attempt-reservation.json"))
        if reservation != {"operation": self.operation, "attempt": self.attempt, "selectedDigest": self.selected_digest}:
            raise PairRefusal("attempt is not the immutable selected reservation")
        selected_value = decoded(self.selected_bytes)
        if "parent" in selected_value:
            parent = selected_value["parent"]
            transfer = decoded(private_bytes(self.directory / "recovery-transfer.json"))
            if not same(transfer, {"parent": parent, "child": {"operation": self.operation, "attempt": self.attempt, "selectedDigest": self.selected_digest}}):
                raise PairRefusal("recovery child has no original completed ownership transfer")
            parent_root = self.private_root / parent["operation"]
            parent_dir = parent_root / parent["attempt"]
            classification_raw = private_bytes(parent_dir / (parent["phase"] + ".classification.json"))
            classification = decoded(classification_raw)
            if (sha(private_bytes(parent_root / "selected.json")) != parent["selectedDigest"]
                    or sha(classification_raw) != parent["classificationSHA256"]
                    or sha(private_bytes(parent_dir / (parent["phase"] + ".readback.json"))) != parent["readbackSHA256"]
                    or decoded(private_bytes(parent_root / "state.json")) != {"state": "RECOVERY_TRANSFERRED", "phase": parent["phase"]}
                    or not same(decoded(private_bytes(parent_dir / "recovery-transfer-intent.json")), transfer)):
                raise PairRefusal("original transferred parent evidence changed")
            for suffix, digest in classification["filesSHA256"].items():
                if sha(private_bytes(parent_dir / (parent["phase"] + "." + suffix))) != digest:
                    raise PairRefusal("original transferred parent failure stream changed")

    def hold_unknown(self, phase, error):
        """Retain a caught started-reader failure; never re-identify its attempt."""
        self.require_owner()
        if not isinstance(phase, str) or not re.fullmatch(r"[a-z][a-z0-9_-]{0,80}", phase):
            raise PairRefusal("bad unknown phase")
        exclusive_file(self.directory / (phase + ".caught-error.json"), encoded({
            "type": type(error).__name__, "message": str(error), "outcome": "UNKNOWN"}) + b"\n")
        replace_json(self.operation_dir / "state.json", {"state": "UNKNOWN", "phase": phase})


if __name__ == "__main__":
    raise SystemExit("No execute entry point: independently qualified finite caller/admission required.")
