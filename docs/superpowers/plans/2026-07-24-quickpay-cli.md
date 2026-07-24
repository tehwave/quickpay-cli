# Quickpay CLI – implementeringsplan

## Resumé

Opret `/Users/peterchrjoergensen/webdev/quickpay-cli` som et lokalt Git-repo på `main`, uden remote. Projektet bygges med Laravel Zero 12, PHP 8.4+, Pest og Laravels HTTP-klient.

CLI’en får Flare-inspireret struktur, sikker credential-håndtering, læsbart terminaloutput, `--json` til automation og en agent-skill. Quickpays legacy Swagger-specifikation bruges som dokumentationskilde, men ikke til kodegenerering. API-integrationen følger Quickpays v10-authentication, headers, betalingsflow og Link-pagination. [Laravel Zero-dokumentation](https://github.com/laravel-zero/docs), [Quickpay API](https://learn.quickpay.net/tech-talk/api/), [Flare CLI](https://github.com/spatie/flare-cli).

## Implementering

1. **Repository og fundament**
   - Scaffold `quickpay-cli` med `composer create-project laravel-zero/laravel-zero:^12.0`.
   - Konfigurér binæren `quickpay`, Composer-pakken `peterchrjoergensen/quickpay-cli`, MIT-licens og PHP `^8.4`.
   - Tilføj Pint, Larastan/PHPStan og Pest samt Composer-scripts: `test`, `analyse`, `format`, `format:test`, `build` og `check`.
   - Gem den godkendte plan i `docs/superpowers/plans/2026-07-24-quickpay-cli.md`.

2. **API-klient og authentication**
   - Implementér `QuickpayClient`, som sender JSON til `https://api.quickpay.net`, bruger Basic Auth med tomt brugernavn, `Accept-Version: v10`, 30 sekunders timeout og ingen automatiske retries på mutationer.
   - Implementér `QuickpayResponse` med HTTP-status, headers og decoded/raw body samt struktureret gengivelse af `message`, `errors`, `error_code` og Quickpay-statusfelter.
   - Implementér `CredentialStore` med prioriteten `QUICKPAY_API_KEY` → `~/.config/quickpay/config.json`. Skriv atomisk med mappe/file modes `0700`/`0600`, og vis aldrig nøglen i output.
   - Tilføj:
     - `quickpay login`: hemmelig prompt, valider nøglen via `/ping`, og kræv merchant-scope.
     - `quickpay auth`: vis credential-kilde, API-version og aktiv scope uden nøgle.
     - `quickpay logout`: fjern kun gemte credentials og forklar, hvis en environment-variabel fortsat er aktiv.

3. **Offentlig betalings-CLI**
   - Alle read-kommandoer viser tabel/detaljer som standard. `--json` skriver Quickpays originale JSON til stdout; fejl går til stderr.
   - Implementér:
     - `payments:list [--accepted] [--state=] [--order-id=] [--created-after=] [--created-before=] [--page-size=20] [--all] [--max-pages=100] [--json]`
     - `payments:get {id} [--operations-size=] [--json]`
     - `payments:create {order-id} {currency=DKK} [--field=key=value]* [--json]`
     - `payments:link {id} {amount} [--continue-url=] [--cancel-url=] [--callback-url=] [--language=] [--payment-methods=] [--auto-capture] [--field=key=value]* [--json]`
     - `payments:capture {id} {amount} [--synchronized] [--callback-url=] [--yes] [--json]`
     - `payments:refund {id} {amount} [--vat-rate=] [--synchronized] [--callback-url=] [--yes] [--json]`
     - `payments:cancel {id} [--synchronized] [--callback-url=] [--yes] [--json]`
   - Beløb er altid positive heltal i valutaens mindste enhed; `1000` betyder DKK 10,00. Order-ID valideres som 4–20 tegn, og valuta normaliseres til tre store bogstaver.
   - `--all` følger kun Quickpays `rel="next"`-links og afviser pagination-URL’er på andre hosts.
   - Capture, refund og cancel henter betalingen først, viser ID/order-ID/valuta/status/beløb og kræver bekræftelse. Ikke-interaktiv brug kræver `--yes`.

4. **Rå API-adgang og sikkerhed**
   - Tilføj `quickpay api {method} {path} [--query=key=value]* [--data=key=value]* [--data-json=] [--header=name:value]* [--yes] [--json]`.
   - Tillad kun relative paths under `api.quickpay.net`; afvis schemes, hosts, `..` og overrides af `Authorization`, `Host` og `Accept-Version`.
   - GET-kald kan køre direkte. POST, PUT, PATCH og DELETE kræver bekræftelse eller `--yes`.
   - `--data` og `--data-json` er gensidigt udelukkende. API-nøglen må aldrig optræde i fejl, verbose output eller exceptions.

5. **Distribution og dokumentation**
   - Byg `builds/quickpay` med Laravel Zeros Box-baserede `app:build`, og peg Composer `bin` på PHAR-filen, så en senere Packagist-udgivelse kan installeres globalt.
   - Tilføj GitHub Actions til PHP 8.4/8.5-tests, statisk analyse, formatting-check og PHAR-smoketest; opret ingen GitHub-remote nu.
   - Skriv README med installation, login, alle kommandoer, Quickpays testtransaktioner, sikkerhedsadvarsler og releaseprocedure.
   - Tilføj `skills/quickpay/SKILL.md` med sikre agent-workflows og en kommando-reference. Forbered installation via `skills add peterchrjoergensen/quickpay-cli`, men dokumentér den først som aktiv efter offentliggørelse.
   - Brug Composer-opdatering som update-mekanisme; ingen separat self-update-kommando.

## Testplan

- Credential-precedence, filrettigheder, atomisk skrivning, logout og hemmelighedsredaktion.
- Korrekte Basic Auth-, JSON- og `Accept-Version`-headers med `Http::fake`; ingen tests må ramme live-API’et.
- Login med gyldig merchant-key, ugyldig key, forkert scope og netværksfejl.
- Tabel- og JSON-output for list/get samt Link-pagination, sidegrænse og afvisning af fremmede hosts.
- Request-shapes og validering for create/link/capture/refund/cancel.
- Bekræftelser, `--yes` og afvisning af mutationer i ikke-interaktive terminaler.
- Raw API path/header-sikkerhed, dataformater og fejlrendering uden credential-læk.
- `composer check`, PHAR-build og smoketest af `builds/quickpay --version` og `builds/quickpay list`.

## Antagelser og afgrænsning

- V1 omfatter betalings-MVP’en, auth/configuration, raw API og agent-skill.
- Subscriptions, callback-listener, komplet API-dækning, OpenAPI-konvertering og macOS Keychain er ikke med.
- Quickpay v10 fastlåses som eneste API-version, fordi det er den aktuelt dokumenterede version.
- Repoet forbliver lokalt uden remote eller publicering; strukturen er blot klar til en senere GitHub/Packagist-release.
