# Ekrany biegu w aplikacji web

Nowy kierowca przechodzi: landing → imię → **historia** → **wybór wraku** (krok 2 z 2) → **garaż**, czyli ekran biegu, na którym prowadzi go samouczek. API, z którego korzystają, opisuje `docs/api-runs.md`.

## Wybór wraku (`/choose`)

Trzy karty z `GET /starter-cars`, od najtańszego, który jest polecany na start. Karta jest taka jak na planszy Wybór: zdjęcie auta po lewej, nazwa, znacznik napędu (FWD, RWD, AWD; pełna nazwa w podpowiedzi), dwie płytki z silnikiem i mocą (pojemność zawsze z jednym miejscem po przecinku, „R4 2,0”), opis z zalecaniem i przycisk „Wybierz”. Polecany wrak ma kartę z bursztynową obwódką i przycisk w kolorze głównym, pozostałe przycisk szary; zamiast osobnej plakietki „Polecany na start” mówi o tym sam opis wraku. Przycisk pokazuje samo „Wybierz”, a czytnik ekranu czyta „Wybierz: nazwa”, żeby trzy przyciski różniły się nazwą.

Plansza nie ma czasu okrążenia, nagrody, zużycia paliwa, prędkości, masy, baku ani ładunku, które karta pokazywała wcześniej, więc ich nie ma: API nadal je zwraca (liczy je tym samym kodem co zamrożenie biegu), a po wyborze kierowca widzi je w garażu. Wrak jest ekranem **kroku 2 z 2**: u góry nazwa gry, „Krok 2 z 2” i pełny pasek postępu (`StepShell`).

Wybór startuje bieg na torze startowym (`POST /runs`) i otwiera garaż. Kierowca, który ma już bieg, trafia od razu do garażu, także gdy bieg został założony w innej karcie (409 `run_exists`). Nieudane wczytanie można powtórzyć, nieudany start zostawia karty na ekranie.

## Strona startowa (`/`) i tworzenie kierowcy (`/create`)

**Strona startowa** jest taka jak plansza Landing: pasek z nazwą gry i przyciskiem „Mam już konto” (prowadzi do `/recover`; kierowca, który jest zalogowany, go nie widzi), nagłówek „Dziupla, która ściga się, gdy Cię nie ma” z żółto-czarnym paskiem ostrzegawczym, duży przycisk „Utwórz kierowcę” z dopiskiem „Bez e-maila. Konto zabezpieczysz później.”, zdjęcie dziupli i trzy płaskie karty (Dobór, Rozwój, Idle). Kierowca, który jest zalogowany, ma w miejscu przycisku „Kontynuuj jako {imię}”. Komunikat o usuniętym koncie jest kartą z obwódką pod paskiem. Na telefonie nagłówek ma 44 px zamiast 76 px, a obraz 220 px zamiast 340 px.

**Tworzenie kierowcy** jest krokiem 1 z 2 według planszy Kreator: pytanie „Jak masz na imię, kierowco?”, jedno pole (56 px, 20 px), znacznik pod nim, karta z zapewnieniem, że nie trzeba konta ani e-maila, i przycisk „Dalej” z odnośnikiem „Wróć”. Dwa odstępstwa od planszy:

- Plansza ma znacznik „Nazwa wolna”, ale żaden punkt API nie sprawdza, czy nazwa jest wolna (zajętość sprawdza dopiero `POST /guests` i odpowiada 422). Napis „Nazwa wolna” byłby więc nieprawdą, dlatego znacznik mówi „Nazwa poprawna” i pojawia się, gdy nazwa ma dobry kształt (3–16 znaków: litery, cyfry, `_`, `-`); gdy serwer odpowie, że nazwa jest zajęta, znika, a pod polem jest komunikat. Sprawdzanie zajętości w trakcie pisania wymagałoby punktu API, który odpowiada „wolna” lub „zajęta” na pytanie anonimowego klienta, czyli wyliczania nazw (do rozważenia osobno).
- Zgoda na regulamin i politykę prywatności (zdanie z odnośnikami, `create.consent`) jest drugim akapitem tej samej karty, bo plansza jej nie przewiduje, a bez niej tworzenie kierowcy nie mówiłoby, co oznacza.

