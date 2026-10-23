# Wdrożenie RideIdle na serwer Ubuntu

Ten folder stawia całą grę na jednym serwerze Ubuntu (22.04 albo 24.04) pod jedną domeną, `idlerace.cerasusdigital.pl`: aplikacja web pod `/`, API pod `/api`, certyfikat Let's Encrypt, kolejka maili, harmonogram, kopie bazy i zapora. Zestaw jest sprawdzony statycznie i w części na Windowsie, **ale nie był jeszcze uruchomiony na prawdziwym serwerze Ubuntu** (sekcja „Co sprawdzono, a czego nie” na końcu). Pierwsze uruchomienie zrób na serwerze, który możesz wyrzucić.

## Jak to jest ułożone

```
internet ─ 80/443 ─ nginx ─┬─ /          pliki aplikacji web (current/web/dist), reszta adresów dostaje index.html
                           ├─ /api/      PHP-FPM ─ Laravel (current/api)
                           └─ /up        sprawdzenie, czy Laravel żyje
PostgreSQL (baza)   Redis (tylko liczniki limitów)   rideidle-queue (systemd, wysyła maile)   cron (harmonogram, kopie)
```

```
/var/www/idlerace/
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

- Serwer z Ubuntu 22.04 lub 24.04, **najlepiej 2 GB pamięci i 20 GB dysku** (mniej pamięci: skrypt dokłada 2 GB swapu, bo budowanie aplikacji web jej potrzebuje), publiczny adres IPv4, dostęp przez SSH jako root kluczem.
- Dostęp do DNS domeny `cerasusdigital.pl`.
- Na Twoim komputerze: `git` i `ssh` (Git Bash na Windowsie wystarczy; `rsync` nie jest potrzebny).
- Dane SMTP, przez które pójdą maile z linkami (patrz „Poczta”).

## Krok 1. DNS

U siebie, gdzie jest strefa `cerasusdigital.pl`, dodaj rekord:

| Typ | Nazwa | Wartość | TTL |
|---|---|---|---|
| A | `idlerace` | adres IPv4 serwera | 300 |

- **AAAA** dodaj tylko wtedy, gdy serwer ma publiczny adres IPv6, który chcesz obsługiwać (nginx i zapora go obsługują). Błędny AAAA psuje wydanie certyfikatu, bo Let's Encrypt najpierw próbuje IPv6.
- **CAA** (niewymagany, ale tani): `idlerace.cerasusdigital.pl. CAA 0 issue "letsencrypt.org"`.
- Jeśli domena jest u Cloudflare, ustaw **„tylko DNS” (szara chmurka)**. Przez proxy API widziałoby adresy Cloudflare, a limity prób są liczone po adresie klienta; zestaw nie ma ustawionego zaufanego proxy.

Poczekaj, aż `nslookup idlerace.cerasusdigital.pl` pokaże adres serwera. `provision.sh` sprawdza to sam i zatrzymuje się z opisem, jeśli rekord jest zły.

## Krok 2. Przygotowanie serwera (raz)

Z katalogu repozytorium, na Twoim komputerze:

```bash
deploy/upload.sh bootstrap root@ADRES_SERWERA
```

Skrypt wysyła folder `deploy/` do `/root/rideidle-deploy` i pokazuje, co dalej. Potem na serwerze:

```bash
ssh root@ADRES_SERWERA
cd /root/rideidle-deploy
DOMAIN=idlerace.cerasusdigital.pl ADMIN_EMAIL=twoj@adres.pl bash provision.sh
```

`ADMIN_EMAIL` dostaje od Let's Encrypt ostrzeżenia o certyfikacie. Pozostałe ustawienia (zmienne środowiska przed `bash provision.sh`):

| Zmienna | Znaczenie |
|---|---|
| `MAIL_FROM_ADDRESS` | nadawca maili; domyślnie `no-reply@cerasusdigital.pl` |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`, `MAIL_USERNAME` | serwer SMTP (port 587 + `smtp`, albo 465 + `smtps`) |
| `LEGAL_OPERATOR_NAME`, `LEGAL_OPERATOR_ADDRESS`, `LEGAL_CONTACT_EMAIL` | kto prowadzi grę; pokazują to regulamin i polityka prywatności |
| `SSH_PORT` | port SSH, który zapora zostawia otwarty (domyślnie 22) |
| `DEPLOY_USER`, `APP_DIR`, `PHP_VERSION`, `NODE_MAJOR` | użytkownik wydań (`deploy`), folder gry, wersje |
| `CERTBOT_STAGING=1` | testowy certyfikat, który nie zużywa limitów Let's Encrypt |
| `SKIP_DNS_CHECK=1` | pomija sprawdzenie DNS (nie używaj bez powodu) |

