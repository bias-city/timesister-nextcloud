# Änderungen

## 0.3.0 – unveröffentlicht

Schnittstelle Fassung 2 (`api: 2`), ohne Übergang von Fassung 1:

- Je Team **eine** Teamgruppe (`groups: { team }`). Admin des Teams ist,
  wer in Nextcloud Gruppenadmin der Teamgruppe ist (`ISubAdmin`); `lead` und
  `subadmin` und der Austritt stehen in der App (Tabelle `ts_members`).
  Rollen-, Konten- und Zeit-Gruppen entfallen; alte Zeilen in
  `ts_role_groups` bleiben stehen und werden ignoriert.
- `/me` mit `admins`, `leads` und `calendar_share`; `/team` mit
  `members: [{ uid, display_name, role, left_at }]`, auch Ausgetretene.
- `PUT /team/members/{uid}` (Verwaltung und Admin; `subadmin` vergeben nur
  Admins), dazu `PUT /admin/teams/{id}/members/{uid}` für die Admin-Seite.
- `GET`/`PUT /me/calendar-share` (Standard an), `calendar_shared` in
  `POST`/`GET /status`.
- Projekte voll nur für Verwaltung, Admin und die Leitungen des Projekts,
  sonst der Buchungskatalog; die Leitung ändert ihr Projekt ganz, legt aber
  keine neuen an. Verlauf eines Projekts auch für seine Leitung.
- Admin-Seite: ein Gruppenfeld, Admins als Liste mit Hinweis, Mitglieder
  mit Rolle und Austritt (setzbar in „Team bearbeiten“), im Zustand die
  Konten ohne freigegebenen Zeitkalender.
- Migration `Version1004…`, nur hinzufügend (`ts_members`,
  `ts_client_status.calendar_shared`).

## 0.2.2 – unveröffentlicht

- Schnittstelle Fassung 1.2: Team-Einstellungen `settings`
  (`leads_see_calendars`, Standard an; `backup_required`, Standard aus) in
  `/me`, `/team` und `/admin/teams`, setzbar über `POST`/`PUT /admin/teams`.
- Admin-Seite: zwei Schalter im Formular „Team bearbeiten“, Zustand in der
  Teamliste.
- Migration `Version1003…`, nur hinzufügend.

## 0.2.1 – unveröffentlicht

Schnittstelle Fassung 1.1, Sicherungen (0.2.0 war nur ein Zwischenstand der
Entwicklung):

- Der Server sichert selbst: stündlich geprüft, je Konto höchstens einmal je
  ISO-Woche, Export mit `ICalendarExport`, neue Sicherung nur bei geänderter
  Prüfsumme.
- Nur mit Freigabe der Person (`GET`/`PUT /backups/consent`, mit Fassung der
  Aufklärung); zurückziehen löscht nichts.
- Zwei Ablagen: geschützt in `IAppData` und sichtbar beim Sicherungs-Konto
  des Teams (`backup_owner`, auf der Admin-Seite wählbar). Wahlweise eine
  Kopie im eigenen Ordner (`/backups/own-copy`).
- `POST /backups/now`, `source` und `file_path` in den Sicherungen,
  `backup_owner` in `/team`, Freigabe in `/status`.
- Ausdünnen statt fester Frist: 4 Wochen alle, dann monatlich bis 12 Monate,
  dann jährlich bis 10 Jahre. Gelöscht wird nur, was die App selbst
  angelegt und sich gemerkt hat (`ts_backup_files`), über den Papierkorb.
- Admin-Seite: Sicherungs-Konto je Team, im Zustand Freigaben, fehlende
  Sicherungen dieser Woche und letzte Server-Sicherung; breite Tabellen
  scrollen waagrecht.
- Migrationen `Version1001…` und `Version1002…`, nur hinzufügend.

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
