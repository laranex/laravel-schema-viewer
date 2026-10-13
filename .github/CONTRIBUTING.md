# Contribution Guide

Thank you for considering contributing to Laravel Schema Viewer! Please review the following guidelines before submitting a pull request.

For significant changes, please open an issue first so we can discuss the approach.

## Process

1. Fork the project
2. Create a new branch
3. Code, test, commit, and push
4. Open a pull request detailing your changes

## Guidelines

- Ensure the coding style passes by running `composer lint`.
- Send a coherent commit history, making sure each commit in your pull request is meaningful.
- You may need to [rebase](https://git-scm.com/book/en/v2/Git-Branching-Rebasing) to avoid merge conflicts.
- Please remember that we follow [SemVer](http://semver.org/).

## Setup

Clone your fork, then install the dev dependencies:

```bash
composer install
```

## Lint

Lint your code:

```bash
composer lint
```

## Tests

Run all tests:

```bash
composer test
```

## The viewer

The viewer is a React app in `ui/`, built into `dist/`, which is committed. After changing it, build it and commit `dist/` with your change; CI fails when `dist/` does not match `ui/src`. Use the Node version in `ui/.nvmrc`, so the build is the same as CI's:

```bash
cd ui
npm ci
npm run typecheck
npm run build
```

## Browser tests

`ui/e2e` tests the built viewer in Chromium and WebKit, on a desktop and a phone, with [Playwright](https://playwright.dev) and axe for accessibility. They serve `dist/` with a small Node server (`ui/e2e/server.mjs`) on a real snapshot of the shared blog schema:

```bash
cd ui
npx playwright install chromium webkit
npm run build
npm run test:e2e
```

`ui/e2e/fixtures/blog.json` is what `schema-viewer:build` writes for the test schema, and `tests/Feature/UiFixtureTest.php` fails when it falls behind. Write it again with:

```bash
SCHEMA_VIEWER_UPDATE_UI_FIXTURE=1 vendor/bin/pest tests/Feature/UiFixtureTest.php
```

