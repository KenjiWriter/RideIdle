# Wdrożenie RideIdle na serwer Ubuntu

Ten folder stawia całą grę na jednym serwerze Ubuntu (22.04 albo 24.04) pod jedną domeną, `raceidle.cerasusdigital.pl`: aplikacja web pod `/`, API pod `/api`, certyfikat Let's Encrypt, kolejka maili, harmonogram, kopie bazy i zapora. Zestaw jest sprawdzony statycznie i w części na Windowsie, **ale nie był jeszcze uruchomiony na prawdziwym serwerze Ubuntu** (sekcja „Co sprawdzono, a czego nie” na końcu). Pierwsze uruchomienie zrób na serwerze, który możesz wyrzucić.

## Jak to jest ułożone

```
internet ─ 80/443 ─ nginx ─┬─ /          pliki aplikacji web (current/web/dist), reszta adresów dostaje index.html
                           │              (roboty podglądów linków: ta sama strona z API, z tagami gry)
                           ├─ /api/      PHP-FPM ─ Laravel (current/api)
                           └─ /up        sprawdzenie, czy Laravel żyje
PostgreSQL (baza)   Redis (tylko liczniki limitów)   rideidle-queue (systemd, wysyła maile)   cron (harmonogram, kopie)
```

```
/var/www/rideidle/
├── releases/20261023-141500-ab12cd3/   jedno wydanie = jeden folder (zostaje pięć ostatnich)
├── current -> releases/...             wydanie, które działa; zmienia się jednym przełączeniem linku
├── shared/.env                         ustawienia API; poza wydaniami, więc nowe wydanie je zastaje
├── shared/storage/                     logi i pamięć podręczna Laravela
├── incoming/                           tu upload.sh kładzie źródła przed zbudowaniem
└── bin/deploy.sh                       skrypt wydania
```

| Plik | Co robi |
|---|---|
| `provision.sh` | raz, jako root, na świeżym serwerze: instaluje wszystko i ustawia. Można go uruchomić ponownie (nie nadpisuje `.env` ani bazy) |
| `deploy.sh` | wydanie: zbuduj, zmigruj, przełącz, sprawdź, w razie błędu wróć |
| `upload.sh` | z Twojego komputera: wysyła zatwierdzony stan repozytorium i wywołuje `deploy.sh` |
| `nginx/`, `systemd/` | szablony konfiguracji, w które `provision.sh` wstawia domenę i foldery |
| `bin/rideidle-backup` | nocna kopia bazy |
| `bin/rideidle-backup-verify` | sprawdzanie kopii: co noc szybkie, co niedzielę z odtworzeniem do osobnej bazy |
| `bin/rideidle-backup-offsite` | wysyłanie najnowszej kopii (szyfrowanej) na inny serwer |
| `loadtest/loadtest.mjs` | test obciążeniowy API (Node 18+, bez pakietów) |
| `env.production.example` | wzór `.env` na serwer |

## Czego potrzebujesz

- Serwer z Ubuntu 22.04 lub 24.04, **najlepiej 2 GB pamięci i 20 GB dysku** (mniej pamięci: skrypt dokłada 2 GB swapu, bo budowanie aplikacji web jej potrzebuje), publiczny adres IPv4, dostęp przez SSH jako `root` albo jako użytkownik z `sudo` (np. `ubuntu` na VPS OVH). **Serwer ma być pusty** (patrz „Serwer, który już jest w użyciu”).
- Dostęp do DNS domeny `cerasusdigital.pl`.
- Na Twoim komputerze: `git` i `ssh` (Git Bash na Windowsie wystarczy; `rsync` nie jest potrzebny).
- Dane SMTP, przez które pójdą maile z linkami (patrz „Poczta”).

## Krok 1. DNS

U siebie, gdzie jest strefa `cerasusdigital.pl`, dodaj rekord:

| Typ | Nazwa | Wartość | TTL |
|---|---|---|---|
| A | `raceidle` | adres IPv4 serwera | 300 |

- **AAAA** dodaj tylko wtedy, gdy serwer ma publiczny adres IPv6, który chcesz obsługiwać (nginx i zapora go obsługują). Błędny AAAA psuje wydanie certyfikatu, bo Let's Encrypt najpierw próbuje IPv6.
- **CAA** (niewymagany, ale tani): `raceidle.cerasusdigital.pl. CAA 0 issue "letsencrypt.org"`.
- Jeśli domena jest u Cloudflare, ustaw **„tylko DNS” (szara chmurka)**. Przez proxy API widziałoby adresy Cloudflare, a limity prób są liczone po adresie klienta; zestaw nie ma ustawionego zaufanego proxy.

Poczekaj, aż `nslookup raceidle.cerasusdigital.pl` pokaże adres serwera. `provision.sh` sprawdza to sam i zatrzymuje się z opisem, jeśli rekord jest zły.

## Krok 2. Przygotowanie serwera (raz)

Pliki z `deploy/` muszą trafić na serwer. Są dwie drogi.

**A. Z Twojego komputera** (Git Bash, z katalogu repozytorium; wymaga klucza SSH na komputerze):

```bash
deploy/upload.sh bootstrap ubuntu@ADRES_SERWERA
```

Skrypt wysyła zatwierdzony stan `deploy/` do `~/rideidle-deploy` i pokazuje, co dalej.

**B. Przez git na serwerze** (gdy logujesz się innym narzędziem, np. MobaXterm). Zmiany muszą być wypchnięte do GitHuba, a repozytorium dostępne dla serwera (publiczne albo z kluczem):

```bash
git clone https://github.com/KenjiWriter/RideIdle.git ~/RideIdle
```

W obu wypadkach potem, na serwerze (`sudo env` przekazuje zmienne; jako `root` pomiń `sudo`):

```bash
cd ~/rideidle-deploy   # albo: cd ~/RideIdle/deploy
sudo env DOMAIN=raceidle.cerasusdigital.pl ADMIN_EMAIL=twoj@adres.pl bash provision.sh
```

`ADMIN_EMAIL` dostaje od Let's Encrypt ostrzeżenia o certyfikacie. Pozostałe ustawienia (zmienne środowiska po `sudo env`, przed `bash provision.sh`):

| Zmienna | Znaczenie |
|---|---|
| `MAIL_FROM_ADDRESS` | nadawca maili; domyślnie `no-reply@cerasusdigital.pl` |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`, `MAIL_USERNAME` | serwer SMTP (port 587 + `smtp`, albo 465 + `smtps`) |
| `LEGAL_OPERATOR_NAME`, `LEGAL_OPERATOR_ADDRESS`, `LEGAL_CONTACT_EMAIL` | kto prowadzi grę; pokazują to regulamin i polityka prywatności |
| `SSH_PORT` | port SSH, który zapora zostawia otwarty (domyślnie 22) |
| `DEPLOY_USER`, `APP_DIR`, `PHP_VERSION`, `NODE_MAJOR` | użytkownik wydań (`deploy`), folder gry, wersje |
| `CERTBOT_STAGING=1` | testowy certyfikat, który nie zużywa limitów Let's Encrypt |
| `SKIP_DNS_CHECK=1` | pomija sprawdzenie DNS (nie używaj bez powodu) |
| `SHARED_SERVER=1` | serwer już obsługuje inne strony przez nginx: dodaj grę i nie ruszaj reszty (przeczytaj najpierw następną sekcję) |

**Hasła nie wpisuj w poleceniu** (zostaje w historii powłoki): `MAIL_PASSWORD` uzupełnij w `.env` (krok 3). Wartości ze znakami `$`, `\`, `"`, `&` są zapisywane do `.env` poprawnie.

