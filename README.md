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
npm run dev                    # http://localhost:5173, /api idzie do portu 8000
npm test && npm run typecheck && npm run build

# Usługi pomocnicze w Dockerze (MariaDB, PostgreSQL, Redis, Mailpit)
docker compose -f docker/compose.yaml up -d
```

Testy API na konkretnej bazie: `DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3307 DB_DATABASE=ride_idle DB_USERNAME=root DB_PASSWORD=secret vendor/bin/pest`. CI uruchamia je na SQLite, MariaDB i PostgreSQL.

## Zasady pracy

- Każda zmiana to osobny commit. Komunikaty commitów piszemy po angielsku, w stylu `feat(api): ...`, `fix(web): ...`, `chore: ...`.
- Dokumentacja (ten plik, `docs/`) jest po polsku. Teksty w grze powstają od razu w pięciu językach.

## Stan

Faza F0 (środowisko i szkielet). Postęp według roadmapy z płótna.

Zrobione: szkielet Laravel 13 (Sanctum, Pest, testy architektury), szkielet Vue 3 (TypeScript, Pinia, router, Tailwind, Vitest), wsparcie pięciu języków w API i w aplikacji, Docker Compose, CI z macierzą baz.

Dalej: rejestracja i logowanie gościa (kod odzyskiwania), schemat paczki treści v0, rozliczanie według specyfikacji (`settle` jako funkcja czysta z testami W1–W7).
