# Penates

Overzicht van artikelen in Business Central-projectbins die aandacht nodig
hebben: restvoorraad die geen actief werkorder meer nodig heeft, voorraad
onder de minimumvoorraad, of te weinig voor openstaande (nog niet gepickte)
werkorderbehoefte. Ook voorraad bij een project met status `04 FINISHED` en
binvoorraad die lager is dan de gepickte hoeveelheid wordt gemarkeerd.
Restvoorraad die precies de minimumvoorraad dekt blijft buiten het overzicht.

## Werking

- `web/nightly.php` haalt voor alle actieve BC-bedrijven de volledige dataset op
  en schrijft `web/data/penates_snapshot.json`.
- `web/index.php` leest uitsluitend deze snapshot en doet bij paginalaad geen
  OData-verzoeken.
- Via de hercontroleknop op een tabelregel wordt alleen dat specifieke artikel
  live in BC gecontroleerd en in de snapshot bijgewerkt of verwijderd.

De productieomgeving kan de nachtelijke refresh via `GET /nightly.php` blijven
aanroepen. Lokaal kan hetzelfde met:

```sh
php web/nightly.php
```


## Mímir (optional)

Set in `web/auth.php` (not in git):

```php
$mimirApi  = 'mimir_…';
// optional:
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';
```

With `$mimirApi` set, OData fetches (nightly snapshot build and live recheck) and company discovery try Mímir first (`max_age` from `PENATES_NIGHTLY_MAX_AGE` = 14400 on nightly builds, `PENATES_ODATA_TTL` = 86400 on live recheck). If that call fails (connection/timeout, non-2xx, invalid JSON, or a Mímir error payload), Penates fetches the same data on the legacy Business Central path (`$baseUrl`, `$auth` / `$auth_list`, `$environment`, local odata file cache) and skips Mímir for the rest of that PHP request. Keep those BC credentials in `auth.php` next to `$mimirApi`; if they are absent the original Mímir error is raised. This covers live pages (`index.php` recheck via `refresh.php`) and nightly/CLI (`php web/nightly.php` and `GET /nightly.php`). Without `$mimirApi` the existing direct-BC path remains unchanged. Penates has no separate company userprefs beyond the on-page company filter (snapshot-driven).

Pure classificatietests:

```sh
php tests/penates_data_test.php
```
