<?php
// This file is part of the quiz_livemonitor plugin for Moodle.
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * German strings for quiz_livemonitor.
 *
 * @package    quiz_livemonitor
 * @copyright  2026 Christian Kraus
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Live-Monitor';
$string['livemonitor:view'] = 'Live-Monitor eines Tests einsehen';
// Beide Strings sind für jedes Quiz-Report-Subplugin Pflicht, weil der Core seine
// Bezeichnungen aus dem Report-Namen bildet: quiz_extend_settings_navigation()
// nutzt get_string($report, ...) für den Eintrag unter "Ergebnisse",
// mod/quiz/settings.php nutzt get_string($report . 'report', ...) für die
// Einstellungsseite. Ohne sie bricht die Report-Seite bei aktivem Debugging ab.
$string['livemonitor'] = 'Live-Monitor';
$string['livemonitorreport'] = 'Live-Monitor-Report';

// Spaltenüberschriften.
$string['col_name'] = 'Teilnehmer/in';
$string['col_status'] = 'Status';
$string['col_progress'] = 'Fortschritt';
$string['col_lastactivity'] = 'Letzte Aktivität';
$string['col_elapsed'] = 'Verstrichen';
$string['col_timeleft'] = 'Restzeit';

// Status-Bezeichnungen.
$string['status_active'] = 'Aktiv';
$string['status_idle'] = 'Inaktiv';
$string['status_finished'] = 'Abgegeben';
$string['status_overdue'] = 'Zeit überzogen';
$string['status_abandoned'] = 'Abgebrochen';
$string['status_notstarted'] = 'Nicht begonnen';

// Übersichts-Kacheln.
$string['summary_total'] = 'Teilnehmende';
$string['summary_active'] = 'Gerade aktiv';
$string['summary_inprogress'] = 'In Bearbeitung';
$string['summary_finished'] = 'Abgegeben';
$string['summary_overrun'] = 'Zeit überzogen';
$string['summary_notstarted'] = 'Nicht begonnen';

// Sonstige Oberfläche.
$string['active'] = 'Gerade aktiv';
$string['ago'] = 'vor {$a}';
$string['overrunby'] = 'überzogen um {$a}';
$string['lastupdated'] = 'Zuletzt aktualisiert:';
$string['noparticipants'] = 'Es sind noch keine Teilnehmenden für diesen Test berechtigt.';
$string['pause'] = 'Auto-Aktualisierung pausieren';
$string['resume'] = 'Auto-Aktualisierung fortsetzen';
$string['refreshnow'] = 'Jetzt aktualisieren';
$string['legend'] = 'Legende';
$string['sebnote'] = 'Hinweis: „Aktiv" bezeichnet serverseitige Aktivität innerhalb der letzten Sekunden. Der Safe Exam Browser sperrt das Endgerät, meldet Moodle aber weder Fensterfokus noch Tastatureingaben.';

// Einstellungen.
$string['setting_refreshinterval'] = 'Aktualisierungs-Intervall (Sekunden)';
$string['setting_refreshinterval_desc'] = 'Wie oft der Browser der/des Lehrenden einen neuen Snapshot abruft. Nur die aufrufende Lehrperson pollt; Studierende sind nicht betroffen.';
$string['setting_activewindow'] = 'Aktiv-Zeitfenster (Sekunden)';
$string['setting_activewindow_desc'] = 'Ein laufender Versuch gilt als „aktiv", wenn die letzte serverseitige Aktivität innerhalb dieser Sekundenzahl liegt.';

// Datenschutz.
$string['privacy:metadata'] = 'Das Plugin „Live-Monitor" speichert keine personenbezogenen Daten. Es zeigt nur Daten an, die bereits von der Test-Aktivität erhoben werden.';
