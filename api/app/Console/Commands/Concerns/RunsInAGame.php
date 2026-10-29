<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Games\CurrentGame;
use App\Models\Game;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A command that is for one game: `--game=<slug>` says which (the signature has the option), and without it the default game is the one, as before there were
 * more of them. A game that does not exist stops the command before it does anything.
 */
trait RunsInAGame
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = $input->hasOption('game') ? $input->getOption('game') : null;

        if (is_string($slug) && $slug !== '') {
            $game = Game::query()->where('slug', $slug)->first();

            if ($game === null) {
                $this->error("There is no game {$slug}.");

                return self::FAILURE;
            }

            app(CurrentGame::class)->set($game);
        }

        return parent::execute($input, $output);
    }
}
