import type { LegalTexts } from '../types'

const texts: LegalTexts = {
  terms: {
    title: 'Conditions d’utilisation',
    intro:
      'Ces conditions s’appliquent à RideIdle, un jeu de course idle qui fonctionne dans votre navigateur. Lisez-les avec la politique de confidentialité. L’exploitant du jeu est indiqué en haut de cette page.',
    sections: [
      {
        id: 'scope',
        heading: 'À qui s’adressent ces conditions',
        paragraphs: [
          'En créant un pilote, en vous connectant ou en utilisant RideIdle de toute autre manière, vous acceptez ces conditions. Si vous ne les acceptez pas, n’utilisez pas le jeu.',
          'Si une partie de ces conditions ne peut pas s’appliquer à vous en raison d’une loi impérative du lieu où vous vivez, le reste continue de s’appliquer.',
        ],
      },
      {
        id: 'age',
        heading: 'Âge minimum',
        paragraphs: [
          'Vous devez avoir au moins {minAge} ans pour utiliser RideIdle. Lorsque vous sécurisez votre compte, nous vous demandons de confirmer votre âge et nous enregistrons le moment de cette confirmation.',
          'Si nous apprenons qu’une personne n’ayant pas l’âge minimum possède un compte, nous pouvons le supprimer.',
        ],
      },
      {
        id: 'account',
        heading: 'Votre pilote et votre compte',
        paragraphs: [
          'Vous commencez avec un compte invité : vous choisissez un nom de pilote et le jeu crée ce compte sans demander de données personnelles. Un compte invité est supprimé, avec tout son contenu, après {guestDays} jours sans activité.',
          'Vous pouvez sécuriser votre compte avec une adresse e-mail, Google, Facebook ou Apple. Un compte sécurisé n’expire pas et peut être ouvert sur un autre appareil. Vous pouvez aussi garder un code de récupération. Toute personne qui possède votre code de récupération ou un lien de connexion peut accéder à votre compte : gardez-les pour vous.',
          'Choisissez un nom de pilote qui ne soit pas offensant et qui n’usurpe pas l’identité d’une autre personne. Nous pouvons modifier ou supprimer un nom qui enfreint cette règle.',
          'Vous êtes responsable de toute activité effectuée depuis votre compte. Donnez-nous des informations exactes lorsque vous le sécurisez.',
        ],
      },
      {
        id: 'fair-play',
        heading: 'Jeu loyal',
        paragraphs: [
          'Le serveur décide de chaque résultat dans le jeu. Jouez comme le jeu est conçu pour être joué.',
          'Ne trichez pas, n’utilisez pas de bots ni de scripts qui jouent à votre place, n’exploitez pas volontairement les erreurs du jeu, n’essayez pas d’accéder aux comptes d’autres personnes et ne perturbez pas le service pour les autres. Si vous trouvez une erreur, signalez-la-nous au lieu de l’utiliser.',
          'Si une erreur ou un jeu déloyal a donné à quelqu’un de l’argent du jeu, de l’expérience ou autre chose qu’il ne devait pas avoir, nous pouvons le corriger.',
        ],
      },
      {
        id: 'virtual-items',
        heading: 'Biens virtuels',
        paragraphs: [
          'L’argent du jeu, l’expérience, les voitures et les pièces font partie du jeu. Ils n’ont aucune valeur en dehors du jeu, ne peuvent pas être échangés contre de l’argent réel et ne deviennent pas votre propriété.',
          'RideIdle est gratuit pour le moment et n’a pas de fonctions payantes. Si cela change, nous vous le dirons, et des conditions distinctes s’appliqueront aux achats.',
        ],
      },
      {
        id: 'service',
        heading: 'Le jeu peut évoluer',
        paragraphs: [
          'RideIdle est encore en développement. Nous pouvons ajouter, modifier ou supprimer des fonctions, et le jeu peut être indisponible de temps en temps.',
          'Votre progression est conservée sur nos serveurs, mais nous ne pouvons pas promettre qu’elle ne sera jamais perdue ni modifiée. Lorsque c’est nécessaire pour corriger une erreur, nous pouvons ajuster des soldes ou la progression et, lorsque c’est raisonnable, en expliquer la raison.',
        ],
      },
      {
        id: 'ending',
        heading: 'Fin de l’utilisation',
        paragraphs: [
          'Vous pouvez arrêter de jouer à tout moment. Pour faire supprimer votre compte, écrivez à l’adresse de contact indiquée en haut de cette page.',
          'Nous pouvons suspendre ou supprimer un compte qui enfreint ces conditions. Nous supprimons les comptes invités inactifs depuis {guestDays} jours.',
        ],
      },
      {
        id: 'liability',
        heading: 'Notre responsabilité',
        paragraphs: [
          'Notre responsabilité est engagée dans les conditions prévues par la loi. Rien dans ces conditions ne limite un droit que la loi vous accorde et qui ne peut pas être limité, par exemple en tant que consommateur, ni notre responsabilité en cas de faute intentionnelle, de faute lourde ou de dommage corporel.',
          'Pour le reste, et dans la mesure permise par la loi, nous ne sommes pas responsables des dommages indirects, comme la perte de progression dans le jeu, car le jeu est gratuit et proposé pour le divertissement.',
        ],
      },
      {
        id: 'law',
        heading: 'Droit applicable',
        paragraphs: ['Les règles de protection des consommateurs du pays où vous vivez, auxquelles il n’est pas possible de renoncer, continuent de s’appliquer à vous.'],
      },
      {
        id: 'changes',
        heading: 'Modification des conditions',
        paragraphs: [
          'Nous pouvons modifier ces conditions, par exemple lorsque le jeu change. La date en haut indique la version. Lorsqu’un changement est important, nous l’indiquerons dans le jeu. Si vous continuez à utiliser le jeu après un changement, la nouvelle version s’applique à vous ; si vous ne l’acceptez pas, vous pouvez arrêter de jouer et nous demander de supprimer votre compte.',
        ],
      },
      {
        id: 'contact',
        heading: 'Contact',
        paragraphs: ['Questions sur ces conditions : écrivez à l’adresse de contact indiquée en haut de cette page.'],
      },
    ],
  },
  privacy: {
    title: 'Politique de confidentialité',
    intro:
      'Cette politique explique quelles données personnelles RideIdle utilise, pourquoi, pendant combien de temps, et ce que vous pouvez faire à ce sujet. Nous l’avons écrite avec des mots simples ; si quelque chose n’est pas clair, demandez-nous.',
    sections: [
      {
        id: 'controller',
        heading: 'Qui est responsable',
        paragraphs: [
          'L’exploitant indiqué en haut de cette page décide pourquoi et comment vos données sont utilisées (il est le responsable du traitement). Vous pouvez le joindre à l’adresse de contact qui y figure, y compris pour toute question ou demande concernant vos données.',
        ],
      },
      {
        id: 'data',
        heading: 'Quelles données nous utilisons',
        paragraphs: [
          'Pour un compte invité : le nom de pilote que vous choisissez, la langue du jeu, votre progression (voitures, courses, argent du jeu, expérience et leur historique), l’heure de votre dernière activité et un jeton d’appareil. Le jeton est un secret aléatoire conservé dans votre navigateur ; nous n’en gardons qu’une empreinte cryptographique (hachage).',
          'Lorsque vous sécurisez votre compte : votre adresse e-mail, ou l’identifiant que Google, Facebook ou Apple nous donne pour votre compte, avec l’adresse e-mail que ce fournisseur nous communique. Le jeu n’affiche une adresse que sous forme partiellement masquée, comme t***@example.com.',
          'Nous enregistrons aussi le moment où les conditions ont été acceptées et l’âge confirmé, ainsi que la version des conditions acceptée. Si vous créez un code de récupération, nous n’en gardons qu’une empreinte.',
          'Pour les liens de connexion et les connexions avec un fournisseur, nous gardons un enregistrement de courte durée : une empreinte du secret, votre adresse e-mail et son objet. Il est supprimé peu après son expiration ou son utilisation.',
          'Pour empêcher les abus, nous comptons les requêtes par adresse IP et par empreinte de l’adresse e-mail, pendant une heure au plus. Nos serveurs et notre hébergeur peuvent aussi conserver des journaux techniques, comme l’adresse IP et l’heure d’une requête.',
          'Nous n’utilisons ni publicité, ni outils d’analyse ou de suivi, et nous ne vendons pas vos données.',
        ],
      },
      {
        id: 'why',
        heading: 'Pourquoi nous les utilisons, et sur quelle base',
        paragraphs: [
          'Pour vous fournir le jeu et gérer votre compte : le pilote, votre progression, la connexion et les e-mails que vous demandez, par exemple un lien de connexion. Base légale : c’est nécessaire à l’exécution du service que vous avez demandé (article 6, paragraphe 1, point b, du RGPD).',
          'Pour que le jeu reste sûr et équitable, pour empêcher les abus et la triche, et pour corriger les erreurs. Base légale : notre intérêt légitime à exploiter un jeu sûr (article 6, paragraphe 1, point f, du RGPD).',
          'Pour pouvoir démontrer l’acceptation des conditions et la confirmation de l’âge. Base légale : notre intérêt légitime à pouvoir le démontrer (article 6, paragraphe 1, point f, du RGPD).',
        ],
      },
      {
        id: 'recipients',
        heading: 'Qui les reçoit',
        paragraphs: [
          'Les entreprises qui nous aident à exploiter le jeu : hébergement et infrastructure, et le service qui envoie les e-mails. Elles ne peuvent utiliser les données que sur nos instructions.',
          'Google, Facebook (Meta) ou Apple, seulement si vous choisissez de vous connecter avec eux. Nous ne leur envoyons rien sur votre jeu. Ils nous communiquent votre identifiant et votre adresse e-mail, et ils utilisent vos données selon leurs propres politiques de confidentialité.',
          'Nous ne transmettons vos données à personne d’autre, sauf si la loi l’exige.',
        ],
      },
      {
        id: 'transfers',
        heading: 'Hors de votre pays',
        paragraphs: [
          'Certaines de ces entreprises peuvent traiter des données en dehors de l’Espace économique européen ou de votre pays. Dans ce cas, nous nous appuyons sur les garanties exigées par la loi, par exemple les clauses contractuelles types.',
        ],
      },
      {
        id: 'retention',
        heading: 'Combien de temps nous les gardons',
        paragraphs: [
          'Un compte invité, avec ses données de jeu, est supprimé après {guestDays} jours sans activité.',
          'Un compte sécurisé est conservé tant qu’il existe. Vous pouvez nous demander de le supprimer à tout moment.',
          'Les liens de connexion et les enregistrements de connexion sont supprimés peu après leur expiration ou leur utilisation. Les compteurs de requêtes servant à prévenir les abus durent une heure au plus. Les journaux techniques ne sont conservés que le temps nécessaire à la sécurité.',
        ],
      },
      {
        id: 'rights',
        heading: 'Vos droits',
        paragraphs: [
          'Vous pouvez nous demander l’accès à vos données, leur rectification, leur effacement, la limitation de leur utilisation et leur portabilité. Vous pouvez aussi vous opposer à l’utilisation fondée sur notre intérêt légitime.',
          'Écrivez à l’adresse de contact indiquée en haut de cette page. Nous répondons dans un délai d’un mois. Pour protéger votre compte, nous pouvons vous demander de prouver qu’il est bien le vôtre, par exemple par un lien envoyé à l’adresse e-mail du compte. Pour l’instant, les demandes sont traitées par e-mail.',
          'Vous pouvez aussi introduire une réclamation auprès de l’autorité de contrôle de votre pays.',
        ],
      },
      {
        id: 'storage',
        heading: 'Ce qui reste dans votre navigateur',
        paragraphs: [
          'RideIdle n’utilise pas de cookies. Il enregistre quelques éléments dans le stockage local de votre navigateur : le jeton d’appareil, la langue choisie, un repère du dernier état de votre course que vous avez vu, le fait que vous avez reporté la fenêtre qui vous invite à sauvegarder votre garage et, pendant quelques minutes lors d’une connexion avec un fournisseur, un secret à usage unique. Ces éléments sont nécessaires au jeu et ne servent pas à vous suivre.',
          'Si vous effacez les données de votre navigateur, votre session se ferme. Un compte invité sans code de récupération ne peut plus être rouvert.',
        ],
      },
      {
        id: 'age',
        heading: 'Âge',
        paragraphs: ['Le jeu s’adresse aux personnes qui ont au moins {minAge} ans. Si vous pensez qu’une personne n’ayant pas l’âge minimum possède un compte, écrivez-nous et nous le supprimerons.'],
      },
      {
        id: 'security',
        heading: 'Sécurité',
        paragraphs: [
          'Nous ne gardons les secrets du jeu (codes de récupération, liens de connexion, jetons d’appareil) que sous forme d’empreintes, et nous limitons le nombre d’essais. Aucun système n’est parfaitement sûr : gardez donc votre code de récupération et vos liens de connexion pour vous.',
        ],
      },
      {
        id: 'changes',
        heading: 'Modification de cette politique',
        paragraphs: ['Nous pouvons modifier cette politique. La date en haut indique la version. Lorsqu’un changement est important, nous l’indiquerons dans le jeu.'],
      },
      {
        id: 'contact',
        heading: 'Contact',
        paragraphs: ['Questions ou demandes concernant vos données : écrivez à l’adresse de contact indiquée en haut de cette page.'],
      },
    ],
  },
}

export default texts
