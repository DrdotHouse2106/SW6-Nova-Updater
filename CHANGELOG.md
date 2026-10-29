# 1.7.0
- Sicherheit: Verknüpfungen werden nur noch laut dem mit dem Updater ausgelieferten Katalog auf einen neuen Repository-Namen umgestellt (nicht mehr laut Online-Katalog), nur innerhalb desselben GitHub-Besitzers und nur, wenn der neue Name in den erlaubten Repositories steht. Jede Umstellung wird protokolliert, verweigerte Umstellungen ebenfalls
- Sicherheit: Liste „Erlaubte Repositories“ wird bei Installation/Update einmalig vorbelegt, wenn sie leer ist (leer = alles erlaubt): `DrdotHouse2106/*`, `FriendsOfShopware/*` sowie bereits verknüpfte Repositories anderer Besitzer. Eine gepflegte Liste bleibt unverändert
- Sicherheit: Release-Pakete werden gegen die SHA-256-Prüfsumme von GitHub geprüft und müssen dieselbe Version wie die `composer.json` des Tags tragen, sonst wird der Quellcode des Tags verwendet
- Update-Sicherung bleibt erhalten, bis Shopwares Update-Schritt (Migrationen) durchgelaufen ist. Scheitert er, wird der vorherige Stand automatisch wiederhergestellt und gemeldet. Nicht abgeschlossene Updates behalten ihre Sicherung bis zu 7 Tage
- Shopwares Update- und Aktivierungsschritt laufen unter derselben Sperre wie der Dateitausch; diese Schritte und das Kompilieren der Themes verlangen jetzt wie Installation und Update zusätzlich das Recht `system.plugin_upload`
- Downgrade-Schutz prüft auch die Version im tatsächlich geladenen Paket; nur Tags mit lesbarer Versionsnummer sind installierbar
- Update-Prüfung zeigt nur noch eigene Fehlertexte (technische Details stehen im Log); „Nach Updates suchen“ fragt höchstens einmal pro Minute bei GitHub nach
- Dateinamen aus Archiven erscheinen in Fehlermeldungen nur noch bereinigt

# 1.6.2
- Fehlerbehebung: Der kompilierte Service-Container wurde bisher nur nach einer **Neuinstallation** verworfen, nach einem **Update** nicht (anders als in 1.6.1 beschrieben). Ein Update mit geändertem Konstruktor hing deshalb bei „Shopware arbeitet noch …“, und Shop/Cron meldeten „Too few arguments“, bis `var/cache/prod_*` von Hand gelöscht wurde. Jetzt wird der Container auch nach jedem Update verworfen
- Zusätzlich wird der PHP-Opcache geleert, damit kein alter Plugin-Code weiterläuft

# 1.6.1
- Absicherung: Nach dem Austausch der Plugin-Dateien verwirft der Updater Shopwares kompilierten Service-Container. Bisher konnte ein Update, das Konstruktoren eines Plugins ändert, Shop und Administration mit Fehler 500 lahmlegen (Anzeige „Shopware arbeitet noch …“ hing), bis `var/cache` von Hand gelöscht wurde

# 1.6.0
- Neu: **„Was ist neu?“** – auf der Plugin-Karte neben „Aktualisieren“ zeigt ein Fenster die Release-Notizen aller Versionen zwischen der installierten und der gewählten Version (mit „Jetzt aktualisieren“)
- Nach einem Update öffnet sich das Fenster automatisch mit den Änderungen von der alten zur neuen Version
- Release-Notizen kommen aus den GitHub-Releases; Markdown wird sicher dargestellt (keine Skripte, nur https-Links)

# 1.5.0
- Neu: Knopf **„Nach Updates suchen“** oben in *Erweiterungen → Meine Erweiterungen* – prüft alle verknüpften Plugins sofort, meldet gefundene Updates (bzw. „Alles aktuell“) und aktualisiert die Plugin-Karten ohne Neuladen

# 1.4.0
- Neues Plugin-Icon im Nova-Stil (Erweiterungen → Meine Erweiterungen)
- Repository umbenannt in SW6-Nova-Updater – alte Adresse leitet weiter
- Katalog: neue Repository-Namen der Nova-Plugins. Verknüpfungen mit dem alten Namen werden automatisch auf den neuen umgestellt (Katalogfeld `formerRepositories`)

# 1.3.1
- Neuer Anzeigename „Nova Updater“ (technischer Name DhGithubUpdater bleibt – keine Neuinstallation nötig)
- Katalog: Nova Upload, Anzeigenamen der Nova-Plugins

