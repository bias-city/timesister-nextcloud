# Nextcloud X ist erschienen – Ablaufblatt

Für Menschen und für Claude. Ziel: die App in einem Pull-Request auf eine neue
Nextcloud-Hauptversion bringen, ohne mehr zu ändern als nötig.

**Auftrag an Claude** (als Issue-Kommentar mit `@claude` oder lokal):

> Lies UPDATE.md und bring die App auf Nextcloud X.

## Regeln

- Nur öffentliche Schnittstellen `OCP\…` per Konstruktor-Injektion. Kein
  `\OC\`, kein `\OC_`, kein `\OC::$server`, kein `OCA\DAV`, keine andere App.
- Datenbank nur über `IDBConnection`, `IQueryBuilder`, `QBMapper`,
  `OCP\DB\Types`. Kein rohes SQL. Migrationen nur additiv (neue Klasse
  `lib/Migration/Version<NNNN>Date<YYYYMMDDHHMMSS>`), nie eine alte ändern.
- Was Psalm als *deprecated* meldet, ist ein Fehler: ersetzen, nicht unterdrücken.
- Die Schnittstelle (`docs/API.md`, Fassung 1) bleibt, wie sie ist. Muss sie
  sich ändern, ist das eine eigene Entscheidung, kein Update.
- Admin-Seite: Vanilla-JS ohne `OC.*`, Token aus `document.head.dataset.requesttoken`,
  Stile nur über Nextclouds CSS-Variablen.

## Schritte

<!-- checkliste -->
- [ ] **Frühwarnung lesen:** Issue mit Label `nc-update` – welche Version, welcher Schritt, welcher Log-Auszug.
- [ ] **Werkzeug:** `tools/nc-version.sh X [pfad/zu/docker-compose.yml]` – hebt `max-version`, die Psalm-Stubs und das Docker-Tag.
- [ ] **Psalm:** `composer run psalm:all` – gegen jede Version aus `info.xml` und master; alle Befunde beheben.
- [ ] **PHP-Fassung:** `phpVersion` in `psalm.xml` = kleinste PHP-Fassung der unterstützten Versionen (das Werkzeug sagt es).
- [ ] **Unit-Tests:** `composer run test:unit`.
- [ ] **Entwicklungs-Nextcloud mit neuem Tag:** zurücksetzen und aufsetzen (sichert vorher die Datenbank).
- [ ] **Integration:** `NC_URL=http://localhost:8081 composer run test:integration` – alle grün.
- [ ] **Fassung:** `<version>` in `appinfo/info.xml` heben (Patch für reine Anpassung), `CHANGELOG.md` ergänzen.
- [ ] **Pull-Request** mit Verweis auf das Issue; CI grün (Lint, Psalm, PHPUnit min/max).
- [ ] **Tag** `nc-app-v<version>` nach dem Merge.
- [ ] **App Store:** GitHub-Release mit Tag `v<version>` im Repo der App; bauen, signieren und hochladen macht `appstore-build-publish.yml`.
<!-- /checkliste -->

## Einzelheiten

### Werkzeug

```sh
tools/nc-version.sh 35                                   # nur App
tools/nc-version.sh 35 ../../docker-next/docker-compose.yml   # im Monorepo
NC_COMPOSE_FILE=/pfad/docker-compose.yml tools/nc-version.sh 35
```

Composer: `composer` im PATH oder `COMPOSER_BIN="php /pfad/composer.phar"`.
`min-version` bleibt; sollen nur zwei Hauptversionen gelten, von Hand heben.

### Psalm-Befunde

- *Deprecated*: den im Docblock genannten Ersatz nehmen (`vendor/nextcloud/ocp`).
- Neue Signaturen (Rückgabetypen, Parameter): an die öffentliche Schnittstelle
  anpassen, nicht `@psalm-suppress` setzen.
- Unterstützt `min-version` die neue Form nicht, hilft ein kleiner Adapter in
  `lib/Service` – oder `min-version` heben.

