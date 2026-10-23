# Konto i logowanie

Kierowca zaczyna jako **gość**: konto powstaje od razu, bez e-maila, a urządzenie dostaje token. Gość, który nie zapisał kodu odzyskiwania, traci garaż razem z urządzeniem albo danymi przeglądarki. Dlatego po pierwszym zarobku gra proponuje **połączyć konto**: e-mailem (link jednorazowy), przez Google, Facebooka albo Apple. Kod odzyskiwania zostaje jako wyjście dla tych, którzy nie chcą podawać niczego.

## Zasady

- **Tożsamości.** Sposób logowania to wiersz w `account_identities`: dostawca (`email`, `google`, `facebook`, `apple`), `subject` (adres zapisany małymi literami albo stały identyfikator nadany przez dostawcę) i adres do wyświetlenia. Jedna tożsamość należy do jednego konta (`unique(provider, subject)`), a konto ma najwyżej jedną tożsamość każdego dostawcy (`unique(user_id, provider)`).
- **Połączenie pierwszej tożsamości zabezpiecza gościa** (`claimed_at`), od tego momentu konto nie wygasa. Zgody (regulamin, wiek) zapisują się wtedy jako `terms_accepted_at` i `age_confirmed_at`. **Zgody składa się raz**: konto, które ma obie na koncie, nie jest pytane o nie przy dodawaniu drugiego sposobu logowania, a zapisane momenty zostają bez zmian. Konto, które ma tylko jedną z nich, jest pytane nadal. Adres konta (`users.email`) ustawia tylko tożsamość e-mail, bo tylko ją gracz udowodnił linkiem.
- **Nigdy nie szukamy konta po adresie, który podaje dostawca.** Cudze konto Google, które twierdzi, że ma adres właściciela, nie wpuszcza do jego garażu. Tożsamość znajdujemy po `(dostawca, subject)` i tylko tak.
- **Dwóch kont nie łączymy.** Jeśli tożsamość należy już do innego garażu, odpowiedź to 409 `identity_taken` i nic się nie zmienia; gracz wybiera, na którym gra. Łączenie walut i aut z dwóch kont otworzyłoby drogę do nadużyć.
- **Logowanie nieznanym dostawcą nie zakłada konta.** Odpowiedź to 404 `no_account`. Konto powstaje tylko przy „Utwórz kierowcę”.
- **Sekrety jednorazowe** (`account_tokens`): 32 losowe bajty, w bazie tylko skrót HMAC (klucz aplikacji), ważność, jedno użycie wymuszone jednym `UPDATE … WHERE nieużyty AND niewygasły AND cel`. Zły cel albo zły weryfikator nie zużywa sekretu.

## Okno „Zapisz swój garaż” (web)

- Otwiera się w garażu, gdy kierowca jest gościem i ma co najmniej jedno okrążenie. Czeka, aż kierowca zatwierdzi podsumowanie nieobecności, żeby nic się nie nakładało.
- To okno dialogowe: fokus przechodzi do środka i tam zostaje, `Esc` i kliknięcie poza oknem odkładają sprawę, fokus wraca tam, skąd przyszedł.
- Zawiera dwie zgody, przyciski dostawców, którzy są włączeni na serwerze, i pole e-mail. Bez obu zgód nic się nie wysyła. Pierwsza zgoda nazywa regulamin i politykę prywatności i linkuje do `/terms` i `/privacy` (w nowej karcie, żeby okno zostało, jak było); druga mówi, ile lat trzeba mieć, a liczbę podaje serwer (`LEGAL_MIN_AGE`). Gdy serwera nie da się zapytać o wiek, pole mówi to ogólnie, a serwer i tak o zgody pyta.
- „Później” zapamiętuje to w `localStorage` (`ride.linkPrompt`) i zostawia cichy baner, dopóki konto jest gościem. „Wolę kod odzyskiwania” zamyka okno i przewija do panelu z kodem. Wylogowanie i zalogowanie kogoś innego kasuje zapamiętane „później”.
- Kierowca, który ma już konto, widzi w garażu kartę „Twoje sposoby logowania” (adres zamaskowany: `t***@example.com`) z przyciskiem „Dodaj kolejny sposób logowania”. Przycisk rozwija to, co zostało do dodania: dostawców włączonych na serwerze, których konto jeszcze nie ma, i e-mail, jeśli konta go nie ma. Bez pytania o zgody, bo złożono je przy pierwszym sposobie. Gdy konto ma już wszystko, przycisku nie ma. Dzięki temu utrata jednego sposobu (np. konta Google) nie zamyka drogi do garażu.

## Ekran „Mam już konto” (`/recover`)

