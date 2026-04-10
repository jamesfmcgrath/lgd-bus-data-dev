##
## lgd-bus-data-dev — dev environment for localgov_bus_data module
## Usage: make <target>
##

.PHONY: help start stop restart open test lint lint-fix stan \
        import import-dry import-full seed cr si enable logs \
        mod-log mod-status mod-fetch mod-branch tag switch mr

MODULE = web/modules/custom/localgov_bus_data

## ── Environment ─────────────────────────────────────────────────────────────

help: ## Show this help
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage: make \033[36m<target>\033[0m\n\nTargets:\n"} \
	/^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)

start: ## Start DDEV
	ddev start

stop: ## Stop DDEV
	ddev stop

restart: ## Restart DDEV
	ddev restart

open: ## Open site in browser
	ddev launch

logs: ## Tail web server logs
	ddev logs -f

## ── Drupal ───────────────────────────────────────────────────────────────────

si: ## Fresh Drupal install (LocalGov profile)
	ddev drush si localgov --existing-config -y
	ddev drush cr

enable: ## Enable the localgov_bus_data module
	ddev drush en localgov_bus_data -y
	ddev drush cr

cr: ## Clear Drupal caches
	ddev drush cr

## ── Bus data ─────────────────────────────────────────────────────────────────

import: ## Run incremental GTFS import
	ddev drush bus-times:import

import-dry: ## Dry run — show what would be imported
	ddev drush bus-times:import --dry-run

import-full: ## Full import — rollback and re-import everything
	ddev drush bus-times:import --full

seed: ## Load fixture data for local development (no BODS connection needed)
	ddev drush bus-times:seed
	ddev drush cr

## ── Quality ──────────────────────────────────────────────────────────────────

test: ## Run PHPUnit tests
	ddev exec vendor/bin/phpunit --testsuite custom

lint: ## Run PHPCS against the module
	ddev exec vendor/bin/phpcs $(MODULE)

lint-fix: ## Auto-fix PHPCS violations with PHPCBF
	ddev exec vendor/bin/phpcbf $(MODULE)

stan: ## Run PHPStan static analysis
	ddev exec vendor/bin/phpstan analyse $(MODULE)

check: lint stan test ## Run all quality checks (lint + stan + test)

## ── Maintainer (Drupal.org) ──────────────────────────────────────────────────

mod-log: ## Recent module commits (usage: make mod-log or make mod-log N=40)
	git -C $(MODULE) log --oneline -$${N:-20}

mod-status: ## Git status of the module
	git -C $(MODULE) status

mod-fetch: ## Fetch latest from Drupal.org
	git -C $(MODULE) fetch origin
	@echo ""
	@git -C $(MODULE) log --oneline -5 origin/$$(git -C $(MODULE) rev-parse --abbrev-ref HEAD)

mod-branch: ## List branches and show current
	git -C $(MODULE) branch -av

tag: ## Tag and push a release  (usage: make tag VERSION=1.0.0-alpha1)
	@test -n "$(VERSION)" || (echo "Usage: make tag VERSION=1.0.0-alpha1" && exit 1)
	git -C $(MODULE) tag $(VERSION)
	git -C $(MODULE) push origin $(VERSION)
	@echo "Tagged and pushed $(VERSION)"

switch: ## Switch module branch  (usage: make switch BRANCH=1.0.x)
	@test -n "$(BRANCH)" || (echo "Usage: make switch BRANCH=1.0.x" && exit 1)
	git -C $(MODULE) checkout $(BRANCH)

mr: ## Check out a contributor MR for review  (usage: make mr MR=123)
	@test -n "$(MR)" || (echo "Usage: make mr MR=123" && exit 1)
	git -C $(MODULE) fetch origin merge-requests/$(MR)/head:mr-$(MR)
	git -C $(MODULE) checkout mr-$(MR)
	@echo "On branch mr-$(MR) — run 'make switch BRANCH=1.0.x' when done reviewing"
