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

## Sklep i warsztat (`/shop`, `/workshop`)

Nad ekranami gry jest nawigacja `GameNav` (Garaż, Warsztat, Sklep). API i zasady są w `docs/api-runs.md` (część „Garaż, sklep i warsztat”), a stan trzyma sklep Pinia `garage`.

**Sklep.** Lista części z reguł: nazwa w języku kierowcy, gniazdo, tier, co część robi (`PartStats`: „Moc +2%”, „Przyczepność 100”), cena i przycisk „Kup”. Kupuje się jedną część naraz. Przycisk jest nieaktywny, gdy brakuje gotówki, a pod częścią, której auto nie zamontuje z powodu poziomu, jest „Wymaga poziomu auta 3” i poziom auta kierowcy (część i tak można kupić na zapas). Po zakupie komunikat mówi, co dodano, i prowadzi do warsztatu; to, czego odmawia serwer, pokazuje się jego własnym tekstem w języku kierowcy. Zakup ma `request_id`, który zostaje ten sam, dopóki nie ma odpowiedzi (jak przy tankowaniu), więc powtórzone kliknięcie po zgubionej odpowiedzi kupuje raz.

**Warsztat.** Kolejka mechanika (zlecenie w toku z odliczaniem, reszta „Czeka”), auto z poziomem i statystykami razem z tym, co zmieniają części („Przyczepność 100 +10”), dziewięć gniazd (z przyciskiem „Zdejmij” przy zamontowanej części; silnik jest na razie częścią nadwozia) i inwentarz z przyciskiem „Załóż”. Część, która już czeka w kolejce, ma przyciski nieaktywne. Gdy auto stoi w warsztacie, jest o tym komunikat, a ekran garażu pokazuje status „W warsztacie” i nie oferuje naprawy ani holowania.

**Czas.** Zlecenia mają czasy w zegarze serwera, więc odliczanie liczy się z przesunięciem między zegarem serwera a urządzenia z ostatniej odpowiedzi, a nie z samego zegara urządzenia. Serwer wykonuje zlecenia dopiero wtedy, gdy ktoś go pyta, dlatego otwarty warsztat pyta o garaż, gdy czas zlecenia minął (najwyżej co 3 s, żeby spóźnione zlecenie nie wywołało lawiny zapytań) oraz co 15 s, gdy karta jest widoczna.

## Wygląd (skórka z canvy)

Ekrany mają wygląd z płótna projektowego (`ride.css`, „skórka gry”), przeniesiony do `web/src/style.css` jako tokeny Tailwinda i klasy (`.btn`, `.card`, `.chip`, `.bar`, `.fuelbar`, `.tile`, `.st`, `.hud`, `.navb`), więc dostają go wszystkie ekrany, także starsze. Ciemne stalowe panele ze skosem, bursztyn dla tego, co można nacisnąć, cyjan dla tego, co trwa, Barlow Condensed w tytułach i przyciskach, JetBrains Mono w liczbach. **Fonty i zdjęcia aut są plikami aplikacji** (`web/src/assets/fonts`, `web/src/assets/art`; fonty na licencji OFL, tylko podzbiory latin i latin-ext, bo polskie litery są w drugim), a nie zapytaniem do Google: zewnętrzny font mówiłby Google'owi, kto czyta stronę, wbrew polityce prywatności.

Ekrany gry (`GameShell`) mają u góry HUD (płytka z poziomem, XP w poziomie na pasku, gotówka z monetą), a na dole nawigację z ikonami z canvy. Poziom kierowcy liczy krzywa z projektu (`45 · n^2,3` XP łącznie do poziomu n, `features/player/level.ts`); jest tylko pokazywany, serwer niczego jeszcze po nim nie bramkuje. Garaż pokazuje auto ze zdjęciem, statystyki na paskach (pasek jest pełny przy 1,6 raza wartości odniesienia toru, czyli przy limicie wzoru okrążenia) z tym, co dodają części, dziewięć gniazd jako kafelki prowadzące do warsztatu i bak w dziesięciu komórkach. **Jeszcze nie z canvy:** strona startowa, tworzenie kierowcy, wybór wraku, mapa, turnieje i samouczek (ich makiety są na płótnie: Landing, Kreator, Wybór, Krok, Samouczek) oraz zegar gry i pogoda w HUD (bez nich HUD pokazuje imię kierowcy).

## Sprawdzenie

Testy komponentów i sklepów: `cd web && npm test`. Dodatkowo ścieżka została sprawdzona ręcznie w przeglądarce na lokalnym API (tymczasowa baza SQLite): wybór wraku na telefonie po polsku i na szerokim ekranie po angielsku, start, nieobecność dwóch godzin (28 okrążeń, 434 $, 700 XP, koniec paliwa), tankowanie, awaria wykryta przez odpytywanie bez odświeżania strony i naprawa. To sprawdzenie ujawniło jedną wadę, poprawioną osobnym commitem: wynik tankowania zostawał pod licznikiem bez końca.

Holowanie sprawdzone tak samo, w najgorszym przypadku: awaria na pierwszym okrążeniu, 15,50 $ w kieszeni przy naprawie za 30 $. Przycisk naprawy jest wtedy nieaktywny, a pod nim pojawia się holowanie; po kliknięciu auto ma status „Holowane”, gotówka się nie zmienia, a po upływie czasu auto samo jedzie dalej i zarabia. Ujawniło to wadę kosmetyczną (puste kontrolki zostawiały przerwy w karcie), poprawioną osobnym commitem.

Od tego sprawdzenia ścieżka sklepu i warsztatu: na telefonie (375 px) po polsku kupiłem opony uliczne T2 za 570 $ z kieszeni 3000 $, założyłem je w warsztacie (auto stanęło, a kolejka pokazała „Zakładanie: Opony uliczne, W toku: Zostało 3 min 51 s”), skróciłem czas zlecenia w bazie i warsztat sam odświeżył się po jego końcu: kolejka pusta, przyczepność, prowadzenie i hamowanie +10, a czas okrążenia w garażu spadł z 1:15.0 do 1:09.2 i auto znów jechało. To sprawdzenie wykryło dwie wady poprawione osobnym commitem: komunikat „Opony uliczne jest w Twoim inwentarzu” (część w liczbie mnogiej) i dziewięć gniazd w jednej kolumnie na telefonie.
