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

The viewer is the shared [laranex-schema-viewer-ui](https://www.npmjs.com/package/laranex-schema-viewer-ui) package, pinned by `ui.lock.json` (version, tarball and integrity). Its verified files are committed under `resources/viewer/` and `resources/contract/`, and `ui.stamp.json` records their SHA-256; CI fails when they differ from the locked tarball. After changing `ui.lock.json`, install and check them:

```bash
composer ui:fetch
composer ui:check
```

## Browser test

`scripts/browser-smoke.mjs` opens the viewer as the package serves it in headless Chrome, under the page's own Content-Security-Policy, with no Node.js dependencies:

```bash
composer build
php vendor/bin/testbench schema-viewer:build
php vendor/bin/testbench serve --port=8000 &
VIEWER_URL=http://127.0.0.1:8000/schema-viewer node scripts/browser-smoke.mjs
```
