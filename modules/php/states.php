<?php

trait StateTrait {

//////////////////////////////////////////////////////////////////////////////
//////////// Game state actions
////////////

    /*
        Here, you can create methods defined as "game state actions" (see "action" property in states.inc.php).
        The action method of state X is called everytime the current game state is set to X.
    */

    function stStartRound() {   
        self::setGameStateValue(ROUND_NUMBER, intval($this->getGameStateValue(ROUND_NUMBER)) + 1);

        // reset cards
        $this->animals->moveAllCardsInLocation(null, 'deck');
        $this->animals->shuffle('deck');
        $this->ferries->moveAllCardsInLocation(null, 'deck');
        $this->ferries->shuffle('deck');

        $this->setInitialCardsAndResources($this->getPlayersIds());

        // TODO TEMP
        $this->debugSetup();

        $this->gamestate->nextState('');
    }

    function stChooseOpponent() {
        if (count($this->getPlayersIds()) == 2) {
            $opponentId = $this->getOpponentId(self::getActivePlayerId());
            if (intval(self::getGameStateValue(LOOK_OPPONENT_HAND)) == 1) {
                $this->applyLookCards($opponentId);
            } else if (intval(self::getGameStateValue(EXCHANGE_CARD)) == 1) {
                $this->applyExchangeCard($opponentId);
            } else if (intval(self::getGameStateValue(GIVE_CARD_FROM_FERRY)) == 1) {
                $this->applyGiveCardFromFerry($opponentId);
            } 
        }
    }

    function stGiveCard() {
        $playerId = self::getActivePlayerId();
        $opponentId = intval(self::getGameStateValue(EXCHANGE_CARD));

        $cardsInHand = $this->getAnimalsFromDb($this->animals->getCardsInLocation('hand', $opponentId));
        $removedCard = null;
        $cardsNumber = count($cardsInHand);
        if ($cardsNumber > 0) {
            $removedCard = $cardsInHand[bga_rand(1, $cardsNumber) - 1];
            $this->animals->moveCard($removedCard->id, 'hand', $playerId);
            $removedCards[$opponentId] = $removedCard;

            self::notifyPlayer($opponentId, 'removedCard', clienttranslate('Card ${animalName} was removed from your hand'), [
                'playerId' => $opponentId,
                'animal' => $removedCard,
                'fromPlayerId' => $playerId,
                'animalName' => $this->getAnimalName($removedCard->type),
            ]);

            self::notifyPlayer($playerId, 'newCard', clienttranslate('Card ${animalName} was picked from ${player_name2} hand'), [
                'playerId' => $playerId,
                'player_name2' => $this->getPlayerName($opponentId),
                'animal' => $removedCard,
                'fromPlayerId' => $opponentId,
                'animalName' => $this->getAnimalName($removedCard->type),
            ]);
        }
    }

    function stMoveNoah() {
        if (intval($this->getGameStateValue(NOAH_NEXT_MOVE)) == 0) {
            $this->gamestate->nextState('checkOptimalLoading');
        }
    }

    function stOptimalLoading() {
        $ferry = $this->getFerry(intval(self::getGameStateValue(LAST_LOADED_ANIMAL_POSITION)));

        $ferryComplete = $ferry->getCurrentWeight() == $ferry->getMaxWeight();

        if (!$ferryComplete) {
            if ($this->isSoloMode()) {
                $this->gamestate->nextState('drawCards');
            } else {
                $this->gamestate->nextState('nextPlayer');
            }
        } else {
            $this->applyOptimalLoading();

            $playerId = self::getActivePlayerId();

            if ($this->isSoloMode()) {
                $this->gamestate->nextState('drawCards');
            } else {
                if ($this->getNumberOfCardsToGive($playerId) == 0) {
                    $this->gamestate->nextState('nextPlayer');
                } else {
                    $this->gamestate->nextState('giveCards');
                }
            }
        }
    }

