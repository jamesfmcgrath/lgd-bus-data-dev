# lgd-bus-data-dev — Dev Environment

Local development environment for the [`localgov_bus_data`](https://www.drupal.org/project/localgov_bus_data) Drupal module.

This repo provides a preconfigured [LocalGov Drupal](https://localgovdrupal.org) install with DDEV, static analysis tooling, and AI agent context. The module lives in its own repo and is cloned into `web/modules/custom/localgov_bus_data/` (gitignored here).

## Requirements

- [DDEV](https://ddev.com) — local dev environment
- [uv](https://docs.astral.sh/uv/) + [agr](https://github.com/kasperjunge/agent-resources) — Claude Code agent resources (optional but recommended)

## Quick start

```bash
git clone git@github.com:jamesfmcgrath/lgd-bus-data-dev.git  # this dev env repo
cd lgd-bus-data-dev
./scripts/setup.sh
```

The setup script will: install agent resources, clone the module, start DDEV, install Drupal, and enable the module.

## Manual setup

```bash
# 1. Start DDEV and install dependencies
ddev start
ddev composer install

# 2. Clone the module
git clone git@git.drupal.org:project/localgov_bus_data.git web/modules/custom/localgov_bus_data

# 3. Install Drupal
ddev drush si localgov --existing-config -y

# 4. Enable the module
ddev drush en localgov_bus_data -y && ddev drush cr

# 5. Load fixture data (no BODS connection needed)
make seed
```

## Common commands

```bash
make help          # List all available commands

make seed          # Load fixture data for local development
make import        # Run incremental GTFS import from BODS
make import-full   # Full re-import (rollback first)
make import-dry    # Preview import without writing data

make test          # Run PHPUnit test suite
make lint          # PHPCS
make lint-fix      # PHPCBF auto-fix
make stan          # PHPStan static analysis
make check         # Run all quality checks

make cr            # Clear Drupal caches
make si            # Fresh Drupal install
make open          # Open site in browser
```

## Project structure

```
.claude/
  MEMORY.md              # AI agent persistent memory — keep this current
  settings.local.json    # Machine-specific Claude Code permissions (gitignored)
  settings.local.json.dist  # Template — copy and edit for your machine
.ddev/
  config.yaml            # DDEV project config
scripts/
  setup.sh               # One-shot environment setup
CLAUDE.md                # AI coding guidelines (Claude Code + Cowork)
SPEC.md                  # Full project specification and phased delivery plan
Makefile                 # Dev workflow shortcuts
```

## AI agent setup (Claude Code)

Install skills and the code-review agent once after cloning (from the repo root so paths land under `.claude/` here):

```bash
uv tool install agr
agr add madsnorgaard/drupal-agent-resources/drupal-expert --overwrite
agr add madsnorgaard/drupal-agent-resources/ddev-expert --overwrite
mkdir -p .claude/agents
curl -fsSL -o .claude/agents/drupal-reviewer.md \
  https://raw.githubusercontent.com/madsnorgaard/drupal-agent-resources/main/.claude/agents/drupal-reviewer.md
```

[`agr`](https://github.com/kasperjunge/agent-resources) current releases only install **skills** (and may mirror them under `.cursor/skills/`). **drupal-reviewer** is a Claude Code **agent** (`.claude/agents/*.md`), so it is fetched with `curl` as above. `./scripts/setup.sh` does the same.

Copy and edit the Claude Code permissions file:

```bash
cp .claude/settings.local.json.dist .claude/settings.local.json
# Edit settings.local.json — replace <absolute-path-to-repo> with your actual path
```

## Module

The `localgov_bus_data` module is a standalone, reusable Drupal 10/11 module for UK council bus timetables, powered by BODS GTFS data. See `SPEC.md` for the full architecture and phased delivery plan.

**Current status:** Alpha — Phases 1–4 complete (GTFS import, entities, Views, NaPTAN enrichment). Phase 5 (real-time departure times) is next.

## Stack

- Drupal 10.2+ / LocalGov Drupal 3.x
- PHP 8.3, MariaDB 10.6, nginx-fpm (via DDEV)
- BODS GTFS bulk download (no API key required for Phases 1–4)
- Leaflet.js + OpenStreetMap
- NaPTAN stop enrichment
