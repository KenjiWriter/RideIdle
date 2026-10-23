# Ekrany biegu w aplikacji web

Nowy kierowca przechodzi: landing → imię → **wybór wraku** (krok 2 z 2) → **garaż**, czyli ekran biegu. API, z którego korzystają, opisuje `docs/api-runs.md`.

## Wybór wraku (`/choose`)

Trzy karty z `GET /starter-cars`, od najtańszego, który jest polecany na start. Karta ma napęd, silnik i moc, opis oraz to, co auto robi na torze startowym: czas okrążenia, nagrodę (z groszami), zużycie paliwa, prędkość maksymalną, masę, bak i ładunek. Liczby liczy serwer tym samym kodem co zamrożenie biegu, więc ekran pokazuje to, co auto naprawdę pojedzie.

Wybór startuje bieg na torze startowym (`POST /runs`) i otwiera garaż. Kierowca, który ma już bieg, trafia od razu do garażu, także gdy bieg został założony w innej karcie (409 `run_exists`). Nieudane wczytanie można powtórzyć, nieudany start zostawia karty na ekranie.

## Garaż (`/garage`)

Karta biegu: auto i tor, status (jedzie, brak paliwa, awaria), gotówka, doświadczenie, okrążenia, nagroda i czas okrążenia. Pod nią licznik paliwa z zasięgiem („wystarczy na 28 okrążeń, ok. 31 min 16 s”), a po postoju baner z powodem. Kierowca bez biegu jest odsyłany do wyboru wraku. Okrążenia liczą się w formach liczby mnogiej każdego języka (polski ma trzy: okrążenie, okrążenia, okrążeń).

### Aktualność

Otwarty ekran pyta o bieg co 15 s, gdy karta jest widoczna, jeszcze raz w chwili powrotu do karty, nigdy dwa razy naraz i wcale, gdy karta jest ukryta. Serwer rozlicza wszystko w chwili pytania, więc ukryta karta nic nie traci.

### Podsumowanie nieobecności

Przeglądarka pamięta, którą wersję biegu kierowca widział ostatnio (`ride.run.seen`: numer biegu i `state_version`). Przy następnym wejściu pyta o wszystko od tej wersji (`since_version`). Nieobecność dłuższa niż dwie minuty staje się kartą „Pod Twoją nieobecność”: czas, okrążenia, zarobek, doświadczenie i powód postoju. Karta zostaje, dopóki kierowca jej nie zatwierdzi, także po odświeżeniu strony, i dopiero wtedy wszystko do tej chwili liczy się jako widziane. Krótka nieobecność, na przykład przeładowanie strony, nie dostaje karty. Wylogowanie zapomina bieg i widzianą wersję, żeby następny kierowca na tym urządzeniu ich nie odziedziczył.

### Tankowanie

Przycisk „Zatankuj do pełna · $118,11” podaje koszt przed naciśnięciem, zaokrąglony w górę do centa jak na serwerze. Gdy gotówki nie starcza na pełny bak, drugi przycisk oferuje to, na co kierowcę stać (całe litry), więc nie zostaje z pustym bakiem i nieaktywnym przyciskiem. Kto nie kupi nawet litra, dostaje o tym informację.

### Naprawa

Po awarii pojawia się przycisk „Napraw · $30,00”. Kierowca, którego nie stać, widzi komunikat zamiast przycisku, który by zawiódł. Wynik zostaje na ekranie po zniknięciu przycisku: auto znów jedzie albo, gdy bak jest pusty, jest naprawione i czeka na paliwo.

### Holowanie

Stojące auto nie zarabia, więc kierowca, którego nie stać na jego uruchomienie (naprawa po awarii, paliwo na okrążenie), nigdy by nie zdobył pieniędzy. Dlatego, gdy serwer mówi `tow.available`, pod przyciskiem naprawy jest wyjście: „Holuj auto · za darmo”. Opis mówi, co to kosztuje: nie pieniądze, tylko czas, bo auto czeka 5 minut, zanim znów pojedzie, a z pustym bakiem rusza z odrobiną paliwa. Czekanie kosztuje więcej utraconego zarobku niż naprawa z paliwem, więc nie opłaca się go wybierać, gdy można zapłacić.

Ekran nie decyduje sam, czy holowanie przysługuje: czyta `tow.available`, który serwer liczy tą samą regułą co sama akcja. Holowane auto ma `status` `running` i start w przyszłości, więc garaż pokazuje je jako „Holowane” z odliczaniem do ruszenia, a po upływie czasu samo wraca do jazdy.

### Wspólne zasady akcji

- Każda akcja płatna ma `request_id`, który zostaje ten sam, dopóki nie ma odpowiedzi, więc ponowne naciśnięcie po zgubionej odpowiedzi nie obciąży dwa razy. Po minucie identyfikator jest porzucany, bo wtedy kierowca ma na myśli nowe tankowanie. Tam, gdzie nie ma `crypto.randomUUID` (zwykłe http w sieci lokalnej), identyfikator powstaje z losowych bajtów.
- Drugie kliknięcie w trakcie pierwszego nic nie robi.
- Wynik akcji znika po ośmiu sekundach, żeby nie wprowadzał w błąd, gdy kierowca wróci do ekranu po godzinie.
- Błędy i wyniki są ogłaszane czytnikom ekranu (`role="status"` i `role="alert"`). Odmowę z powodu braku gotówki serwer podaje w języku kierowcy.
- Token, którego serwer nie zna (401), kończy sesję i odsyła na stronę startową.

## Sprawdzenie

Testy komponentów i sklepów: `cd web && npm test`. Dodatkowo ścieżka została sprawdzona ręcznie w przeglądarce na lokalnym API (tymczasowa baza SQLite): wybór wraku na telefonie po polsku i na szerokim ekranie po angielsku, start, nieobecność dwóch godzin (28 okrążeń, 434 $, 700 XP, koniec paliwa), tankowanie, awaria wykryta przez odpytywanie bez odświeżania strony i naprawa. To sprawdzenie ujawniło jedną wadę, poprawioną osobnym commitem: wynik tankowania zostawał pod licznikiem bez końca.

Holowanie sprawdzone tak samo, w najgorszym przypadku: awaria na pierwszym okrążeniu, 15,50 $ w kieszeni przy naprawie za 30 $. Przycisk naprawy jest wtedy nieaktywny, a pod nim pojawia się holowanie; po kliknięciu auto ma status „Holowane”, gotówka się nie zmienia, a po upływie czasu auto samo jedzie dalej i zarabia. Ujawniło to wadę kosmetyczną (puste kontrolki zostawiały przerwy w karcie), poprawioną osobnym commitem.