**Hasła nie wpisuj w poleceniu** (zostaje w historii powłoki): `MAIL_PASSWORD` uzupełnij w `.env` (krok 3). Wartości ze znakami `$`, `\`, `"`, `&` są zapisywane do `.env` poprawnie.

Skrypt: sprawdza system i DNS, dodaje swap, instaluje PHP 8.4 (z PPA ondrej), Composera, Node 24, PostgreSQL, Redis (tylko na 127.0.0.1) i nginx, zakłada bazę i użytkownika, tworzy użytkownika `deploy` (z tym samym kluczem SSH co root) i foldery, generuje `.env` z nowym `APP_KEY` i hasłem bazy, dopisuje wąskie uprawnienia `sudo` dla `deploy`, instaluje kolejkę, harmonogram i kopie, zdobywa certyfikat (webroot, odnawiany sam; po odnowieniu nginx jest przeładowywany), a na końcu włącza zaporę `ufw` (SSH, 80, 443), `fail2ban` i automatyczne aktualizacje bezpieczeństwa. Na końcu wypisuje, co zostało do zrobienia.

**Najpierw na próbę:** `CERTBOT_STAGING=1 DOMAIN=... bash provision.sh` daje certyfikat testowy, któremu przeglądarki nie ufają. Wtedy dopisz do `/etc/rideidle/deploy.env` linię `HEALTH_CURL_OPTS=-k`, żeby sprawdzenie po wydaniu go przyjęło. Gdy wszystko działa: `certbot delete --cert-name idlerace.cerasusdigital.pl` i `bash provision.sh` jeszcze raz, bez `CERTBOT_STAGING` (przy okazji `deploy.env` wraca do normalnego).

SSH nie jest utwardzany (hasła nadal mogą działać), żeby skrypt nie mógł Cię zablokować. Gdy klucz działa dla `root` i `deploy`, wyłącz logowanie hasłem sam (`PasswordAuthentication no` w `/etc/ssh/sshd_config.d/`).

## Krok 3. Uzupełnij `.env`

```bash
ssh deploy@idlerace.cerasusdigital.pl
nano /var/www/idlerace/shared/.env
```

Do uzupełnienia, jeśli nie podałeś tego w kroku 2:

- `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD`: bez nich linki w mailach nie wychodzą.
- `LEGAL_OPERATOR_NAME`, `LEGAL_OPERATOR_ADDRESS`, `LEGAL_CONTACT_EMAIL`: bez nich strony prawne pokazują brakujące dane na czerwono.
- `GOOGLE_*`, `FACEBOOK_*`, `APPLE_*`: dostawca jest włączony tylko wtedy, gdy ma klucze (patrz „Dostawcy logowania”).
- `LEGAL_MIN_AGE=16` jest wartością roboczą do decyzji prawnika (`docs/prawo.md`).

Zmiana w `.env` działa od następnego wydania. Żeby zadziałała od razu (konfiguracja jest zapisana w pamięci podręcznej Laravela):

```bash
cd /var/www/idlerace/current/api
sudo -u www-data php8.4 artisan config:cache
sudo systemctl reload php8.4-fpm
sudo systemctl restart rideidle-queue
```