Strony z własnym paskiem u góry (`meta.bare` w trasie: `/`, `/create`, `/choose`) nie mają nagłówka aplikacji. Wybór języka jest wtedy w stopce, po lewej od odnośników do regulaminu i polityki; na pozostałych ekranach jest w nagłówku, razem z nazwą gry w kroju tytułów.

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

**Sklep.** Lista części z reguł: nazwa w języku kierowcy, gniazdo, tier, co część robi (`PartStats`: „Moc +2%”, „Przyczepność 100”), cena i przycisk „Kup”. Kupuje się jedną część naraz. Przycisk jest nieaktywny, gdy brakuje gotówki, a pod częścią, której auto nie zamontuje z powodu poziomu, jest „Wymaga poziomu auta 3. Twoje auto ma poziom 2. Zdobywasz XP, jeżdżąc tym autem.” (część i tak można kupić na zapas). Po zakupie komunikat mówi, co dodano, i prowadzi do warsztatu; to, czego odmawia serwer, pokazuje się jego własnym tekstem w języku kierowcy. Zakup ma `request_id`, który zostaje ten sam, dopóki nie ma odpowiedzi (jak przy tankowaniu), więc powtórzone kliknięcie po zgubionej odpowiedzi kupuje raz.

**Warsztat.** Kolejka mechanika (zlecenie w toku z odliczaniem, reszta „Czeka”), auto z poziomem i statystykami razem z tym, co zmieniają części („Przyczepność 100 +10”), dziewięć gniazd (z przyciskiem „Zdejmij” przy zamontowanej części; silnik jest na razie częścią nadwozia) i inwentarz z przyciskiem „Załóż”. Część, która już czeka w kolejce, ma przyciski nieaktywne. Gdy auto stoi w warsztacie, jest o tym komunikat, a ekran garażu pokazuje status „W warsztacie” i nie oferuje naprawy ani holowania.

**Opony i terenowość.** Garaż i warsztat rysują **szósty pasek, „Terenowość”** (jak dobrze auto radzi sobie poza drogą; hatchback ma jej mało, pickup 4x4 dużo), na tej samej skali ze stałego sufitu co reszta. Część, która jest oponą (w sklepie, w warsztacie i w inwentarzu), pod swoimi statystykami wymienia **mnożnik przyczepności na każdej nawierzchni** („Sucho ×1,00 · Mokro ×0,85 · Żwir ×0,60 · Błoto ×0,45 · Śnieg ×0,40”), a nawierzchnie, na których opona jest co najmniej tak dobra jak uliczna na suchym asfalcie, są jaśniejsze, żeby było widać, na czym opona jest dobra. Liczby przychodzą z serwera (`surfaces`), więc ekran nie zna macierzy.

**Anulowanie zlecenia.** Przycisk „Anuluj” jest przy zleceniu w toku (w karcie mechanika) i przy każdym, które czeka. Zlecenie, które czeka i nic za sobą nie ciągnie, anuluje się jednym kliknięciem, bo nic się z nim nie traci. Zlecenie w toku i zdjęcie, za którym czeka założenie nowej części, pytają najpierw („Anulować to zlecenie?”) i mówią, co przepada: praca, która już trwa (część zostaje tam, gdzie była), i założenie części, które odpada razem ze zdjęciem. Po anulowaniu komunikat mówi, co zostało anulowane („Zlecenie anulowane. Odpadło też zakładanie części: Opony uliczne.”), a zlecenia za nim są takie, jakie podał serwer: przesunięte do przodu. Gdy serwer odpowie, że zlecenia nie ma albo jest już wykonane (kolejka na ekranie była nieaktualna), pokazuje się jego tekst i garaż jest pytany od nowa. Przyciski są nieaktywne, gdy trwa inna akcja. Plansza Warsztat nie ma ani tego przycisku, ani pytania: wyglądają jak reszta ekranu, a ich układ jest moją decyzją (zob. `docs/api-runs.md`, reguły anulowania).

