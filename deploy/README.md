# Wdrożenie RideIdle na serwer Ubuntu

Ten folder stawia całą grę na jednym serwerze Ubuntu (22.04 albo 24.04) pod jedną domeną, `raceidle.cerasusdigital.pl`: aplikacja web pod `/`, API pod `/api`, certyfikat Let's Encrypt, kolejka maili, harmonogram, kopie bazy i zapora. Zestaw jest sprawdzony statycznie i w części na Windowsie, **ale nie był jeszcze uruchomiony na prawdziwym serwerze Ubuntu** (sekcja „Co sprawdzono, a czego nie” na końcu). Pierwsze uruchomienie zrób na serwerze, który możesz wyrzucić.

## Jak to jest ułożone

```
internet ─ 80/443 ─ nginx ─┬─ /          pliki aplikacji web (current/web/dist), reszta adresów dostaje index.html
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

   Sprawdza DNS, bierze osobny certyfikat dla hosta panelu, kładzie stronę z `nginx/rideidle-admin.conf.template` (jeśli nginx jej nie przyjmie, wycofuje ją i nic innego się nie zmienia), wpisuje `ADMIN_DOMAIN` i `SESSION_SECURE_COOKIE=true` do `.env` i robi `config:cache` oraz `route:cache`. Strony gry nie rusza. Można go uruchomić ponownie.
3. **Pierwszy administrator** (hasło jest pokazane raz i nigdzie niezapisane; przy pierwszym logowaniu panel wymusi ustawienie aplikacji uwierzytelniającej, a kody odzyskiwania zapisz w bezpiecznym miejscu):

   ```bash
   cd /var/www/rideidle/current/api && sudo -u www-data php8.4 artisan admin:create ty@example.com --role=publisher
   ```

4. **Role:** `editor` patrzy na paczki i robi szkice, `publisher` także zamawia paczkę z szkiców (strona Publikacje), uruchamia paczkę (też cofa do wcześniejszej), importuje paczkę z pliku i czyta dziennik. **Publikacja idzie przez kolejkę**: bez działającego `rideidle-queue` zamówiona paczka zostaje w stanie „Czeka”; zadanie trwa kilka sekund do kilku minut (tabele pochodne i bramki symulatorów), ma własny limit 900 s, a `retry_after` kolejki bazy to 90 s (przy jednym workerze, jak we wdrożeniu, nie ma to znaczenia). Migracja `create_content_publications_table` jest zwykłą migracją i wchodzi z wydaniem. Zmiana roli, wyłączenie, nowe hasło i zdjęcie aplikacji uwierzytelniającej: `artisan admin:update ADRES --role=… | --deactivate | --activate | --reset-password | --reset-mfa`. **Zgubiony telefon:** `admin:update ADRES --reset-mfa` (i `--reset-password`, jeśli ktoś mógł przejąć konto); aplikację ustawia się od nowa przy następnym logowaniu.
5. **Tylko z wybranych adresów:** odkomentuj w szablonie `allow …; deny all;` i uruchom skrypt ponownie.
6. **Lokalnie:** `php artisan serve` i `http://localhost:8000/admin` (bez `ADMIN_DOMAIN`). Administratora robi to samo `admin:create`.

Zasoby panelu (style i skrypty) publikuje `composer install` (polecenie `filament:upgrade` w `composer.json`), więc nie ma ich w repozytorium, a wydanie je ma. Każde logowanie, wylogowanie i uruchomienie paczki zostaje w dzienniku (`admin_audit_logs`).

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
- **Kopie leżą na tym samym serwerze**, więc nie ratują przed jego utratą. Kopiuj je gdzieś indziej, np. z innego komputera lub serwera (folder jest tylko dla roota):

```bash
scp -r root@raceidle.cerasusdigital.pl:/var/backups/rideidle ./kopie-rideidle
```

- **Kopie a usuwanie kont:** konto usunięte przez kierowcę albo `accounts:delete` pozostaje w kopiach do 14 dni, a w kopiach przechowywanych gdzie indziej tak długo, jak je trzymasz. Polityka prywatności jeszcze o kopiach nie mówi (`docs/prawo.md`).
- **Odtwarzanie** (jako `root`, bo `deploy` ma `sudo` tylko do przeładowania usług; zrób to raz na próbę, zanim będzie potrzebne). Właściciele tabel są w kopii, więc tabele zostają własnością użytkownika bazy `rideidle`:

```bash
systemctl stop rideidle-queue
runuser -u postgres -- pg_restore --clean --if-exists -d rideidle /var/backups/rideidle/PLIK.dump
systemctl start rideidle-queue
```

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