Skrypt: sprawdza system i DNS, dodaje swap, instaluje PHP 8.4 (z PPA ondrej), Composera, Node 24, PostgreSQL, Redis (tylko na 127.0.0.1) i nginx, zakłada bazę i użytkownika, tworzy użytkownika `deploy` (z kluczami SSH konta, z którego uruchomiłeś skrypt, albo roota) i foldery, generuje `.env` z nowym `APP_KEY` i hasłem bazy, dopisuje wąskie uprawnienia `sudo` dla `deploy`, instaluje kolejkę, harmonogram i kopie, zdobywa certyfikat (webroot, odnawiany sam; po odnowieniu nginx jest przeładowywany), a na końcu włącza zaporę `ufw` (SSH, 80, 443), `fail2ban` i automatyczne aktualizacje bezpieczeństwa. Na końcu wypisuje, co zostało do zrobienia.

**Najpierw na próbę:** `CERTBOT_STAGING=1` w poleceniu z `provision.sh` daje certyfikat testowy, któremu przeglądarki nie ufają. Wtedy dopisz do `/etc/rideidle/deploy.env` linię `HEALTH_CURL_OPTS=-k`, żeby sprawdzenie po wydaniu go przyjęło. Gdy wszystko działa, uruchom `provision.sh` jeszcze raz, **bez** `CERTBOT_STAGING`: skrypt rozpozna certyfikat testowy i **zamieni go w miejscu** na prawdziwy (ten sam efekt daje ręcznie `certbot certonly --webroot -w /var/www/certbot -d DOMENA --cert-name DOMENA --force-renewal --deploy-hook "systemctl reload nginx" -m ADRES --agree-tos`). Nie używaj `certbot delete`: strona wskazuje na pliki certyfikatu, więc po usunięciu każdy `reload` nginx, także dla innych stron na serwerze, by się nie powiódł. Na koniec usuń linię `HEALTH_CURL_OPTS=-k` z `/etc/rideidle/deploy.env`.

SSH nie jest utwardzany (hasła nadal mogą działać), żeby skrypt nie mógł Cię zablokować. Gdy klucz działa dla `deploy`, wyłącz logowanie hasłem sam (`PasswordAuthentication no` w `/etc/ssh/sshd_config.d/`).

### Serwer, który już jest w użyciu

Domyślnie `provision.sh` jest napisany dla serwera, na którym nie ma nic innego, i ustawia go **dla wszystkiego, co na nim działa**: zajmuje porty 80 i 443, usuwa domyślną stronę nginx, włącza zaporę `ufw` z blokadą ruchu przychodzącego, ustawia PHP 8.4 jako domyślne PHP w systemie, przepisuje ustawienia Redisa i restartuje go, instaluje Node 24 w systemie, nadpisuje `/etc/apt/apt.conf.d/20auto-upgrades` i włącza `fail2ban`. Dlatego **zatrzymuje się, zanim cokolwiek zmieni**, gdy widzi, że serwer jest w użyciu (coś innego niż nginx na 80 lub 443, Apache, inna strona w nginx, kontenery Docker, inna aplikacja w `/var/www`), i wypisuje, co znalazł.

**Tryb `SHARED_SERVER=1`** jest dla serwera, który obsługuje już inne strony przez **nginx** (tak jest na VPS z kilkoma projektami). Dodaje grę jako jeszcze jedną stronę i nic z tej listy nie rusza:

| Zostaje jak było | Jak to robi |
|---|---|
| zapora `ufw`, `fail2ban`, automatyczne aktualizacje | nic nie instaluje ani nie zmienia; tylko ostrzega, jeśli `ufw` jest włączony i nie widzi reguł dla 80 i 443 |
| domyślne PHP (`php`) | PHP 8.4 instaluje obok, a po instalacji przywraca poprzednie PHP jako domyślne; gra wywołuje `/usr/bin/php8.4` po nazwie (kolejka, cron, FPM, Composer) |
| Node systemu | Node 24 pobiera z nodejs.org (suma kontrolna) do `/opt/rideidle/node`, a `deploy.sh` używa go tylko do budowania gry |
| Redis (ustawienia, dane, restart) | używa go bez zmian, w bazie numer 5 (`artisan cache:clear` opróżnia całą bazę, więc inna numeracja chroni dane innych stron). Gdy Redis wymaga hasła, gra trzyma liczniki w bazie danych |
| nginx | dodaje jeden plik strony i definicje o własnych nazwach; przed pierwszą zmianą robi kopię `/etc/nginx` w `/var/backups`, a po każdej zmianie sprawdza `nginx -t` i **jeśli nginx jej nie przyjmie, usuwa własne pliki**, żeby reload innych stron nie został z błędną konfiguracją |
| domyślna strona nginx i cudze strony | nie usuwa; zatrzymuje się, jeśli inna strona już odpowiada na tę domenę |
| usługi innych stron (nginx, bazy, Redis, supervisor) | instalacje `apt` nie restartują ich: `needrestart` jest wstrzymany (`NEEDRESTART_SUSPEND`). Ubuntu zwykle restartuje po instalacji wszystko, co używa zaktualizowanej biblioteki, a na serwerze współdzielonym byłyby to cudze usługi |
| logi innych stron | użytkownik `deploy` dostaje tylko grupę `www-data` (bez `adm` i `systemd-journal`), więc logi czytasz przez `sudo` |

Zostaje to, co wspólne dla całego serwera i czego tryb nie zmienia:

- **Wszystkie strony działają jako `www-data`** (tak jak na serwerze, na którym uruchomiono tryb). Proces PHP innej strony może więc czytać pliki gry, w tym `.env` z hasłem bazy, kluczem aplikacji i hasłem SMTP (grupa `www-data`, prawa 640), a gra może czytać cudze. Pełne oddzielenie wymaga osobnego użytkownika i osobnej puli PHP-FPM dla gry; nie jest tu zrobione.
- PostgreSQL i Redis są wspólne: gra ma własną rolę i bazę (`rideidle`), ale jedna awaria dotyka wszystkich. PHP 8.4 pochodzi z PPA ondrej, a jego instalacja może zaktualizować wspólny pakiet `php-common`.
- Wydanie (Composer, `npm ci`, budowanie) obciąża procesor i pamięć serwera; nie rób go w godzinach największego ruchu innych stron.

Przed pierwszym uruchomieniem sprawdź, czy dotychczasowe strony nie używają bazy `rideidle` ani roli `rideidle` w PostgreSQL (skrypt jej nie nadpisze, ale wykorzysta istniejącą).

## Krok 3. Uzupełnij `.env`

```bash
ssh deploy@raceidle.cerasusdigital.pl
nano /var/www/rideidle/shared/.env
```

