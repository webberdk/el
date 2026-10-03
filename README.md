# FlexEnergi og vejr til smedegaard.org/el

Upload filerne i denne mappe til webhotellets mappe `el` i webroden. Åbn
https://smedegaard.org/el/. Dette repository indeholder kun elpris- og vejrsiden.
PHP 8.0+ med cURL eller `allow_url_fopen` og udgående HTTPS til Energinet og
MET Norway kræves. Ingen API-nøgle, build eller cron er nødvendig.
PHP skal kunne oprette en `cache`-mappe. Den beskyttes med `.htaccess` på Apache.

## Prisgrundlag

DK2, Andel FlexEnergi og Radius kundekategori C. Bekræft Radius og Andels tillæg
på din regning; et individuelt aftaletillæg kan afvige fra det offentliggjorte.
Alle viste totalpriser inkluderer moms, nettarif, Energinet og elafgift.
Faste abonnementer er ikke inkluderet i kr/kWh. De kan ikke fordeles præcist
uden at kende forbruget. Fire komplette kvarterpriser giver timegennemsnittet.
Ved kvartersafregning vil regningen afhænge af forbrugets placering i timen.

Satserne findes i `config.php` og er kontrolleret 3. oktober 2026:

- Andel: 14,63 øre/kWh inklusive moms.
- Energinet: nettarif 4,3 + systemtarif 7,2 øre/kWh uden moms.
- Elafgift: 0,8 øre/kWh uden moms i 2026 og 2027.
- Radius uden moms: lav 10,62 øre; vinter høj 31,85 og spids 95,56;
  sommer høj 15,93 og spids 41,41.

Kilder:
https://andelenergi.dk/el/flexenergi/
https://energinet.dk/media/5v3pikp3/energinets_tarifkatalog_2026.pdf
https://app.cerius-radius.dk/assets/files/Radius%20-%201.%20april%202026%20-%20Fuld%20prisliste.pdf
https://skat.dk/erhverv/afgifter-paa-varer-og-ydelser-punktafgifter/nyhedsbrev-afgifter/midlertidig-nedsaettelse-af-elafgiften-i-2026-og-2027

Kontrollér satser ved prisændringer, især 1. januar og 1. juli. Den 1. januar
2027 stopper samlede elpriser, indtil `config.php` har opdaterede satser og
`SATSER_TIL`. Siden genbruger ikke gamle satser som aktuelle.

## Vejr og drift

Timeprognosen for Greve hentes fra MET Norway Locationforecast 2.0 (`compact`).
Ingen API-nøgle kræves. Temperatur er °C, vind er m/s og `next_1_hours`
giver nedbør i mm for den efterfølgende time. Seks- og tolvtimerssummer
fordeles aldrig som timeværdier. METs vejrkoder vises med lokale Lucide-ikoner;
ved manglende koder bruges en enkel klassifikation af skydække og nedbør.
Tidligere timer i dag kan mangle i den nyeste prognose og vises da som ukendte.
Alle tider kobles via UTC, inklusive gentagne timer ved vintertid.

I dag og i morgen vises. Ukendte priser og vejrdata vises som “–”.
Elpris og vejr kan fejle uafhængigt; gammel cache markeres med hentetidspunkt.
Siden genindlæses hvert femte minut. METs `Expires` styrer vejrcachen, og
`If-Modified-Since` bruges ved opdatering. User-Agent identificerer siden og
linker til dette repo. MET Norway krediteres med CC BY 4.0-link på siden.
Separat MET-cache forhindrer genbrug af tidligere udbyderes data.
Ved fejl vises HTTP-status eller forbindelsesfejl. Forsøg pauses mindst to
minutter, også før første vellykkede hentning; `Retry-After` respekteres. API-versionens udfasning (HTTP 203) vises som en advarsel.
JSON: `/el/?format=json`.

Dokumentation:
https://api.met.no/doc/locationforecast/HowTO
https://api.met.no/doc/ForecastJSON
https://api.met.no/doc/TermsOfService
https://api.met.no/doc/License


## Kontrol

`php -l index.php`, `php -l functions.php` og `php tests/flexenergi.php` og `php tests/met.php`
fra repository-roden. Kontroller inkluderer tarifgrænser, moms, manglende
kvarterer, negative priser, gentagne timer ved vintertid og METs timeintervaller og enheder.

Vejrikoner: Lucide (ISC), licens i `icons/LICENSE`.
