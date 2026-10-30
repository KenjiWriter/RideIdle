<?php

return [
    'entries' => [
        'branding' => [
            'label' => 'Comment fonctionne le formulaire de marque et de page',
            'help' => 'Ici, vous réglez l’apparence de l’application de ce jeu : couleurs, polices, feuille de style propre, blocs de la page d’accueil et aperçu du lien. Un champ vide correspond à l’apparence de l’application ; un brouillon enregistré arrive dans le jeu avec le prochain pack publié.',
            'steps' => [
                'Commencez par les couleurs : modifiez-en quelques-unes (fond, accent, texte) et laissez les autres vides. Les couleurs s’écrivent #rrggbb.',
                'Choisissez les polices des titres, du texte et des chiffres, ou gardez celle de l’application.',
                'Ajoutez les blocs de la page d’accueil depuis le haut (Haut de page, Cartes, Texte, Image, Boutons) et écrivez les textes au moins en anglais et en polonais.',
                'Réglez le titre, la description et l’image de l’aperçu du lien : c’est ce qu’on voit quand quelqu’un colle l’adresse du jeu dans une messagerie.',
                'Ajoutez votre propre CSS en dernier, et seulement si les couleurs ne suffisent pas ; les règles qui ne passent pas sont signalées à l’enregistrement.',
                'Enregistrez le brouillon et publiez un pack pour que les joueurs voient le changement.',
            ],
            'example' => 'Un jeu de bateaux : l’Accent #2fb7c9 au lieu de l’ambre, la police des titres « À empattement système », un bloc Haut de page intitulé « Flottes en mer » (en polonais « Floty na morzu ») et un aperçu du lien avec le même titre.',
        ],
        'vocabulary' => [
            'label' => 'Comment fonctionne le Vocabulaire',
            'help' => 'Le Vocabulaire change les mots de l’application pour ce jeu (noms des emplacements, menu, textes des écrans) dans cinq langues. Un champ vide correspond au texte de l’application ; un brouillon enregistré arrive dans le jeu avec le prochain pack.',
            'steps' => [
                'Choisissez une zone, ou saisissez un mot dans Rechercher et validez avec Entrée ; 30 messages au plus sont affichés à la fois.',
                'Sous la clé d’un message se trouve le texte de l’application, qui apparaît comme indication dans le champ vide d’une langue.',
                'Écrivez le nouveau texte dans les langues dont vous disposez ; laissez inchangés les noms entre accolades, comme {name}.',
                'Remplissez toutes les langues qui comptent pour vous : une langue vide affichera le texte anglais du pack, pas le texte de l’application dans cette langue.',
                'Enregistrez avant de changer de zone, de recherche ou de filtre : les modifications non enregistrées sont perdues. Les messages modifiés ont un point ● près de la clé.',
                'Vous pouvez télécharger un thème entier dans un fichier ou en charger un depuis un fichier avec les boutons en haut de la page ; celui qu’on charge remplace tous les mots du pack dans le brouillon.',
            ],
            'example' => 'Un jeu de bateaux : un emplacement de clé hull reçoit le message slot.hull, en anglais « Hull » et en polonais « Kadłub ».',
        ],
        'rules_text' => [
            'label' => 'Comment fonctionne l’édition des règles en texte',
            'help' => 'La voie avancée : tout le document des règles ou du tutoriel en JSON, pour ce que les autres pages ne montrent pas. Le document est jugé comme sur les autres pages et enregistré en brouillon, qui arrive dans le jeu avec le prochain pack.',
            'steps' => [
                'Choisissez le document : Règles du jeu ou Tutoriel.',
                'Ne changez que ce qu’il faut, et conservez les accolades, les virgules et les guillemets. La plupart des nombres se changent plus facilement dans le Studio de paramètres.',
                'Enregistrez. Une erreur de syntaxe est signalée par le champ, une erreur de contenu par une notification rouge avec l’endroit et la raison ; dans les deux cas, rien n’est enregistré.',
                'La suppression d’une ville, d’un circuit ou d’une étape du tutoriel que des joueurs utilisent est refusée.',
                'Changer de document dans le champ Document abandonne le texte non enregistré.',
            ],
            'example' => 'Dans les Règles du jeu, changez « price_cents_per_liter » dans « fuel » de 200 à 250 (ce sont des centimes : 2,50 $ le litre), enregistrez, et vérifiez dans le Studio de paramètres que la section Carburant affiche 2,50 $.',
        ],
        'studio' => [
            'label' => 'Comment fonctionne le Studio de paramètres',
            'help' => 'Le Studio modifie les nombres du jeu, une section à la fois : prix, durées, récompenses, usure. Les nombres sont dans l’unité dans laquelle on pense (%, s, $, l) ; un brouillon enregistré arrive dans le jeu avec le prochain pack.',
            'steps' => [
                'Laissez le mode simple activé (le rythme et l’argent du jeu) ; désactivez-le quand il vous faut chaque nombre.',
                'Choisissez une section ; la phrase en dessous dit ce que change la modification de ses nombres dans le jeu.',
                'Réglez un nombre avec son curseur, ou désactivez Curseurs et saisissez la valeur exacte. À côté d’un nombre, on voit sa valeur et, en jaune, celle du pack en ligne quand elle est différente ; la flèche remet celle du pack en ligne.',
                'Enregistrez avant de changer de section, de mode ou de curseurs : les modifications non enregistrées sont perdues.',
                'Utilisez l’Aperçu de l’équilibre pour voir comment le brouillon enregistré change le rythme du jeu par rapport au pack en ligne.',
            ],
            'example' => 'Dans la section Carburant, un litre coûte 2,00 $ (200 centimes dans le document). Mettez 2,50 $, enregistrez, et regardez dans l’Aperçu de l’équilibre comment le rythme du jeu change.',
        ],
        'model' => [
            'label' => 'Comment fonctionne le Modèle du jeu',
            'help' => 'Le modèle dit de quoi une voiture est faite : les emplacements où se montent les pièces (avec le rôle que connaissent les formules) et les statistiques que montrent les écrans. Un brouillon enregistré arrive dans le jeu avec le prochain pack.',
            'steps' => [
                'Ajoutez un emplacement : donnez-lui une clé (lettres minuscules, chiffres, _) et choisissez un rôle, ou laissez « Sans rôle ».',
                'Réglez l’ordre des emplacements en les faisant glisser : le garage et la boutique les listent dans cet ordre.',
                'Masquez un emplacement si le garage ne doit pas le lister.',
                'Mettez les statistiques dans l’ordre des écrans, ou omettez celles que le jeu ne doit pas montrer.',
                'Écrivez le nom d’un nouvel emplacement dans le Vocabulaire sous la forme slot.clé (anglais et polonais), pour que le jeu n’affiche pas la clé nue.',
                'Enregistrez ; un refus dit ce qui nomme encore l’emplacement dont vous voulez vous défaire.',
            ],
            'example' => 'Un jeu de bateaux : un emplacement hull au rôle Pneus (c’est lui qui décide de l’adhérence), reactor au rôle Moteur et thrusters au rôle Suralimentation ; les noms « Coque », « Réacteur » et « Propulseurs » écrits dans le Vocabulaire sous les formes slot.hull, slot.reactor et slot.thrusters.',
        ],
    ],
];