Do uzupełnienia, jeśli nie podałeś tego w kroku 2:

- `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD`: bez nich linki w mailach nie wychodzą.
- `LEGAL_OPERATOR_NAME`, `LEGAL_OPERATOR_ADDRESS`, `LEGAL_CONTACT_EMAIL`: bez nich strony prawne pokazują brakujące dane na czerwono.
- `GOOGLE_*`, `FACEBOOK_*`, `APPLE_*`: dostawca jest włączony tylko wtedy, gdy ma klucze (patrz „Dostawcy logowania”).
- `LEGAL_MIN_AGE=16` jest wartością roboczą do decyzji prawnika (`docs/prawo.md`).

Zmiana w `.env` działa od następnego wydania. Żeby zadziałała od razu (konfiguracja jest zapisana w pamięci podręcznej Laravela):

```bash
cd /var/www/rideidle/current/api
sudo -u www-data php8.4 artisan config:cache
sudo systemctl reload php8.4-fpm
sudo systemctl restart rideidle-queue
```

## Krok 4. Pierwsze wydanie

Z katalogu repozytorium na Twoim komputerze:

```bash
deploy/upload.sh release deploy@raceidle.cerasusdigital.pl
```

Bez klucza SSH na komputerze (droga B z kroku 2) wydanie zrobisz na samym serwerze, jako użytkownik `deploy`; serwer pobierze kod z GitHuba (musi być wypchnięty):

```bash
sudo -iu deploy /var/www/rideidle/bin/deploy.sh --repo https://github.com/KenjiWriter/RideIdle.git --ref main
```

Wysyłany przez `upload.sh` jest **zatwierdzony** stan (`HEAD`), nie to, co leży w katalogu roboczym; skrypt ostrzega, jeśli masz niezatwierdzone zmiany. Inny commit lub tag: `deploy/upload.sh release deploy@... v1.0`. Pierwsze wydanie trwa kilka minut (Composer, `npm ci`, budowanie). Kroki: źródła → `composer install --no-dev` → `npm ci` i `npm run build` → `.env` i `storage` z `shared/` → `content:validate` → migracje → `content:check --install` (treść, z której czyta gra, jest cała i pasuje do tego kodu; przy `CONTENT_SOURCE=database` pierwsze wdrożenie wkłada do bazy paczkę wydania, a każde następne tylko sprawdza paczkę, która jest na żywo, i niczego nie nadpisuje) → pamięci podręczne Laravela → przełączenie linku `current` → przeładowanie PHP-FPM i restart kolejki → **sprawdzenie** (`/up`, `/api/v1/meta/locales` i strona główna z serwera na samego siebie). Jeśli sprawdzenie nie przejdzie, poprzednie wydanie wraca, a skrypt kończy się błędem; nieudane wydanie zostaje w `releases/` do obejrzenia.

**Słownik gry i pliki tekstów webu.** Panel (strona „Słownik”) i walidator paczek czytają domyślne teksty aplikacji z `web/src/i18n/locales/*.json` obok `api/` (`WEB_LOCALES_PATH`, gdy leżą gdzie indziej). W wydaniu z `deploy.sh` ten katalog jest, bo skrypt po budowie usuwa tylko `web/node_modules`; gdy ktoś wdroży samo `api/`, strona słownika ostrzeże, że nie widzi tekstów, a klucze nie będą sprawdzane. **Obrazki aut i części (L2c)** leżą w bazie (tabela `content_images`, migracja `deploy.sh` ją zakłada), więc nie ma nic do ustawienia w nginx ani `storage:link`, a kopia zapasowa bazy (`rideidle-backup`) obejmuje je razem z resztą; obrazek wgrywa się w panelu (limit pliku to 1 MB, nginx panelu ma `client_max_body_size 2m`) i podaje go publiczny `GET /api/v1/media/{name}`. **Wiele gier (L4a).** Migracja zakłada pierwszą grę (`raceidle`) na domenie z `APP_URL`, więc po wdrożeniu nic się nie zmienia, a wdrożenie (`deploy.sh`, `content:check --install`) dalej dotyczy tej gry. **Druga gra** to wiersz na stronie „Gry” w panelu (slug, nazwa, domena, status), jej paczkę wprowadza się poleceniem `php artisan content:import --game=SLUG --activate` albo plikiem paczki ze strony „Paczki” po wyborze gry w górnym pasku, a polecenia treści, kont i metryk mają `--game=SLUG` (bez niego gra pierwsza). **W produkcji host, którego żadna gra nie ma, dostaje 404**, więc domena nowej gry musi być wpisana na stronie „Gry”, zanim zacznie wskazywać na serwer. Blok nginx, certyfikat i sprawdzenie DNS dla nowej domeny robi `game-site.sh` (sekcja „Nowa gra na własnej domenie” niżej); gracze nowej gry dostają aplikację tej samej wersji.

**PWA.** Szablon nginx (`nginx/rideidle.conf.template`) ma dwie nowe lokalizacje: `/sw.js` z `Cache-Control: no-cache` oraz `/icons/` na tydzień. **Manifest nie jest plikiem** (od L4b wydaje go API pod `/api/v1/meta/manifest`, osobno dla każdej gry według hosta, więc nginx nie ma dla niego lokalizacji; `/api/` idzie do PHP jak reszta). Lokalizacje dochodzą na serwer przy następnym `provision.sh` (można go uruchomić ponownie, nie rusza `.env` ani bazy); **bez tego PWA też działa**, bo przeglądarka sprawdza `sw.js` poza cache HTTP. Po zmianie sprawdź: `curl -sI https://DOMENA/sw.js | grep -i cache-control` ma dać `no-cache`. Worker wymaga HTTPS (jest) i nic nie robi na hoście panelu. Szablon nie był uruchomiony w nginx (brak go na tej maszynie): sprawdź `sudo nginx -t` przed przeładowaniem, jeśli kopiujesz go ręcznie. Dokument `rules/vocabulary` trafia do bazy z następną paczką; baza zrobiona wcześniej dostaje go przy pierwszym zapisie ze strony („Słownik”), więc `content:import` nie jest do tego potrzebny.

Potem sprawdź w przeglądarce `https://raceidle.cerasusdigital.pl`: strona startowa, utworzenie kierowcy, `/terms` i `/privacy` oraz `https://raceidle.cerasusdigital.pl/up`.

## Poczta

Linki do logowania i zabezpieczenia konta idą mailem, więc dostarczalność jest częścią gry.

1. Wybierz dostawcę SMTP (własny serwer pocztowy, Mailgun, Postmark, Brevo, Amazon SES i podobne). Wiele hostingów **blokuje port 25**; używaj 587 (`MAIL_SCHEME=smtp`) lub 465 (`smtps`).
2. Adres w `MAIL_FROM_ADDRESS` musi należeć do domeny, którą dostawca zweryfikował: w DNS domeny nadawcy (domyślnie `cerasusdigital.pl`) dodaj **SPF** i **DKIM** według instrukcji dostawcy oraz **DMARC**, np. `_dmarc.cerasusdigital.pl TXT "v=DMARC1; p=none; rua=mailto:twoj@adres.pl"` (zacznij od `p=none`, zaostrz po kilku tygodniach bez problemów). Bez tego Gmail i inni wrzucą linki do spamu albo je odrzucą.
3. Sprawdź wysyłkę (idzie od razu, bez kolejki, więc błąd widać na ekranie):

