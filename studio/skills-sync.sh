#!/usr/bin/env bash
# Fetch Remotion's official agent skills at a pinned, inspected commit into .claude/skills/ (git-ignored: the skills
# repository carries no licence file, so we use it in place and do not redistribute it). Change the pin only after
# reading the diff. Inspected 29 Oct 2026: remotion-best-practices, remotion-markup (incl. arabic-text), remotion-render.
set -euo pipefail
PIN=30d4553ecd46fbc5212fd0ff006ae43af522158b
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
git -C "$TMP" init -q && git -C "$TMP" remote add origin https://github.com/remotion-dev/skills
git -C "$TMP" fetch -q --depth 1 origin "$PIN" && git -C "$TMP" checkout -q FETCH_HEAD
for s in remotion-best-practices remotion-markup remotion-render; do
  rm -rf "$ROOT/.claude/skills/$s" && cp -r "$TMP/skills/$s" "$ROOT/.claude/skills/$s"
  rm -rf "$ROOT/.claude/skills/$s/remotion-maps"
done
rm -rf "$TMP"
echo "Remotion skills at $PIN in .claude/skills/"
