# Blokové čištění Brno → kalendář

Kalendáře blokového čištění ulic v Brně ve formátu iCalendar. Data se berou
z veřejného API [cisteni.bkom.cz](https://cisteni.bkom.cz/cs) (Brněnské komunikace a.s.),
generuje se jeden `.ics` na každou ulici a všechno se publikuje na GitHub Pages.

**➡️ [Najdi svou ulici](https://milous.github.io/cisteni-bkom/)**

## Jak to použít

1. Otevři [stránku s vyhledáváním](https://milous.github.io/cisteni-bkom/) a najdi svou ulici.
2. Klikni na **Přidat do kalendáře** (odkaz `webcal://`), nebo si zkopíruj URL
   `https://milous.github.io/cisteni-bkom/<ID ulice>.ics`.
   U ulic čištěných po částech se pod ulicí nabídnou jednotlivé úseky s vlastním
   kalendářem — ať nedostáváš upozornění na část ulice, kde neparkuješ.
3. Kalendář se sám aktualizuje, upozornění přijde 18 hodin před začátkem čištění.

Přidání odebíraného kalendáře:

- **iOS / macOS** — odkaz `webcal://` stačí otevřít, systém se zeptá na potvrzení.
- **Google Calendar** — *Jiné kalendáře → + → Z adresy URL* a vložit `https://…​.ics`.
  Google si obsah tahá po svém, klidně i jednou za den.
- **Android** — přes Google Calendar (viz výše), aplikace samotná odběr URL neumí.

Kalendář se všemi termíny v Brně najednou: [`all.ics`](https://milous.github.io/cisteni-bkom/all.ics).

> Neoficiální nástroj. **Závazné je vždy dopravní značení na místě.**

## Jak to funguje

| Krok | Co se děje |
| --- | --- |
| 1 | `GET /api/sweep/map?from=…&to=…` stáhne všechny úklidy v okně −1 rok … +2 roky (jeden request, ~2600 záznamů). |
| 2 | `GET /api/street` stáhne číselník ulic (názvy, městské části, souřadnice). |
| 3 | `SweepStorage` porovná data se snapshotem `data/sweeps.json`. Termín, který z API zmizel a ještě nenastal, se označí jako zrušený. |
| 4 | `IcsGenerator` vygeneruje `output/<ID ulice>.ics`, u ulic s více úseky navíc `output/<ID ulice>-<úsek>.ics`, a `output/all.ics`. |
| 5 | `IndexGenerator` vygeneruje `output/streets.json` a překopíruje `public/` (vyhledávací stránka). |
| 6 | GitHub Action commitne snapshot a nasadí `output/` na GitHub Pages. |

Poznámky k API:

- Pokud se v `/api/sweep/map` uvede `streetID`, parametry `from`/`to` se ignorují.
  Proto se stahuje celý dataset najednou a filtruje se až lokálně.
- `section.id` (`sid`) **není stabilní** — stejný úsek dostane při každém termínu
  jiné id (Bítešská: `3539` v září, `2616` v říjnu). Úseky se proto identifikují
  podle normalizovaného názvu, což zároveň sloučí drobné rozdíly v zápisu
  (`Sabinova ||` vs `Sabinova`).
- Noční úklidy mají konec se stejným datem jako začátek (19:00–05:00 přijde jako
  `from 17:00Z`, `to 03:00Z` téhož dne); konec se posouvá na další den.

Zrušené termíny dostanou `STATUS:CANCELLED` a prefix `[ZRUSENO]`, aby zmizely
i z už odebíraných kalendářů. Ve snapshotu se drží ještě 30 dní po plánovaném termínu,
pak se zahodí; historie se uchovává jeden rok.

## Vývoj

```bash
make build     # sestavení Docker image
make install   # composer install
make test      # PHPUnit
make sync      # ostrý běh proti API, zapíše do output/
make shell     # shell v kontejneru
```

Pro rychlé ladění se dá generování omezit na vybrané ulice:

```bash
docker compose run --rm -e CISTENI_STREET_IDS=2526 php php sync.php
```

Adresář `output/` je v `.gitignore` — generuje se při každém běhu znovu
a rovnou se nasazuje na Pages. V gitu je jen snapshot `data/sweeps.json`.

### Nasazení Pages

Jednorázově je potřeba přepnout zdroj Pages na GitHub Actions:

```bash
gh api -X POST repos/milous/cisteni-bkom/pages -f build_type=workflow
```

Volitelné secrets pro e-mail při selhání syncu: `SMTP_URL`, `SMTP_USER`,
`SMTP_PASSWORD`, `MAIL_FROM`, `MAIL_TO`. Bez nich se notifikace přeskočí.

## Licence

MIT
