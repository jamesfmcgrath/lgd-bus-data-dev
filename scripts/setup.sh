#!/usr/bin/env bash
# lgd-bus-times — one-shot dev environment setup
# Run from the repo root: ./scripts/setup.sh
set -euo pipefail

BOLD="\033[1m"
GREEN="\033[32m"
YELLOW="\033[33m"
RED="\033[31m"
RESET="\033[0m"

MODULE_REPO="git@git.drupal.org:project/localgov_bus_data.git"
MODULE_PATH="web/modules/custom/localgov_bus_data"

info()    { echo -e "${BOLD}▶ $*${RESET}"; }
success() { echo -e "${GREEN}✔ $*${RESET}"; }
warn()    { echo -e "${YELLOW}⚠ $*${RESET}"; }
error()   { echo -e "${RED}✘ $*${RESET}"; exit 1; }

echo ""
echo -e "${BOLD}=== lgd-bus-times dev environment setup ===${RESET}"
echo ""

# ── Prerequisites ────────────────────────────────────────────────────────────

info "Checking prerequisites..."

if ! command -v ddev &>/dev/null; then
  error "DDEV not found. Install from https://ddev.com then re-run this script."
fi
success "DDEV found: $(ddev version | head -1)"

if ! command -v git &>/dev/null; then
  error "git not found."
fi

# ── Agent resources ──────────────────────────────────────────────────────────

info "Installing Claude Code agent resources..."

if command -v agr &>/dev/null; then
  agr add madsnorgaard/drupal-expert
  agr add madsnorgaard/ddev-expert
  agr add madsnorgaard/drupal-reviewer
  success "Agent resources installed."
else
  warn "agr not found — skipping agent resource install."
  warn "To install later:"
  warn "  uv tool install agent-resources"
  warn "  agr add madsnorgaard/drupal-expert ddev-expert drupal-reviewer"
fi

# ── Claude settings.local.json ───────────────────────────────────────────────

if [ ! -f ".claude/settings.local.json" ]; then
  info "Creating .claude/settings.local.json from dist..."
  REPO_PATH="$(pwd)"
  sed "s|<absolute-path-to-repo>|${REPO_PATH}|g" .claude/settings.local.json.dist > .claude/settings.local.json
  success ".claude/settings.local.json created."
else
  success ".claude/settings.local.json already exists — skipping."
fi

# ── Module ───────────────────────────────────────────────────────────────────

if [ ! -d "${MODULE_PATH}/.git" ]; then
  info "Cloning localgov_bus_data module into ${MODULE_PATH}..."
  git clone "${MODULE_REPO}" "${MODULE_PATH}"
  success "Module cloned."
else
  success "Module already present at ${MODULE_PATH} — skipping clone."
fi

# ── DDEV ─────────────────────────────────────────────────────────────────────

info "Starting DDEV..."
ddev start
success "DDEV started."

info "Installing Composer dependencies..."
ddev composer install
success "Dependencies installed."

# ── Drupal install ───────────────────────────────────────────────────────────

info "Installing LocalGov Drupal..."
ddev drush si localgov --existing-config -y
success "Drupal installed."

info "Enabling localgov_bus_data module..."
ddev drush en localgov_bus_data -y
ddev drush cr
success "Module enabled."

# ── Done ─────────────────────────────────────────────────────────────────────

echo ""
echo -e "${GREEN}${BOLD}=== Setup complete ===${RESET}"
echo ""
echo -e "  Site:   ${BOLD}https://lgd-bus-times.ddev.site${RESET}"
echo -e "  Admin:  ${BOLD}https://lgd-bus-times.ddev.site/admin${RESET}"
echo ""
echo "Next steps:"
echo "  make seed          Load fixture data (no BODS connection needed)"
echo "  make import-dry    Preview a live GTFS import from BODS"
echo "  make test          Run the PHPUnit test suite"
echo "  make check         Run lint + PHPStan + tests"
echo ""
