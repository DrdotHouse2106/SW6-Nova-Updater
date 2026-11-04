# Beitragen

Beiträge sind willkommen – vor allem neue Einträge für den **Plugin-Katalog**.

## Plugin in den Katalog aufnehmen

Der Katalog ist die Datei [`src/Resources/catalog/plugins.json`](src/Resources/catalog/plugins.json).
Shops mit aktivierter Option „Aktuellen Katalog von GitHub laden“ sehen neue Einträge
spätestens 6 Stunden nach dem Merge – ohne Update des Updaters.

**Weg 1 – Pull Request (am schnellsten):** Eintrag in `plugins.json` ergänzen und einen
Pull Request öffnen. Die Tests prüfen den Katalog automatisch.

**Weg 2 – Vorschlag:** [Issue „Plugin vorschlagen“](https://github.com/DrdotHouse2106/SW6-Nova-Updater/issues/new?template=plugin-vorschlagen.yml)
ausfüllen; der Eintrag wird dann übernommen.

### Aufbau eines Eintrags

```json
{
    "repository": "owner/repository",
    "pluginName": "MeinPlugin",
    "name": "Mein Plugin",
    "vendor": "Firma oder Name",
    "category": "storefront",
    "license": "MIT",
    "description": {
        "de-DE": "Was das Plugin macht – ein bis zwei Sätze.",
        "en-GB": "What the plugin does – one or two sentences."
    }
}
```

- `repository` (Pflicht): `owner/repository` auf github.com
- `pluginName` (Pflicht): letzter Teil von `extra.shopware-plugin-class`
- `name`, `vendor` (Pflicht): je max. 80 Zeichen
- `category` (Pflicht): `payment`, `shipping`, `search`, `marketing`, `storefront`,
  `performance`, `administration`, `language`, `developer` oder `other`
- `license` (optional): SPDX-Kennung, z. B. `MIT`
- `description` (Pflicht): `de-DE` und `en-GB`, je max. 400 Zeichen

### Voraussetzungen für das Plugin

- Öffentliches Repository, `composer.json` im Hauptverzeichnis mit `"type": "shopware-platform-plugin"`.
- Versionen als Tags/Releases (`1.2.0` oder `v1.2.0`) mit korrekter `require.shopware/core`-Angabe.
- **Empfohlen:** ein fertiges ZIP als Release-Anhang (`<PluginName>.zip` oder `<PluginName>-<Version>.zip`,
  darin der Ordner `<PluginName>/`). Der Updater bevorzugt dieses Paket automatisch – damit kommen
  gebaute Administration, Storefront-JavaScript und ggf. `vendor/` mit.
  Ohne Release-Paket wird der Quellcode installiert; dann müssen `src/Resources/public/administration`
  bzw. `src/Resources/app/storefront/dist` im Repository liegen.
- Eine fertige GitHub Action, die solche Releases automatisch erzeugt, liegt in
  [`.github/workflows/release.yml`](.github/workflows/release.yml) und kann übernommen werden.
  Sie legt zum ZIP auch eine Prüfsummen-Datei `<ZIP-Name>.sha256` (Ausgabe von `sha256sum`) ins
  Release, gegen die der Updater zusätzlich prüft.
- Das Plugin wird aktiv gepflegt und ist mit Shopware 6.6 oder 6.7 kompatibel.

Vor dem Pull Request bitte im eigenen Shop „Verbindung testen“ ausführen.

## Code

```bash
composer install
vendor/bin/phpunit
```

Hinweistexte und Dokumentation bitte auf Deutsch (Englisch zusätzlich gern).