Na górze logowanie kontem (dostawcy i link e-mailem, bez zgód, bo konto już istnieje), niżej kod odzyskiwania. Kod działa także wtedy, gdy nie da się wczytać listy dostawców.

## E-mail

| Punkt końcowy | Opis |
|---|---|
| `POST /account/email` (token) | Prosi o link, który zabezpiecza konto albo dodaje do niego e-mail. Wymaga `email`, a od konta, które nie złożyło jeszcze zgód, także `accept_terms` i `confirm_age`. |
| `POST /account/login-link` | Prosi o link, który loguje na nowym urządzeniu. Tylko `email`. |
| `POST /account/email/confirm` | Używa linku (`token`). Link zabezpieczający nie loguje nikogo, link logujący zwraca token urządzenia. |

- Odpowiedź na prośbę o link to zawsze 202 i ten sam tekst, niezależnie od tego, czy adres ma już garaż. Nikt nie sprawdzi w ten sposób, kto gra. Gdy adres ma garaż, link dostaje jego właściciel (do jego garażu).
- Link jest ważny 30 minut (`ride.account.link_ttl_seconds`) i działa raz. Wygląda tak: `{WEB_URL}/account/confirm?token=…`. Strona od razu usuwa sekret z adresu.
- Limity na godzinę: prośba o zabezpieczenie 5 na konto, 3 na adres (skrót SHA-1, adresu nie przechowujemy) i 30 na IP; link logujący 3 na adres i 10 na IP; użycie linku 30 na IP.
- Maile idą kolejką (`Mail::queue`), w języku konta, w pięciu językach (`api/lang/<język>/mail.php`). Są cztery rodzaje: `claim` (zabezpiecza gościa), `add` (dodaje adres do konta, które jest już zabezpieczone, więc nie mówi, że garaż „będzie zabezpieczony”), `login` i `existing`. Link działa tak samo w `claim` i `add`, różni się tylko treść.

## Google, Facebook, Apple

Socialite (`laravel/socialite`) i `socialiteproviders/apple`. Przepływ jest rozbity na trzy kroki, bo samo „callback łączy konto” byłoby podatne na **login CSRF**: napastnik zaczyna własne logowanie Google, podsuwa ofierze adres powrotu, a konto Google ofiary łączy się z garażem napastnika.

1. **Start** (`POST /account/identities/{dostawca}/start` z tokenem, żeby połączyć albo dodać kolejnego dostawcę, albo `POST /auth/{dostawca}/start` bez konta, żeby się zalogować). Serwer zapisuje stan (`oauth`, 10 minut) z kontem, zgodą i **skrótem weryfikatora**, a przeglądarce oddaje adres dostawcy i sam **weryfikator**. Przeglądarka zapamiętuje go w `localStorage` (`ride.oauth`, do 15 minut) i idzie do dostawcy. Apple dostaje też nonce wyliczony ze stanu.
2. **Callback** (`GET` albo `POST` na `/auth/{dostawca}/callback`; Apple wraca formularzem). Serwer zużywa stan (raz, i tylko dla dostawcy, dla którego powstał), pyta dostawcę, kim jest osoba, i zapisuje **bilet** (`oauth_result`, 5 minut). **Niczego nie zmienia na koncie.** Przeglądarka dostaje przekierowanie na `{WEB_URL}/auth/callback#result=ready&provider=…&ticket=…`. Wynik jest we fragmencie adresu, którego nie widzi żaden serwer ani log; `cancelled` oznacza „nie” u dostawcy, `error` każdą inną porażkę.
3. **Zakończenie** (`POST /auth/complete` z `ticket` i `verifier`). Bilet można odebrać tylko z weryfikatorem tej przeglądarki, która zaczęła; zły weryfikator nie zużywa biletu. Intencja `link` wymaga zalogowanego kierowcy, **tego samego**, dla którego zaczęto (inaczej 403 `wrong_account`), i dodaje dostawcę do konta. Intencja `login` zwraca token urządzenia albo 404 `no_account`.

Strona `/auth/callback` czyta fragment, od razu go usuwa, pokazuje serwerowi weryfikator z `localStorage` i mówi kierowcy, co się stało. Jeśli przeglądarka nie zaczęła tego logowania, nie pyta serwera wcale. Weryfikator zostaje skasowany po każdej odpowiedzi serwera (logowanie kończy się raz) i zostaje, gdy serwera nie dało się dosięgnąć.

`GET /auth/providers` zwraca włączonych dostawców, w kolejności Google, Facebook, Apple. Dostawca jest włączony tylko wtedy, gdy ma komplet kluczy; połowicznie skonfigurowany nie pojawia się na ekranie.

Limity na godzinę: start 20 na konto i 60 na IP; zakończenie 30 na IP.