**Czas.** Zlecenia mają czasy w zegarze serwera, więc odliczanie liczy się z przesunięciem między zegarem serwera a urządzenia z ostatniej odpowiedzi, a nie z samego zegara urządzenia. Serwer wykonuje zlecenia dopiero wtedy, gdy ktoś go pyta, dlatego otwarty warsztat pyta o garaż, gdy czas zlecenia minął (najwyżej co 3 s, żeby spóźnione zlecenie nie wywołało lawiny zapytań) oraz co 15 s, gdy karta jest widoczna.

## Turniej (`/race`)

Ekran Turniej to plansza Wyscig: wyścig takim, jakim jest teraz. Wejście jest w nawigacji (Garaż, **Turniej**, Warsztat, Sklep). Ma tytuł z nazwą toru i podpisem „Okrążenie 4 · Rdzak GT-4 · sucho”, znacznik **NA ŻYWO** (cyjan; gdy auto stoi, jest tam jego stan na czerwono albo bursztynowo), pasek pogody, **pierścień okrążenia**, tablicę, bak z tankowaniem i, gdy auto stoi, to samo co w garażu: komunikat, naprawę, holowanie i jego czekanie (wspólny składnik `RunAlerts`).

- **Pierścień** pokazuje, ile zostało do końca okrążenia w toku (`0:41 DO KOŃCA`), według zegara serwera, odświeżany co 250 ms. Gdy okrążenie mija, ekran po chwili pyta serwer, ile zapłaciło, więc gotówka i licznik okrążeń zmieniają się w tym samym momencie, a nie przy następnym odpytaniu (co 15 s). Do czasu odpowiedzi pierścień kręci się dalej z tą samą długością okrążenia, a odpowiedź go prostuje (nowa pogoda, pusty bak). Auto, które stoi, ma w pierścieniu kreskę i swój stan, a czekające na holowanie odlicza czekanie.
- **Pasek pogody** mówi, jaka jest pogoda teraz, za ile się zmienia i jaka będzie („Teraz: sucho. Za 12 min 30 s: deszcz.”). Gdy pogoda zmienia czas okrążenia, dodaje, ile okrążenie trwa i płaci w tej pogodzie, i ile w suchym. Efektem pogody jest to, co liczą reguły: oponami nie da się jej jeszcze zmieniać, więc planszowego „Zmień opony” nie ma.
- Wiersze obok pierścienia: okrążenie (numer w toku albo liczba ukończonych), czas okrążenia i zysk na okrążenie, a pod nimi zysk, XP gracza i XP auta za okrążenie w tej pogodzie (XP auta jest w każdej pogodzie takie samo).

Czego z planszy **nie ma**, bo nie ma jeszcze, na czym to oprzeć: turnieju i rund („Runda 3/10”: bieg jedzie, póki jest paliwo), **pozycji** (P3/8) i rywali na tablicy (tablica ma jeden wiersz, z kierowcą, i mówi, że boty i duchy dojdą później: boty to F3, duchy F4), przycisku „Zmień turniej” (jeden tor, mapa to F2), przełącznika auto-powtarzania (auto zawsze jedzie do końca paliwa), zakładek Turnieje i Ranking oraz zegara gry i pogody w HUD. Tytuł „Turniej” w nawigacji jest z planszy: po F6, gdy będą turnieje z drabinką, ten ekran będzie ich zakładką „Wyścig”.

## Mapa (`/map`)

Ekran Mapa (plansza Mapa; w nawigacji jest pierwszy: Mapa, Garaż, Turniej, Warsztat, Sklep) rysuje pięć miast i drogi między nimi na planszy z projektu (ciemne drogi z kreskowaną osią), z przyciskami-węzłami: dom dla miasta startowego, flaga dla pozostałych, kłódka i przygaszenie dla zamkniętych, bursztynowa obwódka dla miasta, w którym jest auto, i turkusowy pierścień dla wybranego. Pod nazwą miasta jest „LV 3 · Żwir”. Kółko auta stoi w jego mieście, a w czasie podróży jedzie po prostej między miastami (drogi przez inne miasto nie rysuje: prototyp).

