# Drupal Development Guidelines

This is a Drupal 10/11 project — the `localgov_bus_data` module for Cumberland Council bus timetables. Follow these guidelines when working on Drupal code.

## Project Context

- **Module:** `web/modules/custom/localgov_bus_data/`
- **DDEV:** `lgd-bus-times` at `https://lgd-bus-times.ddev.site` (PHP 8.3, nginx-fpm)
- **Stack:** Drupal 10.2+, LGD (LocalGov Drupal), BODS GTFS bulk download, Leaflet.js + geofield, NaPTAN
- **Key module:** Phase 5 only (real-time SIRI-SM auth) — not in current codebase
- **Spec:** See `SPEC.md` for full phased delivery plan and architecture decisions
- **Do not use git worktrees** — work directly in the repo on the feature branch

## DDEV

Run all commands via DDEV from the repo root:

```bash
ddev drush <command>
ddev composer <command>
```

Never run `drush` or `php` directly outside DDEV.

## Research-First

**Before writing custom code:** Check drupal.org for existing contrib modules. Prefer contrib over custom.

## Code Standards

- **PHP 8.3**: Constructor property promotion, typed properties, `declare(strict_types=1)` in every file
- **PHP Attributes**: Use `#[Block(...)]` style for plugins, not `@Block` annotations
- **Dependency injection**: Never use `\Drupal::service()` in classes; always inject via constructor
- **Config schema**: Required for all custom configuration (`config/schema/`)
- **`final` classes**: Prefer `final` on service and form classes unless extension is required

## Key Patterns

- Use Drush generators: `ddev drush generate module`, `ddev drush field:create`, etc.
- Use parameterized queries; never concatenate user input into SQL
- Add cache metadata to render arrays: `#cache` with tags, contexts, max-age
- Use `#plain_text` or `Xss::filterAdmin()` for user content; never raw `#markup` with unsanitized input
- API keys: always store in Key module entities (`drupal/key`), never in plain config — Key module will be added in Phase 5 for SIRI-SM real-time auth

## Drupal AJAX Form Pattern

When a form class (extending `FormBase` or `ConfigFormBase`) injects a service used in an AJAX callback:

- The injected service property **must be `protected`** (not `private` or `readonly`) so `DependencySerializationTrait::__sleep()` can detect it via `ReverseContainer`. `private` properties are invisible to `get_object_vars()` when called from the parent class scope.
- **Never override `__sleep()`** — the trait handles serialization automatically once visibility is correct.
- AJAX callbacks must **build their result element directly** on `$form` before returning it. Do not rely on `$form_state->setRebuild(TRUE)` inside the callback to populate the result — the rebuild happens after the callback returns.

## Agent Resources

This project uses three specialised agent resources. Use them proactively — do not wait to be asked.

### drupal-expert
Use for any Drupal implementation question, API lookup, hook usage, or architecture decision. Trigger it whenever you are about to write non-trivial Drupal code and are uncertain about the correct API, pattern, or contrib option.

### drupal-reviewer
**Always run after writing or modifying Drupal PHP files.** This catches security issues, DI violations, render-array escaping gaps, and best-practice problems that PHPCS will not catch. Do not skip this step.

### ddev-expert
Use when troubleshooting DDEV container issues, config, or service problems. Trigger on any `ddev` error that isn't an obvious application-level issue.

To install (one-time setup):

```bash
uv tool install agent-resources
agr add madsnorgaard/drupal-expert
agr add madsnorgaard/ddev-expert
agr add madsnorgaard/drupal-reviewer
```
