# Podręcznik operatora panelu: plan (etap P7, do napisania po skończeniu funkcji)

Właściciel poprosił o podręcznik użytkownika, **gdy wszystko będzie gotowe** (po P3, P4, P5 i G4). To jest bieżąca lista tego, co musi w nim być, żeby nic nie zginęło. Podręcznik to Markdown w `docs/` po polsku oraz strona „Pomoc” w panelu. **Źródłem treści jest katalog pomocy** (`api/lang/pl/help/*.php`, etap P6): opisy pól w podręczniku mają być tymi samymi zdaniami, które operator widzi w formularzu, więc podręcznik nie może się z nimi rozjechać (najlepiej generować z katalogu to, co da się wygenerować: tabele pól z etykietą, opisem, przykładem i zakresem).

## Rozdziały, po jednym na zadanie operatora

1. **Zaczynam: logowanie, kod z aplikacji, role** (edytor i wydawca), sieć gier i gra, przełącznik gry, co widać w menu sieci i w menu gry.
2. **Utworzenie gry i jej domeny** (kreator w trzech krokach: nazwa i domena, punkt startowy, domena i SSL), sprawdzenie DNS, strona „Domena i SSL”, co robi worker na serwerze, jak czytać status i log, co zrobić, gdy certyfikat się nie udał; ikony gry; **po stronie administratora serwera** (osobna sekcja: Ubuntu, timer, certbot).
3. **Auto**: formularz auta pole po polu (klasa, napęd, układ, silnik, moc, masa, prześwit, ładunek, zbiornik lub bateria, poziom, cena, źródła), obrazek, szkic, zapis, odrzucenie szkicu, cykl życia (wycofanie, przywrócenie, archiwum, usunięcie), przykład: dodajemy nowe auto od początku do gry.
4. **Część**: slot i jego rola, rodzaj opon, statystyki części (co znaczy każda), wymagania (cylindry, doładowanie, napęd, klasy), rzadkość i afiksy (skąd się biorą, bo nie wpisuje się ich tu), przykład: dodajemy część do slotu.
5. **Świat i tory**: miasta (mapa, pogoda w procentach, klimat), drogi, tory (rodzaj, długość, czas okrążenia, paliwo, nagroda, XP, wagi statystyk, opony odniesienia), jak liczby toru przekładają się na zarobek na godzinę, jak sprawdzić, że nowy tor nie psuje tempa gry (symulator).
6. **Samouczek**: skrypt pierwszej jazdy, kroki, fakty startu i końca, wiadomości mentora w pięciu językach, nagrody, ułożone zlecenia.
7. **Reguły w Studiu parametrów**: tryb prosty i zaawansowany, sekcje, suwaki i pola, jednostki (procenty, sekundy, dolary, litry), „co to zmieni”, wartość z żywej paczki i jak do niej wrócić, podgląd balansu i jak czytać bramki.
8. **Model gry, słownik, marka i strona**: sloty i role, statystyki, teksty aplikacji w językach, kolory, czcionki, własny CSS (co jest odrzucane), strona startowa, podgląd linku.
9. **Szkice i publikowanie**: pasek niezapisanych zmian, strona „Zmiany do publikacji”, zlecanie paczki, bramki, uruchomienie paczki, co widzą gracze i kiedy (z następną paczką), dziennik zmian.
10. **Wycofanie zmian** (rollback): powrót do wcześniejszej paczki, odrzucenie szkicu, co się dzieje z kontami graczy.
11. **Gracze**: lista, wyszukiwanie, filtry, strona konta, plik CSV (dane osobowe: kto je widzi, co zapisuje dziennik), czego panel nie robi (akcji na kontach nie ma).
12. **Płatne funkcje** (po G4): ceny paczek monet, przyspieszanie, VIP, odnawianie, strona ustawień, przychód w sieci.
13. **Symulator z autami testowymi** (po P5, jeśli powstanie): zestawy aut, jak czytać wynik, jak go użyć przed publikacją.
14. **Co robić, gdy coś nie działa**: najczęstsze komunikaty błędów w panelu i co znaczą, kiedy napisać do administratora serwera.

## Zrzuty ekranu do podręcznika

Formularze auta, części i świata zrobione przy etapie P6 (przed i po) leżą w katalogu notatnika sesji: `C:\Users\macie\AppData\Local\Temp\claude\C--dev-RideIdle\3c2ac42d-98e8-4dd0-b1c3-1a23d46dd1be\scratchpad\screens\` (lista plików w raporcie z etapu P6). Zrzuty do podręcznika trzeba zrobić od nowa na końcu, na czystych danych demonstracyjnych, w jednym rozmiarze okna.

## Zasady, których podręcznik ma się trzymać

- Na każde zadanie: cel, kroki, przykład z prawdziwymi liczbami gry, co się stanie w grze i kiedy, co sprawdzić po zapisaniu.
- Żadnych obietnic o tym, czego nie sprawdzono: miejsca „niesprawdzone” z raportów etapów (prawdziwy Ubuntu i certbot, MariaDB i PostgreSQL, duża gra, pobranie CSV w przeglądarce) mają w podręczniku jawną uwagę.
- Pięć języków dla tego, co widzi operator w panelu (strona „Pomoc”), a sam podręcznik w `docs/` po polsku.