# 1.3.0
- Update und „Installieren und aktivieren“ in Schritten: erst Shopwares Lebenszyklus ohne Theme-Kompilierung, danach die Themes Shop für Shop mit Fortschrittsanzeige („Themes werden kompiliert: 3 von 7 …“) – keine Zeitüberschreitung mehr bei vielen Verkaufskanälen
- Themes werden nur kompiliert, wenn das Plugin Storefront-Dateien oder ein Theme mitbringt
- Nach einer Zeitüberschreitung wartet der Updater bis zu 5 Minuten (vorher 90 Sekunden)
- Hinweis „plugin:update“ nennt jetzt den Plugin-Namen
- Neue Admin-API-Endpunkte lifecycle/update, lifecycle/activate, compile-theme

# 1.2.4
- Zeitüberschreitung beim Installieren/Aktualisieren: Der Updater wartet jetzt (bis 90 Sekunden), bis Shopware im Hintergrund fertig ist, und zeigt nur dann eine Warnung, wenn das Plugin danach wirklich nicht aktiv bzw. aktualisiert ist
- Composer-Pakete werden nach der Installation im Shop nachgeprüft (vendor/composer/installed.json) – der Hinweis erscheint nur noch, wenn ein Paket tatsächlich fehlt
- Neuer Admin-API-Endpunkt missing-packages

# 1.2.3
- Hinweise nach dem Neuladen erscheinen in der Sprache der Administration (vorher teils Englisch)
- Zeitüberschreitung beim Aktivieren: Ist das Plugin trotzdem aktiv, kommt eine Erfolgsmeldung statt einer Warnung
- Hinweis zu Composer-Paketen ist jetzt eine Info und erklärt, dass Shopware sie meist selbst installiert

# 1.2.2
- Update-Prüfung braucht deutlich weniger GitHub-Anfragen: Es werden nur Versionen geprüft, die neuer als die installierte sind (vorher bis zu 15 je Plugin) – wichtig ohne Token (60 Anfragen pro Stunde)
- Meldung beim GitHub-Limit nennt die Uhrzeit, ab der es wieder geht, und den Hinweis auf das Token

# 1.2.1
- Scheitert nach dem Herunterladen nur das Installieren/Aktivieren durch Shopware (z. B. Zeitüberschreitung beim Kompilieren der Themes), erscheint ein genauer Hinweis statt „Unbekannter Fehler“; die Seite wird trotzdem neu geladen, damit das Plugin in der Liste steht
- Fehlermeldungen ohne Text zeigen den HTTP-Status bzw. „Timeout“

# 1.2.0
- Neu: Plugin-Katalog („Plugin-Katalog“ in Meine Erweiterungen) mit Suche, Kategorien und Status je Plugin; Installieren bzw. Verknüpfen mit einem Klick. Katalog wird alle 6 Stunden von GitHub aktualisiert, Fallback auf mitgelieferte Fassung
- Neu: Eigene Plugins beitragen per Pull Request oder Issue-Formular („Plugin vorschlagen“), CONTRIBUTING.md
- Neu: Fertige Release-Pakete (ZIP-Anhang am GitHub-Release) werden bevorzugt installiert – mit gebauter Administration und `vendor/`; Fallback auf Quellcode
- Neu: Automatische Update-Prüfung alle 12 Stunden (geplante Aufgabe), Updates direkt auf der Plugin-Karte, einmalige Benachrichtigung je neuer Version
- Neu: Hinweise nach Installation/Update bei fehlender gebauter Administration/Storefront oder fehlenden Composer-Paketen
- Neue Admin-API-Endpunkte `catalog` und `update-status`

# 1.1.1
- Meldungen zeigen wieder den Plugin-Namen an (Platzhalter gingen verloren)
- „Updates prüfen“ unterscheidet jetzt zwischen „auf dem neuesten Stand“ (mit neuester Version) und „keine passende Version/kein Release gefunden“

# 1.1.0

- Plugin-Karten überarbeitet: Der GitHub-Bereich erscheint nur noch bei verknüpften Plugins
  und ist kompakt (GitHub-Symbol, Repository-Link, installierte Version, Update-Hinweis).
- Verknüpfen, Bearbeiten und Entfernen der GitHub-Verknüpfung über das „…“-Menü der
  Plugin-Karte – für alle installierten Plugins, inkl. Store-Plugins – mit eigenem Dialog
  und URL-Prüfung.
- Neu: „Verbindung testen“ (Dialog und „…“-Menü). Prüft serverseitig und nur lesend
  Eingabe, erlaubte Repositories, Zugriff/Token/Ratenlimit, composer.json und
  Plugin-Zuordnung, Releases sowie kompatible Versionen für die laufende Shopware-Version.
  Neuer Admin-API-Endpunkt `GET /api/_action/dh-github-updater/test-connection`.
- README: „Häufige Probleme und Lösungen“ als Abschnitte statt breiter Tabelle.

# 1.0.0

- Erste Version: Installation, Update-Prüfung und Update von Plugins aus GitHub mit
  Sicherheitsmaßnahmen gegen Shell-Injection, Zip-Slip, Path-Traversal, SSRF und Zip-Bomben.
