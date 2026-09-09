# Penates

Overzicht van artikelen die nog in een Business Central-projectbin liggen, maar
door geen actief werkorder meer nodig zijn.

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

Pure classificatietests:

```sh
php tests/penates_data_test.php
```
