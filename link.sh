#!/usr/bin/env bash
# Symlink the MauticInsightsBundle into the local mautic ddev project so the plugin is loaded.
# Run from the mautic-observer repo root:  ./link.sh
set -euo pipefail

MAUTIC_PLUGINS="../mautic/plugins"
LINK="$MAUTIC_PLUGINS/MauticInsightsBundle"

if [ -e "$LINK" ]; then
  echo "Already linked: $LINK"
  exit 0
fi

mkdir -p "$MAUTIC_PLUGINS"
ln -s ../../mautic-observer "$LINK"
echo "Linked $LINK -> ../../mautic-observer"
echo "Now run 'ddev restart' (or 'ddev composer install') in the mautic project to pick up the plugin."
