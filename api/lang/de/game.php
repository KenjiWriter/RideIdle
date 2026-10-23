<?php

return [

    'errors' => [
        'insufficient_funds' => 'Nicht genug Geld.',
        'run_exists' => 'Du hast bereits ein Auto auf der Strecke.',
        'no_run' => 'Du hast noch kein Auto auf der Strecke.',
        'player_level_required' => 'Erfordert Spielerstufe :required (du hast :current).',
        'car_level_required' => 'Erfordert Autostufe :required (du hast :current).',
        'workshop_level_required' => 'Erfordert Werkstattstufe :required.',
        'car_locked' => 'Dieses Auto ist in einem Turnier gesperrt und kann nicht geändert werden.',
        'part_not_for_sale' => 'Dieses Teil ist nicht im Angebot.',
        'part_not_found' => 'Du besitzt dieses Teil nicht.',
        'part_busy' => 'Dieses Teil wartet bereits in der Werkstatt.',
        'part_mounted' => 'Dieses Teil ist bereits am Auto montiert.',
        'part_not_mounted' => 'Dieses Teil ist nicht am Auto montiert.',
        'queue_full' => 'Die Warteschlange der Werkstatt ist voll.',
        'slot_busy' => 'Ein anderes Teil für diesen Platz wartet bereits in der Werkstatt.',
        'no_car' => 'Du hast noch kein Auto.',
        'request_reused' => 'Diese Anfrage wurde bereits für etwas anderes verwendet.',
    ],

    'fuel' => [
        'empty' => 'Der Tank ist leer.',
        'low' => 'Der Kraftstoff wird knapp.',
    ],

    'rarity' => [
        'common' => 'Gewöhnlich',
        'uncommon' => 'Ungewöhnlich',
        'rare' => 'Selten',
        'epic' => 'Episch',
        'legendary' => 'Legendär',
    ],

    'drivetrain' => [
        'fwd' => 'Frontantrieb',
        'rwd' => 'Heckantrieb',
        'awd' => 'Allradantrieb',
    ],

    'weather' => [
        'dry' => 'Trocken',
        'rain' => 'Regen',
        'night' => 'Nacht',
    ],

];
