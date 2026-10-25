# Nova Updater (DhGithubUpdater) – Shopware-6-Plugins sicher aus GitHub installieren

🇬🇧 [English version](README.en.md)

Mit diesem Plugin installierst und aktualisierst du Shopware-6-Plugins direkt aus
GitHub-Repositories – öffentlich oder privat – ohne Shopware-Account und ohne Plugin-Store.
Es bietet die gleichen Funktionen wie
[yaetcom/ShopwareGitHubUpdater](https://github.com/yaetcom/ShopwareGitHubUpdater),
wurde aber von Grund auf neu und mit Fokus auf Sicherheit geschrieben.

## Funktionen

- **Installation aus GitHub**: In *Erweiterungen → Meine Erweiterungen* gibt es den Button
  „Aus GitHub installieren“. URL eingeben, Versionen laden, Version wählen, installieren.
- **Nur kompatible Versionen**: Es werden nur Tags (optional Branches) angeboten, deren
  `composer.json` laut `require.shopware/core` zur laufenden Shopware-Version passt
  (echte Semver-Prüfung mit `composer/semver`).
- **Updates**: Jedes installierte Plugin kann über das „…“-Menü seiner Karte mit einem GitHub-Repository verknüpft werden (inkl. „Verbindung testen“).
  „Updates prüfen“ zeigt neuere Versionen, „Aktualisieren“ spielt sie ein. Anschließend
  läuft der reguläre Shopware-Update-Prozess (inkl. Migrationen).
- **Branch-Updates**: Bei Installation aus einem Branch wird der Commit gespeichert, neue
  Commits werden als Update erkannt (muss in der Konfiguration aktiviert werden).
- **Rollback**: Schlägt ein Update beim Einlesen fehl, wird der vorherige Stand
  automatisch wiederhergestellt.
- **Plugin-Katalog** (neu in 1.2.0): Button „Plugin-Katalog“ mit geprüften Plugins
  (PayPal, Sprachpaket, FriendsOfShopware, Zahlungsanbieter, Nova-Plugins …) – Suche,
  Kategorien, Installieren oder Verknüpfen mit einem Klick. Eigene Plugins können
  [beigetragen](CONTRIBUTING.md) werden.
- **Fertige Release-Pakete** (neu in 1.2.0): Hängt am GitHub-Release ein Plugin-ZIP, wird
  dieses statt des Quellcodes installiert – mit gebauter Administration und ggf. `vendor/`,
  genau wie beim manuellen ZIP-Upload.
- **Automatische Update-Prüfung** (neu in 1.2.0): alle 12 Stunden, neue Versionen erscheinen
  direkt auf der Plugin-Karte und einmalig als Benachrichtigung (Glocke).

## Sicherheitskonzept

Im Original-Plugin wurden u. a. folgende Schwachstellen gefunden. Die Tabelle zeigt,
wie sie hier gelöst sind:

| Problem im Original | Risiko | Lösung in diesem Plugin |
|---|---|---|
| `exec("php bin/console plugin:install --activate $pluginName")` – Plugin-Name stammt ungeprüft aus der `composer.json` des fremden Repos | **Remote Code Execution** (Shell-Injection) beim bloßen Installieren | Es werden **keine Shell-Befehle** ausgeführt. Dateien ablegen + `PluginService::refreshPlugins()`; Installieren/Aktivieren/Update übernimmt Shopware über die eigenen, ACL-geschützten Endpunkte. Plugin-Namen werden streng validiert (`^[A-Za-z][A-Za-z0-9_]*$`). |
| `ZipArchive::extractTo()` ohne Prüfung der Einträge | **Zip-Slip**: Dateien können außerhalb von `custom/plugins` (z. B. in `public/`) geschrieben werden | Eigener Entpacker: lehnt `..`, absolute Pfade, Backslashes, Laufwerksbuchstaben, Steuerzeichen, **Symlinks** und mehrere Wurzelverzeichnisse ab. |
| Request-Parameter `name` wird direkt in Pfade eingesetzt und mit `Filesystem::remove()` gelöscht | **Beliebiges Löschen/Überschreiben** von Verzeichnissen (Path-Traversal) | Zielpfade entstehen nur aus dem validierten Plugin-Namen und werden per `realpath()` gegen `custom/plugins` geprüft. Gelöscht wird nur innerhalb des eigenen Staging-Verzeichnisses. |
| Keine ACL-Prüfung an den API-Routen | Jeder Admin-API-Benutzer bzw. jede Integration (auch mit minimalen Rechten) kann Code installieren | ACL `system.plugin_maintain` + `system.plugin_upload`, nur echte Admin-Benutzer (keine Integrationen), standardmäßig **nur Administratoren**. Respektiert `shopware.deployment.runtime_extension_management`. |
| Repository-URL per `preg_match('/github\.com\/…/')` irgendwo im String gesucht; Tags/Branches ungeprüft in URLs eingesetzt | URL-/Pfad-Manipulation der GitHub-Anfragen | Strenger Parser (nur `https://github.com/<owner>/<repo>`), Tags/Branches nur exakt aus der GitHub-API-Liste, Download immer über die **Commit-SHA**. |
| `file_get_contents()` folgt beliebigen Weiterleitungen, keine Größenlimits | SSRF, Speicher-Erschöpfung, Zip-Bomben | HTTP-Client mit Host-Positivliste auch für Redirects, Timeouts, Download- und Entpack-Limits (Größe + Dateianzahl), Streaming statt Laden in den RAM. |
| Update-Quelle (`url`) kam aus dem Request und die DAL-Entity war über die Admin-API frei beschreibbar | Untergeschobene Repositories bei Updates | Repository wird serverseitig gespeichert und beim Update nur aus der Datenbank gelesen; das Archiv muss exakt das erwartete Plugin (Name + Klasse) enthalten. Keine generisch beschreibbare Entity. |
| Rohe Exception-Meldungen an den Client | Informationspreisgabe (Pfade, interne Fehler) | Nur bewusst formulierte Meldungen; alles andere wird geloggt und generisch beantwortet. |
| Download landet direkt in `custom/plugins` | Halb entpackte/defekte Plugins bei Fehlern | Entpacken in ein von Shopware ignoriertes Staging-Verzeichnis, dann atomares `rename()`, Rollback bei Fehlern, Sperre gegen parallele Vorgänge. |
| Kein Downgrade-Schutz, keine Kompatibilitätsprüfung (`str_contains`) | Inkompatible Versionen, kaputter Shop | Semver-Prüfung mit `composer/semver`, Downgrades bei Tags werden abgelehnt. |
| GitHub-Token: nicht vorgesehen | – | Optionales Token, wird ausschließlich an `api.github.com` gesendet (nicht an Download-Hosts) und nie geloggt. |

> **Wichtig:** Ein Plugin ist ausführbarer PHP-Code mit vollem Zugriff auf deinen Shop.
> Dieses Plugin verhindert, dass der *Updater selbst* angreifbar ist – es kann aber nicht
> prüfen, ob der Code eines fremden Plugins vertrauenswürdig ist. Installiere nur aus
> Quellen, denen du vertraust, und nutze die **Repository-Positivliste**.

## Voraussetzungen

- Shopware 6.6 oder 6.7, PHP ≥ 8.2 mit `ext-zip`
- `custom/plugins` muss für den Webserver-Benutzer beschreibbar sein
- Laufzeit-Erweiterungsverwaltung aktiv (`shopware.deployment.runtime_extension_management: true`, Standard)

## Installation

### Variante A: ZIP-Upload in der Administration (ohne Konsole)

1. Das ZIP `DhGithubUpdater-<version>.zip` herunterladen (enthält den Ordner `DhGithubUpdater/`).
2. *Erweiterungen → Meine Erweiterungen → Erweiterung hochladen* → ZIP auswählen.
3. Beim Plugin „GitHub Plugin-Updater“ auf **Installieren** und danach **Aktivieren** klicken.
4. Administration neu laden (F5). Oben erscheint der Button **„Aus GitHub installieren“**.

ZIP selbst erstellen (aus diesem Repository):

```bash
git archive --format=zip --prefix=DhGithubUpdater/ -o DhGithubUpdater.zip HEAD
```

### Variante B: Konsole

```bash
cd <shopware>/custom/plugins
git clone https://github.com/DrdotHouse2106/SW6-Nova-Updater.git DhGithubUpdater
cd <shopware>
bin/console plugin:refresh
bin/console plugin:install --activate DhGithubUpdater
bin/console cache:clear
```

Die Oberfläche der Administration ist **fertig gebaut** im Repository enthalten
(`src/Resources/public/administration`, gebaut mit Shopware 6.7.15). Ein Neubau mit
`bin/build-administration.sh` ist nur nötig, wenn du die Quelltexte unter
`src/Resources/app/administration` änderst.

## Konfiguration

*Einstellungen → Erweiterungen → GitHub Plugin-Updater*

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Nur Administratoren | an | Wenn aus, reichen die ACL-Rechte `system.plugin_maintain` + `system.plugin_upload`. |
| Erlaubte Repositories | leer (= alle) | Ein Eintrag pro Zeile: `owner/repo` oder `owner/*`. **Empfohlen.** |
| Installation aus Branches | aus | Branches sind keine festen Releases. Tags werden immer bevorzugt. |
| GitHub-Token | leer | Nötig für private Repositories, erhöht außerdem das API-Limit von 60 auf 5000 Anfragen/Stunde. Siehe [Private Repositories](#private-repositories). |
| Maximale Downloadgröße | 50 MB | Schutz vor übergroßen Archiven. |
| Anzahl geprüfter Tags | 15 | Jeder Tag kostet eine API-Anfrage (Ergebnisse werden pro Commit zwischengespeichert). |

## Private Repositories

Plugins aus privaten Repositories lassen sich installieren und aktualisieren, sobald in der
Plugin-Konfiguration ein GitHub-Token mit Lesezugriff hinterlegt ist.

So erstellst du ein passendes Token (GitHub → *Settings → Developer settings →
Personal access tokens → Fine-grained tokens → Generate new token*):

1. **Resource owner**: Benutzer bzw. Organisation, der die Repositories gehören.
2. **Repository access**: *Only select repositories* und nur die benötigten Repos auswählen.
3. **Permissions → Repository permissions**: nur **Contents: Read-only**
   (*Metadata: Read-only* wird automatisch gesetzt). Keine weiteren Rechte vergeben.
4. **Expiration**: ein Ablaufdatum setzen (z. B. 1 Jahr) und rechtzeitig erneuern.
5. Token in Shopware unter *Einstellungen → Erweiterungen → GitHub Plugin-Updater →
   GitHub-Token* eintragen und speichern.

Wie das Token geschützt wird:

- Es wird **ausschließlich an `api.github.com`** gesendet. Den eigentlichen Download leitet
  GitHub auf `codeload.github.com` mit einem kurzlebigen, nur für dieses Archiv gültigen
  Schlüssel weiter – das Token selbst verlässt dabei nicht den API-Aufruf.
- Es wird nie geloggt und nie an die Administration zurückgegeben (außer im
  Passwortfeld der Konfiguration).
- Es liegt wie alle Plugin-Einstellungen in der Tabelle `system_config`. Wer Zugriff auf die
  Datenbank oder Admin-Rechte für die Systemeinstellungen hat, kann es auslesen – deshalb
  nur Lesezugriff und nur die nötigen Repositories freigeben.

Zusätzlich empfiehlt sich die **Repository-Positivliste**, z. B. `DeinName/*`.

### Diesen Updater aus einem privaten Repository installieren

Ein einfaches `git clone` benötigt dann eine Anmeldung. Entweder mit Token klonen und es
anschließend wieder aus der Git-Konfiguration entfernen:

```bash
cd <shopware>/custom/plugins
git clone https://<TOKEN>@github.com/DrdotHouse2106/SW6-Nova-Updater.git DhGithubUpdater
git -C DhGithubUpdater remote set-url origin https://github.com/DrdotHouse2106/SW6-Nova-Updater.git
```

oder auf GitHub *Code → Download ZIP* wählen, entpacken und den Ordner als
`custom/plugins/DhGithubUpdater` hochladen. Danach wie unter [Installation](#installation)
beschrieben fortfahren.

## Nutzung

1. **Installieren**: *Erweiterungen → Meine Erweiterungen → „Aus GitHub installieren“*,
   z. B. `https://github.com/FriendsOfShopware/FroshTools` eingeben → „Versionen laden“
   → Version wählen → „Installieren“.
2. **Mit GitHub verknüpfen** (für bereits vorhandene Plugins – auch solche aus dem Store
   oder per ZIP hochgeladene): Auf der Plugin-Karte das **„…“-Menü** öffnen →
   **„Mit GitHub verknüpfen …“**. Im Dialog die Repository-URL eingeben
   (`https://github.com/owner/repository` oder `owner/repository`), optional
   **„Verbindung testen“** und dann **„Speichern“**.
3. **Aktualisieren**: Verknüpfte Plugins zeigen auf der Karte einen kleinen GitHub-Bereich
   (Repository-Link und installierte Version). Dort „Auf Updates prüfen“ klicken – ist ein
   Update verfügbar, erscheint es hervorgehoben mit dem Button **„Aktualisieren“**.
   Nicht verknüpfte Plugins zeigen keinen GitHub-Bereich.
   **„Was ist neu?“** neben „Aktualisieren“ zeigt die Release-Notizen aller Versionen bis zur gewählten;
   nach dem Update öffnet sich dieselbe Übersicht automatisch.
   **Alle auf einmal:** oben in *Meine Erweiterungen* **„Nach Updates suchen“** – prüft sofort alle
   verknüpften Plugins, meldet gefundene Updates (oder „Alles aktuell“) und zeigt sie auf den Karten.
   Das Update läuft **in Schritten**: zuerst Shopwares Update (Migrationen, Einstellungen) ohne
   die Themes, danach werden die Themes **Shop für Shop** kompiliert – die Karte zeigt
   „Themes werden kompiliert: 3 von 7 (Shopname) …“. So läuft auch bei vielen Verkaufskanälen
   keine einzelne Anfrage in ein Zeitlimit. Gleiches gilt für „Installieren und aktivieren“.
   Themes werden nur kompiliert, wenn das Plugin Storefront-Dateien oder ein Theme mitbringt.
4. **Verknüpfung bearbeiten oder entfernen**: im „…“-Menü über
   „GitHub-Verknüpfung bearbeiten …“ bzw. „GitHub-Verknüpfung entfernen“. Das Entfernen
   löscht nur die Verknüpfung, nicht das Plugin.

### Plugin-Katalog

*Erweiterungen → Meine Erweiterungen → „Plugin-Katalog“* zeigt eine kuratierte Liste von
Shopware-Plugins auf GitHub mit Suche und Kategorien. Je Plugin:

- **Installieren …** öffnet „Aus GitHub installieren“ mit vorausgefülltem Repository.
- **Mit GitHub verknüpfen** erscheint, wenn das Plugin schon installiert ist (z. B. aus dem
  Store) – danach kommen Updates aus GitHub.
- **✓ Installiert und verknüpft** – nichts zu tun.

Der Katalog wird standardmäßig alle 6 Stunden aktuell von GitHub geladen
(`src/Resources/catalog/plugins.json` in diesem Repository); ohne Verbindung wird die
mitgelieferte Fassung genutzt. Abschaltbar in der Konfiguration („Plugin-Katalog“).
Plugins, die nicht in „Erlaubte Repositories“ stehen, werden angezeigt, aber nicht angeboten.

**Eigene Plugins beitragen:** Pull Request an `src/Resources/catalog/plugins.json` oder
Vorschlag über das Issue-Formular „Plugin vorschlagen“ (Link unten im Katalog) – Details in
der [CONTRIBUTING.md](CONTRIBUTING.md).

### Automatische Update-Prüfung

- Eine geplante Aufgabe (`dh_github_updater.check_updates`) prüft alle 12 Stunden alle
  verknüpften Plugins.
- Verfügbare Updates stehen danach direkt auf der Plugin-Karte („Update verfügbar“ +
  „Aktualisieren“), ohne dass man „Auf GitHub-Updates prüfen“ klicken muss.
- Jede neue Version wird einmal als Benachrichtigung (Glocke oben rechts) gemeldet.
- Laufen in deinem Shop keine geplanten Aufgaben (kein Cron und kein Admin-Worker), wird
  beim Öffnen von „Meine Erweiterungen“ geprüft, sobald das letzte Ergebnis älter als
  12 Stunden ist.
- Abschaltbar unter Konfiguration → „Update-Prüfung“. Installiert wird nie automatisch.

### Release-Paket oder Quellcode?

Beim Installieren und Aktualisieren schaut der Updater zuerst, ob am GitHub-Release des
gewählten Tags ein ZIP hängt (`<PluginName>.zip`, `<PluginName>-<Version>.zip` oder das
einzige ZIP). Ist es ein gültiges Plugin-Paket für genau dieses Plugin, wird es verwendet –
so kommen bei PayPal, Sprachpaket, FroshTools, Adyen usw. die gebaute Administration und
Abhängigkeiten mit. Sonst wird wie bisher der Quellcode des Tags installiert. Im
Installationsdialog steht, welche Variante verwendet wird.

Nach der Installation weist der Updater auf typische Lücken hin (keine gebaute
Administration, kein gebautes Storefront-JavaScript, fehlende Composer-Pakete).

### Verbindung testen

Im Verknüpfen-Dialog (vor dem Speichern) und im „…“-Menü verknüpfter Plugins gibt es
**„Verbindung testen“**. Der Test läuft auf dem Server, liest nur über die GitHub-API und
installiert nichts. Jeder Punkt wird mit ✓ (ok), ⚠ (Hinweis) oder ✗ (Fehler) angezeigt:

| Prüfpunkt | Was geprüft wird |
|---|---|
| Eingabe | Ist die Eingabe eine gültige GitHub-Repository-Adresse? |
| Erlaubte Repositories | Ist das Repository laut Konfiguration „Erlaubte Repositories“ zugelassen? |
| Zugriff | Ist das Repository erreichbar (öffentlich bzw. mit Token)? Erkannt werden „nicht gefunden / privat ohne Token“, „Token ungültig“ und „Ratenlimit erreicht“. |
| Plugin | Liegt im Standard-Branch eine gültige `composer.json`, und passen Plugin-Name und Plugin-Klasse zum installierten Plugin? |
| Releases | Wie viele Tags/Releases mit Versionsnummer gibt es? Ohne Releases kommt der Hinweis „Release anlegen oder Branches erlauben“. |
| Kompatibilität | Welche Versionen sind mit der laufenden Shopware-Version kompatibel? Die neueste wird genannt, inkl. Hinweis, ob ein Update verfügbar ist. |

Der Test verbraucht einige GitHub-API-Anfragen (ohne Token sind 60 pro Stunde erlaubt).

## Einschränkungen

- Unterstützt werden Repositories, in deren Hauptverzeichnis die `composer.json` des
  Plugins liegt (`type: shopware-platform-plugin`).
- Plugins ohne Release-Paket, die zusätzliche Composer-Abhängigkeiten brauchen oder keine
  gebauten Assets im Repository haben, funktionieren aus dem Quellcode ggf. nicht vollständig
  (der Updater weist nach der Installation darauf hin).
- Archive mit symbolischen Links werden aus Sicherheitsgründen abgelehnt.
- Per Composer (`vendor/`) installierte Plugins werden nicht angefasst.

## API-Endpunkte

Alle unter `/api/_action/dh-github-updater/` (Admin-API, OAuth):

| Methode | Pfad | ACL |
|---|---|---|
| GET | `versions?repository=<url>` | `system.plugin_maintain` |
| GET | `sources` | `system.plugin_maintain` |
| GET | `catalog?refresh=1` | `system.plugin_maintain` |
| GET | `update-status?refresh=1` | `system.plugin_maintain` |
| GET | `check/{pluginName}` | `system.plugin_maintain` |
| GET | `test-connection?repository=<url>&pluginName=<name>` (nur lesend) | `system.plugin_maintain` |
| POST | `install` (`repository`, `ref`, `type`) | `system.plugin_maintain`, `system.plugin_upload` |
| POST | `update/{pluginName}` (`ref`, `type`) | `system.plugin_maintain`, `system.plugin_upload` |
| POST | `link/{pluginName}` (`repository`, leer = entfernen) | `system.plugin_maintain`, `system.plugin_upload` |
| POST | `lifecycle/update/{pluginName}` – Shopware-Update ohne Theme-Kompilierung, liefert `themeJobs` | `system.plugin_maintain` |
| POST | `lifecycle/activate/{pluginName}` – installieren/aktivieren ohne Theme-Kompilierung, liefert `themeJobs` | `system.plugin_maintain` |
| POST | `compile-theme` (`salesChannelId`, `themeId`) – Theme eines Verkaufskanals kompilieren | `system.plugin_maintain` |

## Entwicklung

```bash
composer install
vendor/bin/phpunit
```

Die Unit-Tests decken u. a. Zip-Slip, Symlinks, Zip-Bomben, URL-Validierung,
Redirect-Schutz, Token-Weitergabe, Pfad-Prüfungen und „Verbindung testen“ ab.

## Häufige Probleme und Lösungen

Hier sammeln wir Hürden, die bei Installation und Betrieb tatsächlich aufgetreten sind. **Neue Probleme bitte ergänzen** (oder als Issue melden), damit andere nicht lange suchen müssen.

### Update hängt bei „Shopware arbeitet noch …“, Shop und Administration zeigen Fehler 500

**Ursache:** Bis 1.6.0 arbeitete Shopware nach dem Dateitausch weiter mit dem alten kompilierten Service-Container. Hat das neue Plugin einen geänderten Konstruktor, scheitert jede Anfrage (im Log bzw. unter `/api/_info/health-check` z. B. „Argument #2 must be of type …“)

**Lösung:** Im Hosting (z. B. Plesk → Dateien) den Ordner `var/cache/prod_…` löschen und die Administration neu laden – Daten gehen dabei nicht verloren. Ab 1.6.1 verwirft der Updater den Container selbst

### Nach dem ZIP-Upload fehlt der Button „Aus GitHub installieren“

**Ursache:** Plugin nur hochgeladen, nicht installiert/aktiviert, oder Administration noch im Browser-Cache

**Lösung:** Plugin **installieren und aktivieren**, Administration mit F5 neu laden. Hilft das nicht: `bin/console assets:install` und `bin/console cache:clear`

### Button fehlt weiterhin, in der Browser-Konsole 404 auf `/bundles/dhgithubupdater/...`

**Ursache:** Assets wurden nicht nach `public/bundles` kopiert

**Lösung:** `bin/console assets:install` ausführen

### „Keine kompatiblen Versionen gefunden“

**Ursache:** Das Repository hat **keine Tags/Releases** – der Updater bietet nur Tags an

**Lösung:** Auf GitHub ein Release anlegen: *Releases → Draft a new release → Tag `v1.0.0` eintippen → „Create new tag on publish“ → Publish*. Alternativ in der Konfiguration „Installation aus Branches“ aktivieren

### Ein Release erscheint nicht in der Liste

**Ursache:** `require.shopware/core` in der `composer.json` dieses Tags passt nicht zur Shopware-Version, oder der Tag ist keine Versionsnummer (z. B. `final`)

**Lösung:** Tags wie `v1.2.0` verwenden und die Shopware-Angabe in der `composer.json` prüfen

### „Das Repository enthält kein Shopware-6-Plugin“

**Ursache:** `composer.json` fehlt im Hauptverzeichnis des Repos oder `type` ist nicht `shopware-platform-plugin`

**Lösung:** `composer.json` mit `"type": "shopware-platform-plugin"` und `extra.shopware-plugin-class` ins Repo-Hauptverzeichnis

### „Updates prüfen“ meldet „auf dem neuesten Stand“, obwohl es eine neuere Version gibt

**Ursache:** Im Repository gibt es für die neue Version keinen **Tag/Release** – der Updater sieht nur Tags. Ab Version 1.1.1 lautet die Meldung in diesem Fall „keine passende Version gefunden“.

**Lösung:** Auf GitHub unter *Releases* prüfen, ob es den Tag (z. B. `v1.1.0`) gibt. Fehlt er, Release anlegen bzw. die Release-Action prüfen (siehe nächster Punkt).

### Die automatische Release-Action läuft nicht („The job was not started because recent account payments have failed …“)

**Ursache:** Bei **privaten** Repositories verbrauchen GitHub Actions kostenpflichtige Minuten. Ist im GitHub-Konto keine gültige Zahlungsart hinterlegt oder das Ausgabenlimit auf 0, startet kein einziger Job – auch keine Tests. Bei öffentlichen Repositories sind Actions kostenlos.

**Lösung:** Unter *GitHub → Settings → Billing and plans* die Zahlung/das Ausgabenlimit für Actions prüfen – oder das Repository öffentlich machen. Danach unter *Actions → Release → Run workflow* den Lauf einmal manuell starten.

### „Was ist neu?“ zeigt keine Notizen

**Ursache:** Die Releases auf GitHub haben keinen Beschreibungstext, oder das Repository ist privat und es ist kein Token hinterlegt

**Lösung:** Beim Release einen Text eintragen – die Release-Action der Nova-Plugins übernimmt dafür automatisch den Abschnitt der Version aus `CHANGELOG_de-DE.md` bzw. `CHANGELOG.md`. Für private Repositories den GitHub-Token in der Plugin-Konfiguration setzen

### Repository wurde auf GitHub umbenannt

**Ursache:** Nach einer Umbenennung (z. B. `SW6-Theme` → `SW6-Nova-Theme`) ist im Shop noch der alte Name gespeichert. GitHub leitet alte Adressen weiter, Updates funktionieren also weiter – im Katalog stand das Plugin aber als „mit anderem Repository verknüpft“

**Lösung:** Ab Version 1.4.0 trägt der Katalog die früheren Namen (`formerRepositories`). Beim Öffnen des Katalogs bzw. der Erweiterungsliste stellt der Updater solche Verknüpfungen automatisch auf den neuen Namen um. Eigene, nicht im Katalog stehende Plugins: unter „Repository verknüpfen“ den neuen Namen eintragen. Ist in der Konfiguration eine Liste erlaubter Repositories gepflegt, dort die neuen Namen ergänzen (oder `DrdotHouse2106/*`)

### Privates Repository: „nicht gefunden“

**Ursache:** Ohne Token sieht GitHub private Repos nicht

**Lösung:** In der Konfiguration ein **GitHub-Token** (nur Leserechte) hinterlegen, siehe „Private Repositories“

### „Die Erweiterungsverwaltung ist in dieser Umgebung deaktiviert“

**Ursache:** `shopware.deployment.runtime_extension_management: false` (z. B. bei Cluster-/Cloud-Setups)

**Lösung:** Plugins dort per Composer/Deployment installieren – der Updater respektiert diese Sperre absichtlich

### Fehlermeldung 403 beim Installieren

**Ursache:** Standardmäßig dürfen nur **Administratoren** installieren

**Lösung:** Als Admin anmelden oder „Nur Administratoren“ in der Konfiguration abschalten (dann gelten die ACL-Rechte `system.plugin_maintain` + `system.plugin_upload`)

### „GitHub-API-Limit erreicht“

**Ursache:** Ohne Token erlaubt GitHub nur **60 Anfragen pro Stunde** je Server-IP. Jede Prüfung kostet je Plugin mehrere Anfragen (Tags, Releases, composer.json je Version); dazu kommen Katalog, automatische Prüfung und „Verbindung testen“. Bis 1.2.1 wurden pro Plugin bis zu 15 Versionen geprüft

**Lösung:** Ab 1.2.2 werden nur neuere Versionen geprüft. Dauerhaft hilft ein **GitHub-Token** (5.000 Anfragen pro Stunde): GitHub → Settings → Developer settings → Personal access tokens → **Fine-grained tokens** → „Generate new token“, Ablaufdatum wählen, bei *Repository access* „**Public repositories (read-only)**“ (für private Repos stattdessen nur diese auswählen und *Contents: Read-only*), erzeugen und unter *Erweiterungen → GitHub Plugin-Updater → Konfigurieren → GitHub-Token* einfügen. Die Fehlermeldung nennt die Uhrzeit, ab der es auch ohne Token wieder geht

### Ein installiertes Plugin hat keinen Admin-Bereich

**Ursache:** Es wurde aus dem Quellcode installiert (kein Release-Paket am GitHub-Release), und das Repository enthält nur Admin-Quelltexte, keine gebauten Dateien. Der Updater zeigt dazu nach der Installation einen Hinweis.

**Lösung:** Prüfen, ob der Hersteller ein Release mit ZIP-Anhang hat – dann über „Aktualisieren“ erneut einspielen (ab 1.2.0 wird das ZIP bevorzugt). Sonst im Plugin `src/Resources/public/administration` mitliefern oder einmal `bin/build-administration.sh` ausführen (benötigt Node.js)

### Keine Glocken-Benachrichtigung bei neuen Versionen

**Ursache:** In der Shop-Umgebung laufen keine geplanten Aufgaben (kein Cron für `bin/console scheduled-task:run` und kein Admin-Worker), oder die Aufgabe ist noch nicht registriert

**Lösung:** Einmal `bin/console scheduled-task:register` ausführen und einen Cron für `scheduled-task:run` bzw. `messenger:consume` einrichten. Ohne Cron werden Updates trotzdem auf den Plugin-Karten angezeigt – geprüft wird dann beim Öffnen von „Meine Erweiterungen“

### Konsole meldet „Cannot find the "redis" extension nor the "relay" extension …“

**Ursache:** Der Befehl `php` bzw. `bin/console` nutzt die Standard-PHP-Version des Servers. Der Shop läuft aber mit einer anderen PHP-Version (bei Plesk z. B. `/opt/plesk/php/8.3`), in der die Redis-Erweiterung aktiv ist. Häufig wird außerdem als `root` gearbeitet

**Lösung:** Die PHP-Version der Domain verwenden und als Benutzer der Domain ausführen, z. B. `sudo -u $(stat -c %U .) /opt/plesk/php/8.3/bin/php bin/console scheduled-task:register`. Die Versionsnummer steht in Plesk unter „PHP-Einstellungen“ der Domain bzw. per `ls /opt/plesk/php/`. Befehle nicht als `root` ausführen, sonst gehören neu angelegte Cache-Dateien `root` und der Shop kann sie nicht mehr schreiben

### Hinweis „Benötigt zusätzliche Composer-Pakete“ nach der Installation

**Ursache:** Das Plugin braucht weitere Pakete (z. B. Nova Suche: `anthropic-ai/sdk` für die Claude-Stufe). Plugins wie die Nova Suche lassen sie von Shopware beim Installieren automatisch nachladen

**Lösung:** Ab 1.2.4 prüft der Updater nach der Installation selbst, ob die Pakete angekommen sind, und meldet sich nur, wenn wirklich etwas fehlt. Dann im Shop-Verzeichnis `composer require <paket>` ausführen (Fehlerbild sonst z. B. „Class … not found“ im Log)

### Update meldet „Shopware hat das Update noch nicht abgeschlossen (… Timeout)“, Karte zeigt weiter die alte Version

**Ursache:** Bis Version 1.2.4 hat Shopware beim Update in **einer** Anfrage die Themes **aller** Verkaufskanäle kompiliert – und zwar bevor es die neue Version einträgt. Bei mehreren Shops dauert das mehrere Minuten, länger als Browser bzw. PHP warten. Die Dateien liegen dann schon auf dem Server, aber die Datenbank kennt noch die alte Version; die Update-Prüfung meldet deshalb weiter „Version … verfügbar“

**Lösung:** Updater auf 1.3.0 aktualisieren – er aktualisiert in Schritten und kompiliert die Themes Shop für Shop. Ein hängengebliebenes Update abschließen: auf der Plugin-Karte „Aktualisieren“ oder per Konsole bzw. Plesk-Aufgabe (PHP-Skript `httpdocs/bin/console`, „Jetzt ausführen“) mit den Argumenten `plugin:update <PluginName>`, danach `cache:clear`

### „Das Theme konnte für diese Shops nicht kompiliert werden“

**Ursache:** Beim Kompilieren eines Verkaufskanals ist ein Fehler aufgetreten (z. B. SCSS-Fehler eines anderen Plugins, fehlende Schreibrechte in `public/theme`) – das Plugin selbst ist aktualisiert

**Lösung:** Unter *Inhalte → Themes* das Theme des genannten Shops öffnen und speichern, oder per Konsole `theme:compile`. Die Ursache steht im Shopware-Log

### Installationsfenster zeigt „Shopware richtet das Plugin noch ein …“

**Ursache:** Der Updater installiert und aktiviert das Plugin und kompiliert danach die Themes Shop für Shop; der Hinweis zeigt den Fortschritt

**Lösung:** Fenster offen lassen. Falls Shopware länger braucht, fragt der Updater bis zu 5 Minuten nach, bevor er eine Warnung zeigt

### Installation endet mit „Unbekannter Fehler“, das Plugin steht aber in der Liste

**Ursache:** Das Herunterladen hat geklappt, aber Shopware ist beim anschließenden Installieren/Aktivieren in eine Zeitüberschreitung gelaufen. Plugins mit Storefront-Dateien (z. B. Nova Tag Manager, Nova Theme) lösen beim Aktivieren das Kompilieren der Themes für **alle** Verkaufskanäle aus – bei mehreren Shops dauert das länger, als Webserver/PHP erlauben

**Lösung:** Plugin in der Liste über den Schalter bzw. „…“ → „Installieren“ erneut aktivieren. Klappt das nicht, per Konsole bzw. Plesk-Aufgabe (PHP-Skript `httpdocs/bin/console`) mit den Argumenten `plugin:install --activate <PluginName>` – dort gibt es keine Zeitbegrenzung. Ab 1.2.1 zeigt der Updater statt „Unbekannter Fehler“ einen genauen Hinweis

### Der Plugin-Katalog zeigt „mitgelieferte Fassung“

**Ursache:** GitHub war nicht erreichbar, das API-Limit war erreicht oder „Aktuellen Katalog von GitHub laden“ ist deaktiviert

**Lösung:** Im Katalog auf „Aktualisieren“ klicken. Bei häufigem Ratenlimit ein GitHub-Token hinterlegen (5000 statt 60 Anfragen pro Stunde)

### Plugin aus dem Katalog lässt sich nicht installieren: „keine kompatiblen Versionen“

**Ursache:** Der Hersteller hat noch keine Version für deine Shopware-Version veröffentlicht (z. B. nur `~6.6` in der composer.json)

**Lösung:** Abwarten oder beim Hersteller nachfragen; der Katalog listet Plugins unabhängig von deiner Shopware-Version

### Plugin mit Composer-Abhängigkeiten (z. B. Nova Suche)

**Ursache:** Shopware lädt Abhängigkeiten beim Installieren per Composer nach – das kann auf Hostern ohne Composer/Schreibrechte scheitern

**Lösung:** Im Shop-Hauptverzeichnis die Abhängigkeit manuell installieren, z. B. `composer require anthropic-ai/sdk`

### „Verbindung testen“ meldet ✗ bei „Plugin“: Plugin passt nicht

**Ursache:** Die `composer.json` im Repository gehört zu einem anderen Plugin (anderer Name bzw. andere `extra.shopware-plugin-class`) – meist falsches Repository verknüpft

**Lösung:** Richtige Repository-URL eintragen. Bei eigenen Plugins Name und Plugin-Klasse in der `composer.json` prüfen

### „Verbindung testen“ meldet ✗ bei „Erlaubte Repositories“

**Ursache:** Das Repository steht nicht in der Positivliste der Plugin-Konfiguration

**Lösung:** In *Einstellungen → Erweiterungen → GitHub Plugin-Updater* unter „Erlaubte Repositories“ `owner/repo` oder `owner/*` ergänzen

### „Verbindung testen“ meldet ✗ bei „Zugriff“: Token ungültig

**Ursache:** Das hinterlegte GitHub-Token ist abgelaufen, widerrufen oder falsch kopiert

**Lösung:** Neues Token (nur Lesezugriff) erstellen und in der Konfiguration eintragen

### Im „…“-Menü fehlt „Mit GitHub verknüpfen“

**Ursache:** Das Plugin ist nicht installiert, der Benutzer hat nicht die Rechte `system.plugin_maintain` + `system.plugin_upload`, oder die Erweiterungsverwaltung ist deaktiviert

**Lösung:** Plugin zuerst installieren bzw. mit ausreichenden Rechten anmelden; Administration nach Plugin-Update mit F5 neu laden

### Auf der Plugin-Karte ist kein GitHub-Bereich zu sehen

**Ursache:** Gewollt: Der Bereich erscheint nur bei verknüpften Plugins

**Lösung:** Über das „…“-Menü → „Mit GitHub verknüpfen …“ verknüpfen

## Lizenz

MIT – siehe [LICENSE](LICENSE).
