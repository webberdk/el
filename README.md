# FlexEnergi og vejr til smedegaard.org/el

Upload filerne i denne mappe til webhotellets mappe `el` i webroden. Åbn
https://smedegaard.org/el/. Dette repository indeholder kun elpris- og vejrsiden.
PHP 8.0+ med cURL eller `allow_url_fopen` og udgående HTTPS til Energinet og
DMI kræves. Ingen API-nøgle, build eller cron er nødvendig.
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

Timeprognosen for Greve kommer direkte fra DMI’s HARMONIE-model via Forecast Data EDR API. Ingen API-nøgle kræves. Kelvin omregnes til °C, vind er m/s og akkumuleret nedbør (kg/m² = mm) omregnes til timeintervaller ved differensen mellem to nabotidspunkter fra samme modelkørsel. Manglende naboer og nulstilling af summer giver ukendt nedbør. Symbolerne er en enkel lokal klassifikation af skydække og nedbør, ikke DMI’s officielle symboler. Den nyeste prognose dækker ikke nødvendigvis tidligere timer i dag; disse vises som ukendte. Temperatur og vind er værdier
ved timens begyndelse; nedbør er summen i det viste timeinterval. Alle tider
kobles på UTC, så sommer- og vintertid håndteres korrekt. Begge gentagne
02-timer får en separat værdi og kan skelnes i detaljer og tabel via CET/CEST.

I dag og i morgen vises. Ukendte priser eller vejrdata vises som “–”. Elpris
og vejr kan fejle uafhængigt. Gammel cache markeres tydeligt med hentetidspunkt.
Siden genindlæses hvert femte minut; spotdata caches 15 min., vejr 30 min.
JSON: `/el/?format=json`. DMI’s Frie Data krediteres på siden.
DMI-cache har et særskilt filnavn, så tidligere Open-Meteo-data aldrig vises som DMI.
Ved fejl vises HTTP-status eller en forbindelsesfejl. Nye forsøg pauses i to
minutter, også før første vellykkede hentning. Eksisterende vejrdata bevares.
Forespørgslen begrænses til i dag og i morgen samt næste midnat til nedbørsberegningen.
Dokumentation:
https://www.dmi.dk/friedata/dokumentation/forecast-data-edr-api
https://www.dmi.dk/friedata/dokumentation/data/weather-model-harmonie-edr-api-parameter-list


## Kontrol

`php -l index.php`, `php -l functions.php` og `php tests/flexenergi.php` og `php tests/dmi.php`
fra repository-roden. Kontroller inkluderer tarifgrænser, moms, manglende
kvarterer, negative priser, gentagne timer ved vintertid og nedbørens timeinterval.

Vejrikoner: Lucide (ISC), licens i `icons/LICENSE`.
