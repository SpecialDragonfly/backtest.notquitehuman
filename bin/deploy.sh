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
# Assumes docker-compose.yml has already been brought up on the server
# (set up separately, once) — the php container name below must match
# docker-compose.yml's container_name, and the app path must match its
# working_dir (both currently backtest_nqh_php / /var/www).

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONTAINER=backtest_nqh_php
CONTAINER_APP_PATH=/var/www

cd "$REPO_ROOT"

echo "==> Fetching origin"
git fetch origin

echo "==> Fast-forwarding to origin/main"
git merge --ff-only origin/main

echo "==> Clearing Twig template cache"
docker exec "$CONTAINER" sh -c "rm -rf '$CONTAINER_APP_PATH/var/cache/twig'/*"

echo "==> Deploy complete ($(git rev-parse --short HEAD))"