**Karta miasta** (domyślnie tego, w którym jest auto) ma nazwę, nazwę toru, znacznik „Tu jesteś” albo „Cel podróży”, chipy (nawierzchnia, światła, poziom), „Czego wymaga tor” jako paski wag statystyk (skalowane do największej), „Dopasowanie” auta w procentach (z podpowiedzią „Zmień opony albo auto” poniżej 80%), koszt drogi („38 km · 26 min 03 s · 2,3 l paliwa”) i przycisk **„Jedź tutaj”**. Zamknięte miasto mówi, czego brakuje („Wymaga poziomu gracza 12 (masz 3). Zdobywasz XP, jeżdżąc.”), a przycisk jest nieaktywny; kierowca bez auta dostaje „Najpierw wybierz auto…”. To, czego odmówi serwer (paliwo, auto, które stoi), pokazuje się jego tekstem, a mapa jest pytana od nowa.

**W drodze**: u góry baner „W drodze: Złomowo → Kruszyn” z paskiem i czasem dojazdu odliczanym zegarem serwera. Otwarta mapa pyta o podróż co 15 s i wtedy, gdy czas dojazdu minął (najwyżej co 3 s), jak warsztat. Auto w drodze ma na ekranach garażu i Turniej status „W drodze” (amber) i baner „Auto jedzie do miasta Kruszyn, dojazd za 1 min 30 s. W drodze nic nie zarabia.”, odliczany na żywo.

## Czas gry, prognoza i noc

**HUD** pokazuje czas gry i pogodę tam, gdzie jedzie auto, jak plansza: „11:20 · sucho” w miejscu, gdzie była nazwa kierowcy (nazwa zostaje w tekście dla czytników ekranu), a po najechaniu „Czas w grze: 11:20, Dzień”. Godzinę liczy klient z zegara serwera i z `clock` z odpowiedzi biegu (trzy godziny realne to doba), odświeża ją co 2 s (minuta gry to 7,5 s) i pokazuje także bez auta, bo wzór jest stały; pogoda jest dopiero z biegu.

**Ekran Turniej** ma pod paskiem pogody kartę **Prognoza**: następne odcinki pogody, półtorej godziny naprzód, każdy jako „Za 35 min 00 s · deszcz · okrążenie 1:24.0” (czas okrążenia w tej pogodzie, z zamrożonej konfiguracji). Gdy któryś z nadchodzących odcinków wydłuża okrążenie o co najmniej 8% względem tego, które się jedzie, pod listą jest zdanie „Ta pogoda spowalnia auto o 12%. Inne opony mogą pomóc, zajrzyj do warsztatu.” (podpowiedź nie sprawdza inwentarza, tylko odsyła do warsztatu). Na torze bez świateł ekran mówi też o nocy: „Noc: okrążenia na tym torze są o 3% dłuższe, bo nie ma świateł.” albo, gdy noc zaczyna się w ciągu prognozy, „Noc za 3 min 45 s: …”. Pogoda „śnieg” ma nazwę we wszystkich językach.

## Historia i samouczek

**Historia** (`/story`) leży między imieniem a wyborem wraku (kreator → historia → wybór wraku) i jest taka jak storyboard z planszy Historia: cztery sceny jednego zdjęcia (dziupla nocą, trzy wraki, Kuba wychodzi, szyld z imieniem kierowcy), kamera, która się po nim porusza (6, 6, 7 i 5 s), słowa Kuby pod obrazem i jego pierwszy SMS w scenie 4 (numer +48 555 010 112). Dotknięcie obrazu przewija, „Pomiń” jest po 3 s, a przycisk „Wybierz wraka” po 2 s ostatniej sceny; ostatnia scena nie kończy się sama. Kierowca, który prosi system o mniej ruchu (`prefers-reduced-motion`), ma kamerę nieruchomą i nic nie przewija się samo (brak limitów czasu na czytanie), a „Pomiń” i wybór są od razu. Tekst jest zawsze na ekranie. Pominięte na planszy: dźwięk (brak plików).

**Samouczek** to nakładka na garażu (`TutorialOverlay`), jak na planszy Krok: ekran jest przyciemniony, **jedyny aktywny element jest podświetlony**, a z dołu wysuwa się stary telefon z SMS-ami od Kuby, przyciskiem odpowiedzi („OK, tankuję”), przyciskiem „Kto to?” (Kuba odpowiada „Nie pytaj. — K.”) i numerem kroku („Krok 4/5”; licznik rośnie razem z krokami z danych, a nie jest sztywnym 20). „Pomiń” stoi tuż pod HUD-em i **pyta o potwierdzenie** (kierowca traci nagrody kroków, które go jeszcze czekają).

