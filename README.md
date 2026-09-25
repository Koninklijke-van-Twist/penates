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

With `$mimirApi` set, `$auth_list`, `$environment`, `$baseUrl` and `$auth` are unused for Business Central — OData fetches (nightly snapshot build and live recheck) and company discovery go through Mímir (`max_age` from `PENATES_ODATA_TTL`). Without `$mimirApi` the existing direct-BC path remains unchanged. Penates has no separate company userprefs beyond the on-page company filter (snapshot-driven).

Pure classificatietests:

```sh
php tests/penates_data_test.php
```
