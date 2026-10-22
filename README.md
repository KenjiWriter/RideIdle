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

## Stan

Faza F0 (środowisko i szkielet). Postęp według roadmapy z płótna.
