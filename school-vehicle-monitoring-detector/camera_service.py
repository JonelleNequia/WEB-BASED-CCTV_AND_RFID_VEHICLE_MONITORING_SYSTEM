import os
import sys


def close_inherited_descriptors():
    """
    Phase 1: drop file descriptors inherited from the process that started us.

    Laravel launches this script from the PHP web server. Without this, the
    detector kept the web server's listening socket open, so the web port
    stayed busy after the server restarted and requests to it hung.
    """
    if os.name != "posix":
        return

    try:
        import resource

        soft_limit, _ = resource.getrlimit(resource.RLIMIT_NOFILE)
    except (ImportError, OSError, ValueError):
        soft_limit = 1024

    upper = 4096 if soft_limit in (-1, 0) else min(int(soft_limit), 4096)
    os.closerange(3, upper)


close_inherited_descriptors()

from detector_service import run_detector_loop  # noqa: E402


if __name__ == "__main__":
    sys.exit(run_detector_loop())
