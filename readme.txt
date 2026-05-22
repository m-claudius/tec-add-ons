=== TEC add ons ===
Contributors: m-claudius
Tags: events, the-events-calendar, newsletter, community, filter
Requires at least: 6.1
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.6.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Zusatzmodule für The Events Calendar: Filterleiste, Featured-Sortierung, Newsletter mit Double-Opt-In und Community-Einreichung.

== Description ==

TEC add ons erweitert *The Events Calendar* (TEC) um mehrere Module:

* **Filterleiste links** — Kategorie-Checkboxen, „Eigene Veranstaltungen" oben, Anwenden/Zurücksetzen, Hintergrundfarbe konfigurierbar, Zählung nur über zukünftige Events.
* **Featured zuerst** — Hervorgehobene Veranstaltungen stehen in Listenansichten immer oben.
* **Mailing / Newsletter** — Wochen- und Monatsmail mit Double-Opt-In, Thumbnails, Quellenanzeige, Testversand, Versand-Log.
* **Abonnentenverwaltung** — Einträge ansehen und löschen, DSGVO-konform.
* **Statistik** — Anmeldungen, bestätigte Opt-Ins, Versände, Fehlerquote.
* **Community** — Frontend-Einreichung von Veranstaltungen durch eingeloggte Nutzer mit Quellen-Mapping, Whitelist-Auto-Freigabe, Editor-/Direkt-Freigabe per E-Mail-Link.

Voraussetzungen: WordPress 6.1+, PHP 7.4+, aktives *The Events Calendar*, funktionierender `wp_mail()`-Versand.

== Installation ==

1. Ordner `tec-add-ons` nach `/wp-content/plugins/` kopieren.
2. *The Events Calendar* aktivieren.
3. Plugin **TEC add ons** unter Plugins aktivieren.

Beim Aktivieren werden die Datenbanktabellen für Subscribe/Mailing-Log/Community angelegt und der Mailing-Cron geplant.

= Cron auf Synology (empfohlen) =

WP-Cron alle 10 Minuten anstoßen:

`/usr/bin/curl -sS https://DEINE-DOMAIN/wp-cron.php?doing_wp_cron=1 > /dev/null`

In `wp-config.php` kein `define('DISABLE_WP_CRON', true);` setzen.

== Frequently Asked Questions ==

= Filter-Checkboxen reagieren nicht? =

CSS/JS-Minify deaktivieren oder Cache leeren. Theme-CSS kann Inputs maskieren.

= Newsletter kommt nicht an? =

Im Mailing-Menü das Versand-Log prüfen, Synology-Task-Log kontrollieren, SMTP-Versand testen.

= Bearbeiten-Link aus Community-Mail öffnet nur die Startseite? =

Das Event wurde bereits freigegeben — Frontend-Bearbeitung ist nur bis zur Freigabe möglich.

= Welche Shortcodes gibt es? =

* `[tec_addons_subscribe]` — Newsletter-Abo-Formular (Double-Opt-In)
* `[tec_addons_submit_event]` — Veranstaltung einreichen (Login erforderlich)

== Changelog ==

= 1.6.3 =
* Aktuelle Veröffentlichung; siehe Git-History auf https://github.com/m-claudius/tec-add-ons für Details.

== Upgrade Notice ==

= 1.6.3 =
Vor dem Update Backup empfohlen.