## Krok 4. Pierwsze wydanie

Z katalogu repozytorium na Twoim komputerze:

```bash
deploy/upload.sh release deploy@idlerace.cerasusdigital.pl
```

Wysyłany jest **zatwierdzony** stan (`HEAD`), nie to, co leży w katalogu roboczym; skrypt ostrzega, jeśli masz niezatwierdzone zmiany. Inny commit lub tag: `deploy/upload.sh release deploy@... v1.0`. Pierwsze wydanie trwa kilka minut (Composer, `npm ci`, budowanie). Kroki: źródła → `composer install --no-dev` → `npm ci` i `npm run build` → `.env` i `storage` z `shared/` → `content:validate` → migracje → pamięci podręczne Laravela → przełączenie linku `current` → przeładowanie PHP-FPM i restart kolejki → **sprawdzenie** (`/up`, `/api/v1/meta/locales` i strona główna z serwera na samego siebie). Jeśli sprawdzenie nie przejdzie, poprzednie wydanie wraca, a skrypt kończy się błędem; nieudane wydanie zostaje w `releases/` do obejrzenia.

Potem sprawdź w przeglądarce `https://idlerace.cerasusdigital.pl`: strona startowa, utworzenie kierowcy, `/terms` i `/privacy` oraz `https://idlerace.cerasusdigital.pl/up`.

## Poczta

Linki do logowania i zabezpieczenia konta idą mailem, więc dostarczalność jest częścią gry.

1. Wybierz dostawcę SMTP (własny serwer pocztowy, Mailgun, Postmark, Brevo, Amazon SES i podobne). Wiele hostingów **blokuje port 25**; używaj 587 (`MAIL_SCHEME=smtp`) lub 465 (`smtps`).
2. Adres w `MAIL_FROM_ADDRESS` musi należeć do domeny, którą dostawca zweryfikował: w DNS domeny nadawcy (domyślnie `cerasusdigital.pl`) dodaj **SPF** i **DKIM** według instrukcji dostawcy oraz **DMARC**, np. `_dmarc.cerasusdigital.pl TXT "v=DMARC1; p=none; rua=mailto:twoj@adres.pl"` (zacznij od `p=none`, zaostrz po kilku tygodniach bez problemów). Bez tego Gmail i inni wrzucą linki do spamu albo je odrzucą.
3. Sprawdź wysyłkę (idzie od razu, bez kolejki, więc błąd widać na ekranie):

```bash
cd /var/www/idlerace/current/api
sudo -u www-data php8.4 artisan tinker --execute='Mail::raw("test", fn ($m) => $m->to("twoj@adres.pl")->subject("RideIdle: test poczty"));'
```

W Gmailu „Pokaż oryginał” powinien pokazać SPF, DKIM i DMARC jako PASS. Potem przejdź całą ścieżkę w grze: gość → „Zapisz swój garaż” → mail.

Maile, które się nie wysłały, trafiają do `failed_jobs` (`sudo -u www-data php8.4 artisan queue:failed`) i harmonogram usuwa je po tygodniu.

## Dostawcy logowania (Google, Facebook, Apple)

Klucze zakładasz u dostawców w swoich kontach i wpisujesz do `.env` (opis zmiennych: `docs/konto.md`). Adresy do wpisania w ich konsolach:

| Co | Adres |
|---|---|
| Adres powrotu Google | `https://idlerace.cerasusdigital.pl/api/v1/auth/google/callback` |
| Adres powrotu Facebook | `https://idlerace.cerasusdigital.pl/api/v1/auth/facebook/callback` |
| Adres powrotu Apple (Services ID; domena: `idlerace.cerasusdigital.pl`) | `https://idlerace.cerasusdigital.pl/api/v1/auth/apple/callback` |
| Polityka prywatności | `https://idlerace.cerasusdigital.pl/privacy` |
| Regulamin | `https://idlerace.cerasusdigital.pl/terms` |

