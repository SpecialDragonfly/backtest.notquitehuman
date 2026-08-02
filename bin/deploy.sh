#!/usr/bin/env bash
# Deploys the current branch on the production server: fast-forwards from
# origin/main and clears the compiled Twig template cache (prod runs with
# auto_reload off, so stale .twig output would otherwise persist until the
# cache directory is emptied by hand).
#
# Run this ON THE SERVER, from within the backtest.notquitehuman.new checkout:
#   ssh webserver
#   cd ~/Projects/other_docker/backtest_notquitehuman_data   # adjust to wherever you checked it out
#   bin/deploy.sh
#
# Assumes a container named backtest_notquitehuman already exists on the
# server (set up separately, once) — adjust CONTAINER/CONTAINER_APP_PATH
# below if you named things differently.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONTAINER=backtest_notquitehuman
CONTAINER_APP_PATH=/var/www/backtest_notquitehuman

cd "$REPO_ROOT"

echo "==> Fetching origin"
git fetch origin

echo "==> Fast-forwarding to origin/main"
git merge --ff-only origin/main

echo "==> Clearing Twig template cache"
docker exec "$CONTAINER" sh -c "rm -rf '$CONTAINER_APP_PATH/var/cache/twig'/*"

echo "==> Deploy complete ($(git rev-parse --short HEAD))"
