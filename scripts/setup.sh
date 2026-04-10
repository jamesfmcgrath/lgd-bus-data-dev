#!/usr/bin/env bash
# lgd-bus-data-dev — one-shot dev environment setup
# Run from the repo root: ./scripts/setup.sh
set -euo pipefail

BOLD="\033[1m"
GREEN="\033[32m"
YELLOW="\033[33m"
RED="\033[31m"
RESET="\033[0m"

MODULE_REPO="git@git.drupal.org:project/localgov_bus_data.git"
MODULE_PATH="web/modules/custom/localgov_bus_data"
DRUPAL_AGENT_RESOURCES_AGENT_URL="https://raw.githubusercontent.com/madsnorgaard/drupal-agent-resources/main/.claude/agents/drupal-reviewer.md"

info()    { echo -e "${BOLD}▶ $*${RESET}"; }
success() { echo -e "${GREEN}✔ $*${RESET}"; }
warn()    { echo -e "${YELLOW}⚠ $*${RESET}"; }
error()   { echo -e "${RED}✘ $*${RESET}"; exit 1; }

echo ""
echo -e "${BOLD}=== lgd-bus-data-dev dev environment setup ===${RESET}"
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
  # --overwrite: safe to re-run setup; agr errors if a skill dir already exists
  agr add madsnorgaard/drupal-agent-resources/drupal-expert --overwrite
  agr add madsnorgaard/drupal-agent-resources/ddev-expert --overwrite
  success "Skills installed (drupal-expert, ddev-expert)."
else
  warn "agr not found — skipping skill install (drupal-expert, ddev-expert)."
  warn "To install later: uv tool install agr"
  warn "  agr add madsnorgaard/drupal-agent-resources/drupal-expert --overwrite"
  warn "  agr add madsnorgaard/drupal-agent-resources/ddev-expert --overwrite"
fi

# Current agr only manages skills; drupal-reviewer is a Claude Code agent (.md under .claude/agents/).
info "Installing drupal-reviewer agent..."
mkdir -p .claude/agents
if command -v curl &>/dev/null; then
  if curl -fsSL -o .claude/agents/drupal-reviewer.md "${DRUPAL_AGENT_RESOURCES_AGENT_URL}"; then
    success "drupal-reviewer agent installed to .claude/agents/drupal-reviewer.md"
  else
    warn "Could not download drupal-reviewer agent. Install manually:"
    warn "  curl -fsSL -o .claude/agents/drupal-reviewer.md \\"
    warn "    ${DRUPAL_AGENT_RESOURCES_AGENT_URL}"
  fi
else
  warn "curl not found — cannot download drupal-reviewer agent."
  warn "Save manually to .claude/agents/drupal-reviewer.md from:"
  warn "  ${DRUPAL_AGENT_RESOURCES_AGENT_URL}"
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
# Note: --existing-config cannot be used with the localgov profile because it
# implements hook_install(). Instead we do a plain install then import config.

info "Installing LocalGov Drupal..."
ddev drush si localgov --account-pass=admin -y
success "Drupal installed."

# info "Importing configuration..."
# ddev drush cim -y
# success "Configuration imported."

info "Enabling localgov_bus_data module..."
ddev drush en localgov_bus_data -y
ddev drush cr
success "Module enabled."

# ── Done ─────────────────────────────────────────────────────────────────────

echo ""
echo -e "${GREEN}${BOLD}=== Setup complete ===${RESET}"
echo ""
echo -e "  Site:   ${BOLD}https://lgd-bus-data-dev.ddev.site${RESET}"
echo -e "  Admin:  ${BOLD}https://lgd-bus-data-dev.ddev.site/admin${RESET}"
echo ""
echo "Next steps:"
echo "  make seed          Load fixture data (no BODS connection needed)"
echo "  make import-dry    Preview a live GTFS import from BODS"
echo "  make test          Run the PHPUnit test suite"
echo "  make check         Run lint + PHPStan + tests"
echo ""
