# Regulamin i polityka prywatności

Teksty są **projektem roboczym**: napisałem je na podstawie tego, co aplikacja naprawdę robi z danymi (kod, migracje, ustawienia), a nie przeglądu prawnego. Nie wolno ich traktować jako gotowych do publikacji, dopóki nie przejrzy ich prawnik. Ten dokument mówi, co jest, jak to zmieniać i o co prawnika zapytać.

## Co jest

- **Strony** `/terms` (regulamin) i `/privacy` (polityka prywatności), otwarte dla wszystkich, w pięciu językach. Każdy język to osobny plik `web/src/legal/content/<język>.ts` z tymi samymi sekcjami, w tej samej kolejności i z tą samą liczbą akapitów; pilnuje tego test (`content.spec.ts`), tak samo jak tego, że żaden język nie ma wpisanej na sztywno liczby 16 ani 90.
- **Linki:** stopka każdej strony, informacja przy tworzeniu kierowcy („Tworząc kierowcę, akceptujesz regulamin i politykę prywatności”) i zgody w oknie „Zapisz swój garaż” (otwierają się w nowej karcie, żeby okno zostało, jak było).
- **Liczby i dane operatora** nie są w tekstach, tylko w ustawieniach API (`GET /meta/legal`), żeby tekst nie rozjechał się z konfiguracją:

| Zmienna (`api/.env`) | Znaczenie |
|---|---|
| `LEGAL_MIN_AGE` | Minimalny wiek, który kierowca potwierdza. **Roboczo 16** (najwyższy próg zgody cyfrowej w RODO), do potwierdzenia po przeglądzie prawnym. Trafia do obu tekstów i do pola wyboru w oknie. |
| `LEGAL_VERSION` | Dzień ostatniej zmiany tekstów (`2026-10-23`). Pokazuje się na stronach i zapisuje przy pierwszej zgodzie konta (`users.terms_version`). |
| `LEGAL_OPERATOR_NAME`, `LEGAL_OPERATOR_ADDRESS`, `LEGAL_CONTACT_EMAIL` | Kto prowadzi grę i gdzie pisać. Strony pokazują je na górze. **Puste pole strona pokazuje na czerwono jako „jeszcze nie podano”**, więc niedokończona konfiguracja jest widoczna. |

Czas, po którym wygasa konto gościa (`ride.guest_expiry_days`, 90) też idzie z serwera do tekstów.

## Jak zmienić teksty

1. Zmień ten sam akapit we **wszystkich pięciu** plikach (test sprawdzi strukturę, ale nie sens tłumaczenia).
2. Podnieś `LEGAL_VERSION` (do dnia zmiany) w `api/.env` i, jeśli zmieniasz domyślną wartość, w `api/config/ride.php`.
3. Konta, które już się zgodziły, mają zapisaną starą wersję. **Aplikacja nie wymusza ponownej zgody po zmianie wersji**: to decyzja do podjęcia z prawnikiem (kiedy zmiana jest istotna na tyle, że trzeba zapytać jeszcze raz).

Liczby w tekstach to `{minAge}` i `{guestDays}`. Podstawia je `fillLegal`, a nie vue-i18n, bo kompilator komunikatów traktuje znaki takie jak `@` i `|` jak składnię, a tekst prawny powinien móc je zawierać. Uwaga: polskie „po {guestDays} dniach” zakłada, że to nie jest jeden dzień.

## Co aplikacja robi z danymi

To jest podstawa polityki prywatności i lista, od której prawnik powinien zacząć.

| Dane | Gdzie | Po co | Jak długo |
|---|---|---|---|
| Imię kierowcy, język | `users` | konto, wyświetlanie | do usunięcia konta; gość: `guest_expiry_days` dni bez aktywności |
| Postęp: biegi, portfel, księga, rozliczenia | `race_runs`, `wallets`, `ledger_entries`, `settlements` | gra | jak konto (kasowane kaskadowo) |
| Token urządzenia: skrót, nazwa (np. `google-login`), ostatnie użycie | `personal_access_tokens` | uwierzytelnienie urządzenia | do wylogowania lub usunięcia konta |
| Czas ostatniej aktywności | `users.last_seen_at` | wygaszanie gości | jak konto |
| Kod odzyskiwania: tylko skrót i data | `users` | odzyskanie konta | jak konto |
| E-mail (małymi literami), czas potwierdzenia | `users`, `account_identities` | logowanie linkiem, kontakt | jak konto |
| Identyfikator u Google/Facebook/Apple i adres, który zgłosili | `account_identities` | logowanie | jak konto |
| Moment zgody na regulamin i potwierdzenia wieku, wersja regulaminu | `users` | wykazanie zgody | jak konto |
| Sekrety jednorazowe: skrót, e-mail, cel, czas | `account_tokens` | linki i logowanie przez dostawcę | ważne 30 min (link), 10 min (stan), 5 min (bilet); sprzątane co godzinę, gdy wygasły albo zostały użyte ponad dobę temu |
| Liczniki limitów: adres IP, skrót e-maila | cache | ograniczanie nadużyć | najwyżej godzina |
| Kolejka maili: adres i treść | `jobs`, `failed_jobs` | wysyłka | do wysłania; **nieudane zadania zostają w `failed_jobs`, dopóki ktoś ich nie usunie** (`queue:prune-failed`), więc to trzeba ustawić |
| Dzienniki serwera i hostingu | poza aplikacją | bezpieczeństwo | **do ustalenia po wyborze hostingu**; tekst obiecuje tylko „tak długo, jak potrzebuje tego bezpieczeństwo” |