### Entwicklungs-Nextcloud (Monorepo)

`docker-next/zuruecksetzen.sh` sichert die Datenbank nach
`~/ClaudeBackup/databases/`, baut neu auf und legt die Testkonten an.

### Signatur und App Store

Macht `.github/workflows/appstore-build-publish.yml` bei jedem veröffentlichten
Release (einmal vorher: [Erste Veröffentlichung](#erste-veröffentlichung-im-app-store)).
Von Hand, falls der Workflow ausfällt:

1. Paket ohne die Dateien aus `.nextcloudignore`:
   `rsync -a --exclude-from=.nextcloudignore ./ ../build/timesister/ && tar -C ../build -czf ../build/timesister.tar.gz timesister`
2. Signatur des Archivs: `openssl dgst -sha512 -sign <schlüssel> ../build/timesister.tar.gz | openssl base64`
3. Upload im App Store (apps.nextcloud.com) mit Link zum Release-Archiv und Signatur.

### CI und Frühwarnung

- Bei jedem Push: Lint (PHP min/max, info.xml) und Psalm (min…max).
- Bei Pull-Requests und auf `main`: PHPUnit und Integration gegen min und max
  (Nextcloud-Checkout mit SQLite).
- Wöchentlich (`fruehwarnung.yml`): neueste Veröffentlichung und master;
  Fehler → Issue `nc-update`, bei einer neuen Hauptversion ruft sie Claude.
- Bei einem veröffentlichten Release (`appstore-build-publish.yml`): Paket,
  Signatur, App Store; aus, solange die drei Secrets fehlen.
- Claude (`claude.yml`) läuft nur, wenn das Secret `ANTHROPIC_API_KEY` gesetzt
  ist und die Claude-GitHub-App installiert ist.

## Erste Veröffentlichung im App Store

Einmalig; danach genügt für jede Fassung ein Release. Der private Schlüssel
liegt beim Maintainer und kommt in kein Repo, nur als Secret zu GitHub.

1. **Zertifikat holen**, sobald der Pull-Request
   [nextcloud/app-certificate-requests#1270](https://github.com/nextcloud/app-certificate-requests/pull/1270)
   gemergt ist: die Datei `timesister/timesister.crt` im Repo
   `nextcloud/app-certificate-requests`. Passt es zum Schlüssel, geben
   `openssl x509 -in timesister.crt -noout -pubkey` und
   `openssl pkey -in <schlüssel> -pubout` dasselbe aus.
2. **App registrieren** auf [apps.nextcloud.com](https://apps.nextcloud.com)
   (angemeldet: „Register app“): das Zertifikat einfügen, dazu die Signatur
   der App-ID:
   `echo -n "timesister" | openssl dgst -sha512 -sign <schlüssel> | openssl base64`
3. **Secrets** im Repo `bias-city/timesister-nextcloud` setzen
   (Settings → Secrets and variables → Actions):
   - `APP_PRIVATE_KEY` – der private Schlüssel (ganze PEM-Datei)
   - `APP_PUBLIC_CRT` – der Inhalt von `timesister.crt`
   - `APPSTORE_TOKEN` – das API-Token des Kontos auf apps.nextcloud.com

   Solange eines fehlt, bleibt der Workflow aus und meldet das als Hinweis.
4. **Fassung heben:** `<version>` in `appinfo/info.xml` und einen Eintrag in
   `CHANGELOG.md`; auf `main` bringen. Das Bild für den Store
   (`img/screenshot-admin.png`) muss dort liegen, `info.xml` verlinkt es.
5. **Release** auf GitHub mit Tag `v<version>` veröffentlichen, z. B.
   `gh release create v0.1.0 --title 0.1.0 --notes "…"`. Der Workflow
   prüft Tag gegen `info.xml`, baut, signiert, hängt
   `timesister-v<version>.tar.gz` ans Release und lädt es in den Store.
