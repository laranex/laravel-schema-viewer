# Security Policy

**PLEASE DON'T DISCLOSE SECURITY-RELATED ISSUES PUBLICLY, [SEE BELOW](#reporting-a-vulnerability).**

## Supported Versions

Only the latest major version receives security fixes.

## Reporting a Vulnerability

If you discover a security vulnerability within Laravel Schema Viewer, please send an email to Nay Thu Khant at naythukhant644@gmail.com. All security vulnerabilities will be promptly addressed.

You can also report it privately through [GitHub private vulnerability reporting](https://github.com/laranex/laravel-schema-viewer/security/advisories/new). Do not put vulnerability details or a real schema snapshot in a public issue, pull request or discussion.

## Security model

Laravel Schema Viewer is a development tool. The generic, cross-framework policy is the shared viewer's [security page](https://laranex.github.io/schema-viewer-ui/security.html); this section covers what the Laravel package does itself and what is left to you.

| Concern | The package enforces | You (the application or repository owner) must |
| --- | --- | --- |
| Viewer routes | One route group, the page and every asset, with the `Authorize` middleware added after your configured `middleware` on every route. It lets in the `SchemaViewer::auth()` callback's answer when one is set; otherwise loopback requests in `local` (below); otherwise users the `viewSchema` gate allows. Everyone else gets 403. | Define the `viewSchema` gate or `SchemaViewer::auth()` for the few users who need the viewer; keep the `web` middleware (or your own session and authentication) so the gate sees the user. |
| `local` environment | Without a callback, only requests from this machine to this machine: a loopback peer address (127.0.0.0/8, ::1) and a `localhost`, `*.localhost` or loopback `Host`, read from the request itself, never from proxy headers. A teammate on the network, a tunnel or a public name needs the gate. | Never run a shared or reachable server with `APP_ENV=local`. |
| Production and every other environment | The routes are not registered unless `enabled` (`SCHEMA_VIEWER_ENABLED`) is set; unset means `local` only. | Keep it off. Enabling it is a security exception, not a recommended deployment, and requires an application-owned `viewSchema` gate or `SchemaViewer::auth()` callback, HTTPS, and a fresh `schema-viewer:build` in the deploy. |
| Page and schema data | The document is inlined in the page as a JSON data block; there is no API and no URL that serves `schema-viewer.json`. The page is sent with `Cache-Control: no-store`, a strict Content-Security-Policy (`script-src 'self'`, no inline script, `frame-ancestors 'none'`), `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin` and a `noindex` robots tag. No model code runs in a request. | Keep full-page caches, proxies and CDNs from storing the page; never add a route or static path that serves `schema-viewer.json`. |
| Errors | The 403 and the asset route's 404 carry `Cache-Control: no-store` and nothing about the schema. | Keep your exception handler from adding schema details to error pages. |
| Viewer assets | Only the files the viewer's manifest lists, at the manifest's size, under the bundle's fingerprint, behind the same `Authorize` check, cached `private` (never by shared caches), `nosniff`. The viewer's own `index.html` is never served. `composer ui:check` verifies every file's SHA-256 against the npm tarball `ui.lock.json` pins, in CI. | Never copy the viewer files into `public/` or a CDN; make sure your caches honour `private`. |
| Snapshot file | `schema-viewer:build` writes `schema-viewer.json` (and `schema-viewer.changes.json`) in the project root, outside `public/`. Inside a Git work tree it warns when the file is not ignored and exits 1 without writing when Git tracks it. | Add `/schema-viewer.json`, `/schema-viewer.changes.json` and `/schema-viewer/` to `.gitignore`; build the file in CI and in the deploy script instead of committing it; protect the server's file system and deploy artifacts. |
| Exports | `schema-viewer:export` refuses to write into `public/` or `storage/app/public` (resolved through symbolic links), applies the build's Git checks to `--output`, and notes on standard error that an export is sensitive. The HTML export carries its own strict Content-Security-Policy. | Store and share exported files responsibly, including the files the viewer's Download dialog makes in the browser. |
| Snapshots in Git | `schema-viewer:scan` (below). | Run it in a pre-commit hook and in CI; review every allowlist change. |
| Reading the models | Every command that reads the models (`schema-viewer:build`, `build --check`, `schema-viewer:export`, `schema-viewer:check`, and `schema-viewer:diff` when it compares with the current schema) constructs every model and calls its relation methods with every connection pretending (queries are logged, never sent) and model events off, with jobs, queued jobs, mail, notifications and the HTTP client faked, the default cache kept in memory and, while relation methods run, events faked; everything is put back before the command writes or prints its result. The viewer page, `schema-viewer:diff` without a base or with `--against`, and `schema-viewer:scan` only read files and run no model code. | Skip side effects that go around Laravel's facades and clients (a direct SDK or Guzzle call, a raw Redis write, a file write) when `SchemaViewer::building()` is `true`, behind a `class_exists()` check. Run the commands only where the application's code may run. |
| Database inspection | Tables, columns, indexes and foreign keys are read through Laravel's schema builder; no rows are queried. | Nothing beyond the connections you configure. |
| MCP | The package has no MCP server. | Not applicable. |

## Schema snapshots are sensitive

`schema-viewer.json`, `schema-viewer.changes.json` and every export of a private schema (JSON, the offline HTML viewer, DBML, Mermaid, Markdown, SVG, PNG) list every table and column, foreign keys, the models' fillable, hidden, casts and scopes, notes, and source file paths. Treat them as you treat the application's source: never commit them, even to a private repository, and never publish them. The same goes for the output of `schema-viewer:check --format=json` and `schema-viewer:diff` kept as CI artifacts or job summaries: they name tables and columns to everyone who can read the job, so restrict access and keep their retention short.

## Protecting the page alone is insufficient

The schema reaches a browser through the page, its assets, downloads made from it, any iframe that embeds it, and every cache, CDN or object store a copy passes through. Each of these paths needs the same server-side protection. The package protects its own routes (page and assets alike) and sends `Cache-Control: no-store` on the page and its errors; anything you add in front of it or beside it must do the same. Beyond localhost, serve it over HTTPS only. Authentication is enforced only by the server, here the application and the package's `Authorize` middleware, never by the viewer in the browser.

## Development-only viewer

The viewer is a development tool: install the package with `--dev` and never expose the viewer in production. Authentication is mandatory, even locally. In `local` the package's default is the one narrow exception: requests from the developer's own machine to itself, over loopback. Any shared development, staging or UAT server needs the `viewSchema` gate or `SchemaViewer::auth()`. Production is disabled by default; enabling it there is a documented security exception that requires an application-owned gate or `auth()` callback, not a recommended deployment.

## Git protection

`php artisan schema-viewer:scan` finds laranex-schema documents by content, with the options, report and exit codes of the shared scanner. Name at least one scope: `--tracked` (the Git index: staged bytes, and files tracked before they were ignored), `--untracked` (files Git would add), `--artifacts DIR` (a build output directory, ignore rules not applied, repeatable) and `--history` (every past version; fails on a shallow clone). `--json` prints the `laranex-schema-scan` report and `--report FILE` writes it; it holds paths and reasons, never file content.

- **Exit codes:** 0 clean, 1 a snapshot found, 2 the scan failed or is incomplete (usage errors included), 3 something looks like a snapshot. CI must fail on any non-zero exit.
- **Allowlist:** a synthetic fixture committed on purpose is listed in `.laranex-schema-allowlist.json` at the project root by exact path and SHA-256; a changed or moved file is reported again. An entry is an explicit review decision, not proof that the content is harmless. Never allowlist a real application's snapshot to make the scan pass.
- **`.gitignore`:** the package has no `init` command. `schema-viewer:build` and `schema-viewer:export --output` check their files with Git: a warning on standard error when a file is not ignored, exit 1 before writing when Git tracks it, with the `git rm --cached` command to run.
- **Pre-commit and CI:** the [README](../README.md#keep-the-schema-out-of-git) has the Git hook (`php artisan schema-viewer:scan --tracked`), the Composer script and the CI step.

## Limitations

- Detection is by content, of the supported formats only, and candidates are chosen by file extension (`.json`, `.html`, `.htm`, plus `.js`, `.mjs`, `.cjs` and `.map` with `--artifacts`).
- Compressed or encoded payloads are not scanned, for example the base64+gzip offline HTML files laravel-schema-viewer 1.x exported.
- Renamed files, HTML-entity-escaped documents, and SVG, PNG, DBML, Mermaid and Markdown exports cannot be detected by content.
- The scan guards against accidents, not adversaries.
- Isolation while reading the models covers Laravel's facades and clients only, as described above; it is not a sandbox, and code that goes around them runs for real.

## Incident response

When a snapshot or an export reached Git or was published:

1. Remove it from the index (`git rm --cached -- <path>`), add the ignore rule, and commit.
2. Treat anything already pushed or shared as disclosed. Find every copy with `php artisan schema-viewer:scan --history`.
3. Rewrite the history (`git filter-repo`) only with the repository owner's approval, and coordinate the force-push and the re-clones.
4. Purge CI artifacts, caches, CDN and Pages copies, and forks where possible.
5. Rotate anything the schema revealed, such as secrets in column defaults or comments. Rotation is the fix; rewriting the history is cleanup.

## Responsibility boundary

The package is responsible for safe defaults, for protecting the snapshots it generates, for the scanner's checks of the supported formats, and for serving the viewer securely. Once you deliberately download or export a standalone file, storing, sharing and distributing it is the repository owner's responsibility; the package does not track it.

## Disclaimer

Laravel Schema Viewer is provided under the MIT License, "as is" and without warranty; see [LICENSE.md](../LICENSE.md).