- Podświetlenie to otwór w warstwie przyciemnienia (`clip-path`), więc dotknięcie poza nim jest zatrzymane, a dotknięcie w nim dociera do przycisku. Miejsce elementu jest mierzone co 250 ms (strona się wczytuje i przesuwa), a element, na który wskazuje krok, jest przewijany pod HUD i nad telefon raz, gdy zostanie znaleziony. Elementy oznaczone są `data-tutorial` (`laps`, `cash`, `refuel`, `repair`).
- Po odpowiedzi na SMS-a telefon i przyciemnienie znikają, a pierścień na elemencie zostaje (miękki), dopóki kierowca nie zrobi tego, o co Kuba prosił.
- Garaż pyta o samouczek przy otwarciu i za każdym razem, gdy zmienia się wersja biegu (każde rozliczenie, tankowanie, naprawa), więc krok kończy się, gdy tylko jest zrobiony. Wypłata kroku jest powiedziana przez chwilę w garażu („K. przesłał Ci 200,00 $.”), a gotówka w HUD odświeża się. Samouczek nie jest powodem błędu: gdy go nie da się wczytać, garaż jest garażem.
- Okno „Zapisz swój garaż” nie otwiera się, dopóki jakiś krok czeka na kierowcę (z telefonem albo bez), bo to samo okrążenie, które zarabia na ofertę, przynosi następną wiadomość. Otwiera się po odpowiedzi na SMS-a o kodzie odzyskiwania, czyli dokładnie wtedy, o co Kuba prosi.
- Dostępność: telefon ma `role="dialog"`, `aria-modal` i etykietę „Wiadomość od {numer}”, odpowiedź dostaje fokus, Escape zamyka pytanie o pominięcie, wysuwanie telefonu wyłącza się przy `prefers-reduced-motion`, a przyciski odpowiedzi mają co najmniej 44 px wysokości. Tło strony poza otworem nie jest `inert`: nawigacja klawiaturą może wyjść poza telefon (do poprawy, jeśli test z czytnikiem ekranu to potwierdzi).

Samouczek jest wyświetlany tylko w garażu: SMS, który przychodzi, gdy kierowca jest w warsztacie albo sklepie, czeka na garaż.

## XP i poziom auta w garażu

Pod tytułem garażu jest pasek z planszy Garaż: „XP auta 410 / 600” (ile auto ma w swoim poziomie i ile ten poziom trwa; pasek pokazuje ten ułamek), a pod nim, jaki poziom otwiera następne części („Od poziomu 6: części T3”). Na najwyższym poziomie pasek jest pełny i mówi „Najwyższy poziom”. Obok tytułu jest znacznik „Auto LV 3”.

Gdy garaż pyta o auto (przy otwarciu i za każdym razem, gdy zmienia się wersja biegu, więc po każdym rozliczeniu) i widzi **wyższy poziom niż poprzednio**, mówi o tym raz („Auto awansowało na poziom 5. Można montować części T3.”; drugie zdanie tylko wtedy, gdy awans otworzył tier, a przy kilku poziomach naraz jest to najwyższy otwarty tier). Pierwsza odpowiedź nigdy nie jest awansem, a komunikat czeka, gdy awans zauważył warsztat, a kierowca wraca do garażu później. XP auta z nagrody samouczka („K. dorzucił Twojemu autu 100 XP.”) odświeża pasek od razu, bo wersja biegu się wtedy nie zmienia.

## Wygląd (skórka z canvy)

Ekrany mają wygląd z płótna projektowego (`ride.css`, „skórka gry”), przeniesiony do `web/src/style.css` jako tokeny Tailwinda i klasy (`.btn`, `.card`, `.chip`, `.bar`, `.fuelbar`, `.tile`, `.st`, `.hud`, `.navb`), więc dostają go wszystkie ekrany, także starsze. Ciemne stalowe panele ze skosem, bursztyn dla tego, co można nacisnąć, cyjan dla tego, co trwa, Barlow Condensed w tytułach i przyciskach, JetBrains Mono w liczbach. **Fonty i zdjęcia aut są plikami aplikacji** (`web/src/assets/fonts`, `web/src/assets/art`; fonty na licencji OFL, tylko podzbiory latin i latin-ext, bo polskie litery są w drugim), a nie zapytaniem do Google: zewnętrzny font mówiłby Google'owi, kto czyta stronę, wbrew polityce prywatności.

