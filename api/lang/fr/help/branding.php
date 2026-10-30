<?php

return [
    'entries' => [
        'color_bg' => [
            'label' => 'Fond de la page',
            'help' => 'La couleur de fond de toute l’application et des champs de saisie. En général la couleur la plus sombre du thème ; le fond de la page lui-même est cette couleur légèrement assombrie.',
            'range' => '#rrggbb : six chiffres hexadécimaux. Champ vide : la couleur de l’application (:default).',
            'tip' => 'Les creux sombres et les ombres sous les panneaux sont aussi calculés à partir de cette couleur ; la modifier déplace donc tout le « bas » du thème.',
        ],
        'color_surface' => [
            'label' => 'Surface (cartes)',
            'help' => 'La couleur des cartes et des panneaux : écrans du garage, de la boutique et de l’atelier, et cartes plates de la page d’accueil. Elle doit être un peu plus claire que le fond.',
            'range' => '#rrggbb : six chiffres hexadécimaux. Champ vide : la couleur de l’application (:default).',
        ],
        'color_surface_2' => [
            'label' => 'Surface, niveau 2',
            'help' => 'Une surface plus claire : le haut des panneaux et des tuiles, les en-têtes de tableaux et le fond éclairé de la scène où se tient la voiture.',
            'range' => '#rrggbb : six chiffres hexadécimaux. Champ vide : la couleur de l’application (:default).',
        ],
        'color_surface_3' => [
            'label' => 'Surface, niveau 3',
            'help' => 'La surface la plus claire : plaques des boutons, pastilles, barre supérieure du jeu et cases vides de la jauge de carburant.',
            'range' => '#rrggbb : six chiffres hexadécimaux. Champ vide : la couleur de l’application (:default).',
        ],
        'color_line' => [
            'label' => 'Ligne',
            'help' => 'Les lignes et bordures fines : séparations entre les lignes des tableaux, contours des cartes et des barres.',
            'range' => '#rrggbb : six chiffres hexadécimaux. Champ vide : la couleur de l’application (:default).',
        ],
        'color_line_2' => [
            'label' => 'Ligne plus marquée',
            'help' => 'Des bordures plus visibles : champs de saisie, boutons secondaires, étiquettes et bords des plaques.',
            'range' => '#rrggbb : six chiffres hexadécimaux. Champ vide : la couleur de l’application (:default).',
        ],
        'color_ink' => [
            'label' => 'Texte principal',
            'help' => 'La couleur des titres, des chiffres et du texte courant. Elle doit se détacher nettement du fond et des surfaces, car la lisibilité de toute l’application en dépend.',
            'range' => '#rrggbb : six chiffres hexadécimaux. Champ vide : la couleur de l’application (:default).',
            'tip' => 'À moitié transparente, cette couleur est aussi le contour du nom de la voiture dessiné derrière elle sur la scène.',
        ],
        'color_muted' => [
            'label' => 'Texte atténué',
            'help' => 'Le texte de second plan : descriptions, paragraphes des pages, lignes des tableaux et icône d’engrenage de la barre supérieure.',
            'range' => '#rrggbb : six chiffres hexadécimaux. Champ vide : la couleur de l’application (:default).',
        ],
        'color_faint' => [
            'label' => 'Texte discret',
            'help' => 'Le texte le plus discret : petites légendes au-dessus des champs, indications dans les champs vides et emplacements vides. N’abaissez pas le contraste au point de le rendre illisible.',
            'range' => '#rrggbb : six chiffres hexadécimaux. Champ vide : la couleur de l’application (:default).',
            'tip' => 'Quelques petits éléments ont des couleurs fixes (par exemple les libellés gris du menu du bas, la ligne sous le titre d’un écran et le téléphone du tutoriel) ; les couleurs de cette page ne les changent donc pas.',
        ],
        'color_amber' => [
            'label' => 'Accent (ambre par défaut)',
            'help' => 'La couleur d’accent principale, pour tout ce qui peut être pressé ou mis en valeur : le bouton principal, l’insigne de niveau, les barres de progression, l’onglet actif du menu, la bordure d’une carte mise en avant, les bandes d’avertissement et la pièce.',
            'range' => '#rrggbb : six chiffres hexadécimaux. Champ vide : la couleur de l’application (:default).',
            'tip' => 'Les nuances claire et sombre du bouton, son bord et son halo sont tous calculés à partir de cette seule couleur. « Amber » n’est que le nom du rôle : la couleur peut être n’importe laquelle.',
        ],
        'color_amber_ink' => [
            'label' => 'Texte sur l’accent',
            'help' => 'La couleur des lettres et des icônes posées sur l’accent : texte du bouton principal, numéro de niveau, libellés sur les plaques. Elle doit contraster fortement avec la couleur d’accent.',
            'range' => '#rrggbb : six chiffres hexadécimaux. Champ vide : la couleur de l’application (:default).',
        ],
        'color_amber_bg' => [
            'label' => 'Fond de l’accent',
            'help' => 'Une nuance très sombre de l’accent : fond de la ligne « Vous » dans le tableau de course et seconde couleur des bandes d’avertissement de la page d’accueil.',
            'range' => '#rrggbb : six chiffres hexadécimaux. Champ vide : la couleur de l’application (:default).',
        ],
        'color_cyan' => [
            'label' => 'Second accent (cyan par défaut)',
            'help' => 'La couleur de ce qui est en cours : jauge de carburant de l’écran du réservoir, barres et anneaux des tâches de l’atelier, bordure d’un panneau « en cours », gemme RideCoin et plaque de classe de la voiture dans la barre supérieure.',
            'range' => '#rrggbb : six chiffres hexadécimaux. Champ vide : la couleur de l’application (:default).',
            'tip' => 'Elle teinte aussi légèrement le fond de la scène où se tient la voiture.',
        ],
        'color_cyan_bg' => [
            'label' => 'Nuance sombre du second accent',
            'help' => 'La couleur du texte et des icônes sur les plaques du second accent : plaque de classe de la voiture dans la barre supérieure et horloge d’une tâche en attente. Malgré son nom « bg », ce n’est pas un fond.',
            'range' => '#rrggbb : six chiffres hexadécimaux. Champ vide : la couleur de l’application (:default).',
        ],
        'color_ok' => [
            'label' => 'Réussite (vert)',
            'help' => 'La couleur de ce qui s’est bien passé : remplissage de la jauge de carburant de la barre supérieure, bouton « + » du réservoir, signe plus quand une statistique augmente, bordure d’une pièce montée et étiquettes « OK ».',
            'range' => '#rrggbb : six chiffres hexadécimaux. Champ vide : la couleur de l’application (:default).',
        ],
        'color_danger' => [
            'label' => 'Erreur et avertissement (rouge)',
            'help' => 'La couleur des erreurs et des alertes : messages d’erreur, carburant bas, pièce usée, boutons de suppression et signe moins quand une statistique baisse.',
            'range' => '#rrggbb : six chiffres hexadécimaux. Champ vide : la couleur de l’application (:default).',
        ],
        'color_r1' => [
            'label' => 'Rareté 1 : commune',
            'help' => 'La couleur de la rareté la plus basse des pièces (« Commune » dans le jeu) : nom de la rareté, petits carrés, bordure de la tuile d’une pièce et repère de la pièce sur la voiture.',
            'range' => '#rrggbb : six chiffres hexadécimaux. Champ vide : la couleur de l’application (:default).',
            'tip' => 'C’est aussi la couleur du texte des onglets inactifs (Voiture | Flotte, Boutique | Bourse | Concessionnaire).',
        ],
        'color_r2' => [
            'label' => 'Rareté 2 : peu commune',
            'help' => 'La couleur de la rareté « Peu commune » : nom de la rareté, petits carrés, bordure de la tuile d’une pièce et repère de la pièce sur la voiture.',
            'range' => '#rrggbb : six chiffres hexadécimaux. Champ vide : la couleur de l’application (:default).',
        ],
        'color_r3' => [
            'label' => 'Rareté 3 : rare',
            'help' => 'La couleur de la rareté « Rare » : nom de la rareté, petits carrés, bordure de la tuile d’une pièce et repère de la pièce sur la voiture.',
            'range' => '#rrggbb : six chiffres hexadécimaux. Champ vide : la couleur de l’application (:default).',
        ],
        'color_r4' => [
            'label' => 'Rareté 4 : épique',
            'help' => 'La couleur de la rareté « Épique » : nom de la rareté, petits carrés, bordure de la tuile d’une pièce et repère de la pièce sur la voiture.',
            'range' => '#rrggbb : six chiffres hexadécimaux. Champ vide : la couleur de l’application (:default).',
        ],
        'color_r5' => [
            'label' => 'Rareté 5 : légendaire',
            'help' => 'La couleur de la rareté la plus haute, « Légendaire » : nom de la rareté, petits carrés, bordure de la tuile d’une pièce et repère de la pièce sur la voiture.',
            'range' => '#rrggbb : six chiffres hexadécimaux. Champ vide : la couleur de l’application (:default).',
            'tip' => 'Par défaut, elle a la même couleur que l’accent, si bien que les pièces légendaires brillent comme des boutons. Modifiez-la séparément si l’accent doit être différent.',
        ],
        'font_display' => [
            'label' => 'Police des titres',
            'help' => 'La police des titres, des boutons, des noms de voitures et des grands chiffres (dans l’application, elle est étroite, grasse et en capitales). Champ vide : la police de l’application.',
            'options' => [
                'barlow-condensed' => ['label' => 'Barlow Condensed', 'help' => 'La police étroite et robuste de l’application, par défaut pour les titres. Le fichier est fourni avec l’application.'],
                'barlow' => ['label' => 'Barlow', 'help' => 'La police de texte lisible de l’application, par défaut pour le texte courant. Le fichier est fourni avec l’application.'],
                'jetbrains-mono' => ['label' => 'JetBrains Mono', 'help' => 'Une police à chasse fixe, par défaut pour les chiffres. Le fichier est fourni avec l’application.'],
                'system-sans' => ['label' => 'Sans empattement système', 'help' => 'La police sans empattement de l’appareil du joueur (par exemple Segoe UI ou Roboto) ; elle varie un peu d’un appareil à l’autre.'],
                'system-serif' => ['label' => 'À empattement système', 'help' => 'La police à empattement de l’appareil du joueur (Georgia ou Times New Roman).'],
                'system-mono' => ['label' => 'Chasse fixe système', 'help' => 'La police à chasse fixe de l’appareil du joueur (Consolas, Courier New ou équivalente).'],
            ],
        ],
        'font_sans' => [
            'label' => 'Police du texte',
            'help' => 'La police du texte courant : descriptions, paragraphes, messages et champs de saisie. Champ vide : la police de l’application.',
            'options' => [
                'barlow-condensed' => ['label' => 'Barlow Condensed', 'help' => 'La police étroite et robuste de l’application, par défaut pour les titres. Le fichier est fourni avec l’application.'],
                'barlow' => ['label' => 'Barlow', 'help' => 'La police de texte lisible de l’application, par défaut pour le texte courant. Le fichier est fourni avec l’application.'],
                'jetbrains-mono' => ['label' => 'JetBrains Mono', 'help' => 'Une police à chasse fixe, par défaut pour les chiffres. Le fichier est fourni avec l’application.'],
                'system-sans' => ['label' => 'Sans empattement système', 'help' => 'La police sans empattement de l’appareil du joueur (par exemple Segoe UI ou Roboto) ; elle varie un peu d’un appareil à l’autre.'],
                'system-serif' => ['label' => 'À empattement système', 'help' => 'La police à empattement de l’appareil du joueur (Georgia ou Times New Roman).'],
                'system-mono' => ['label' => 'Chasse fixe système', 'help' => 'La police à chasse fixe de l’appareil du joueur (Consolas, Courier New ou équivalente).'],
            ],
        ],
        'font_mono' => [
            'label' => 'Police des chiffres',
            'help' => 'La police des chiffres et des petites légendes : pastilles de la barre supérieure, libellés au-dessus des champs et étiquettes. Champ vide : la police de l’application.',
            'options' => [
                'barlow-condensed' => ['label' => 'Barlow Condensed', 'help' => 'La police étroite et robuste de l’application, par défaut pour les titres. Le fichier est fourni avec l’application.'],
                'barlow' => ['label' => 'Barlow', 'help' => 'La police de texte lisible de l’application, par défaut pour le texte courant. Le fichier est fourni avec l’application.'],
                'jetbrains-mono' => ['label' => 'JetBrains Mono', 'help' => 'Une police à chasse fixe, par défaut pour les chiffres. Le fichier est fourni avec l’application.'],
                'system-sans' => ['label' => 'Sans empattement système', 'help' => 'La police sans empattement de l’appareil du joueur (par exemple Segoe UI ou Roboto) ; elle varie un peu d’un appareil à l’autre.'],
                'system-serif' => ['label' => 'À empattement système', 'help' => 'La police à empattement de l’appareil du joueur (Georgia ou Times New Roman).'],
                'system-mono' => ['label' => 'Chasse fixe système', 'help' => 'La police à chasse fixe de l’appareil du joueur (Consolas, Courier New ou équivalente).'],
            ],
        ],
        'css' => [
            'label' => 'Feuille de style propre (CSS)',
            'help' => 'Des règles CSS qui viennent après la feuille de style de l’application et ont le dernier mot, donc elles peuvent changer l’aspect de n’importe quel élément. La feuille ne peut rien charger de l’extérieur : sont refusés @import, @font-face, @namespace, image-set(), src(), image(), element(), cross-fade(), paint(), attr(), expression(), javascript :, behavior :, -moz-binding, tout url() autre qu’une image du jeu (/api/v1/media/nom) et une barre oblique inverse hors d’une chaîne entre guillemets.',
            'example' => '.ttl { text-shadow: none; } retire l’ombre sous les titres des écrans.',
            'range' => '20 000 octets au plus (une lettre hors ASCII prend de 2 à 4 octets).',
            'tip' => 'La feuille est lue comme le lit un navigateur : les commentaires et les chaînes entre guillemets sont ignorés, donc content : "\201C" est permis. Une feuille qui ne passe pas n’est pas enregistrée, et le message donne la raison de chaque chose refusée ; une feuille n’est jamais acceptée en partie. Une image dans url() doit déjà être dans le magasin d’images du jeu (on la téléverse dans le panneau), et son nom est fait de 64 caractères hexadécimaux suivis de l’extension png, webp ou jpg.',
        ],
        'blocks' => [
            'label' => 'Blocs de la page',
            'help' => 'La page à l’adresse du domaine du jeu, de haut en bas. Ajoutez des blocs et faites-les glisser pour changer l’ordre ; il y en a 12 au plus. Sans aucun bloc, le jeu affiche la page d’accueil propre à l’application.',
            'tip' => 'Les types de blocs : Haut de page (surtitre, titre, sous-titre, une image et les boutons de départ), Cartes (de 1 à 6), Texte (un titre et des paragraphes), Image (avec une description et une légende) et Boutons (un titre, une phrase et les boutons de départ). L’application ajoute elle-même les boutons « Créer votre pilote » et « Continuer », et un bloc auquel manque l’indispensable (un titre, par exemple) est ignoré.',
        ],
        'items' => [
            'label' => 'Cartes',
            'help' => 'De 1 à 6 cartes dans un même bloc, côte à côte (l’une sous l’autre sur un écran étroit). Chacune a un repère (une petite légende au-dessus du titre, facultative), un titre et un texte.',
        ],
        'text_eyebrow' => [
            'label' => 'Surtitre',
            'help' => 'Une petite ligne en capitales au-dessus du titre principal. Écrivez-la dans les langues que le jeu doit parler ; l’anglais et le polonais sont obligatoires dès qu’une langue a du texte dans le champ, et une langue sans texte affiche l’anglais.',
            'example' => 'Jeu de navigateur · idle · course (page d’accueil propre à l’application).',
            'range' => 'Jusqu’à :limit caractères dans chaque langue.',
        ],
        'text_title' => [
            'label' => 'Titre',
            'help' => 'L’intitulé : dans le bloc Haut de page, c’est le grand titre de la page (obligatoire), sur une carte le titre de la carte (obligatoire), et dans les blocs Texte et Boutons l’intitulé au-dessus du contenu (facultatif). L’anglais et le polonais sont obligatoires quand le champ a du texte ; une langue sans texte affiche l’anglais.',
            'example' => 'Un atelier qui court pendant votre absence (page d’accueil propre à l’application).',
            'range' => 'Jusqu’à :limit caractères dans chaque langue.',
        ],
        'text_subtitle' => [
            'label' => 'Sous-titre',
            'help' => 'La phrase sous le titre dans le bloc Haut de page, juste au-dessus des boutons de départ. L’anglais et le polonais sont obligatoires quand le champ a du texte ; une langue sans texte affiche l’anglais.',
            'range' => 'Jusqu’à :limit caractères dans chaque langue.',
        ],
        'text_alt' => [
            'label' => 'Description de l’image',
            'help' => 'Une description de l’image pour les personnes qui ne la voient pas, et pour le cas où elle ne se charge pas. Obligatoire dans le bloc Image, facultative dans le bloc Haut de page.',
            'example' => 'L’atelier de Złomowo au crépuscule et trois épaves dans la cour.',
            'range' => 'Jusqu’à :limit caractères dans chaque langue.',
        ],
        'text_kicker' => [
            'label' => 'Repère de carte',
            'help' => 'Une petite ligne en capitales au-dessus du titre d’une carte. Facultatif ; l’anglais et le polonais sont obligatoires quand le champ a du texte, et une langue sans texte affiche l’anglais.',
            'example' => 'Choix, Progression, Idle (les cartes de la page d’accueil propre à l’application).',
            'range' => 'Jusqu’à :limit caractères dans chaque langue.',
        ],
        'text_text' => [
            'label' => 'Texte',
            'help' => 'Un texte court : sur une carte la description sous le titre (obligatoire), dans le bloc Boutons la phrase sous le titre (facultative). C’est du texte brut, jamais du HTML. Une langue sans texte affiche l’anglais.',
            'range' => 'Jusqu’à :limit caractères dans chaque langue.',
        ],
        'text_body' => [
            'label' => 'Contenu',
            'help' => 'Le contenu du bloc Texte. Une ligne vide commence un nouveau paragraphe ; tout est du texte brut, jamais du HTML. L’anglais et le polonais sont obligatoires ; une langue sans texte affiche l’anglais.',
            'range' => 'Jusqu’à :limit caractères dans chaque langue.',
        ],
        'text_caption' => [
            'label' => 'Légende',
            'help' => 'La légende sous l’image dans le bloc Image. Facultative ; l’anglais et le polonais sont obligatoires quand le champ a du texte, et une langue sans texte affiche l’anglais.',
            'range' => 'Jusqu’à :limit caractères dans chaque langue.',
        ],
        'text_description' => [
            'label' => 'Description de l’aperçu du lien',
            'help' => 'La phrase sous le titre dans l’aperçu qu’une messagerie ou un réseau social fait de l’adresse du jeu. Il n’y a qu’un aperçu : il prend le texte dans la langue du jeu (celle de ses réglages) ou l’anglais si cette langue n’en a pas, donc les autres langues ne sont pas lues. Champ vide : le nom du jeu.',
            'range' => 'Jusqu’à :limit caractères dans chaque langue.',
        ],
        'share_title' => [
            'label' => 'Titre de l’aperçu du lien',
            'help' => 'Le titre qu’une messagerie ou un réseau social affiche quand quelqu’un colle l’adresse du jeu. C’est un seul texte, sans langues. Champ vide : le nom du jeu.',
            'range' => '70 caractères au plus.',
        ],
        'image_upload' => [
            'label' => 'Image',
            'help' => 'L’image du bloc. Choisissez un fichier : l’enregistrement du brouillon le garde dans le magasin d’images et inscrit son nom dans le bloc ; sans choix, l’image actuelle reste.',
            'range' => 'PNG, WebP ou JPEG, 1 Mo au plus, chaque côté de :min à :max pixels.',
            'tip' => 'Dans le bloc Haut de page, l’image est étirée sur toute la largeur en bandeau de 220 px de haut (340 px sur un grand écran) et recadrée ; gardez donc l’important au centre. Dans le bloc Image, elle est montrée en entier sur toute la largeur.',
        ],
        'share_image_upload' => [
            'label' => 'Image de l’aperçu',
            'help' => 'L’image de l’aperçu du lien, en général 1200 × 630 pixels. Choisissez un fichier : l’enregistrement du brouillon le garde dans le magasin d’images ; sans choix, l’image actuelle reste.',
            'range' => 'PNG, WebP ou JPEG, 1 Mo au plus, chaque côté de :min à :max pixels.',
            'tip' => 'Sans image propre, l’aperçu prend l’icône du jeu ; un jeu sans icône n’a pas d’image dans l’aperçu.',
        ],
        'image_remove' => [
            'label' => 'Retirer l’image',
            'help' => 'Activez-le et enregistrez le brouillon pour que le bloc (ou l’aperçu du lien) n’ait plus d’image. Un fichier choisi dans le champ voisin est prioritaire. N’apparaît que lorsqu’une image est définie.',
            'tip' => 'Le bloc Image exige une image : l’enregistrement sans elle est refusé.',
        ],
    ],
];