## Twoje dane (kopia i usunięcie konta)

W garażu każdy kierowca, także gość, ma sekcję „Twoje dane”. „Pobierz moje dane” pobiera plik JSON (`GET /account/export`), a „Usuń moje konto” (`DELETE /account` z `confirm_name`) usuwa konto, gdy kierowca wpisze swoje imię. Po usunięciu urządzenie zapomina konto, a strona startowa raz mówi, że konto usunięto. Operator ma te same możliwości jako polecenia `accounts:export` i `accounts:delete`. Szczegóły i ograniczenia w `docs/prawo.md`.

## Konfiguracja

Klucze zakłada się u dostawców, w swoich kontach deweloperskich; w repozytorium ich nie ma. W każdym trzeba zarejestrować **adres powrotu** `APP_URL/api/v1/auth/{google|facebook|apple}/callback`.

| Zmienna | Znaczenie |
|---|---|
| `WEB_URL` | Adres aplikacji web (domyślnie `http://localhost:5173`). Tam prowadzą linki z maili i powroty od dostawców. |
| `LEGAL_MIN_AGE`, `LEGAL_VERSION`, `LEGAL_OPERATOR_NAME`, `LEGAL_OPERATOR_ADDRESS`, `LEGAL_CONTACT_EMAIL` | Regulamin i polityka prywatności: wiek, wersja tekstów i dane operatora. Opis w `docs/prawo.md`. |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | Google Cloud Console → API i usługi → Dane logowania → Identyfikator klienta OAuth, typ „Aplikacja internetowa”. W „Autoryzowanych identyfikatorach URI przekierowania” adres powrotu. |
| `FACEBOOK_CLIENT_ID`, `FACEBOOK_CLIENT_SECRET` | Meta for Developers → aplikacja → Logowanie przez Facebooka → „Prawidłowe identyfikatory URI przekierowania OAuth”. Identyfikator i sekret aplikacji są w ustawieniach podstawowych. |
| `APPLE_CLIENT_ID` | Services ID (nie App ID) z włączonym „Sign in with Apple”; domena i adres powrotu są w jego konfiguracji. |
| `APPLE_CLIENT_SECRET` | Gotowy podpisany token (ważny najwyżej sześć miesięcy)… |
| `APPLE_TEAM_ID`, `APPLE_KEY_ID`, `APPLE_PRIVATE_KEY` | …albo identyfikator zespołu, identyfikator klucza i bezwzględna ścieżka pliku `.p8` (klucz z włączonym „Sign in with Apple”), z których serwer sam robi sekret. |
| `APPLE_REDIRECT_URI`, `GOOGLE_REDIRECT_URI`, `FACEBOOK_REDIRECT_URI` | Tylko gdy adres powrotu ma być inny niż `APP_URL/api/v1/auth/{dostawca}/callback`. |

Uwagi:

- **Apple wymaga HTTPS i prawdziwej domeny**, nie przyjmie `localhost`. Do lokalnego sprawdzenia Apple trzeba tunelu (np. własnej domeny z certyfikatem). Google i Facebook (aplikacja w trybie deweloperskim) przyjmują `http://localhost`.
- **Adresy stron prawnych**: Google (ekran zgody OAuth), Facebook (ustawienia aplikacji) i Apple (Services ID) wymagają adresu polityki prywatności (`WEB_URL/privacy`), a Google i Facebook także regulaminu (`WEB_URL/terms`).
- **Lokalnie**: `APP_URL=http://localhost:8000`, `WEB_URL=http://localhost:5173`. Adres powrotu to API, które przekierowuje do web, więc proxy Vite nie jest do tego potrzebne.
- **Kolejka**: maile idą kolejką. Przy `QUEUE_CONNECTION=database` musi działać `php artisan queue:work`, inaczej nic nie wyjdzie. Lokalnie wystarczy `QUEUE_CONNECTION=sync` albo Mailpit z `docker/compose.yaml` (SMTP 1025, interfejs na 8025).
- **Harmonogram**: `accounts:prune-tokens` (co godzinę) usuwa wygasłe i zużyte sekrety, `accounts:prune-guests` (codziennie) nieużywane konta gości. Potrzebny jest cron z `php artisan schedule:run` albo `schedule:work`.
- **Serwer WWW** musi oddawać `index.html` dla `/account/confirm` i `/auth/callback`, tak jak dla reszty tras aplikacji. Szablon nginx z `deploy/` to robi.
- **Dzienniki serwera**: w adresie `/account/confirm?token=…` i w adresie powrotu od Google i Facebooka jest sekret, więc log dostępu nginx z `deploy/` zapisuje adres tylko do znaku `?`. Własny serwer powinien robić to samo.
- **Wdrożenie** pod `idlerace.cerasusdigital.pl`, w tym adresy powrotu do wpisania u dostawców: `deploy/README.md`.

