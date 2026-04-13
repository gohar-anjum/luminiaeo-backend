import logging
import sys

def setup_logging():
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s | %(levelname)s | %(name)s | %(message)s",
        handlers=[logging.StreamHandler(sys.stdout)],
    )
    # Pipeline steps: same INFO as root; adjust here if you want DEBUG-only tracing.
    logging.getLogger("page_analysis.pipeline").setLevel(logging.INFO)
