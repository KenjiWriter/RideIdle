# Konto i logowanie

Kierowca zaczyna jako **gość**: konto powstaje od razu, bez e-maila, a urządzenie dostaje token. Gość, który nie zapisał kodu odzyskiwania, traci garaż razem z urządzeniem albo danymi przeglądarki. Dlatego po pierwszym zarobku gra proponuje **połączyć konto**: e-mailem (link jednorazowy), przez Google, Facebooka albo Apple. Kod odzyskiwania zostaje jako wyjście dla tych, którzy nie chcą podawać niczego.

## Zasady

- **Tożsamości.** Sposób logowania to wiersz w `account_identities`: dostawca (`email`, `google`, `facebook`, `apple`), `subject` (adres zapisany małymi literami albo stały identyfikator nadany przez dostawcę) i adres do wyświetlenia. Jedna tożsamość należy do jednego konta (`unique(provider, subject)`), a konto ma najwyżej jedną tożsamość każdego dostawcy (`unique(user_id, provider)`).
- **Połączenie pierwszej tożsamości zabezpiecza gościa** (`claimed_at`), od tego momentu konto nie wygasa. Zgody (regulamin, wiek) zapisują się wtedy jako `terms_accepted_at` i `age_confirmed_at`. Adres konta (`users.email`) ustawia tylko tożsamość e-mail, bo tylko ją gracz udowodnił linkiem.
- **Nigdy nie szukamy konta po adresie, który podaje dostawca.** Cudze konto Google, które twierdzi, że ma adres właściciela, nie wpuszcza do jego garażu. Tożsamość znajdujemy po `(dostawca, subject)` i tylko tak.
- **Dwóch kont nie łączymy.** Jeśli tożsamość należy już do innego garażu, odpowiedź to 409 `identity_taken` i nic się nie zmienia; gracz wybiera, na którym gra. Łączenie walut i aut z dwóch kont otworzyłoby drogę do nadużyć.
- **Logowanie nieznanym dostawcą nie zakłada konta.** Odpowiedź to 404 `no_account`. Konto powstaje tylko przy „Utwórz kierowcę”.
- **Sekrety jednorazowe** (`account_tokens`): 32 losowe bajty, w bazie tylko skrót HMAC (klucz aplikacji), ważność, jedno użycie wymuszone jednym `UPDATE … WHERE nieużyty AND niewygasły AND cel`. Zły cel albo zły weryfikator nie zużywa sekretu.

## Okno „Zapisz swój garaż” (web)

- Otwiera się w garażu, gdy kierowca jest gościem i ma co najmniej jedno okrążenie. Czeka, aż kierowca zatwierdzi podsumowanie nieobecności, żeby nic się nie nakładało.
- To okno dialogowe: fokus przechodzi do środka i tam zostaje, `Esc` i kliknięcie poza oknem odkładają sprawę, fokus wraca tam, skąd przyszedł.
- Zawiera dwie zgody (regulamin i polityka prywatności, wiek), przyciski dostawców, którzy są włączeni na serwerze, i pole e-mail. Bez obu zgód nic się nie wysyła.
- „Później” zapamiętuje to w `localStorage` (`ride.linkPrompt`) i zostawia cichy baner, dopóki konto jest gościem. „Wolę kod odzyskiwania” zamyka okno i przewija do panelu z kodem. Wylogowanie i zalogowanie kogoś innego kasuje zapamiętane „później”.
- Kierowca, który ma już konto, widzi w garażu listę swoich sposobów logowania (adres zamaskowany: `t***@example.com`).

## Ekran „Mam już konto” (`/recover`)

Na górze logowanie kontem (dostawcy i link e-mailem, bez zgód, bo konto już istnieje), niżej kod odzyskiwania. Kod działa także wtedy, gdy nie da się wczytać listy dostawców.

## E-mail

| Punkt końcowy | Opis |
|---|---|
| `POST /account/email` (token) | Prosi o link, który zabezpiecza konto. Wymaga `email`, `accept_terms`, `confirm_age`. |
| `POST /account/login-link` | Prosi o link, który loguje na nowym urządzeniu. Tylko `email`. |
| `POST /account/email/confirm` | Używa linku (`token`). Link zabezpieczający nie loguje nikogo, link logujący zwraca token urządzenia. |

