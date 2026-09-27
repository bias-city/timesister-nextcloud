# A new Nextcloud X is out – runbook

Kept short, for people and for Claude. Goal: bring the app up to a new
Nextcloud major version in one pull request, changing no more than
necessary.

**Task for Claude** (as an issue comment with `@claude`, or locally):

> Read UPDATE.md and bring the app to Nextcloud X.

## Rules

- Only public interfaces `OCP\…` via constructor injection. No `\OC\`, no
  `\OC_`, no `\OC::$server`, no `OCA\DAV`, no other app.
- Database only via `IDBConnection`, `IQueryBuilder`, `QBMapper`,
  `OCP\DB\Types`. No raw SQL. Migrations only additive (new class
  `lib/Migration/Version<NNNN>Date<YYYYMMDDHHMMSS>`), never change an old
  one.
- Whatever Psalm reports as *deprecated* is a bug: replace it, don't
  suppress it.
- The API (`docs/API.md`, version 2) stays as it is. Should it need to
  change, that's a separate decision, not part of an update.
- Admin page: vanilla JS without `OC.*`, token from
  `document.head.dataset.requesttoken`, styles only via Nextcloud's CSS
  variables.

## Steps

<!-- checkliste -->
- [ ] **Read the early warning:** issue with label `nc-update` – which version, which step, which log excerpt.
- [ ] **Tool:** `tools/nc-version.sh X [path/to/docker-compose.yml]` – raises `max-version`, the Psalm stubs and the Docker tag.
- [ ] **Psalm:** `composer run psalm:all` – against every version from `info.xml` and master; fix all findings.
- [ ] **PHP version:** `phpVersion` in `psalm.xml` = the smallest PHP version among the supported Nextcloud versions (the tool says which).
- [ ] **Unit tests:** `composer run test:unit`.
- [ ] **Development Nextcloud with the new tag:** reset and set up (backs up the database first).
- [ ] **Integration:** `NC_URL=http://localhost:8081 composer run test:integration` – all green.
- [ ] **Version:** raise `<version>` in `appinfo/info.xml` (patch for a plain adjustment), add to `CHANGELOG.md`.
- [ ] **Pull request** referencing the issue; CI green (lint, Psalm, PHPUnit min/max).
- [ ] **Tag** `nc-app-v<version>` after the merge.
- [ ] **App Store:** GitHub release with tag `v<version>` in the app's repo; building, signing and uploading is done by `appstore-build-publish.yml`.
<!-- /checkliste -->

## Details

### Tool

```sh
tools/nc-version.sh 35                                   # app only
tools/nc-version.sh 35 ../../docker-next/docker-compose.yml   # in the monorepo
NC_COMPOSE_FILE=/path/docker-compose.yml tools/nc-version.sh 35
```

Composer: `composer` in the PATH, or
`COMPOSER_BIN="php /path/composer.phar"`. `min-version` stays; if only two
major versions should be supported, raise it by hand.

### Psalm findings

- *Deprecated*: use the replacement named in the docblock
  (`vendor/nextcloud/ocp`).
- New signatures (return types, parameters): adapt to the public interface,
  don't set `@psalm-suppress`.
- If `min-version` doesn't support the new form, a small adapter in
  `lib/Service` helps – or raise `min-version`.

### Development Nextcloud (monorepo)

`docker-next/zuruecksetzen.sh` backs up the database to
`~/ClaudeBackup/databases/`, rebuilds it and creates the test accounts.

### Signing and App Store

Done by `.github/workflows/appstore-build-publish.yml` on every published
release (once beforehand: [First App Store
release](#first-app-store-release)). By hand, should the workflow fail:

1. Package without the files from `.nextcloudignore`:
   `rsync -a --exclude-from=.nextcloudignore ./ ../build/timesister/ && tar -C ../build -czf ../build/timesister.tar.gz timesister`
2. Sign the archive: `openssl dgst -sha512 -sign <key> ../build/timesister.tar.gz | openssl base64`
3. Upload in the App Store (apps.nextcloud.com) with a link to the release
   archive and the signature.

### CI and early warning

- On every push: lint (PHP min/max, info.xml) and Psalm (min…max).
- On pull requests and on `main`: PHPUnit and integration against min and
  max (Nextcloud checkout with SQLite).
- Weekly (`fruehwarnung.yml`): latest release and master; a failure opens
  issue `nc-update`, and calls Claude on a new major version.
- On a published release (`appstore-build-publish.yml`): package, signature,
  App Store; off as long as the three secrets are missing.
- Claude (`claude.yml`) only runs when the secret `ANTHROPIC_API_KEY` is set
  and the Claude GitHub app is installed.

## First App Store release

One-off; after that, one release per version suffices. The private key
stays with the maintainer and goes into no repo, only into GitHub as a
secret.

1. **Get the certificate** once the pull request
   [nextcloud/app-certificate-requests#1270](https://github.com/nextcloud/app-certificate-requests/pull/1270)
   is merged: the file `timesister/timesister.crt` in the repo
   `nextcloud/app-certificate-requests`. If it matches the key,
   `openssl x509 -in timesister.crt -noout -pubkey` and
   `openssl pkey -in <key> -pubout` give the same output.
2. **Register the app** on [apps.nextcloud.com](https://apps.nextcloud.com)
   (signed in: "Register app"): paste in the certificate, plus the
   signature of the app ID:
   `echo -n "timesister" | openssl dgst -sha512 -sign <key> | openssl base64`
3. **Secrets** in the repo `bias-city/timesister-nextcloud` (Settings →
   Secrets and variables → Actions):
   - `APP_PRIVATE_KEY` – the private key (the whole PEM file)
   - `APP_PUBLIC_CRT` – the contents of `timesister.crt`
   - `APPSTORE_TOKEN` – the API token of the account on apps.nextcloud.com

   As long as one is missing, the workflow stays off and reports that as a
   notice.
4. **Raise the version:** `<version>` in `appinfo/info.xml` and an entry in
   `CHANGELOG.md`; get it onto `main`. The image for the store
   (`img/screenshot-admin.png`) must be there; `info.xml` links to it.
5. **Publish a release** on GitHub with tag `v<version>`, e.g.
   `gh release create v0.1.0 --title 0.1.0 --notes "…"`. The workflow
   checks the tag against `info.xml`, builds, signs, attaches
   `timesister-v<version>.tar.gz` to the release and uploads it to the
   store.
</content>