```bash
cd /var/www/rideidle/current/api
sudo -u www-data php8.4 artisan tinker --execute='Mail::raw("test", fn ($m) => $m->to("twoj@adres.pl")->subject("RideIdle: test poczty"));'
```

W Gmailu „Pokaż oryginał” powinien pokazać SPF, DKIM i DMARC jako PASS. Potem przejdź całą ścieżkę w grze: gość → „Zapisz swój garaż” → mail.

Maile, które się nie wysłały, trafiają do `failed_jobs` (`sudo -u www-data php8.4 artisan queue:failed`) i harmonogram usuwa je po tygodniu.

## Dostawcy logowania (Google, Facebook, Apple)

Klucze zakładasz u dostawców w swoich kontach i wpisujesz do `.env` (opis zmiennych: `docs/konto.md`). Adresy do wpisania w ich konsolach:

| Co | Adres |
|---|---|
| Adres powrotu Google | `https://raceidle.cerasusdigital.pl/api/v1/auth/google/callback` |
| Adres powrotu Facebook | `https://raceidle.cerasusdigital.pl/api/v1/auth/facebook/callback` |
| Adres powrotu Apple (Services ID; domena: `raceidle.cerasusdigital.pl`) | `https://raceidle.cerasusdigital.pl/api/v1/auth/apple/callback` |
| Polityka prywatności | `https://raceidle.cerasusdigital.pl/privacy` |
| Regulamin | `https://raceidle.cerasusdigital.pl/terms` |

Po zmianie `.env` zastosuj ją poleceniem z kroku 3. **Przepływ z prawdziwymi dostawcami nie był jeszcze sprawdzony** (`docs/konto.md`); pierwsze sprawdzenie to ten moment.

## Panel administracyjny (osobny host, opcjonalnie)

Panel (`/admin`, Filament) służy do patrzenia na paczki treści i ich uruchamiania; z czasem będzie miejscem edycji treści (`docs/api-runs.md`, „Panel administracyjny”). **Ma host osobny od gry** (`admin.raceidle.cerasusdigital.pl`), żeby jego ciasteczka nie mieszały się z grą i żeby dało się go wyłączyć po nazwie. Produkcyjny nginx przepuszcza do PHP tylko `/api/*` i `/up`, więc dopóki nie zrobisz poniższego, panelu nie da się osiągnąć.

1. **DNS:** rekord A `admin.raceidle.cerasusdigital.pl` na adres serwera (jak w kroku 1).
2. **Na serwerze**, po pierwszym wydaniu (skrypt jest w każdym wydaniu):

   ```bash
   sudo env ADMIN_DOMAIN=admin.raceidle.cerasusdigital.pl ADMIN_EMAIL=ty@example.com bash /var/www/rideidle/current/deploy/admin-site.sh
   ```

   Sprawdza DNS, bierze osobny certyfikat dla hosta panelu, kładzie stronę z `nginx/rideidle-admin.conf.template` (jeśli nginx jej nie przyjmie, wycofuje ją i nic innego się nie zmienia), wpisuje `ADMIN_DOMAIN` i `SESSION_SECURE_COOKIE=true` do `.env`, robi `config:cache` oraz `route:cache` i **przeładowuje PHP-FPM** (opcache nie sprawdza zmian plików, więc bez tego pracujące procesy trzymają starą konfigurację bez hosta panelu, a pod adresem panelu widać stronę powitalną Laravela zamiast logowania; gdyby to się zdarzyło, wystarczy `sudo systemctl reload php8.4-fpm`). Strony gry nie rusza. Można go uruchomić ponownie.
3. **Pierwszy administrator** (hasło jest pokazane raz i nigdzie niezapisane; przy pierwszym logowaniu panel wymusi ustawienie aplikacji uwierzytelniającej, a kody odzyskiwania zapisz w bezpiecznym miejscu):

   ```bash
   cd /var/www/rideidle/current/api && sudo -u www-data php8.4 artisan admin:create ty@example.com --role=publisher
   ```

4. **Role:** `editor` patrzy na paczki i robi szkice, `publisher` także zamawia paczkę z szkiców (strona Publikacje), uruchamia paczkę (też cofa do wcześniejszej), importuje paczkę z pliku i czyta dziennik. **Publikacja idzie przez kolejkę**: bez działającego `rideidle-queue` zamówiona paczka zostaje w stanie „Czeka”; zadanie trwa kilka sekund do kilku minut (tabele pochodne i bramki symulatorów), ma własny limit 900 s, a `retry_after` kolejki bazy to 90 s (przy jednym workerze, jak we wdrożeniu, nie ma to znaczenia). Migracja `create_content_publications_table` jest zwykłą migracją i wchodzi z wydaniem. Zmiana roli, wyłączenie, nowe hasło i zdjęcie aplikacji uwierzytelniającej: `artisan admin:update ADRES --role=… | --deactivate | --activate | --reset-password | --reset-mfa`. **Zgubiony telefon:** `admin:update ADRES --reset-mfa` (i `--reset-password`, jeśli ktoś mógł przejąć konto); aplikację ustawia się od nowa przy następnym logowaniu.
5. **Tylko z wybranych adresów:** odkomentuj w szablonie `allow …; deny all;` i uruchom skrypt ponownie.
6. **Lokalnie:** `php artisan serve` i `http://localhost:8000/admin` (bez `ADMIN_DOMAIN`). Administratora robi to samo `admin:create`.

Zasoby panelu (style i skrypty) publikuje `composer install` (polecenie `filament:upgrade` w `composer.json`), więc nie ma ich w repozytorium, a wydanie je ma. Każde logowanie, wylogowanie i uruchomienie paczki zostaje w dzienniku (`admin_audit_logs`).

## Nowa gra na własnej domenie (L4b)

Jedna instalacja prowadzi kilka gier (L4a): każda ma własną treść, własnych graczy i własną domenę, a wszystkie domeny są obsługiwane z **tego samego wydania** (aplikacja z `web/dist`, API z `api/public`). Żądanie trafia do gry, która ma jego host wpisany jako domenę (strona „Gry” w panelu), a **w produkcji host, którego żadna gra nie ma, dostaje 404** (`unknown_game`). Pierwsza gra odpowiada na domenę z `APP_URL`. Dla każdej następnej:

1. **DNS:** rekord A nowej domeny (np. `statki.example.com`) na adres serwera, jak w kroku 1.
2. **Wiersz gry w panelu** (strona „Gry”, tylko dla administratora wszystkich gier): slug, nazwa, **ta sama domena co w kroku 3** (małymi literami), język, kolor i status. Po domenie żądanie znajduje grę, a gra w stanie `draft` nie odpowiada graczom, więc można ją przygotować przed otwarciem.
3. **Na serwerze**, po pierwszym wydaniu (skrypt jest w każdym wydaniu):

   ```bash
   sudo env GAME_DOMAIN=statki.example.com GAME_EMAIL=ty@example.com bash /var/www/rideidle/current/deploy/game-site.sh
   ```

   (`SKIP_DNS_CHECK=1` pomija sprawdzenie DNS, `CERTBOT_STAGING=1` bierze certyfikat próbny, jak w `admin-site.sh`.)