- Odpowiedź na prośbę o link to zawsze 202 i ten sam tekst, niezależnie od tego, czy adres ma już garaż. Nikt nie sprawdzi w ten sposób, kto gra. Gdy adres ma garaż, link dostaje jego właściciel (do jego garażu).
- Link jest ważny 30 minut (`ride.account.link_ttl_seconds`) i działa raz. Wygląda tak: `{WEB_URL}/account/confirm?token=…`. Strona od razu usuwa sekret z adresu.
- Limity na godzinę: prośba o zabezpieczenie 5 na konto, 3 na adres (skrót SHA-1, adresu nie przechowujemy) i 30 na IP; link logujący 3 na adres i 10 na IP; użycie linku 30 na IP.
- Maile idą kolejką (`Mail::queue`), w języku konta, w pięciu językach (`api/lang/<język>/mail.php`).

## Google, Facebook, Apple

Socialite (`laravel/socialite`) i `socialiteproviders/apple`. Przepływ jest rozbity na trzy kroki, bo samo „callback łączy konto” byłoby podatne na **login CSRF**: napastnik zaczyna własne logowanie Google, podsuwa ofierze adres powrotu, a konto Google ofiary łączy się z garażem napastnika.

1. **Start** (`POST /account/identities/{dostawca}/start` z tokenem, żeby połączyć, albo `POST /auth/{dostawca}/start` bez konta, żeby się zalogować). Serwer zapisuje stan (`oauth`, 10 minut) z kontem, zgodą i **skrótem weryfikatora**, a przeglądarce oddaje adres dostawcy i sam **weryfikator**. Przeglądarka zapamiętuje go w `localStorage` (`ride.oauth`, do 15 minut) i idzie do dostawcy. Apple dostaje też nonce wyliczony ze stanu.
2. **Callback** (`GET` albo `POST` na `/auth/{dostawca}/callback`; Apple wraca formularzem). Serwer zużywa stan (raz, i tylko dla dostawcy, dla którego powstał), pyta dostawcę, kim jest osoba, i zapisuje **bilet** (`oauth_result`, 5 minut). **Niczego nie zmienia na koncie.** Przeglądarka dostaje przekierowanie na `{WEB_URL}/auth/callback#result=ready&provider=…&ticket=…`. Wynik jest we fragmencie adresu, którego nie widzi żaden serwer ani log; `cancelled` oznacza „nie” u dostawcy, `error` każdą inną porażkę.
3. **Zakończenie** (`POST /auth/complete` z `ticket` i `verifier`). Bilet można odebrać tylko z weryfikatorem tej przeglądarki, która zaczęła; zły weryfikator nie zużywa biletu. Intencja `link` wymaga zalogowanego kierowcy, **tego samego**, dla którego zaczęto (inaczej 403 `wrong_account`), i dodaje dostawcę do konta. Intencja `login` zwraca token urządzenia albo 404 `no_account`.

Strona `/auth/callback` czyta fragment, od razu go usuwa, pokazuje serwerowi weryfikator z `localStorage` i mówi kierowcy, co się stało. Jeśli przeglądarka nie zaczęła tego logowania, nie pyta serwera wcale. Weryfikator zostaje skasowany po każdej odpowiedzi serwera (logowanie kończy się raz) i zostaje, gdy serwera nie dało się dosięgnąć.

`GET /auth/providers` zwraca włączonych dostawców, w kolejności Google, Facebook, Apple. Dostawca jest włączony tylko wtedy, gdy ma komplet kluczy; połowicznie skonfigurowany nie pojawia się na ekranie.

Limity na godzinę: start 20 na konto i 60 na IP; zakończenie 30 na IP.

## Konfiguracja

Klucze zakłada się u dostawców, w swoich kontach deweloperskich; w repozytorium ich nie ma. W każdym trzeba zarejestrować **adres powrotu** `APP_URL/api/v1/auth/{google|facebook|apple}/callback`.

