# Architecture: statscheckup

## Purpose

A PrestaShop statistics module that performs a store health check, highlighting configuration issues such as missing currencies, payment methods, or carrier setup problems.

## Directory Structure

```
statscheckup.php   - Module class; all business logic and rendering
upgrade/           - Migration scripts
tests/             - PHPUnit test stubs and PHPStan bootstrap
translations/      - Locale string overrides
```

## Key Design Decisions

- **Diagnostic queries**: Runs targeted SQL and PrestaShop API calls to assess store configuration completeness.
- **Simple HTML output**: Renders a checklist-style HTML block directly without using the ModuleGrid/Graph engine.

## Extension Points

- Extend `hookDisplayAdminStatsModules()` to add custom diagnostic checks.

## Dependency Flow

```
statscheckup (Module)
  └─> hookDisplayAdminStatsModules() — renders the health check report
        └─> Configuration::get()     — reads store config values
        └─> Db::getInstance()        — optional diagnostic queries
```
