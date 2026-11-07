#!/bin/sh
# Create or update the repository's labels from labels.tsv (name, colour, description).
# Needs the GitHub CLI, signed in with access to the repository:
#   sh tools/github/labels.sh [owner/repo]     (default: kestellohq/cms)
set -eu
repo="${1:-kestellohq/cms}"
dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
command -v gh >/dev/null 2>&1 || { echo "labels.sh: the GitHub CLI (gh) is not installed" >&2; exit 1; }

tail -n +2 "$dir/labels.tsv" | while IFS="$(printf '\t')" read -r name color description; do
    [ -n "$name" ] || continue
    gh label create "$name" --repo "$repo" --color "$color" --description "$description" --force
done