4. **Paczka gry:** `cd /var/www/rideidle/current/api && sudo -u www-data php8.4 artisan content:import --game=SLUG --activate` (treść z repozytorium, czyli gra z tymi samymi autami na start; `SLUG` to slug z kroku 2) albo plik paczki z innego środowiska lub z innej gry: strona „Paczki”, **z wybraną grą w górnym pasku**, akcja „Zaimportuj paczkę”. Bez żywej paczki gra nie ma czego czytać.
5. **Sprawdzenie:** `curl -s https://statki.example.com/api/v1/meta/game` (nazwa i kolor gry; 404 `unknown_game`, dopóki gry nie ma w panelu pod tą domeną) i `curl -s https://statki.example.com/up`.

**Co robi skrypt.** Sprawdza, że domena wskazuje na ten serwer, bierze **osobny certyfikat** (nazwa certyfikatu to domena, więc odnawia się i wymienia osobno od pierwszej gry i panelu, z przeładowaniem nginx) i kładzie obok strony pierwszej gry **tę samą stronę z tego samego szablonu** (`nginx/rideidle.conf.template` z domeną w miejscu `__DOMAIN__`) jako `/etc/nginx/sites-available/rideidle-game-<nazwa>.conf` (nazwa to domena z myślnikami zamiast kropek) i link do niej. Strona pierwszej gry, strona panelu, `.env`, PHP i baza zostają, jak były. Odmawia domeny pierwszej gry, hosta panelu (z `ADMIN_DOMAIN` w `.env`) i domeny, na którą odpowiada już inna strona nginx; domenę zapisuje małymi literami, a plik innej domeny o tej samej nazwie (`a-b.example.com` i `a.b.example.com`) nie jest przejmowany. Jeśli nginx nie przyjmie strony, skrypt ją **wycofuje** (a przy ponownym uruchomieniu przywraca poprzednią wersję) i nic innego się nie zmienia; wydany już certyfikat zostaje i następny przebieg go użyje. Można go uruchomić ponownie: co jest, zostaje, a co zmieniło się w szablonie, trafia na miejsce. Przy tej okazji z szablonu zniknęła lokalizacja `/manifest.webmanifest`: manifest aplikacji robi API dla gry hosta (`/api/v1/meta/manifest`), a lokalizacja `/api/` już idzie do PHP.

**Czego skrypt nie robi.**

- **Nie wpisuje gry do bazy:** nazwa, domena, kolor i status to wiersz na stronie „Gry”, a paczka to krok 4. Domena w panelu i w skrypcie ma być ta sama, bo inaczej API odpowie 404.
- **Adresy powrotu logowania** (Google, Facebook, Apple): dla nowej domeny trzeba je zarejestrować u dostawcy (`https://DOMENA/api/v1/auth/<dostawca>/callback`); robi to właściciel konta u dostawcy, skrypt tego nie zrobi.
- **Poczta:** nadawca i szablony listów są wspólne i mówią jak pierwsza gra, do czasu L4c (osobne teksty prawne, poczta i płatności per gra).
- **Jeden serwer nginx i jedna aplikacja:** limit żądań po adresie (`rideidle_api`) i dzienniki (`rideidle.access.log`, `rideidle.error.log`) są wspólne dla gier.

**Co sprawdzono:** skrypt przechodzi `bash -n` i został uruchomiony od początku do końca na atrapach `nginx`, `certbot`, `systemctl`, `curl` i `getent` w folderze tymczasowym (zmienne `NGINX_DIR`, `LETSENCRYPT_DIR` i `SKIP_ROOT_CHECK=1` są dla takich prób), w 74 sprawdzeniach (53 z L4b i 21 z L4d, o robotach i starych ustawieniach nginx): pierwsze uruchomienie (strona z domeną i folderami instalacji, certbot z osobną nazwą certyfikatu i hookiem, strony pierwszej gry i panelu bez zmian), ponowne (nic nie zmienione, certbot nie pytany drugi raz), małe litery, odmowy (domena pierwszej gry, panelu, cudza, zła nazwa, brak adresu e-mail), nginx odrzucający stronę (wycofanie, przywrócenie poprzedniej wersji) i sprawdzenie DNS (inny adres, brak rekordu, `SKIP_DNS_CHECK`). Skrypt przechodzi `shellcheck` bez uwag. **Nie sprawdzono** go na prawdziwym nginx, certbocie i DNS.

## Podglądy linków: roboty dostają stronę z tagami gry (L4d)

Czat (Messenger, WhatsApp, Telegram, Slack, Discord), sieć społecznościowa albo wyszukiwarka robi podgląd linku z tagów w nagłówku strony (`og:title`, `og:description`, `og:image`) i **nie uruchamia aplikacji**. Aplikacja ustawia tytuł i kolor dopiero po starcie, więc robot widziałby w każdej grze to, co jest w `index.html` z budowy: „RideIdle” bez opisu i obrazka. Dlatego nginx odróżnia roboty od ludzi i robotom oddaje tę samą stronę aplikacji **z tagami gry hosta**, zrobioną przez API (`GET /api/v1/meta/share`; tytuł, opis w języku gry, obrazek podglądu, kolor; co gra o sobie mówi, ustawia się w panelu).

**Jak to działa.**

- `nginx/rideidle-global.conf` (ustawienia całego nginx, `/etc/nginx/conf.d/rideidle-global.conf`) ma mapę `$rideidle_app_page`: dla żądania **GET albo HEAD** z `User-Agent` jednego z robotów podglądów (facebookexternalhit, Facebot, Twitterbot, Slackbot, Slack-ImgProxy, LinkedInBot, WhatsApp, TelegramBot, Discordbot, Pinterest, SkypeUriPreview, vkShare, redditbot, Embedly, Iframely, Mastodon, Viber, Applebot, Googlebot, bingbot, DuckDuckBot; wielkość liter nie ma znaczenia) daje `/__rideidle_preview`, dla każdego innego `/index.html`. Listę robotów dopisuje się w jednym miejscu, w tym pliku.
- W szablonie strony (`nginx/rideidle.conf.template`) adres, który nie jest plikiem, idzie do lokalizacji `@rideidle_app`, a ta przekierowuje wewnętrznie na stronę z mapy. **Człowiek dostaje `index.html` dokładnie tak jak przed zmianą** (te same nagłówki i bajty, sprawdzone na uruchomionym nginx). Pliki, które istnieją (`/robots.txt`, `/assets/…`, `/icons/…`, `/sw.js`), robot dostaje jak każdy: podmieniana jest tylko strona aplikacji.
- Lokalizacja `= /__rideidle_preview` jest `internal;` (z zewnątrz to 404) i idzie do PHP z `REQUEST_URI` ustawionym na `/api/v1/meta/share`; **nagłówek `Host` dochodzi do PHP bez zmian, więc API znajduje grę po domenie** (każda gra ma swoją stronę nginx z tego samego szablonu, jak w sekcji wyżej). API czyta `web/dist/index.html`, wstawia tagi gry (tytuł, opis, obrazek, `twitter:card`, `theme-color`, `lang`) i oddaje stronę z `Cache-Control: public, max-age=300` i ETagiem; nginx niczego nie buforuje. Odpowiedź ma `Vary: User-Agent`.
- Ta ścieżka idzie do PHP, więc ma **ten sam limit co API** (`rideidle_api`, 20 zapytań na sekundę z adresu, 429 powyżej). Ludzie i pliki statyczne limitu nie mają.
- Gdy PHP nie odpowiada (502, 503, 504) albo API kończy się błędem 500, robot dostaje zwykłe `index.html`: podgląd bez tagów gry jest lepszy niż brak strony. Dotyczy to tylko tej lokalizacji; adresy `/api/` pokazują błąd jak zawsze.
- Człowiek, którego przeglądarka zostałaby wzięta za robota (np. przeglądarka wbudowana w aplikację społecznościową), dostaje stronę z API, która **jest** stroną aplikacji (`index.html` z tagami), więc aplikacja działa i tak.

