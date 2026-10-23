<?php

return [

    'errors' => [
        'insufficient_funds' => 'Dinheiro insuficiente.',
        'run_exists' => 'Você já tem um carro na pista.',
        'no_run' => 'Você ainda não tem um carro na pista.',
        'player_level_required' => 'Requer nível de jogador :required (você tem :current).',
        'car_level_required' => 'Requer nível do carro :required (você tem :current). Você ganha XP dirigindo este carro.',
        'track_locked' => 'Requer nível de jogador :required (você está no nível :current). Você ganha XP dirigindo.',
        'city_locked' => 'Requer nível de jogador :required (você está no nível :current). Você ganha XP dirigindo.',
        'city_not_found' => 'Essa cidade não existe.',
        'no_road' => 'Não há estrada até lá a partir daqui.',
        'already_there' => 'O carro já está lá.',
        'already_travelling' => 'O carro já está a caminho.',
        'car_cannot_travel' => 'Só um carro que está rodando pode partir: conserte, abasteça ou espere a oficina primeiro.',
        'travel_no_fuel' => 'Combustível insuficiente para a viagem: precisa de :required l e o tanque tem :available l.',
        'car_travelling' => 'O carro está na estrada, então a oficina não pode trabalhar nele agora.',
        'track_elsewhere' => 'Essa pista fica em outra cidade. Vá até lá pela estrada primeiro.',
        'car_not_found' => 'Você não tem este carro.',
        'garage_exists' => 'Você já tem uma garagem nessa cidade.',
        'wreck_not_for_sale' => 'O ferro-velho não vende esse carro.',
        'listing_gone' => 'Esse carro não está mais na bolsa de carros.',
        'vehicle_not_for_sale' => 'A concessionária não vende esse carro.',
        'vehicle_level_required' => 'Requer nível de jogador :required (você está no nível :current). Você ganha XP dirigindo.',
        'no_garage' => 'Você não tem garagem em :city. Compre uma lá primeiro.',
        'garage_full' => 'A garagem em :city está cheia.',
        'workshop_level_required' => 'Requer nível da oficina :required.',
        'car_locked' => 'Este carro está bloqueado em um torneio e não pode ser alterado.',
        'part_not_for_sale' => 'Esta peça não está à venda.',
        'part_not_found' => 'Você não tem esta peça.',
        'part_busy' => 'Esta peça já está aguardando na oficina.',
        'part_mounted' => 'Esta peça já está montada no carro.',
        'part_not_mounted' => 'Esta peça não está montada no carro.',
        'queue_full' => 'A fila da oficina está cheia.',
        'slot_busy' => 'Outra peça para este espaço já está aguardando na oficina.',
        'job_not_found' => 'Esse serviço não existe.',
        'job_done' => 'Esse serviço já foi concluído.',
        'no_car' => 'Você ainda não tem um carro.',
        'request_reused' => 'Esta solicitação já foi usada para outra coisa.',
    ],

    'fuel' => [
        'empty' => 'O tanque está vazio.',
        'low' => 'O combustível está acabando.',
    ],

    'rarity' => [
        'common' => 'Comum',
        'uncommon' => 'Incomum',
        'rare' => 'Raro',
        'epic' => 'Épico',
        'legendary' => 'Lendário',
    ],

    'drivetrain' => [
        'fwd' => 'Tração dianteira',
        'rwd' => 'Tração traseira',
        'awd' => 'Tração integral',
    ],

    'weather' => [
        'dry' => 'Seco',
        'rain' => 'Chuva',
        'night' => 'Noite',
    ],

];