    function applyOptimalLoading() {
        $position = intval(self::getGameStateValue(LAST_LOADED_ANIMAL_POSITION));
        $this->animals->moveAllCardsInLocation('table'.$position, 'discard');
        $this->ferries->moveAllCardsInLocation('table', 'discard', $position);
        $remainingFerries = intval($this->ferries->countCardInLocation('deck'));
        $newFerry = null;
        if ($remainingFerries > 0) {
            $newFerry = $this->getFerryFromDb($this->ferries->pickCardForLocation('deck', 'table', $position));
            $remainingFerries--;
        }

        $topFerryDb = $this->ferries->getCardOnTop('deck');
        $topFerry = $topFerryDb != null ? $this->getFerryFromDb($topFerryDb) : null;
        
        $playerId = self::getActivePlayerId();
        self::notifyAllPlayers('departure', clienttranslate('${player_name} completes ferry'), [
            'playerId' => $playerId,
            'player_name' => self::getActivePlayerName(),
            'position' => $position,
            'newFerry' => $newFerry,
            'remainingFerries' => $remainingFerries,
            'topFerry' => $topFerry,
        ]);

        self::incStat(1, 'optimalLoading');
        self::incStat(1, 'optimalLoading', $playerId);

        if ($this->isSoloMode()) {
            $cardsToDiscard = intval($this->ferries->countCardInLocation('discard'));
            $this->animals->pickCardsForLocation($cardsToDiscard, 'deck', 'discard');
        
            self::notifyPlayer($playerId, 'remainingAnimals', '', [
                'remainingAnimals' => intval($this->animals->countCardInLocation('deck')),
            ]);
        }
    }

    function stDrawCards() {
        $playerId = self::getActivePlayerId();
        $number = intval(self::getGameStateValue(SOLO_DRAW_CARDS));
        
        $animals = $this->getAnimalsFromDb($this->animals->pickCardsForLocation($number, 'deck', 'hand', $playerId));
        self::notifyPlayer($playerId, 'newHand', '', [
            'playerId' => $playerId,
            'animals' => $animals,
            'keepCurrentHand' => true,
            'remainingAnimals' => intval($this->animals->countCardInLocation('deck')),
        ]);

        self::setGameStateValue(SOLO_DRAW_CARDS, 1);

        $this->gamestate->nextState('nextPlayer');
    }

    function stNextPlayerForSoloMode(int $playerId) {
        if (intval($this->animals->countCardInLocation('deck')) == 0 && intval($this->animals->countCardInLocation('hand')) == 0) {
            // win !
            $this->setPlayerScore($playerId, 1);
            $this->gamestate->nextState('endGame');
        } else if (count($this->getSelectableAnimals($playerId)) == 0) {
            // loose...
            $this->setPlayerScore($playerId, 0);
            $this->gamestate->nextState('endGame');
        } else {
            // keep going
            self::giveExtraTime($playerId);
            $this->gamestate->nextState('nextPlayer');
        }
    }

    function stNextPlayerForMultiplayer(int $playerId) {
        if ($this->isEndOfRound()) {
            $this->gamestate->nextState('endRound');
        } else {
            if (intval(self::getGameStateValue(PAIR_PLAY_AGAIN)) == 0) {
                $this->activeNextPlayer();       
                $playerId = self::getActivePlayerId();
            } else {
                self::setGameStateValue(PAIR_PLAY_AGAIN, 0);
            }

            self::giveExtraTime($playerId);

            $this->gamestate->nextState('nextPlayer');
        }
    }

    function stNextPlayer() {     
        $playerId = self::getActivePlayerId();

        self::incStat(1, 'turnsNumber');
        self::incStat(1, 'turnsNumber', $playerId);

        if ($this->isSoloMode()) {
            $this->stNextPlayerForSoloMode($playerId);
        } else {
            $this->stNextPlayerForMultiplayer($playerId);
        }
    }

    function stEndRound() {
        // count points remaining in hands
        $playersIds = $this->getPlayersIds();
        foreach($playersIds as $playerId) {
            $animals = $this->getAnimalsFromDb($this->animals->getCardsInLocation('hand', $playerId));
            $points = array_reduce($animals, fn($carry, $item) => $carry + $item->points, 0);
            $this->incPlayerScore($playerId, $points);
        }
        
        // player with highest score starts        
        $sql = "SELECT player_id FROM player where player_score=(select min(player_score) from player) limit 1";
        $minScorePlayerId = self::getUniqueValueFromDB($sql);
        $this->gamestate->changeActivePlayer($minScorePlayerId);
        self::giveExtraTime($minScorePlayerId);

        $roundNumber = intval(self::getGameStateValue(ROUND_NUMBER));

        $endGame = null;
        if ($this->isVariant()) {
            $endGame = $this->getMaxPlayerScore() >= 26;
        } else {
            $endGame = $roundNumber >= 3;
        }

        if ($endGame) {
            $this->gamestate->nextState('endGame');
        } else {
            $this->gamestate->nextState('newRound');
        }
    }
}
