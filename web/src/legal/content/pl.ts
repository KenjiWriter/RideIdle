import type { LegalTexts } from '../types'

const texts: LegalTexts = {
  terms: {
    title: 'Regulamin',
    intro:
      'Ten regulamin dotyczy RideIdle, przeglądarkowej gry idle o wyścigach. Czytaj go razem z polityką prywatności. Operator gry jest podany na górze tej strony.',
    sections: [
      {
        id: 'scope',
        heading: 'Kogo dotyczy regulamin',
        paragraphs: [
          'Tworząc kierowcę, logując się lub korzystając z RideIdle w jakikolwiek inny sposób, akceptujesz ten regulamin. Jeśli się z nim nie zgadzasz, nie korzystaj z gry.',
          'Jeżeli jakaś część regulaminu nie może Cię obowiązywać z powodu bezwzględnie obowiązujących przepisów w miejscu, w którym mieszkasz, pozostałe części nadal obowiązują.',
        ],
      },
      {
        id: 'age',
        heading: 'Minimalny wiek',
        paragraphs: [
          'Aby korzystać z RideIdle, musisz mieć co najmniej {minAge} lat. Przy zabezpieczaniu konta prosimy o potwierdzenie wieku i zapisujemy moment tego potwierdzenia.',
          'Jeśli dowiemy się, że konto ma osoba młodsza, możemy je usunąć.',
        ],
      },
      {
        id: 'account',
        heading: 'Twój kierowca i konto',
        paragraphs: [
          'Zaczynasz jako gość: wybierasz imię kierowcy, a gra zakłada konto gościa, nie pytając o żadne dane osobowe. Konto gościa jest usuwane razem z całą zawartością po {guestDays} dniach bez aktywności.',
          'Konto możesz zabezpieczyć adresem e-mail, kontem Google, Facebook lub Apple. Zabezpieczone konto nie wygasa i można je otworzyć na innym urządzeniu. Możesz też mieć kod odzyskiwania. Każdy, kto ma Twój kod odzyskiwania albo link do logowania, może wejść na Twoje konto, więc nie udostępniaj ich nikomu.',
          'Wybierz imię kierowcy, które nie jest obraźliwe i nie podszywa się pod kogoś innego. Imię, które łamie tę zasadę, możemy zmienić lub usunąć.',
          'Odpowiadasz za to, co dzieje się na Twoim koncie. Zabezpieczając je, podaj prawdziwe informacje.',
        ],
      },
      {
        id: 'fair-play',
        heading: 'Uczciwa gra',
        paragraphs: [
          'O każdym wyniku w grze decyduje serwer. Graj tak, jak gra została pomyślana.',
          'Nie oszukuj, nie używaj botów ani skryptów, które grają za Ciebie, nie wykorzystuj celowo błędów w grze, nie próbuj wchodzić na cudze konta i nie zakłócaj działania usługi innym. Jeśli znajdziesz błąd, zgłoś go nam zamiast z niego korzystać.',
          'Jeżeli błąd lub nieuczciwa gra dały komuś gotówkę, doświadczenie albo coś innego, co mu się nie należało, możemy to skorygować.',
        ],
      },
      {
        id: 'virtual-items',
        heading: 'Przedmioty wirtualne',
        paragraphs: [
          'Gotówka, doświadczenie, auta i części w grze są częścią gry. Nie mają wartości poza nią, nie można ich wymienić na pieniądze i nie stają się Twoją własnością.',
          'RideIdle jest obecnie darmowe i nie ma płatnych funkcji. Jeśli to się zmieni, powiadomimy Cię, a do zakupów będą miały zastosowanie osobne zasady.',
        ],
      },
      {
        id: 'service',
        heading: 'Gra może się zmieniać',
        paragraphs: [
          'RideIdle jest wciąż rozwijane. Możemy dodawać, zmieniać i usuwać funkcje, a gra bywa czasem niedostępna.',
          'Twój postęp jest przechowywany na naszych serwerach, ale nie możemy obiecać, że nigdy nie zostanie utracony ani zmieniony. Jeśli jest to potrzebne do naprawienia błędu, możemy skorygować stany kont lub postęp i, gdy jest to uzasadnione, wyjaśnić dlaczego.',
        ],
      },
      {
        id: 'ending',
        heading: 'Zakończenie korzystania',
        paragraphs: [
          'Możesz przestać grać w każdej chwili. Konto usuniesz w garażu, w sekcji „Twoje dane”, albo możesz napisać na adres kontaktowy podany na górze tej strony.',
          'Możemy zawiesić lub usunąć konto, które łamie ten regulamin. Konta gości nieaktywne przez {guestDays} dni usuwamy.',
        ],
      },
      {
        id: 'liability',
        heading: 'Nasza odpowiedzialność',
        paragraphs: [
          'Odpowiadamy tak, jak wymaga prawo. Żaden zapis regulaminu nie ogranicza praw, które przysługują Ci z mocy prawa i których nie można ograniczyć, na przykład jako konsumentowi, ani naszej odpowiedzialności za umyślne wyrządzenie szkody, rażące niedbalstwo oraz szkody na życiu lub zdrowiu.',
          'Poza tym, w zakresie dozwolonym przez prawo, nie odpowiadamy za straty pośrednie, na przykład utratę postępu w grze, ponieważ gra jest darmowa i służy rozrywce.',
        ],
      },
      {
        id: 'law',
        heading: 'Prawo',
        paragraphs: ['Nadal obowiązują Cię przepisy o ochronie konsumentów kraju, w którym mieszkasz, z których nie można zrezygnować.'],
      },
      {
        id: 'changes',
        heading: 'Zmiany regulaminu',
        paragraphs: [
          'Możemy zmieniać ten regulamin, na przykład gdy zmienia się gra. Data na górze wskazuje wersję. Gdy zmiana jest ważna, poinformujemy o niej w grze. Jeśli po zmianie nadal korzystasz z gry, obowiązuje Cię nowa wersja; jeśli się z nią nie zgadzasz, możesz przestać grać i poprosić o usunięcie konta.',
        ],
      },
      {
        id: 'contact',
        heading: 'Kontakt',
        paragraphs: ['Pytania o ten regulamin: napisz na adres kontaktowy podany na górze tej strony.'],
      },
    ],
  },
  privacy: {
    title: 'Polityka prywatności',
    intro:
      'Ta polityka wyjaśnia, jakich danych osobowych używa RideIdle, po co, jak długo i co możesz w tej sprawie zrobić. Napisaliśmy ją prostym językiem; jeśli coś jest niejasne, zapytaj nas.',
    sections: [
      {
        id: 'controller',
        heading: 'Kto odpowiada',
        paragraphs: [
          'Operator podany na górze tej strony decyduje, po co i w jaki sposób używane są Twoje dane (jest ich administratorem). Możesz się z nim skontaktować pod podanym tam adresem, także w każdej sprawie dotyczącej Twoich danych.',
        ],
      },
      {
        id: 'data',
        heading: 'Jakich danych używamy',
        paragraphs: [
          'Jako gość: imię kierowcy, które wybierasz (widzą je inni kierowcy), język gry, Twój postęp (auta, biegi, gotówka, doświadczenie i ich historia), czas ostatniej aktywności oraz token urządzenia. Token to losowy sekret przechowywany w Twojej przeglądarce; my przechowujemy tylko jego odcisk (skrót).',
          'Gdy zabezpieczasz konto: Twój adres e-mail albo identyfikator, który Google, Facebook lub Apple nadaje Twojemu kontu, razem z adresem e-mail, który zgłaszają. Gra pokazuje adres tylko w skróconej postaci, na przykład t***@example.com.',
          'Zapisujemy też moment akceptacji regulaminu i potwierdzenia wieku oraz wersję regulaminu, którą zaakceptowano. Jeśli utworzysz kod odzyskiwania, przechowujemy tylko jego odcisk.',
          'Dla linków do logowania i logowania przez dostawcę przechowujemy krótkotrwały zapis: odcisk sekretu, Twój adres e-mail i cel. Jest usuwany wkrótce po wygaśnięciu lub użyciu.',
          'Aby zapobiegać nadużyciom, liczymy żądania według adresu IP oraz odcisku adresu e-mail, przez najwyżej godzinę. Nasze serwery i hosting mogą też przechowywać dzienniki techniczne, na przykład adres IP i czas żądania.',
          'Nie używamy narzędzi reklamowych, analitycznych ani śledzących i nie sprzedajemy Twoich danych.',
        ],
      },
      {
        id: 'why',
        heading: 'Po co i na jakiej podstawie',
        paragraphs: [
          'Aby dać Ci grę i konto: kierowcę, postęp, logowanie i e-maile, o które prosisz, na przykład link do logowania. Podstawa: jest to potrzebne do świadczenia gry, o którą prosisz (art. 6 ust. 1 lit. b RODO).',
          'Aby gra była bezpieczna i uczciwa, aby przeciwdziałać nadużyciom i oszustwom oraz naprawiać błędy. Podstawa: nasz prawnie uzasadniony interes w prowadzeniu bezpiecznej gry (art. 6 ust. 1 lit. f RODO).',
          'Aby móc wykazać akceptację regulaminu i potwierdzenie wieku. Podstawa: nasz prawnie uzasadniony interes w możliwości wykazania tego (art. 6 ust. 1 lit. f RODO).',
        ],
      },
      {
        id: 'recipients',
        heading: 'Kto je otrzymuje',
        paragraphs: [
          'Firmy, które pomagają nam prowadzić grę: hosting i infrastruktura oraz usługa wysyłająca e-maile. Mogą używać danych wyłącznie na nasze polecenie.',
          'Google, Facebook (Meta) lub Apple, tylko jeśli wybierzesz logowanie przez nie. Nie wysyłamy im niczego o Twojej grze. Przekazują nam Twój identyfikator i adres e-mail, a Twoje dane wykorzystują zgodnie z własnymi politykami prywatności.',
          'Nie przekazujemy Twoich danych nikomu innemu, chyba że wymaga tego prawo.',
        ],
      },
      {
        id: 'transfers',
        heading: 'Poza Twoim krajem',
        paragraphs: [
          'Niektóre z tych firm mogą przetwarzać dane poza Europejskim Obszarem Gospodarczym lub poza Twoim krajem. W takim przypadku opieramy się na zabezpieczeniach wymaganych przez prawo, na przykład standardowych klauzulach umownych.',
        ],
      },
      {
        id: 'retention',
        heading: 'Jak długo przechowujemy dane',
        paragraphs: [
          'Konto gościa wraz z danymi z gry jest usuwane po {guestDays} dniach bez aktywności.',
          'Zabezpieczone konto jest przechowywane, dopóki istnieje. Możesz je usunąć w każdej chwili, w garażu albo prosząc nas o to.',
          'Linki do logowania i zapisy logowań są usuwane wkrótce po wygaśnięciu lub użyciu. Liczniki żądań służące do zapobiegania nadużyciom działają najwyżej godzinę. Dzienniki techniczne przechowujemy tylko tak długo, jak potrzebuje tego bezpieczeństwo.',
        ],
      },
      {
        id: 'rights',
        heading: 'Twoje prawa',
        paragraphs: [
          'Możesz zażądać dostępu do swoich danych, ich sprostowania, usunięcia, ograniczenia przetwarzania i przeniesienia, a także sprzeciwić się przetwarzaniu opartemu na naszym prawnie uzasadnionym interesie.',
          'W garażu, w sekcji „Twoje dane”, możesz pobrać kopię swoich danych i usunąć konto. W pozostałych sprawach napisz na adres kontaktowy podany na górze tej strony. Odpowiadamy w ciągu miesiąca. Aby chronić Twoje konto, możemy poprosić o potwierdzenie, że jest Twoje, na przykład linkiem wysłanym na adres e-mail konta.',
          'Możesz też złożyć skargę do organu ochrony danych w swoim kraju.',
        ],
      },
      {
        id: 'storage',
        heading: 'Co zostaje w Twojej przeglądarce',
        paragraphs: [
          'RideIdle nie używa plików cookie. Zapisuje kilka rzeczy w pamięci lokalnej (local storage) przeglądarki: token urządzenia, wybrany język, znacznik ostatniego stanu biegu, który został wyświetlony, informację, że okno z prośbą o zapisanie garażu zostało odłożone, oraz, przez kilka minut podczas logowania przez dostawcę, jednorazowy sekret. Gra potrzebuje ich do działania i nie służą do śledzenia Cię.',
          'Jeśli wyczyścisz dane przeglądarki, nastąpi wylogowanie. Konta gościa bez kodu odzyskiwania nie da się już otworzyć.',
        ],
      },
      {
        id: 'age',
        heading: 'Wiek',
        paragraphs: ['Gra jest dla osób, które mają co najmniej {minAge} lat. Jeśli uważasz, że konto ma osoba młodsza, napisz do nas, a je usuniemy.'],
      },
      {
        id: 'security',
        heading: 'Bezpieczeństwo',
        paragraphs: [
          'Sekrety gry (kody odzyskiwania, linki do logowania, tokeny urządzeń) przechowujemy wyłącznie jako odciski i ograniczamy liczbę prób ich użycia. Żaden system nie jest w pełni bezpieczny, więc nie udostępniaj nikomu kodu odzyskiwania ani linków do logowania.',
        ],
      },
      {
        id: 'changes',
        heading: 'Zmiany polityki',
        paragraphs: ['Możemy zmieniać tę politykę. Data na górze wskazuje wersję. Gdy zmiana jest ważna, poinformujemy o niej w grze.'],
      },
      {
        id: 'contact',
        heading: 'Kontakt',
        paragraphs: ['Pytania lub prośby dotyczące Twoich danych: napisz na adres kontaktowy podany na górze tej strony.'],
      },
    ],
  },
}

export default texts
