---
title: Contributing
description: How to contribute to Sugar.
---

# Contributing

Sugar is actively developed and welcomes contributions. Check out the issues or submit a PR.

::: tip
Keep PRs focused and small when possible. It is easier to review, test, and ship.
:::

## Workflow

1. Create a branch.
2. Add or update tests with your change.
3. Run the checks locally.
4. Open a PR with a clear description and screenshots for docs or UX changes.

::: warning
All PRs must be green in CI before they can be merged.
:::

## Testing and Quality Tools

Sugar ships with a full test and analysis toolchain. Use the Composer scripts so local checks match CI.

::: code-group
```bash [Unit tests]
composer test
```

```bash [Static analysis]
composer phpstan
```

```bash [Code style]
composer cs-check
composer cs-fix
```

```bash [Rector]
composer rector-check
composer rector-fix
```
:::

::: details What each tool does
- **PHPUnit:** Executes the unit and integration tests.
- **PHPStan:** Static analysis for type and logic issues.
- **PHPCS/PHPCBF:** Code style enforcement and auto-fixes.
- **Rector:** Automated refactoring based on project rules.
:::

## CI Expectations

Every PR is expected to pass tests and static analysis. If a change breaks a check, update the code or the tests until CI is green.