**Na serwer trafia to razem z `provision.sh`** (mapa w `conf.d`, strona pierwszej gry z szablonu); `provision.sh` można uruchomić ponownie, nie rusza `.env` ani bazy. Dla gier na własnych domenach trzeba potem uruchomić `game-site.sh` jeszcze raz dla każdej domeny (przepisuje stronę z aktualnego szablonu). **Kolejność ma znaczenie:** strona z nowego szablonu używa zmiennej z mapy, więc nginx jej nie przyjmie na serwerze ze starym `rideidle-global.conf`. `game-site.sh` sprawdza to na początku i odmawia z komunikatem „Run provision.sh of this release again”, niczego nie zmieniając; `provision.sh` sam wgrywa najpierw plik globalny, a potem stronę.

**Obrazek podglądu** ustawia się w panelu na stronie „Marka i strona” (podgląd linku: tytuł, opis w językach gry i obraz). Dobry rozmiar to ok. **1200×630 px** (PNG, WebP albo JPEG, do 1 MB, jak każdy obraz gry). Bez własnego obrazu podgląd bierze ikonę gry (pierwsza gra: `/icons/icon-512.png`); gra bez ikony ma podgląd bez obrazka. Bez opisu: nazwa gry (pierwsza gra ma swoje zdanie o grze).

**Jak sprawdzić, że działa** (na serwerze albo z komputera):

```bash
curl -s -A "facebookexternalhit/1.1" https://DOMENA/ | head -30
```

Powinny być `<title>` i `og:title` tej gry, `og:image` na jej domenie i `lang` języka gry. To samo bez `-A` (albo z przeglądarkowym `User-Agent`) ma dać zwykłe `index.html`. `curl -sI -A "Twitterbot/1.0" https://DOMENA/garage | grep -i vary` pokazuje `Vary: User-Agent` tylko dla robota. Podgląd na prawdziwych serwisach: Sharing Debugger Facebooka, Post Inspector LinkedIna albo wklejenie linku na prywatny kanał Slacka lub Discorda. **Serwisy trzymają podgląd w pamięci** (Facebook potrafi tygodniami): po zmianie obrazka każ im pobrać stronę jeszcze raz (w debuggerze Facebooka przycisk „Scrape again”).

**`WEB_DIST_PATH`.** API szuka strony aplikacji w `ride.web_dist_path`, domyślnie `../web/dist` względem folderu API, czyli przy układzie wydań `current/api` + `current/web/dist` jest to folder aplikacji tego samego wydania (PHP rozwiązuje link `current`, więc po przełączeniu wydania czyta nowe). **`env.production.example`, `provision.sh` i `deploy.sh` nie ustawiają tej zmiennej i nie muszą**: `deploy.sh` sprawdza tylko, że budowa zrobiła `web/dist/index.html`. Ustaw `WEB_DIST_PATH` w `.env` tylko przy innym układzie folderów. Gdy folderu nie ma (samo API bez budowy aplikacji), API oddaje robotowi **same tagi** w pustej stronie.

**Co się zmieniło dla innych adresów.** `/__rideidle_preview` z zewnątrz daje 404 (wcześniej każdy adres, którego nie było, dostawał `index.html`); to jedyna różnica dla ludzi.

**Co sprawdzono.** Szablon i ustawienia globalne przeszły `nginx -t` na nginx 1.27.3 (ten z Laragona, na Windowsie, po wyrenderowaniu ścieżek, portów i gniazda PHP na adres TCP, bo Windows nie ma gniazd unix). Ten nginx stał przed prawdziwym API (php-cgi, tymczasowa baza SQLite, druga gra na hoście `ships.localhost`) i: 34 sprawdzenia `User-Agent` dały to, co trzeba (23 roboty z listy, 2 zapisy innymi literami, 3 żądania o inny adres albo HEAD: `/`, strona z sekretem w adresie, `/garage`; po stronie ludzi: dwie przeglądarki, `curl`, inny robot, brak nagłówka, a robot z POST dostaje to co każdy POST); druga gra dostała swój tytuł, `lang` i `og:url` na swojej domenie, z poprawnie zakodowanymi `<`, `&`, `"` i `$`; z 17 zapytań (strony, `/index.html`, pliki, 404 plików, `/.env`, POST, HEAD, robot proszący o plik, `/__rideidle_preview`) 16 odpowiedziało **bajt w bajt** tak jak nginx ze starym szablonem (poza `Date`), a jedyna różnica to `/__rideidle_preview` (404); 150 jednoczesnych zapytań robota: 56 odpowiedzi 429, takich samych zapytań człowieka: wszystkie 200; przy wyłączonym PHP (502) i przy API bez bazy (500) robot dostał `index.html` z kodem 200, a `/api/…` zwróciło 500. Skrypt `game-site.sh` przechodzi 74 sprawdzenia i `shellcheck`. **Nie sprawdzono:** nginx na Ubuntu z PHP-FPM i gniazdem unix, `http2` w `listen`, TLS z prawdziwym certyfikatem, prawdziwych debuggerów serwisów społecznościowych ani tego, jak naprawdę podpisują się ich roboty (lista to nazwy z ich dokumentacji, z pamięci).

## Na co dzień

**Nowe wydanie:** `deploy/upload.sh release deploy@raceidle.cerasusdigital.pl`.

**Wycofanie** do najnowszego wydania starszego od bieżącego (kolejne wywołanie cofa dalej): `deploy/upload.sh rollback deploy@raceidle.cerasusdigital.pl`.

> **Migracje przed przełączeniem.** Migracje biegną, gdy stare wydanie jeszcze obsługuje ruch, więc muszą działać także ze starym kodem: dodawaj kolumny, nie zmieniaj nazw i nie usuwaj ich w tym samym wydaniu. Wycofanie przywraca stary kod, ale **nie cofa migracji**.

**Logi:**

```bash
journalctl -u rideidle-queue -n 100               # kolejka
tail -n 100 /var/www/rideidle/shared/storage/logs/laravel-$(date +%F).log
tail -n 100 /var/log/nginx/rideidle.error.log
```

