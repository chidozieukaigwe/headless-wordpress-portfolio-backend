#!/usr/bin/env bash
set -euo pipefail
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "Not a git repository. Run this from inside your repo." >&2
  exit 1
fi

# Ensure .gitignore contains the rule
if ! grep -Fxq "wp-content/plugins/" .gitignore 2>/dev/null; then
  printf "\n# Ignore installed plugins\nwp-content/plugins/\n" >> .gitignore
  git add .gitignore
fi

# Untrack any currently committed plugin files
git rm -r --cached wp-content/plugins || true

# Commit the removal if there are staged changes
if ! git diff --cached --quiet; then
  git commit -m "Remove installed plugins from repo; ignore plugin packages" || true
fi

echo "Finished: plugins untracked and .gitignore updated."
