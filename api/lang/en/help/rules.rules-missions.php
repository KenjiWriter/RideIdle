<?php

return [
    'entries' => [
        '/missions/reset_hour' => [
            'label' => 'Hour the day of missions starts',
            'help' => 'The hour (in the time zone of the game) at which the day of the missions starts, and with it the new daily set. The week starts on Monday at this hour and the month on the first at this hour. The period before also ends then: what a player finished and did not take, the server takes for them, and the rest is lost.',
            'example' => '4: a player who plays in the evening and after midnight has one day of missions, and gets the new set at 4:00 in the morning.',
            'range' => '0 – 23 (the hour).',
        ],
        '/missions/periods/daily/bonus_coins' => [
            'label' => 'Bonus for the whole set: day (coins)',
            'help' => 'How many premium coins (RideCoin) a player gets for doing every mission of the set of the day. They are the only free coins the missions give; the rewards of single missions have none. So this number is all the free coins of this period, and the schema does not allow more than 5.',
            'example' => '2 coins: all three periods done every time make 2, 8 and 25 coins, about 119 a month; the VIP account costs 150 coins and a pack of 50 coins 4.99 zł.',
            'range' => '0 – 5 coins.',
            'tip' => 'Players feel a lower bonus as a reward taken away, so start low: raising it later is easy.',
        ],
        '/missions/periods/weekly/bonus_coins' => [
            'label' => 'Bonus for the whole set: week (coins)',
            'help' => 'How many premium coins (RideCoin) a player gets for doing every mission of the set of the week. They are the only free coins the missions give; the rewards of single missions have none. So this number is all the free coins of this period, and the schema does not allow more than 20.',
            'example' => '8 coins: all three periods done every time make 2, 8 and 25 coins, about 119 a month; the VIP account costs 150 coins and a pack of 50 coins 4.99 zł.',
            'range' => '0 – 20 coins.',
            'tip' => 'Players feel a lower bonus as a reward taken away, so start low: raising it later is easy.',
        ],
        '/missions/periods/monthly/bonus_coins' => [
            'label' => 'Bonus for the whole set: month (coins)',
            'help' => 'How many premium coins (RideCoin) a player gets for doing every mission of the set of the month. They are the only free coins the missions give; the rewards of single missions have none. So this number is all the free coins of this period, and the schema does not allow more than 60.',
            'example' => '25 coins: all three periods done every time make 2, 8 and 25 coins, about 119 a month; the VIP account costs 150 coins and a pack of 50 coins 4.99 zł.',
            'range' => '0 – 60 coins.',
            'tip' => 'Players feel a lower bonus as a reward taken away, so start low: raising it later is easy.',
        ],
        '/missions/periods/daily/cash_ppm' => [
            'label' => 'Cash for the set: day (% of a tank)',
            'help' => 'How much cash the whole of the set of the day pays, as a part of what the best car of the player earns on one full tank after the fuel (100% is a whole tank). The set divides it among its missions by their shares, so it pays the same whichever missions it draws. It is on top of what the cars earn and does not count in the economy of the game: the gates and the simulator do not see it.',
            'example' => '6%: a player whose tank earns $1,000 gets about $60 for the whole set.',
            'range' => '0 – 1000%.',
        ],
        '/missions/periods/weekly/cash_ppm' => [
            'label' => 'Cash for the set: week (% of a tank)',
            'help' => 'How much cash the whole of the set of the week pays, as a part of what the best car of the player earns on one full tank after the fuel (100% is a whole tank). The set divides it among its missions by their shares, so it pays the same whichever missions it draws. It is on top of what the cars earn and does not count in the economy of the game: the gates and the simulator do not see it.',
            'example' => '20%: a player whose tank earns $1,000 gets about $200 for the whole set.',
            'range' => '0 – 1000%.',
        ],
        '/missions/periods/monthly/cash_ppm' => [
            'label' => 'Cash for the set: month (% of a tank)',
            'help' => 'How much cash the whole of the set of the month pays, as a part of what the best car of the player earns on one full tank after the fuel (100% is a whole tank). The set divides it among its missions by their shares, so it pays the same whichever missions it draws. It is on top of what the cars earn and does not count in the economy of the game: the gates and the simulator do not see it.',
            'example' => '60%: a player whose tank earns $1,000 gets about $600 for the whole set.',
            'range' => '0 – 1000%.',
        ],
        '/missions/periods/daily/xp_ppm' => [
            'label' => 'XP for the set: day (% of a tank)',
            'help' => 'How much XP of the player (the level of the driver) the whole of the set of the day pays, as a part of the XP that the best car of the player collects on one full tank. The level of the player opens tracks, cities and parts, and the tutorial says that levels are to be the work of the laps, so keep this number small: every point moves the pace of the whole game.',
            'example' => '5%: a player whose tank gives 1,000 XP gets about 50 XP for the whole set.',
            'range' => '0 – 1000%.',
        ],
        '/missions/periods/weekly/xp_ppm' => [
            'label' => 'XP for the set: week (% of a tank)',
            'help' => 'How much XP of the player (the level of the driver) the whole of the set of the week pays, as a part of the XP that the best car of the player collects on one full tank. The level of the player opens tracks, cities and parts, and the tutorial says that levels are to be the work of the laps, so keep this number small: every point moves the pace of the whole game.',
            'example' => '15%: a player whose tank gives 1,000 XP gets about 150 XP for the whole set.',
            'range' => '0 – 1000%.',
        ],
        '/missions/periods/monthly/xp_ppm' => [
            'label' => 'XP for the set: month (% of a tank)',
            'help' => 'How much XP of the player (the level of the driver) the whole of the set of the month pays, as a part of the XP that the best car of the player collects on one full tank. The level of the player opens tracks, cities and parts, and the tutorial says that levels are to be the work of the laps, so keep this number small: every point moves the pace of the whole game.',
            'example' => '40%: a player whose tank gives 1,000 XP gets about 400 XP for the whole set.',
            'range' => '0 – 1000%.',
        ],
    ],
];
