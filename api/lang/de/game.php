<?php

return [

    'errors' => [
        'insufficient_funds' => 'Nicht genug Geld.',
        'run_exists' => 'Du hast bereits ein Auto auf der Strecke.',
        'no_run' => 'Du hast noch kein Auto auf der Strecke.',
        'player_level_required' => 'Erfordert Spielerstufe :required (du hast :current).',
        'car_level_required' => 'Erfordert Autostufe :required (du hast :current). XP bekommst du, indem du dieses Auto fährst.',
        'track_locked' => 'Erfordert Spielerstufe :required (du bist auf Stufe :current). XP bekommst du durch Fahren.',
        'city_locked' => 'Erfordert Spielerstufe :required (du bist auf Stufe :current). XP bekommst du durch Fahren.',
        'city_not_found' => 'Diese Stadt gibt es nicht.',
        'no_road' => 'Von hier führt keine Straße dorthin.',
        'already_there' => 'Das Auto ist schon dort.',
        'already_travelling' => 'Das Auto ist schon unterwegs.',
        'car_cannot_travel' => 'Nur ein fahrendes Auto kann losfahren: Repariere es, tanke es auf oder warte zuerst auf die Werkstatt.',
        'travel_no_fuel' => 'Zu wenig Kraftstoff für die Fahrt: Es braucht :required l, der Tank hat :available l.',
        'car_travelling' => 'Das Auto ist unterwegs, die Werkstatt kann jetzt nicht daran arbeiten.',
        'track_elsewhere' => 'Diese Strecke liegt in einer anderen Stadt. Fahre zuerst dorthin.',
        'car_not_found' => 'Du besitzt dieses Auto nicht.',
        'garage_exists' => 'Du hast in dieser Stadt schon eine Garage.',
        'wreck_not_for_sale' => 'Der Schrottplatz verkauft dieses Auto nicht.',
        'listing_gone' => 'Dieses Auto ist nicht mehr an der Börse.',
        'vehicle_not_for_sale' => 'Der Händler verkauft dieses Auto nicht.',
        'vehicle_level_required' => 'Erfordert Spielerstufe :required (du bist auf Stufe :current). XP bekommst du durch Fahren.',
        'car_busy' => 'Das Auto ist unterwegs, oder die Werkstatt hat noch Arbeit daran, deshalb kann es jetzt nicht verkauft werden.',
        'last_car' => 'Du kannst dein letztes Auto nicht verkaufen.',
        'unit_not_found' => 'Dieses Lagerabteil gibt es nicht.',
        'unit_level_required' => 'Erfordert Spielerstufe :required (du bist auf Stufe :current). XP bekommst du durch Fahren.',
        'no_garage' => 'Du hast keine Garage in :city. Kaufe dort zuerst eine.',
        'garage_full' => 'Die Garage in :city ist voll.',
        'workshop_level_required' => 'Erfordert Werkstattstufe :required.',
        'car_locked' => 'Dieses Auto ist in einem Turnier gesperrt und kann nicht geändert werden.',
        'part_not_for_sale' => 'Dieses Teil ist nicht im Angebot.',
        'part_not_found' => 'Du besitzt dieses Teil nicht.',
        'part_busy' => 'Dieses Teil wartet bereits in der Werkstatt.',
        'part_mounted' => 'Dieses Teil ist bereits am Auto montiert.',
        'part_not_mounted' => 'Dieses Teil ist nicht am Auto montiert.',
        'queue_full' => 'Die Warteschlange der Werkstatt ist voll.',
        'slot_busy' => 'Ein anderes Teil für diesen Platz wartet bereits in der Werkstatt.',
        'job_not_found' => 'Diesen Auftrag gibt es nicht.',
        'job_done' => 'Dieser Auftrag ist bereits erledigt.',
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