Ekrany gry (`GameShell`) mają u góry HUD (płytka z poziomem, XP w poziomie na pasku, gotówka z monetą), a na dole nawigację z ikonami z canvy; oba są przyklejone do krawędzi ekranu, więc poziom i gotówka są widoczne także po przewinięciu strony. Poziom kierowcy liczy krzywa z projektu (`45 · n^2,3` XP łącznie do poziomu n, `features/player/level.ts`); jest tylko pokazywany, serwer niczego jeszcze po nim nie bramkuje. Okrążenie daje 2 XP (kotwica ekonomii), więc drugi poziom to ok. 2,3 h jazdy, a pasek w HUD rusza się powoli. Garaż pokazuje auto ze zdjęciem, statystyki na paskach (pasek jest pełny przy suficie statystyki z reguł, `car.stat_ceiling`, czyli przy tym, co ma osiągać najlepsze auto całej gry, a nie przy tym, czego chce tor; wrak startowy stoi więc nisko, od 16 do 50% paska, a części przesuwają go po trochu) z tym, co dodają części, dziewięć gniazd jako kafelki prowadzące do warsztatu i bak w dziesięciu komórkach. Strona startowa, tworzenie kierowcy i wybór wraku też są z canvy (plansze Landing, Kreator i Wybór; patrz wyżej). Historia i pierwsze pięć kroków samouczka też są z canvy (plansze Historia i Krok; patrz „Historia i samouczek”). **Jeszcze nie z canvy:** mapa, turnieje, reszta samouczka (skrzynka Wiadomości z wątkami, kłódki z powodem, kroki 6–20 i powtarzanie kroków z Dziennika) oraz ekrany odzyskiwania kierowcy, potwierdzenia e-maila, powrotu od dostawcy logowania i strony prawne oraz zegar gry i pogoda w HUD (bez nich HUD pokazuje imię kierowcy).

## Sprawdzenie

Testy komponentów i sklepów: `cd web && npm test`. Dodatkowo ścieżka została sprawdzona ręcznie w przeglądarce na lokalnym API (tymczasowa baza SQLite): wybór wraku na telefonie po polsku i na szerokim ekranie po angielsku, start, nieobecność dwóch godzin (28 okrążeń, 434 $, 700 XP, koniec paliwa), tankowanie, awaria wykryta przez odpytywanie bez odświeżania strony i naprawa. To sprawdzenie ujawniło jedną wadę, poprawioną osobnym commitem: wynik tankowania zostawał pod licznikiem bez końca.

Holowanie sprawdzone tak samo, w najgorszym przypadku: awaria na pierwszym okrążeniu, 15,50 $ w kieszeni przy naprawie za 30 $. Przycisk naprawy jest wtedy nieaktywny, a pod nim pojawia się holowanie; po kliknięciu auto ma status „Holowane”, gotówka się nie zmienia, a po upływie czasu auto samo jedzie dalej i zarabia. Ujawniło to wadę kosmetyczną (puste kontrolki zostawiały przerwy w karcie), poprawioną osobnym commitem.

Od tego sprawdzenia ścieżka sklepu i warsztatu: na telefonie (375 px) po polsku kupiłem opony uliczne T2 za 570 $ z kieszeni 3000 $, założyłem je w warsztacie (auto stanęło, a kolejka pokazała „Zakładanie: Opony uliczne, W toku: Zostało 3 min 51 s”), skróciłem czas zlecenia w bazie i warsztat sam odświeżył się po jego końcu: kolejka pusta, przyczepność, prowadzenie i hamowanie +10, a czas okrążenia w garażu spadł z 1:15.0 do 1:09.2 i auto znów jechało. To sprawdzenie wykryło dwie wady poprawione osobnym commitem: komunikat „Opony uliczne jest w Twoim inwentarzu” (część w liczbie mnogiej) i dziewięć gniazd w jednej kolumnie na telefonie.
