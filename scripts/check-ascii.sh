#!/usr/bin/env sh
# Fails if any tracked source file contains non-ASCII bytes (em dashes, emoji, ...).
set -eu
files=$(git ls-files '*.php' '*.py' '*.env' '*.js' '*.css' '*.md' '*.sh' '*.yml' '*.yaml' '*.json' '*.xml' '*.conf' '*.ini' 'Dockerfile' '.env.example' | grep -v '^composer.lock$' | grep -v 'package-lock.json' || true)
bad=0
for f in $files; do
    if LC_ALL=C grep -n -P '[^\x00-\x7F]' "$f" >/dev/null 2>&1; then
        echo "Non-ASCII characters in $f:"
        LC_ALL=C grep -n -P '[^\x00-\x7F]' "$f" | head -5
        bad=1
    fi
done
[ "$bad" -eq 0 ] && echo "All source files are ASCII."
exit "$bad"