(Na serwerze współdzielonym użytkownik `deploy` nie czyta dziennika systemu ani logów nginx: `journalctl` i `tail` na logu nginx uruchom z `sudo` jako użytkownik z pełnym `sudo`, np. `ubuntu`. Log Laravela `deploy` czyta sam.)

Logi Laravela są codzienne i kasowane po 14 dniach (`LOG_DAILY_DAYS`), poziom `warning`.

**Polecenia operatora** (prośby o kopię i usunięcie danych, `docs/prawo.md`) uruchamiasz jako `www-data`:

```bash
cd /var/www/rideidle/current/api
(umask 077; sudo -u www-data php8.4 artisan accounts:export --email=adres@example.com > ~/konto.json)
sudo -u www-data php8.4 artisan accounts:delete --email=adres@example.com --dry-run
```

**Monitoring:** adres `https://raceidle.cerasusdigital.pl/up` wystarczy dowolnemu monitorowi (UptimeRobot i podobne).

**Aktualizacje systemu:** poprawki bezpieczeństwa instalują się same. Serwer **nie restartuje się sam**; od czasu do czasu sprawdź `ls /var/run/reboot-required` i zrestartuj w spokojnej chwili (kolejka i nginx wstają same).

## Kopie zapasowe

- Co noc o 3:15 `pg_dump` zapisuje bazę do `/var/backups/rideidle/rideidle-RRRR-MM-DD-GGMM.dump` (prawa tylko dla roota). Kopie starsze niż 14 dni są kasowane; wynik jest w `/var/log/rideidle-backup.log`.
- **Sprawdzanie kopii** (`rideidle-backup-verify`; krok F5, 2026-10-28). **Kopia, której nikt nie odtworzył, to nadzieja, nie kopia.** Skrypt robi dwie rzeczy. **Co noc o 3:30 (`--quick`):** najnowsza kopia jest, nie jest starsza niż 26 godzin, nie jest maleńka (co najmniej 2 KB) i da się przeczytać jej spis treści. **Co niedzielę o 4:00 (pełne):** to samo, a potem kopia jest **odtwarzana do osobnej bazy `rideidle_verify`** (usuwanej na końcu; bazy gry nie rusza), sprawdza się, że są tabele, które mają znaczenie, i **że saldo każdego portfela jest sumą jego księgi** (gotówka i monety premium): zasada gry, którą kopia ma zachować. Wynik jest w `/var/backups/rideidle/last-verify.txt` (`ok` albo `FAILED: powód`, plik, rozmiar, wiek i liczby wierszy) i w `/var/log/rideidle-backup.log`, a kod wyjścia 1 mówi, że coś jest nie tak. **Uruchom go raz ręcznie, zanim będzie potrzebny:** `sudo rideidle-backup && sudo rideidle-backup-verify`. Sprawdzone tylko na atrapach narzędzi PostgreSQL (37 sprawdzeń logiki: brak kopii, stara, maleńka, uszkodzona, nieodtwarzalna, saldo różne od księgi, kopia najnowsza spośród kilku); na prawdziwym PostgreSQL jeszcze nie uruchomione.
- **Kopia poza serwerem** (`rideidle-backup-offsite`, codziennie o 3:40). **Kopie leżą na tym samym serwerze**, więc nie ratują przed jego utratą. Skrypt nic nie robi, dopóki nie ustawisz, gdzie wysyłać, w `/etc/rideidle/backup.env` (plik tylko dla roota, linie `KLUCZ=wartość`):

```bash
BACKUP_REMOTE=uzytkownik@serwer-kopii:/sciezka/rideidle       # dokąd (rsync przez ssh)
BACKUP_SSH_KEY=/root/.ssh/rideidle-backup                     # klucz bez hasła, który może tam pisać
BACKUP_PASSPHRASE_FILE=/etc/rideidle/backup.pass              # szyfrowanie AES-256 (openssl) przed wysłaniem
```

  **Szyfruj** (kopia ma adresy e-mail i całą grę) i **zachowaj hasło także gdzie indziej**: bez niego kopii nie otworzysz. Otwarcie: `openssl enc -d -aes-256-cbc -pbkdf2 -in PLIK.dump.enc -out PLIK.dump -pass file:/etc/rideidle/backup.pass`. Stare kopie po drugiej stronie kasuje ten, kto ją prowadzi (kopia na noc to kilka megabajtów). Można też kopiować ręcznie, np. `scp -r root@raceidle.cerasusdigital.pl:/var/backups/rideidle ./kopie-rideidle`.
- **Kopie a usuwanie kont:** konto usunięte przez kierowcę albo `accounts:delete` pozostaje w kopiach do 14 dni, a w kopiach przechowywanych gdzie indziej tak długo, jak je trzymasz. Polityka prywatności jeszcze o kopiach nie mówi (`docs/prawo.md`).
- **Odtwarzanie** (jako `root`, bo `deploy` ma `sudo` tylko do przeładowania usług). Właściciele tabel są w kopii, więc tabele zostają własnością użytkownika bazy `rideidle`. Kopię szyfrowaną najpierw otwórz (wyżej). **Zrób to na próbę w osobnej bazie, zanim będzie potrzebne** (to robi niedzielne sprawdzanie), a przy prawdziwej awarii:

```bash
systemctl stop rideidle-queue
runuser -u postgres -- pg_restore --clean --if-exists -d rideidle /var/backups/rideidle/PLIK.dump
systemctl start rideidle-queue
```

## Test obciążeniowy

`loadtest/loadtest.mjs` (Node 18 lub nowszy, bez pakietów; krok F5, 2026-10-28) udaje wielu graczy naraz: każdy robi to, co gra robi sama, gdy jest otwarta (pyta o bieg co 15 s, od czasu do czasu o garaż, mapę, flotę i sklep). Niczego nie kupuje ani nie sprzedaje, tylko czyta, a serwer rozlicza biegi tak jak dla gracza.

1. **Konta.** API pozwala jednemu adresowi założyć 10 gości na godzinę, więc nie zakłada się ich przez nie. Na serwerze: `cd /var/www/rideidle/current/api && sudo -u www-data php8.4 artisan loadtest:accounts 200 --output=/tmp/tokens.json --force` (`--force` jest potrzebne, bo `APP_ENV` to production: to prawdziwe wiersze w prawdziwej bazie). Tokeny trafiają do pliku (tylko dla właściciela), nie na ekran; nazwy zaczynają się od `LoadTest_`.
2. **Test**, z innego komputera: `node deploy/loadtest/loadtest.mjs --base=https://raceidle.cerasusdigital.pl --tokens=tokens.json --users=200 --duration=300`. Opcje: `--poll=MS` (15000, jak w grze; mniej to ostrzejszy test), `--ramp=S` (10, gracze wchodzą w tym czasie), `--max-p95=MS` (1000) i `--max-error-rate=PROC` (1): przy wolniejszym 95. percentylu albo większej liczbie błędów test **kończy się kodem 1**. Raport: dla każdego rodzaju zapytania liczba, ile odpowiedziało, mediana, 95., 99. percentyl i najdłuższe, ile zatrzymał limit (429) i ile się nie udało (5xx, brak odpowiedzi), oraz ile zapytań na sekundę przyjął serwer.
3. **Sprzątanie:** `sudo -u www-data php8.4 artisan loadtest:cleanup` usuwa konta `LoadTest_*` ze wszystkim, co zrobiły, tak jak usuwa się konto (`--dry-run` mówi tylko, ile by usunęło; ruszają się wyłącznie nazwy zaczynające się od `LoadTest`).

