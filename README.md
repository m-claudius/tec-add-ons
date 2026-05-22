# TEC add ons

WordPress-Plugin als Erweiterung für **The Events Calendar (TEC)**.

Bündelt mehrere Zusatzmodule rund um Veranstaltungslisten, Newsletter und Community-Einreichungen.

- **Version:** 1.6.3
- **Autor:** Matthias Clausen (mit ChatGPT)
- **Lizenz:** GPLv2 or later
- **Voraussetzungen:** WordPress 6.1+, PHP 7.4+ (laut Header) / PHP 8+ (laut Handbuch), aktives *The Events Calendar*, funktionierender `wp_mail()`-Versand
- **Text Domain:** `tec-add-ons`

## Funktionsumfang

### Filterleiste (`class-filter-bar.php`)
Linke Sidebar mit Kategorie-Checkboxen für TEC-Listenansichten.
- „Eigene Veranstaltungen" oben, standardmäßig aktiviert
- „Anwenden / Zurücksetzen" oben und unten
- Hintergrundfarbe konfigurierbar (Default: transparent)
- Zählung nur über zukünftige Events; Kategorien mit `0` werden ausgeblendet
- CSS fängt Theme-Konflikte ab

### Featured zuerst (`class-featured-first.php`)
Hebt TEC-„Hervorgehobene Veranstaltungen" in Listenansichten ganz nach oben.

### Mailing / Newsletter (`class-mailing.php`)
- Shortcode `[tec_addons_subscribe]` als Abo-Formular (E-Mail, Quellen, Häufigkeit)
- **Double-Opt-In** mit konfigurierbarer Bestätigungsseite
- Versandpläne:
  - **Wöchentlich:** Samstag 01:00 → Zeitraum ab Samstag + 10 Tage
  - **Monatlich:** letzter Samstag 01:00 → Zeitraum + 6 Wochen
- Inhalte: Titel, Datum/Zeit, Quelle, Thumbnails — **Duplikate erwünscht** (nicht gefiltert)
- Testversand (Wochen-/Monatsmail manuell auslösen), „Pläne neu einplanen"
- Versand-Log (Zeit, Empfänger, Erfolg/Fehler)
- UI-Warnung bei deaktiviertem WP-Cron
- Abmeldelink in jeder Mail

### Abonnenten (`class-subscribe.php`)
Admin-Verwaltung der Abonnenten-Einträge inkl. Löschen, Anzeige von Quellen- und Häufigkeitspräferenzen. DSGVO-Auskunft/-Export via WP-Bordmitteln (E-Mail als Schlüssel).

### Statistik (`class-stats.php`)
Anmeldungen (gesamt/neu), bestätigte Opt-Ins, Versände pro Lauf, Fehlerquote.

### Community (`class-community.php`)
Frontend-Einreichung von Veranstaltungen durch eingeloggte Nutzer.
- Shortcode `[tec_addons_submit_event]` (Login erforderlich)
- Quellen als Taxonomie `event_source`; „Kulturstiftung Seevetal" als Fallback automatisch angelegt
- Sync „Aus Mailing-Quellen synchronisieren"
- User ↔ Quelle Mapping inkl. **Whitelist** für Auto-Freigabe
- Workflow: `pending → prüfen → publish` (oder ablehnen)
- E-Mail-Vorlagen an Einreicher (mit Edit-Link, gültig bis Freigabe) und an Admin (Editor-Link + „Direkt freigeben"-Link)

### Admin (`class-admin.php`)
Zentrales Menü **TEC add ons** mit Untermenüs: Filter, Featured, Mailing, Abonnenten, Statistik, Community.

## Installation

1. Ordner `tec-add-ons` nach `/wp-content/plugins/` kopieren.
2. *The Events Calendar* aktivieren.
3. Plugin **TEC add ons** unter Plugins aktivieren.

Beim Aktivieren werden Tabellen für Subscribe/Mailing-Log/Community angelegt und der Mailing-Cron geplant.

## Update

Plugin-Ordner überschreiben (Backup empfohlen).

## Cron auf Synology (empfohlen)

WP-Cron alle 10 Minuten anstoßen, damit geplante Mailings pünktlich laufen:

```
/usr/bin/curl -sS https://DEINE-DOMAIN/wp-cron.php?doing_wp_cron=1 > /dev/null
```

In `wp-config.php` **kein** `define('DISABLE_WP_CRON', true);` setzen.

## Shortcodes

| Shortcode | Zweck |
|---|---|
| `[tec_addons_subscribe]` | Newsletter-Abo-Formular (Double-Opt-In) |
| `[tec_addons_submit_event]` | Veranstaltung einreichen (Login nötig) |

## Projektstruktur

```
tec-add-ons.php              Plugin-Bootstrap, Hooks, Modul-Init
includes/
  class-admin.php            Admin-Menüs/Settings
  class-filter-bar.php       Filterleiste links
  class-featured-first.php   Hervorgehobene zuerst
  class-mailing.php          Newsletter (Cron, Log, Test)
  class-subscribe.php        Abonnentenverwaltung + Double-Opt-In
  class-stats.php            Statistik
  class-community.php        Community-Einreichung + Quellen
assets/
  css/filter.css
  js/filter.js
```

## Dokumentation

Im Quell-Projektordner (außerhalb des Repos) liegen zwei PDF-Handbücher:
- **TEC_Addons_Admin_Handbuch.pdf** — vollständige Admin-Doku
- **Community_Addon_Endnutzer_Handbuch.pdf** — Anleitung für Einreicher:innen