Po zmianie `.env` zastosuj ją poleceniem z kroku 3. **Przepływ z prawdziwymi dostawcami nie był jeszcze sprawdzony** (`docs/konto.md`); pierwsze sprawdzenie to ten moment.

## Na co dzień

**Nowe wydanie:** `deploy/upload.sh release deploy@idlerace.cerasusdigital.pl`.

**Wycofanie** do najnowszego wydania starszego od bieżącego (kolejne wywołanie cofa dalej): `deploy/upload.sh rollback deploy@idlerace.cerasusdigital.pl`.

> **Migracje przed przełączeniem.** Migracje biegną, gdy stare wydanie jeszcze obsługuje ruch, więc muszą działać także ze starym kodem: dodawaj kolumny, nie zmieniaj nazw i nie usuwaj ich w tym samym wydaniu. Wycofanie przywraca stary kod, ale **nie cofa migracji**.

**Logi:**

```bash
journalctl -u rideidle-queue -n 100               # kolejka
tail -n 100 /var/www/idlerace/shared/storage/logs/laravel-$(date +%F).log
tail -n 100 /var/log/nginx/idlerace.error.log
```

Logi Laravela są codzienne i kasowane po 14 dniach (`LOG_DAILY_DAYS`), poziom `warning`.

**Polecenia operatora** (prośby o kopię i usunięcie danych, `docs/prawo.md`) uruchamiasz jako `www-data`:

```bash
cd /var/www/idlerace/current/api
(umask 077; sudo -u www-data php8.4 artisan accounts:export --email=adres@example.com > ~/konto.json)
sudo -u www-data php8.4 artisan accounts:delete --email=adres@example.com --dry-run
```

**Monitoring:** adres `https://idlerace.cerasusdigital.pl/up` wystarczy dowolnemu monitorowi (UptimeRobot i podobne).

**Aktualizacje systemu:** poprawki bezpieczeństwa instalują się same. Serwer **nie restartuje się sam**; od czasu do czasu sprawdź `ls /var/run/reboot-required` i zrestartuj w spokojnej chwili (kolejka i nginx wstają same).

## Kopie zapasowe

- Co noc o 3:15 `pg_dump` zapisuje bazę do `/var/backups/rideidle/rideidle-RRRR-MM-DD-GGMM.dump` (prawa tylko dla roota). Kopie starsze niż 14 dni są kasowane; wynik jest w `/var/log/rideidle-backup.log`.
- **Kopie leżą na tym samym serwerze**, więc nie ratują przed jego utratą. Kopiuj je gdzieś indziej, np. z innego komputera lub serwera (folder jest tylko dla roota):

```bash
scp -r root@idlerace.cerasusdigital.pl:/var/backups/rideidle ./kopie-rideidle
```

- **Kopie a usuwanie kont:** konto usunięte przez kierowcę albo `accounts:delete` pozostaje w kopiach do 14 dni, a w kopiach przechowywanych gdzie indziej tak długo, jak je trzymasz. Polityka prywatności jeszcze o kopiach nie mówi (`docs/prawo.md`).
- **Odtwarzanie** (jako `root`, bo `deploy` ma `sudo` tylko do przeładowania usług; zrób to raz na próbę, zanim będzie potrzebne). Właściciele tabel są w kopii, więc tabele zostają własnością użytkownika bazy `rideidle`:

```bash
systemctl stop rideidle-queue
runuser -u postgres -- pg_restore --clean --if-exists -d rideidle /var/backups/rideidle/PLIK.dump
systemctl start rideidle-queue
```

## Decyzje i ograniczenia

