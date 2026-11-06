#!/bin/bash
# Usage: bin/run_tests.sh [--static-only | --tex-only]
cd "$(dirname "$0")/.." && php tests/run_all.php "$@"
