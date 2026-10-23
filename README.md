# RideIdle

Przeglądarkowa gra idle o wyścigach (PWA, później aplikacje mobilne przez Capacitor), zbudowana jako dane sterowany „silnik”, który da się przenieść na inny świat (np. statki kosmiczne). Gracz odbudowuje wraki, kupuje coraz lepsze auta, rywalizuje w turniejach, a gra liczy jego postęp także wtedy, gdy go nie ma.

Projekt (analiza założeń, systemy gry, katalogi, rozliczanie, architektura, roadmapa i ekrany) jest na płótnie projektowym: https://claude.ai/artifact/Ag5ArvxSnEiuVvr4vUzecy

## Stack

| Warstwa | Technologia |
|---|---|
| Backend | PHP 8.4, Laravel (API-first), Sanctum |
| Frontend | Vue 3, TypeScript, Vite, Pinia, Tailwind CSS, vue-i18n |
| Baza (lokalnie) | MariaDB |
| Baza (produkcja) | PostgreSQL |
| Cache, kolejki, blokady | baza w MVP, Redis na produkcji |
| Testy | Pest (w tym testy architektury), Vitest |
| CI | GitHub Actions z macierzą MariaDB i PostgreSQL |

## Struktura repozytorium

```
api/       Laravel: logika gry, rozliczanie, API
web/       Vue 3: aplikacja gracza (PWA)
content/   Paczki treści (JSON): auta, części, miasta, tory, zestawy reguł
docker/    Konfiguracja środowiska lokalnego
docs/      Decyzje i specyfikacje przeniesione z płótna projektowego
```

## Zasady, które obowiązują od pierwszego dnia

- Serwer jest jedynym źródłem czasu i wyników. Klient wysyła intencje, nigdy wyniki.
- Rozliczanie jest funkcją czystą na liczbach całkowitych (czas w ms, gotówka w centach), z testami wektorowymi i testem własności (`settle(t1)` potem `settle(t2)` daje to samo co `settle(t2)`).
- Każda zmiana salda lub stanu idzie w transakcji z `lockForUpdate()` i kluczem idempotencji.
- Definicje (treść z paczek) i instancje (rzeczy graczy) są rozdzielone. Slug definicji jest niezmienny.
- Kod działa na MariaDB i PostgreSQL. Różnice wychodzą w CI, nie na produkcji.

## Języki

Gra od pierwszego dnia działa w pięciu językach: `pl`, `en`, `fr`, `de`, `pt-BR` (skrót `br` to alias brazylijskiego portugalskiego). Angielski jest językiem zapasowym.

- **API:** język odpowiedzi wybiera middleware `SetLocale` w kolejności: nagłówek `X-Locale`, język zapisany na koncie (`users.locale`), nagłówek `Accept-Language`, język domyślny. Odpowiedź zawiera `Content-Language`. Komunikaty gry są w `api/lang/<język>/game.php`, a teksty z paczek treści (nazwy, opisy) w polach JSON z tłumaczeniami (`I18nText`).
- **Web:** `vue-i18n`, pliki w `web/src/i18n/locales/<język>.json`, ładowane leniwie (angielski od razu). Liczby i waluta idą przez `Intl`, więc wyglądają zgodnie ze zwyczajem języka.
- **Testy pilnują zgodności:** te same klucze i te same symbole zastępcze we wszystkich językach (API i web), a lista języków w `web` musi zgadzać się z `api/config/ride.php`.

Nowy tekst dodajemy od razu we wszystkich pięciu językach, bo test zgodności inaczej zgłosi błąd.

## Uruchomienie lokalne

Wymagania: PHP 8.4 z Composerem, Node 24, baza MariaDB lub MySQL (np. z Laragona). Docker jest opcjonalny.

```sh
# API
cd api
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan serve              # http://127.0.0.1:8000
vendor/bin/pest                # testy (domyślnie SQLite w pamięci)

# Web
cd web
npm install
npm run dev                    # http://localhost:5173, /api idzie do DEV_API_TARGET (domyślnie port 8000)
npm test && npm run typecheck && npm run build

# Usługi pomocnicze w Dockerze (MariaDB, PostgreSQL, Redis, Mailpit)
docker compose -f docker/compose.yaml up -d
```

Jeśli port 8000 zajmuje inny projekt, uruchom API na innym (`php artisan serve --port=8011`) i wskaż go w `web/.env.local`: `DEV_API_TARGET=http://127.0.0.1:8011`.

Testy API na konkretnej bazie: `DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3307 DB_DATABASE=ride_idle DB_USERNAME=root DB_PASSWORD=secret vendor/bin/pest`. CI uruchamia je na SQLite, MariaDB i PostgreSQL.

## Zasady pracy

- Każda zmiana to osobny commit. Komunikaty commitów piszemy po angielsku, w stylu `feat(api): ...`, `fix(web): ...`, `chore: ...`.
- Dokumentacja (ten plik, `docs/`) jest po polsku. Teksty w grze powstają od razu w pięciu językach.

