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
| Cache, kolejki, blokady | lokalnie baza; na produkcji cache (liczniki limitów) w Redisie, a kolejka maili w bazie, żeby `accounts:delete` mogło ją sprzątać |
| Serwer | Ubuntu, nginx, PHP-FPM, jedna domena (`deploy/`) |
| Testy | Pest (w tym testy architektury), Vitest |
| CI | GitHub Actions z macierzą MariaDB i PostgreSQL |

## Struktura repozytorium

```
api/       Laravel: logika gry, rozliczanie, API
web/       Vue 3: aplikacja gracza (PWA)
content/   Paczki treści (JSON): auta, części, miasta, tory, zestawy reguł
docker/    Konfiguracja środowiska lokalnego
deploy/    Wdrożenie na serwer Ubuntu: skrypty, nginx, systemd, kopie bazy (deploy/README.md)
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

## Wdrożenie

`deploy/` stawia grę na jednym serwerze Ubuntu pod jedną domeną (docelowo `raceidle.cerasusdigital.pl`: aplikacja web pod `/`, API pod `/api`): `deploy/upload.sh bootstrap root@SERWER` i `provision.sh` przygotowują serwer (PHP, PostgreSQL, Redis, nginx, certyfikat Let's Encrypt, kolejka, harmonogram, kopie bazy, zapora), a `deploy/upload.sh release deploy@DOMENA` wydaje zatwierdzony stan repozytorium i w razie nieudanego sprawdzenia wraca do poprzedniego. Kolejność kroków, DNS, poczta, adresy dla Google, Facebooka i Apple, wycofanie i kopie: `deploy/README.md`.

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

Strony prawne (opis w `docs/prawo.md`): regulamin (`/terms`) i polityka prywatności (`/privacy`) w pięciu językach, z linkami w stopce, przy tworzeniu kierowcy i w oknie zgód, z wiekiem, wersją i danymi operatora z ustawień API (`LEGAL_*`) i z zapisem wersji przy zgodzie. **Teksty są roboczym projektem napisanym z tego, co aplikacja robi z danymi, i nie przeszły przeglądu prawnego**; próg wieku (16) jest roboczy. Tłumaczenia francuskie, niemieckie i brazylijskie przeszły niezależny przegląd i poprawki (`docs/prawo.md`), ale nie przegląd przez osobę, dla której język jest ojczysty. Kierowca pobiera kopię swoich danych i usuwa konto sam, w garażu w sekcji „Twoje dane”; operator ma te same możliwości jako polecenia `php artisan accounts:export` i `accounts:delete` (procedura w `docs/prawo.md`).

Własne auta i części, sklep i warsztat (opisy w `docs/api-runs.md` i `docs/web-run.md`): kierowca ma auta i części jako wiersze, część kupuje w sklepie (tylko takie, które zmieniają jazdę: opony, zawieszenie, hamulce, wydech) i zakłada w warsztacie, gdzie jeden mechanik robi zlecenia jedno po drugim na timerze (zdjęcie to 60% czasu założenia, w kolejce mieści się cztery zlecenia). Podczas zlecenia auto stoi i nic nie zarabia, a po ostatnim jedzie dalej z nowymi statystykami i krótszym okrążeniem. Zlecenia wykonują się leniwie, gdy kierowca wraca, w kolejności swoich czasów. Poziom auta ogranicza tier części (tier ≤ 1 + ⌊poziom / 3⌋). Czasy zleceń i uśrednianie zawieszenia i hamulców z oponami są robocze i czekają na symulator ekonomii.

Wdrożenie (opis w `deploy/README.md`): skrypty przygotowania serwera Ubuntu i wydawania wersji pod `raceidle.cerasusdigital.pl`, szablony nginx i systemd, nocne kopie bazy (14 dni), logi dostępu bez zapytania (w adresie bywa sekret linku). Skrypty przeszły `shellcheck`, konfigurację nginx sprawdzono prawdziwym nginx na Windowsie, a zapis `.env` parserem Laravela; **nie były jeszcze uruchomione na prawdziwym serwerze Ubuntu**.

Samouczek i ekran Turniej (opisy w `docs/api-runs.md` i `docs/web-run.md`): nowy kierowca ogląda historię (cztery sceny) i wybiera wrak, a w garażu prowadzą go SMS-y od Kuby na starym telefonie, pięć kroków z danych (`content/rules/tutorial.json`): pierwsze okrążenie, nagrody, tankowanie i pierwsza awaria, z wypłatami i pytaniem przy pomijaniu; postęp liczy serwer. Ekran Turniej pokazuje wyścig na żywo: pierścień z odliczaniem okrążenia, pogodę z prognozą na następne 22,5 minuty i tablicę z kierowcą (bez rywali i pozycji, których jeszcze nie ma).

Symulator ekonomii v0 (opis w `content/README.md`): `php artisan economy:simulate` gra trzy typy graczy po drabinie dziewięciu aut z kotwic w `content/rules/economy.json`, mówi, w którym dniu każdy kupuje każde auto, i sprawdza, czy dni mieszczą się w ±25% celu z projektu (kod wyjścia 1, gdy nie; 24 liczby, na tym zestawie wszystkie co do dnia). Zestawia też liczby gry z kotwicami (`--strict` daje kod 1, gdy któraś odbiega o więcej niż 20%). Pierwszy przebieg wykrył, że XP za okrążenie w grze (25) jest o rząd wielkości za duże wobec kotwic (1,9); skalibrowano je do 2 i teraz `--strict` przechodzi.

XP i poziomy aut (opis w `docs/api-runs.md`): auto zbiera 10 XP na każde rozliczone okrążenie i awansuje (pasmo od poziomu *n* to `200 · n` XP, najwyżej poziom 30), a poziom ogranicza tier części, którą można zamontować. Garaż pokazuje pasek XP auta jak plansza, mówi o awansie i o tym, co otwiera następny tier; trzeci krok samouczka wypłaca 100 XP auta.

Anulowanie kolejki warsztatu (opis w `docs/api-runs.md` i `docs/web-run.md`): zlecenie, które czeka albo które mechanik właśnie robi, można anulować (`DELETE /garage/jobs/{id}`). Nic nie kosztuje i nic nie zwraca, praca zlecenia w toku przepada, a część zostaje tam, gdzie była; zdjęcie ciągnie za sobą założenie części, która miała zająć gniazdo, a zlecenia za anulowanym przesuwają się do przodu. Projekt o anulowaniu milczy, więc te reguły są robocze, a pytanie i komunikaty w garażu to mój pomysł na tej samej skórce.

To była ostatnia pozycja F1 z roadmapy. F2, krok 1 (tory, nawierzchnie i opony; opis w `content/README.md`, `docs/api-runs.md` i `docs/web-run.md`): jest pięć torów (Złomowo, Kruszyn, Suchy Pas, Wilcza Przełęcz, Autodrom) z profilami z projektu, pięć klimatów (w górach pada śnieg) i **macierz opon**: sześć rodzajów opon na pięciu nawierzchniach (suchy i mokry asfalt, żwir, błoto, śnieg), z której zamrożone okrążenie bierze mnożnik przyczepności, prowadzenia i hamowania, więc żadne opony nie są najlepsze wszędzie. Auto ma **szóstą statystykę, Terenowość**. Sklep sprzedaje opony całoroczne, terenowe, deszczowe i zimowe oraz zawieszenie rajdowe, a opona w UI mówi, na czym jest dobra. Tor otwiera poziom gracza, który serwer już liczy (422 `track_locked`). `php artisan tracks:simulate` gra każde auto z każdą kombinacją części na każdym torze: bramki spójności przechodzą (każdy rodzaj opon wygrywa swoją nawierzchnię, teren wymusza inne auto), a **bramka M3 przechodzi, ale cienko**: po dodaniu wpływu masy na prowadzenie i hamowanie (`(1000 kg / masa) ^ 0,6`, moja decyzja) Bizon wygrywa dwa tory, Rdzak dwa, Kuna jeden, tyle że trzy z pięciu rozstrzygają różnice rzędu 1 do 2 punktów procentowych (przedtem Bizon wygrywał cztery). Brakuje napędu w jeździe oraz szerszego katalogu aut. Nagrody i czasy nowych torów są robocze i nie sprawdził ich jeszcze symulator ekonomii. Mapy, miast i podróży jeszcze nie ma.

F2, krok 2 (zegar gry; opis w `docs/api-runs.md`, `docs/web-run.md` i `content/README.md`): **trzy godziny realne to doba gry** z porami dnia (świt, dzień, zmierzch, noc), liczona z samego czasu serwera. Noc wydłuża o 3% okrążenie na torach bez świateł (kamieniołom, drag, serpentyny), a rozliczenie tnie przedziały okrążeń na brzegach nocy, więc rozliczenie w kawałkach nadal równa się jednemu rozliczeniu (test własności przez całą dobę). Odpowiedź biegu ma zegar, prognozę pogody na półtorej godziny z nawierzchnią każdego odcinka i czas nocnego okrążenia. HUD pokazuje „11:20 · sucho”, a ekran Turniej prognozę z czasem okrążenia w każdej pogodzie, uwagę o nocy i, gdy nadchodząca pogoda mocno spowalnia auto, odsyła do warsztatu (opon nie podpowiada z inwentarza). Mgła, świt i zmierzch nie zmieniają okrążenia, bo projekt nie podaje liczb. Złomowo i Autodrom mają światła: to moja decyzja.

F2, krok 3 (mapa i podróż, prototyp; opis w `docs/api-runs.md`, `docs/web-run.md` i `content/README.md`): pięć miast (regiony reguł) z drogami (Złomowo–Kruszyn 38 km i stamtąd trzy dalsze), ekran **Mapa** z planszą z projektu, kartą miasta (czego wymaga tor, dopasowanie auta, koszt drogi) i przyciskiem „Jedź tutaj”. **Podróż to zlecenie czasowe auta**: auto zarabia do wyjazdu, w drodze stoi i nic nie zarabia (czas z prędkości maksymalnej, paliwo za kilometr), a po przyjeździe dostaje tor miasta, zamrożony od nowa; wykonuje się leniwie jak warsztat. **Bramka poziomu gracza**: zamknięte miasto mówi, jakiego poziomu wymaga i jak go zdobyć (422 `city_locked`). Czasu i paliwa drogi, długości dróg ponad te z planszy Kursy i przyjazdu po prostej na mapie nie podaje projekt: to moje robocze liczby. Wielu aut, garaży i warsztatów w miastach wtedy jeszcze nie było: doszły w kroku 4 (niżej).

F2, krok 4 (wiele aut, garaże i warsztaty w miastach; opis w `docs/api-runs.md`, `docs/web-run.md` i `content/README.md`): **kierowca nie ma własnej pozycji, pozycję ma każde auto** (miasto toru jego biegu), a kierowca gra **wybranym** autem (`POST /cars/{car}/select`). Każde auto ma własny bieg i **jeździ samo** na torze swojego miasta, rozliczane leniwie na tym samym portfelu, więc „zostaw auto w A i graj drugim w B” działa. **Garaż w mieście** mieści trzy auta (garaż miasta startowego jest od początku, inne kupuje się za 5 000 $ w mieście, które otwiera poziom), auto w drodze trzyma miejsce w mieście docelowym, a do miasta bez garażu albo bez miejsca nie pojedzie (422 `no_garage`, `garage_full`). **Wraki** kupuje się ze złomowiska do garażu miasta (`POST /cars`, idempotentnie po `request_id`). **Każde miasto ma własny warsztat z własną kolejką i mechanikiem**, który robi zlecenia aut kierowcy jedno po drugim. Ekran **Flota** (druga zakładka garażu, plansza Flota) pokazuje garaże z autami, garaże do kupienia i złomowisko, mapa mówi, ile jest w garażu miasta, i nie pozwala jechać tam, gdzie auto nie ma miejsca. Wszystkie liczby są moje i robocze (pojemność 3, garaż 5 000 $, wraki 1 140 do 1 510 $), a modelu planszy Flota (aktywne sloty, magazyn, zamiana) **nie bierzemy**: jeżdżą wszystkie auta, więc **dochód rośnie z liczbą aut, a symulator ekonomii floty nie modeluje**. To ryzyko dla bilansu, które trzeba sprawdzić przed krokiem 5 (dealer, giełda, wraki) i przed limitem aktywnych aut z VIP-em (F6). Nie ma jeszcze sprzedaży aut ani zakładki Energia.

F2, krok 5 (giełda, dealer i złomowisko, stan auta; opis w `docs/api-runs.md`, `docs/web-run.md` i `content/README.md`): w nawigacji jest **Miasto** zamiast „Sklep”, z zakładkami Sklep, **Giełda** i **Dealer**. Rynek jest rynkiem miasta, w którym stoi auto, którym się gra, a kupione auto staje w garażu tego miasta (potrzebne miejsce, jak przy wraku). **Giełda aut używanych**: cztery ogłoszenia odświeżane co 30 minut i składane od nowa z ziarna kierowcy (zapisuje się tylko inspekcja i kupno), z przebiegiem, wiekiem, **ukrytym stanem** (usterek jest więcej przy większym przebiegu), słowem w ogłoszeniu tylko z grubsza zgodnym z prawdą i ceną ze wzoru z projektu, który odtwarza ogłoszenia i wraki z planszy w granicach 1 do 2%; **inspekcja** za 3% ceny pokazuje stan (u mnie natychmiastowa). **Dealer** sprzedaje nowe auta za pełną cenę od poziomu gracza, a **złomowisko** to najtańsze ogłoszenia (wraki z kroku 4). Doszło siedem aut z Katalogu (Rdzak GT-2, GT-3 i GT-5, Liman 2.0, Kornet V6, Furgon Z-1 i Z-3). **Stan auta** ma na razie jeden skutek: gorszy stan to częstsze awarie (do dziesięciu razy przy stanie 0%); mnożnik statystyk, serwis, naprawa stanu i przebieg liczony przez auto to F3, więc stanu nie da się poprawić. Nowe auta zmieniły wynik symulatora torów: bramka terenu przestała przechodzić, dopóki nie obniżyłem prześwitu GT-2 i GT-3, a **bramki M3 i terenu przechodzą cienko** (0,1% na Wilczej Przełęczy). Rynek jest per kierowca (bez wymiany między graczami), a bilansu giełdy i floty symulator ekonomii nie sprawdza. Dalej: krok 6 (skrzynki), krok 7 (boty).

Samouczek i szybki start (uwagi testera; opis w `docs/api-runs.md`, `docs/web-run.md` i `content/README.md`): **pierwsze auto czeka w garażu na Start w Turnieju**, a samouczek prowadzi tam krok po kroku (nowy krok 2 z 6, podświetlona zakładka Turniej, potem przycisk Start). Auto rusza z rozgrzewką 60 s, więc **pierwsze okrążenie kończy się 15 s po Starcie**. XP za okrążenie jest dziesięć razy większe (20), a poziomy 2–10 to tabela: poziom 2 po 3 okrążeniach (4 min), 5 po 40 (50 min), 10 po 640 (ok. 13 h); od 10 do 20 krzywa idzie po prostej, a od 20 jest krzywą projektu. Skutek: pierwsze trzy auta wymagają teraz gotówki, a nie poziomu, i przychodzą ok. dwa razy szybciej, a reszta drabiny o 10 do 25% wcześniej. Cele w `economy.json` dla L1–L4 zmieniłem na dni z symulatora (czwarte też wypadało poza bramkę); bramka tempa, M3 i spójność przechodzą. Pomysły i uwagi testera (aukcje garaży z losowym autem, sprzedaż i złomowanie aut, autonaprawa z koła fortuny) są zapisane w pamięci projektu i czekają na decyzję.

Sprzedaż aut i magazyny (uwagi testera: „wojny magazynowe”; opis w `docs/api-runs.md`, `docs/web-run.md` i `content/README.md`): **auto sprzedaje się za 70% wartości z chwili zdobycia** (cena z giełdy, od dealera, ze złomowiska albo wartość auta z magazynu), **nie mniej niż złom** (0,25 $/kg), więc kupno i sprzedaż nigdy nie dają zysku. Wartość jest zapisana na aucie (`cars.value_cents`), sprzedane auto zostaje w bazie schowane globalnym zakresem (rozliczenia i księga się do niego odwołują), zarobione do tej chwili jest wypłacone przed sprzedażą, części wracają do inwentarza, a **kierowca zawsze zostaje z jednym autem** i nie sprzeda auta w drodze ani z pracą w warsztacie. Flota ma przycisk „Sprzedaj · 798 $” z pytaniem. **Magazyny** (czwarta zakładka Miasta): mały od poziomu 3 za 1 200 $, średni od 6 za 3 500 $, duży od 9 za 6 000 $, a w środku auto losowane **po stronie serwera** z ziarna HMAC (klucz aplikacji, kierowca, rodzaj, numer otwarcia, więc nie da się go wyłowić), z zapisem każdego losowania w `storage_openings`. Szanse na złom, rupieć, dobre auto i perełkę to 70/25/4,5/0,5% (mały), 35/39/20/6% (średni) i 15/30/40/15% (duży); **15. magazyn bez dobrego auta jest dobry**, a perełka z małego nie jest warta więcej niż 12 000 $. Liczby zatwierdził użytkownik. **Bilans nie jest sprawdzony symulatorem:** oczekiwany zwrot ze sprzedaży to ok. 77 do 79% ceny magazynu, a **małego z gwarancją ok. 92%**, więcej, niż sugerowała propozycja (gwarancja podnosi go o kilkanaście punktów, nie o jeden–dwa).

F2, krok 6 (skrzynki z częściami; opis w `docs/api-runs.md`, `docs/web-run.md` i `content/README.md`): części mają **rzadkość** (Common do Legendary, mnożnik statystyk ×1,00 / 1,10 / 1,22 / 1,38 / 1,60 na to, co część daje; w potoku statystyk, więc rzadsza część daje szybsze okrążenie), a **Sklep sprzedaje skrzynki**: slotowa (kierowca wybiera slot), mieszana (slot jest losowany) i premium (lepsze szanse, od poziomu 6), za gotówkę z gry, z szansami widocznymi przed zakupem (62 / 25 / 10 / 2,7 / 0,3% i 30 / 35 / 24 / 9 / 2%). Skrzynka daje model z puli slotu (części ze sklepu o najwyższym tierze, który poziom gracza otwiera: 1, 3, 6) i kosztuje 1,0×, 1,5× albo 2,0× średniej ceny modeli. **Gwarancje:** co 10. standardowa skrzynka daje Rare lub lepszą, co 40. premium Epic lub lepszą, a co 150. Legendary; liczniki są widoczne w sklepie. Losuje **serwer** z ziarna HMAC (klucz aplikacji, kierowca, grupa, numer otwarcia), każde otwarcie jest zapisane w `crate_openings`, a powtórzone żądanie nie otwiera drugiej skrzynki. Rzadkość jest nazwana i oznaczona kwadratami (nie tylko kolorem) w inwentarzu, w garażu i w warsztacie. Liczby zatwierdził użytkownik. **Bilans nie jest sprawdzony symulatorem ekonomii:** gwarancja zawyża szanse (Rare lub lepsza średnio co 5,8 skrzynki, nie co 7,7), rzadkość przyspiesza okrążenia (Epic w pełnym zestawie to ok. +16 do +36% okrążeń na godzinę), pula modeli jest cienka, a do F3 (afiksy, łączenie, złom) nie da się pozbyć zbędnych części.

Rozszerzenie symulatora ekonomii, etapy 1 do 3 (flota jawna, drabina gry, skrzynki, magazyny i sprzedaż; opis w `content/README.md`). Pomiary pokazały trzy rzeczy. (1) **Gra dawała XP z każdego rozliczenia, także z aut stojących w garażu**, więc zachłanny gracz z 14 wrakami kupował ostatni szczebel w dniu 36 zamiast 76 (A), 98 zamiast 212 (B) i 15 zamiast 37 (C). Zatwierdzona poprawka: **XP gracza dają tylko okrążenia auta granego** (`player.other_cars_xp_ppm` 0, `RunService::experienceForDriver`); pozostałe auta jeżdżą, zarabiają i awansują same, a ekran Flota mówi o tym jedną linią. Dni zakupu L8 to teraz A 69, B 185, C 37 (projekt 76, 212, 37), bramka tempa dla floty gry przechodzi, a cel drugiego auta gracza A to dzień 8 (wrak zwraca się w dobę i kupuje się przed nim). (2) Dochód, który gra naprawdę osiągała (najlepsze auto na najlepszym torze), zgadzał się z kotwicami do szczebla L5 i kończył się na ok. 984 $/h, więc szczebli L6–L8 gra nie osiągała: nagrodę dawał tor, a nie klasa auta (jak w projekcie), cena auta nie wiązała się z dochodem, a paczka miała 10 z 23 aut spalinowych Katalogu. **Zamknięte wariantem A** (2026-10-26): `car.reward_ppm_from_tier` mnoży nagrodę okrążenia według tieru auta (od tieru 5: 1,09, 1,10, 1,35, 1,47, 1,70), paczka ma wszystkie 23 auta spalinowe, a drabina gry mieści się w tolerancji na każdym z 12 poziomów. (3) Skrzynki, magazyny i sprzedaż jako dodatki scenariusza gry: skrzynki przy 10 / 20 / 30% dochodu prawie nie przesuwają dni (długa gra jest związana poziomem, nie pieniędzmi), a gracz zostaje z gotówką i otwiera 28 do 86 skrzynek. **Pierwsza skrzynka od szczebla L4 zwraca się szybciej niż następne auto**, ale bramką jest **cały program 150 skrzynek**, który zwracał się szybciej niż auto tylko na L7, więc ceny skrzynek mają **miękką skalę po tierach** (`shop.crates.tier_price_ppm`: od tieru 8 o 9% więcej; sklep z tierami 1–3 sprzedaje po dawnych cenach) i bramka przechodzi (L7: 148,8 dnia wobec 145,6). Model skrzynek jest tu najsłabszy, bo tabela rzadkości jest zmierzona na częściach T2. Magazyny są loterią bez pętli zysku (średni zwrot 91% dla małego z gwarancją, 76% i 77% dla większych), kosztują netto 1 do 6,7% dochodu auta i prawie nigdy nie dają przeskoku szczebla. Sprzedaż za część zapłaconej ceny jest raportem (artefakt zachłannej polityki). Tabele skrzynek i magazynów są w `content/tables/` i odświeża je `economy:rarity-pace` i `economy:unit-returns`. `economy:simulate --strict-game` kończy się dziś 0. Dalej: etap 4 (Monte Carlo z ziarnem).

Dalej: krok 7 z F2 (boty), a obok przegląd prawny regulaminu i polityki prywatności.
