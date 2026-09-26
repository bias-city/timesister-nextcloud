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
- [ ] **Signatur und App Store:** Archiv bauen, mit dem App-Zertifikat signieren, hochladen.
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

1. Archiv ohne die Dateien aus `.nextcloudignore`:
   `tar --exclude-from=.nextcloudignore -czf timesister.tar.gz ../timesister`
2. Signatur: `openssl dgst -sha512 -sign ~/.nextcloud/certificates/timesister.key timesister.tar.gz | openssl base64`
3. Upload im App Store (apps.nextcloud.com) mit Link zum Release-Archiv und Signatur.

### CI und Frühwarnung

- Bei jedem Push: Lint (PHP min/max, info.xml) und Psalm (min…max).
- Bei Pull-Requests und auf `main`: PHPUnit und Integration gegen min und max
  (Nextcloud-Checkout mit SQLite).
- Wöchentlich (`fruehwarnung.yml`): neueste Veröffentlichung und master;
  Fehler → Issue `nc-update`, bei einer neuen Hauptversion ruft sie Claude.
- Claude (`claude.yml`) läuft nur, wenn das Secret `ANTHROPIC_API_KEY` gesetzt
  ist und die Claude-GitHub-App installiert ist.