## Stan

Faza F0 (środowisko i szkielet). Postęp według roadmapy z płótna.

Zrobione: szkielet Laravel 13 (Sanctum, Pest, testy architektury), szkielet Vue 3 (TypeScript, Pinia, router, Tailwind, Vitest), wsparcie pięciu języków w API i w aplikacji, Docker Compose, CI z macierzą baz, konto gościa (unikalne imię kierowcy, token urządzenia, kod odzyskiwania z limitami prób, wygasanie nieużywanych kont), schemat paczki treści v0 z walidatorem i paczka startowa (`content/`), ekrany wejścia w `web`: landing, tworzenie kierowcy, kod odzyskiwania i przywracanie kierowcy, synchronizacja języka z kontem.

Przepływ wejścia (sprawdzony ręcznie w przeglądarce na lokalnym API): landing → imię → garaż → „Pokaż mój kod odzyskiwania” (kod widać tylko raz) → wylogowanie → „Mam już kierowcę” → kod → garaż w języku zapisanym na koncie.

Rozliczanie (`api/app/Domain/Settlement/`, opis w `docs/settlement.md`): czysta funkcja `settle` na liczbach całkowitych z wektorami W1–W7, testami własności na 10 000 losowych biegów, podział puli turnieju i Respekt z tablicą oczekiwanych wyników.

Zapis w bazie (`api/app/Services/`, też w `docs/settlement.md`): zamrażanie konfiguracji biegu do liczb całkowitych, tabele `wallets`, `race_runs`, `settlements`, `settlement_events` i `ledger_entries`, rozliczanie w jednej transakcji z blokadami (portfel, potem bieg), księga z kluczem idempotencji, zmiana ustawień, tankowanie i naprawa po awarii (nowe ziarno, jazda od teraz). Testy współbieżności (osobne procesy na MySQL, MariaDB i PostgreSQL) uruchamiają się razem z resztą, gdy baza nie jest SQLite.

Punkty końcowe biegu (`api/app/Http/Controllers/Api/V1/RunController.php`, opis w `docs/api-runs.md`): start, podsumowanie z `since_version`, tankowanie, zmiana toru i naprawa. Dane toru, klimatu, pogody i cen są w `content/rules/starter.json`, a auto z paczki `core`.

Ekrany biegu w `web` (opis w `docs/web-run.md`): wybór wraku startowego („krok 2 z 2”), garaż z biegiem, licznik paliwa i zasięg, podsumowanie nieobecności, tankowanie, naprawa i holowanie (wyjście dla kierowcy, którego nie stać na ruszenie stojącego auta: darmowe, ale auto czeka). Całość sprawdzona ręcznie w przeglądarce na lokalnym API.

Zabezpieczenie konta (opis w `docs/konto.md`): po pierwszym zarobku garaż proponuje połączyć konto, czyli zabezpieczyć gościa linkiem jednorazowym z e-maila albo przez Google, Facebooka lub Apple. API: tożsamości (jedna na dostawcę, konta nigdy się nie łączą), sekrety jednorazowe z samym skrótem w bazie, link w mailu (bez ujawniania, czy adres ma garaż), przepływ dostawców w trzech krokach z weryfikatorem trzymanym przez przeglądarkę (obrona przed login CSRF), limity i zadanie sprzątające. Web: okno „Zapisz swój garaż” z zgodami, cichy baner po „Później”, strony `/account/confirm` i `/auth/callback`, logowanie kontem na ekranie „Mam już konto” (kod odzyskiwania zostaje) i dodawanie kolejnego sposobu logowania w garażu (zgody składa się raz). Przepływ dostawców sprawdzono testami z `Socialite::fake` i z prawdziwymi adresami autoryzacji zbudowanymi offline; **z prawdziwym Google, Facebookiem i Apple nie sprawdzano go** (potrzebne są klucze, opis w `docs/konto.md`).

Strony prawne (opis w `docs/prawo.md`): regulamin (`/terms`) i polityka prywatności (`/privacy`) w pięciu językach, z linkami w stopce, przy tworzeniu kierowcy i w oknie zgód, z wiekiem, wersją i danymi operatora z ustawień API (`LEGAL_*`) i z zapisem wersji przy zgodzie. **Teksty są roboczym projektem napisanym z tego, co aplikacja robi z danymi, i nie przeszły przeglądu prawnego**; próg wieku (16) jest roboczy. Tłumaczenia francuskie, niemieckie i brazylijskie przeszły niezależny przegląd i poprawki (`docs/prawo.md`), ale nie przegląd przez osobę, dla której język jest ojczysty. Prośby o kopię i usunięcie danych obsługuje operator poleceniami `php artisan accounts:export` i `accounts:delete` (procedura w `docs/prawo.md`); w aplikacji nie ma samoobsługowych przycisków.

Dalej: tabele definicji i instancji z paczek treści (garaż, części, więcej aut i torów), historia wprowadzająca (samouczek) i przegląd prawny regulaminu i polityki prywatności.