Aplikacja **nie używa** cookies (token, język i kilka znaczników są w `localStorage`), reklam, analityki ani śledzenia, i nie sprzedaje danych. Tekst polityki to mówi, więc dodanie czegokolwiek z tej listy wymaga zmiany tekstu.

## Pytania do przeglądu prawnego

1. **Próg wieku.** Czy 16 lat jest właściwe? RODO (art. 8) pozwala państwom ustalić 13–16 lat dla zgody dziecka na usługi społeczeństwa informacyjnego (Polska i Niemcy: 16, Francja: 15). W Brazylii dane dzieci i nastolatków podlegają LGPD. Dostawcy logowania mają własne limity wieku. Do tego trzeba zdecydować, czy wystarczy pole wyboru, czy potrzebna jest zgoda opiekuna poniżej progu.
2. **Gość bez zgody.** Konto gościa powstaje od razu i nie ma pola wyboru: jest tylko informacja z linkami przy tworzeniu kierowcy, a zgody (z potwierdzeniem wieku) składa się dopiero przy zabezpieczaniu konta. Czy to wystarcza, czy pole wyboru ma być już przy tworzeniu? Gość podaje tylko imię kierowcy.
3. **Prawa osób, których dane dotyczą.** Polityka mówi, że dostęp, sprostowanie, usunięcie i przeniesienie obsługuje się mailem. **Aplikacja nie ma narzędzia do usunięcia ani wyeksportowania konta zabezpieczonego** (jest tylko wygaszanie gości); operator musiałby robić to ręcznie w bazie. To luka do zamknięcia przed publikacją.
4. **Administrator i umowy.** Kto jest administratorem (dane w `LEGAL_*`), rejestr czynności przetwarzania, umowy powierzenia z hostingiem i dostawcą poczty, podstawy prawne (dobrałem art. 6 ust. 1 lit. b i f).
5. **Przekazywanie poza EOG.** Tekst mówi ogólnie o „zabezpieczeniach wymaganych przez prawo”. Trzeba je nazwać, gdy będzie wybrany hosting i poczta.
6. **Odpowiedzialność, prawo właściwe, sąd.** Celowo ich nie wybrałem: regulamin mówi tylko, że bezwzględne przepisy konsumenckie kraju użytkownika nadal obowiązują, a odpowiedzialność ogranicza „w zakresie dozwolonym przez prawo”. To powinien sformułować prawnik.
7. **Zmiany regulaminu** i ponowna zgoda (patrz wyżej) oraz zapis, że dalsze korzystanie oznacza przyjęcie nowej wersji.
8. **Płatności.** Dziś gra jest darmowa i tak mówi regulamin. Gdy pojawią się zakupy, potrzebne będą osobne warunki (prawo konsumenckie, odstąpienie od umowy, ceny).
9. **Dostawcy logowania wymagają adresów tych stron.** W konsoli Google (ekran zgody OAuth), w ustawieniach aplikacji Facebooka i przy Services ID Apple trzeba podać adres polityki prywatności (`WEB_URL/privacy`), a Google i Facebook także regulaminu (`WEB_URL/terms`). Bez tego aplikacja nie przejdzie weryfikacji.

## Tłumaczenia

Wszystkie teksty (interfejs, komunikaty API, maile, regulamin i polityka) powstały jako angielski i polski wzorzec, a francuskie, niemieckie i brazylijskie są moim tłumaczeniem. Nie są sprawdzone przez osoby, dla których te języki są ojczyste. Testy pilnują tylko struktury (klucze, symbole zastępcze), nie jakości.