## Co jest sprawdzone, a co nie

- Testy API (Pest) obejmują tabele i sekrety, połączenie tożsamości, wszystkie punkty końcowe e-mail, start, callback i zakończenie przepływu dostawców, limity, zgodność języków. Przepływ dostawców jest testowany z `Socialite::fake` oraz z prawdziwymi adresami autoryzacji zbudowanymi offline (stan, adres powrotu, nonce i `form_post` Apple). Testy mutacyjne (zepsucie zabezpieczenia i oczekiwanie czerwonego testu) potwierdziły m.in. sprawdzanie weryfikatora, właściciela biletu i dostawcy w stanie.
- Testy web (Vitest) obejmują sklep konta, stronę potwierdzenia linku, stronę powrotu od dostawcy, okno z jego zgodami, fokusem i banerem, ekran „Mam już konto” i parzystość tłumaczeń.
- Ścieżka e-mail sprawdzona ręcznie w przeglądarce na lokalnym API (tymczasowa baza SQLite, poczta do logu, fałszywe klucze Google tylko po to, by pokazać przycisk): nowy gość → okno otwiera się samo, gdy odpytywanie wykryje pierwszy zarobek → wysyłka bez zgód jest zablokowana → link w mailu po polsku, adres znormalizowany → strona potwierdzenia usuwa sekret z adresu → garaż pokazuje „Twoje sposoby logowania” → wylogowanie → „Mam już konto” → link logujący → garaż. Przez API: pytanie o link dla znanego i nieznanego adresu daje identyczną odpowiedź (mail wychodzi tylko do właściciela), a użyty link daje 422. Okno na telefonie (375 px) jest szufladą od dołu i mieści się w całości, baner po „Później” jest dyskretny. Powrót od dostawcy z fałszywym stanem przekierował na `/auth/callback`, a strona usunęła fragment i pokazała komunikat.
- Dodawanie sposobu logowania sprawdzone tak samo: konto z samym Google (zgody zapisane) → w garażu „Dodaj kolejny sposób logowania” pokazuje tylko pole e-mail, bez zgód i bez przycisku Google, który już jest połączony → mail z nowym tematem „add” → po linku lista ma Google i e-mail, a przycisku dodawania już nie ma. Przez API: konto ze zgodami nie jest o nie pytane, zwykły gość dostaje 422.
- To sprawdzenie wykryło dwie wady, każdą poprawioną osobnym commitem. Pierwsza (z poprzedniego sprawdzenia): link z maila zabezpieczył konto z drugiej karty, a pierwsza zostawiała otwarte okno „Zapisz swój garaż”, bo profil był czytany tylko raz. Teraz profil gościa odświeża się razem z biegiem (co 15 s i po powrocie do karty). Druga: po „Użyj innego adresu” pole zachowywało stary adres, więc nowy dopisywał się na końcu i serwer go odrzucał; pole dostaje teraz fokus z zaznaczonym tekstem, który pisanie zastępuje.
- **Nie sprawdzono z prawdziwym Google, Facebookiem i Apple.** Wymaga to kluczy, których w repozytorium nie ma. Najmniej pewny jest Apple: powrót formularzem, nonce i podpisany sekret można zbadać tylko na prawdziwym koncie deweloperskim.

## Czego jeszcze nie ma

- Odłączania logowania i zmiany adresu e-mail. Zanim odłączanie powstanie, trzeba zdecydować, co z kontem, które odłączy ostatni sposób logowania (ostatniego nie wolno odłączyć), i czy odłączenie wymaga ponownego potwierdzenia.
- Nagrody za zabezpieczenie konta (w makiecie 10 diamentów): diamentów jeszcze nie ma w grze.
- Przeglądu prawnego regulaminu i polityki prywatności (`/terms`, `/privacy`, opis w `docs/prawo.md`). Teksty są roboczym projektem. **Próg wieku jest roboczy** (16 lat, `LEGAL_MIN_AGE`): ostateczną liczbę ma wskazać przegląd prawny (ryzyko R9 z roadmapy).
- Samouczka. Okno otwiera się po pierwszym okrążeniu, bo na ekranie „krok 3” makiety („Zapisz swój garaż”) jest pierwszy zarobek; baner „dzień 2 i 3” z makiety jest tu banerem stałym, dopóki konto jest gościem.
- Tłumaczenia francuskie, niemieckie i brazylijskie są robocze (mojego autorstwa) i warto, żeby przejrzała je osoba mówiąca w tych językach.