| Zmienna | Znaczenie |
|---|---|
| `WEB_URL` | Adres aplikacji web (domyślnie `http://localhost:5173`). Tam prowadzą linki z maili i powroty od dostawców. |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | Google Cloud Console → API i usługi → Dane logowania → Identyfikator klienta OAuth, typ „Aplikacja internetowa”. W „Autoryzowanych identyfikatorach URI przekierowania” adres powrotu. |
| `FACEBOOK_CLIENT_ID`, `FACEBOOK_CLIENT_SECRET` | Meta for Developers → aplikacja → Logowanie przez Facebooka → „Prawidłowe identyfikatory URI przekierowania OAuth”. Identyfikator i sekret aplikacji są w ustawieniach podstawowych. |
| `APPLE_CLIENT_ID` | Services ID (nie App ID) z włączonym „Sign in with Apple”; domena i adres powrotu są w jego konfiguracji. |
| `APPLE_CLIENT_SECRET` | Gotowy podpisany token (ważny najwyżej sześć miesięcy)… |
| `APPLE_TEAM_ID`, `APPLE_KEY_ID`, `APPLE_PRIVATE_KEY` | …albo identyfikator zespołu, identyfikator klucza i bezwzględna ścieżka pliku `.p8` (klucz z włączonym „Sign in with Apple”), z których serwer sam robi sekret. |
| `APPLE_REDIRECT_URI`, `GOOGLE_REDIRECT_URI`, `FACEBOOK_REDIRECT_URI` | Tylko gdy adres powrotu ma być inny niż `APP_URL/api/v1/auth/{dostawca}/callback`. |

Uwagi:

- **Apple wymaga HTTPS i prawdziwej domeny**, nie przyjmie `localhost`. Do lokalnego sprawdzenia Apple trzeba tunelu (np. własnej domeny z certyfikatem). Google i Facebook (aplikacja w trybie deweloperskim) przyjmują `http://localhost`.
- **Lokalnie**: `APP_URL=http://localhost:8000`, `WEB_URL=http://localhost:5173`. Adres powrotu to API, które przekierowuje do web, więc proxy Vite nie jest do tego potrzebne.
- **Kolejka**: maile idą kolejką. Przy `QUEUE_CONNECTION=database` musi działać `php artisan queue:work`, inaczej nic nie wyjdzie. Lokalnie wystarczy `QUEUE_CONNECTION=sync` albo Mailpit z `docker/compose.yaml` (SMTP 1025, interfejs na 8025).
- **Harmonogram**: `accounts:prune-tokens` (co godzinę) usuwa wygasłe i zużyte sekrety, `accounts:prune-guests` (codziennie) nieużywane konta gości. Potrzebny jest cron z `php artisan schedule:run` albo `schedule:work`.
- **Serwer WWW** musi oddawać `index.html` dla `/account/confirm` i `/auth/callback`, tak jak dla reszty tras aplikacji.

## Co jest sprawdzone, a co nie

- Testy API (Pest) obejmują tabele i sekrety, połączenie tożsamości, wszystkie punkty końcowe e-mail, start, callback i zakończenie przepływu dostawców, limity, zgodność języków. Przepływ dostawców jest testowany z `Socialite::fake` oraz z prawdziwymi adresami autoryzacji zbudowanymi offline (stan, adres powrotu, nonce i `form_post` Apple). Testy mutacyjne (zepsucie zabezpieczenia i oczekiwanie czerwonego testu) potwierdziły m.in. sprawdzanie weryfikatora, właściciela biletu i dostawcy w stanie.
- Testy web (Vitest) obejmują sklep konta, stronę potwierdzenia linku, stronę powrotu od dostawcy, okno z jego zgodami, fokusem i banerem, ekran „Mam już konto” i parzystość tłumaczeń.
- **Nie sprawdzono z prawdziwym Google, Facebookiem i Apple.** Wymaga to kluczy, których w repozytorium nie ma. Najmniej pewny jest Apple: powrót formularzem, nonce i podpisany sekret można zbadać tylko na prawdziwym koncie deweloperskim.

## Czego jeszcze nie ma

- Odłączania logowania, dodawania kolejnego dostawcy z poziomu garażu dla konta, które już jest zabezpieczone (API to umożliwia, ekran pokazuje na razie tylko listę) i zmiany adresu e-mail.
- Nagrody za zabezpieczenie konta (w makiecie 10 diamentów): diamentów jeszcze nie ma w grze.
- Stron regulaminu i polityki prywatności. Zgody są polami wyboru z tekstem i znacznikami czasu. **Brakuje też progu wieku**: tekst mówi „mam wiek wymagany do założenia konta”, a liczbę ma wskazać przegląd prawny (ryzyko R9 z roadmapy).
- Samouczka. Okno otwiera się po pierwszym okrążeniu, bo na ekranie „krok 3” makiety („Zapisz swój garaż”) jest pierwszy zarobek; baner „dzień 2 i 3” z makiety jest tu banerem stałym, dopóki konto jest gościem.
- Tłumaczenia francuskie, niemieckie i brazylijskie są robocze (mojego autorstwa) i warto, żeby przejrzała je osoba mówiąca w tych językach.