- **Jedna domena, jeden serwer.** Aplikacja web i API są pod jednym adresem, więc nie ma CORS ani drugiego certyfikatu. Przy większym ruchu bazę lub kolejkę można przenieść osobno.
- **Kolejka w bazie, nie w Redisie.** Przez to `accounts:delete` usuwa także maile czekające w kolejce i nieudane, a `queue:prune-failed` je sprząta. Redis trzyma tylko liczniki limitów.
- **Brak Content-Security-Policy.** Teksty gry są kompilowane w przeglądarce (vue-i18n), co wymaga `unsafe-eval`; polityka z nim chroni niewiele. Pozostałe nagłówki są: HSTS, `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer` (adres strony może zawierać sekret linku), `Permissions-Policy`. HSTS ma rok i nie ma `includeSubDomains` ani `preload`.
- **Opcache nie sprawdza plików** (`validate_timestamps=0`); dlatego każde wydanie przeładowuje PHP-FPM.
- **Brak Dockera, brak CI/CD.** Wydanie robisz ręcznie z komputera. CI można dodać później, wywołując `upload.sh`.
- **Zapora** przepuszcza tylko SSH, 80 i 443. PostgreSQL i Redis słuchają wyłącznie lokalnie.
- Nie ma stanu współdzielonego między serwerami: dodanie drugiego serwera wymagałoby wspólnej bazy, Redisa i storage.

## Co sprawdzono, a czego nie

Sprawdzone:

- Wszystkie skrypty przechodzą `bash -n` i `shellcheck` bez uwag.
- Konfiguracja nginx przeszła `nginx -t` (nginx 1.27.3) po wyrenderowaniu szablonu; uruchomiony serwer odpowiadał tak, jak trzeba: przekierowanie HTTP → HTTPS, ścieżka sprawdzenia Let's Encrypt, nagłówki na każdej odpowiedzi, `index.html` dla adresów aplikacji, długie cache tylko dla `assets/` (i nie dla błędu 404), brak dostępu do plików z kropką. To badanie wykryło i poprawiło jedną wadę (404 z `immutable`). Nie sprawdzono PHP-FPM ani TLS z prawdziwym certyfikatem.
- Zapis wartości do `.env` (hasła z `$`, `\`, `"`, `&`, `#`, polskie litery) odczytany parserem `.env` Laravela jest identyczny. Test wykrył błąd bash 5.2, który psuł `&` w wartościach; poprawiony w skrypcie.
- Wybór wydania do wycofania (po jednym i dwóch wycofaniach, najstarsze, brak).

**Niesprawdzone, bo wymaga prawdziwego serwera:** całe `provision.sh` i `deploy.sh` na Ubuntu (nazwy pakietów PPA ondrej i NodeSource, certbot, `ufw`, `sudoers`, uprawnienia plików, `systemd`), odtworzenie kopii bazy, wysyłka maili, przepływ z Google/Facebookiem/Apple. Spodziewaj się drobnych poprawek przy pierwszym uruchomieniu: skrypt przerywa się z numerem linii, a ponowne uruchomienie jest bezpieczne.

## Gdy coś nie działa

| Objaw | Co sprawdzić |
|---|---|
| `provision.sh` zatrzymuje się na DNS | rekord A jeszcze się nie rozszedł albo wskazuje inny adres; poczekaj (`nslookup`) |
| certbot odmawia | port 80 zamknięty u dostawcy serwera, błędny rekord AAAA, albo limit Let's Encrypt (użyj `CERTBOT_STAGING=1`) |
| `composer` lub `npm` kończy się „Killed” | brak pamięci; sprawdź `swapon --show`, rozważ większy serwer |
| 502 Bad Gateway | `systemctl status php8.4-fpm`, `/var/log/nginx/idlerace.error.log` |
| wydanie „is not well”, poprzednie wraca | `.../shared/storage/logs/`, błąd nginx, `.env` (np. hasło bazy); nieudane wydanie zostało w `releases/` |
| maile nie wychodzą | `queue:failed`, `journalctl -u rideidle-queue`, test z sekcji „Poczta” |
| po zmianie `.env` nic się nie zmieniło | `config:cache` i restart (krok 3) |
