# Änderungen

## 0.1.0 – unveröffentlicht

- Erste Fassung: Schnittstelle Fassung 1 (`docs/API.md`), Teams mit vier
  Rollen-Gruppen, Datensätze mit Fassung und Revision, Verlauf, Wiederherstellen,
  Batch, Kalendersicherungen, Lebenszeichen, Admin-Seite, Aufbewahrung.
- Optionale Konten-Gruppe je Team (`groups.accounts`): alle Konten des
  Teams, keine Rolle. Als Gruppenadmins dieser Gruppe können Team-Admins jede
  Rolle entziehen (Nextcloud-Regel OCS 105). Gespeichert als Zeile
  `role = 'accounts'` in `ts_role_groups`, ohne Migration.
- Nextcloud 33–34.
- Admin-Seite übersetzbar: Englisch als Quelle, Deutsch mit „du“ (`de`) und
  mit „Sie“ (`de_DE`). Fehlermeldungen der Schnittstelle bleiben deutsch.
- App Store vorbereitet: englische und deutsche Beschreibung, Bild,
  Workflow `appstore-build-publish.yml` (aus, solange die Secrets fehlen).
