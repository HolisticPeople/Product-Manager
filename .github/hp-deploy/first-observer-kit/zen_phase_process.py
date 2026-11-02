"""Actual observation-FD subprocess boundary; no native method/admission here."""
import os

from pm_process import raw_process
from zen_pair_files import PairRefusal


def observation_process(files, directory, argv, *, env=None, timeout=45, run=raw_process):
    files.require_lock()
    if os.get_inheritable(files._lock_fd):
        raise PairRefusal("private owner descriptor must be CLOEXEC")
    view = os.open(files.content / "hp-deploy/selected-phase.lock", os.O_RDONLY | os.O_NOFOLLOW)
    try:
        owner, actual = os.fstat(files._lock_fd), os.fstat(view)
        if (owner.st_dev, owner.st_ino) != (actual.st_dev, actual.st_ino):
            raise PairRefusal("actual observation resource differs from owner inode")
        environment = dict(os.environ if env is None else env,
                           PM_NATIVE_PHASE_FD=str(view), PM_NATIVE_OWNER_PID=str(os.getpid()))
        # The SAME file, but a NEW open-file description. Never dup/transfer the
        # owner's description to a reader. pass_fds exposes ONLY this view.
        return run(directory, "actual", argv, data=b"", pass_fds=(view,), env=environment, timeout=timeout)
    finally:
        os.close(view)