**Limit adresu.** nginx wpuszcza z jednego adresu 20 zapytań na sekundę (`nginx/rideidle-global.conf`, strefa `rideidle_api`), więc test z jednego komputera mierzy głównie ten limit (będzie dużo 429), a nie możliwości serwera. Żeby zmierzyć serwer, trzeba puścić test z kilku adresów albo na czas testu podnieść limit. 200 graczy pytających co 15 s to ok. 13 zapytań na sekundę, czyli mieści się w limicie jednego adresu.

**Co zmierzono, a czego nie.** Narzędzie sprawdzono lokalnie na tymczasowym API (serwer wbudowany PHP na Windows, SQLite): działa, liczy i kończy się kodem zgodnym z progami. **Liczb z tego nie wolno brać za wydajność serwera**: ten serwer obsługuje jedno żądanie naraz i każde trwa ok. 330 ms (start frameworku bez opcache), a 20 graczy naraz wydłużało to do kilku sekund przez kolejkę. Prawdziwy pomiar wymaga serwera produkcyjnego (PHP-FPM z opcache, PostgreSQL). Odpowiedź na pytanie, czy gra wytrzyma 20–50 graczy alfy, dopiero z tego testu na serwerze.

## Decyzje i ograniczenia

- **Jedna domena, jeden serwer.** Aplikacja web i API są pod jednym adresem, więc nie ma CORS ani drugiego certyfikatu. Wyjątek to opcjonalny panel administracyjny: osobny host i osobny certyfikat (`admin-site.sh`). Przy większym ruchu bazę lub kolejkę można przenieść osobno.
- **Kolejka w bazie, nie w Redisie.** Przez to `accounts:delete` usuwa także maile czekające w kolejce i nieudane, a `queue:prune-failed` je sprząta. Redis trzyma tylko liczniki limitów.
- **Brak Content-Security-Policy.** Teksty gry są kompilowane w przeglądarce (vue-i18n), co wymaga `unsafe-eval`; polityka z nim chroni niewiele. Pozostałe nagłówki są: HSTS, `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer` (adres strony może zawierać sekret linku), `Permissions-Policy`. HSTS ma rok i nie ma `includeSubDomains` ani `preload`.
- **Log dostępu nginx bez zapytania** (`rideidle-global.conf`). Link z maila to `/account/confirm?token=SEKRET`, a kod od Google i Facebooka wraca w adresie powrotu, więc zwykły log trzymałby je tak długo, jak log (Ubuntu rotuje logi nginx przez 14 dni). Format `rideidle` zapisuje adres tylko do znaku `?`. Zostaje jedna luka: **log błędów nginx** zapisuje pełny adres przy błędzie (np. 502 na `/api/v1/auth/google/callback?code=…`); sekret jest wtedy jednorazowy i krótko ważny, ale log czytają `root` i grupa `adm`, do której należy `deploy`.
- **Opcache nie sprawdza plików** (`validate_timestamps=0`); dlatego każde wydanie przeładowuje PHP-FPM.
- **Brak Dockera, brak CI/CD.** Wydanie robisz ręcznie z komputera. CI można dodać później, wywołując `upload.sh`.
- **Zapora** przepuszcza tylko SSH, 80 i 443. PostgreSQL i Redis słuchają wyłącznie lokalnie.
- Nie ma stanu współdzielonego między serwerami: dodanie drugiego serwera wymagałoby wspólnej bazy, Redisa i storage.

## Co sprawdzono, a czego nie

Sprawdzone:

- Wszystkie skrypty przechodzą `bash -n` i `shellcheck` bez uwag.
- Konfiguracja nginx przeszła `nginx -t` (nginx 1.27.3) po wyrenderowaniu szablonu; uruchomiony serwer odpowiadał tak, jak trzeba: przekierowanie HTTP → HTTPS, ścieżka sprawdzenia Let's Encrypt, nagłówki na każdej odpowiedzi, `index.html` dla adresów aplikacji, długie cache tylko dla `assets/` (i nie dla błędu 404), brak dostępu do plików z kropką, brak sekretów z adresu w logu dostępu (także przy wejściu przez HTTP). To badanie wykryło i poprawiło dwie wady (404 z `immutable` i sekrety w logu). Nie sprawdzono PHP-FPM ani TLS z prawdziwym certyfikatem.
- Zapis wartości do `.env` (hasła z `$`, `\`, `"`, `&`, `#`, polskie litery) odczytany parserem `.env` Laravela jest identyczny. Test wykrył błąd bash 5.2, który psuł `&` w wartościach; poprawiony w skrypcie.
- Wybór wydania do wycofania (po jednym i dwóch wycofaniach, najstarsze, brak).
- Strona panelu (`rideidle-admin.conf.template`) przeszła `nginx -t` obok strony gry i na uruchomionym nginx odpowiedziała tak, jak trzeba (przekierowanie HTTP, plik panelu, brak uruchamiania innych skryptów PHP, brak plików z kropką, adres panelu do PHP); `admin-site.sh` przechodzi `shellcheck`, a jego zapis do `.env` (zamiana, dopisanie, wartość z `$`, `"` i `\`) sprawdzono osobno. Sam skrypt (certbot, DNS, `sudo` jako `www-data`) wymaga prawdziwego serwera.

**Niesprawdzone, bo wymaga prawdziwego serwera:** całe `provision.sh` i `deploy.sh` na Ubuntu (nazwy pakietów PPA ondrej i NodeSource, certbot, `ufw`, `sudoers`, uprawnienia plików, `systemd`), odtworzenie kopii bazy, wysyłka maili, przepływ z Google/Facebookiem/Apple. Spodziewaj się drobnych poprawek przy pierwszym uruchomieniu: skrypt przerywa się z numerem linii, a ponowne uruchomienie jest bezpieczne.

## Gdy coś nie działa

| Objaw | Co sprawdzić |
|---|---|
| `provision.sh` zatrzymuje się na DNS | rekord A jeszcze się nie rozszedł albo wskazuje inny adres; poczekaj (`nslookup`) |
| certbot odmawia | port 80 zamknięty u dostawcy serwera, błędny rekord AAAA, albo limit Let's Encrypt (użyj `CERTBOT_STAGING=1`) |
| `composer` lub `npm` kończy się „Killed” | brak pamięci; sprawdź `swapon --show`, rozważ większy serwer |
| 502 Bad Gateway | `systemctl status php8.4-fpm`, `/var/log/nginx/rideidle.error.log` |
| wydanie „is not well”, poprzednie wraca | `.../shared/storage/logs/`, błąd nginx, `.env` (np. hasło bazy); nieudane wydanie zostało w `releases/` |
| maile nie wychodzą | `queue:failed`, `journalctl -u rideidle-queue`, test z sekcji „Poczta” |
| po zmianie `.env` nic się nie zmieniło | `config:cache` i restart (krok 3) |
