<?php

trait ActionTrait {

    //////////////////////////////////////////////////////////////////////////////
    //////////// Player actions
    //////////// 
    
    /*
        Each time a player is doing some game action, one of the methods below is called.
        (note: each method below must match an input method in noah.action.php)
    */

    public function loadAnimal(int $id) {
        self::checkAction('loadAnimal');
        
        $animal = $this->getAnimalFromDb($this->animals->getCard($id));

        if (!$this->canLoadAnimal($animal)) {
            throw new Error("Can't load this animal");
        }

        $position = $this->getNoahPosition();
        $location = 'table'.$position;
        $animalsInFerry = $this->getAnimalsFromDb($this->animals->getCardsInLocation($location, null, 'location_arg'));
        $nbr = count($animalsInFerry);

        if ($nbr < 2 && $animal->power == POWER_HERMAPHRODITE) { // need to set gender
            self::setGameStateValue(SELECTED_ANIMAL, $id);
            $this->gamestate->nextState('chooseGender');
        } else if ($animal->power == POWER_ADJUSTABLE_WEIGHT && $this->getWeightForDeparture() != null) {
            self::setGameStateValue(SELECTED_ANIMAL, $id);
            $this->gamestate->nextState('chooseWeight');
        } else if ($animal->power == POWER_CROCODILE && $this->getFirstAnimalFromFerry() != null) {
            self::setGameStateValue(SELECTED_ANIMAL, $id);            
            self::setGameStateValue(GIVE_CARD_FROM_FERRY, 1);
            $this->gamestate->nextState('chooseOpponent');
        } else {
            $this->applyLoadAnimal($id);
        }
    }

    function setGender(int $gender) {
        self::checkAction('setGender'); 
        
        $position = $this->getNoahPosition();
        $location = 'table'.$position;
        $animalsInFerry = $this->getAnimalsFromDb($this->animals->getCardsInLocation($location));
        $nbr = count($animalsInFerry);

        $animal = $this->getAnimalFromDb($this->animals->getCard(intval(self::getGameStateValue(SELECTED_ANIMAL))));

        if ($nbr > 2 || $animal->power != POWER_HERMAPHRODITE) {
            throw new Error("No animal need to set gender");
        }

        $this->applySetGender($animal->id, $gender);
        $this->applyLoadAnimal($animal->id);
    }

    function setWeight(int $weight) {
        self::checkAction('setWeight'); 
        
        $position = $this->getNoahPosition();
        $location = 'table'.$position;

        $animal = $this->getAnimalFromDb($this->animals->getCard(intval(self::getGameStateValue(SELECTED_ANIMAL))));
        if ($animal->power != POWER_ADJUSTABLE_WEIGHT) {
            throw new Error("No animal need to set weight");
        }

        $this->applySetWeight($animal->id, $weight);
        $this->applyLoadAnimal($animal->id);
    }

    function applyLoadAnimal(int $id) {        
        $animal = $this->getAnimalFromDb($this->animals->getCard($id));
        $position = $this->getNoahPosition();
        $location = 'table'.$position;
        $animalCount = intval($this->animals->countCardInLocation($location));
        $this->animals->moveCard($id, $location, $animalCount++);

        $playerId = self::getActivePlayerId();

        if ($animalCount > 1) {
            $previousAnimal = $this->getAnimalsFromDb($this->animals->getCardsInLocation($location, null, 'location_arg'))[$animalCount-2];
            if ($this->isSoloMode()) {
                if ($animal->type == $previousAnimal->type && $animal->gender != $previousAnimal->gender) {
                    self::setGameStateValue(SOLO_DRAW_CARDS, 2);
                }
            } else {
                if ($animal->type == $previousAnimal->type) {
                    self::setGameStateValue(PAIR_PLAY_AGAIN, 1);
                }
            }

            if ($animalCount == 2) {   
                $stat = $animal->type == $previousAnimal->type ? 'sameGender' : 'alternateGender';
                self::incStat(1, $stat);
                self::incStat(1, $stat, $playerId);
            }
        }

        self::incStat(1, 'playedCards');
        self::incStat(1, 'playedCards', $playerId);

        self::notifyAllPlayers('animalLoaded', clienttranslate('${player_name} loads animal ${animalName}'), [
            'playerId' => $playerId,
            'player_name' => self::getActivePlayerName(),
            'animal' => $animal,
            'position' => $position,
            'animalName' => $this->getAnimalName($animal->type),
        ]);

        if ($animal->power == POWER_CROCODILE) {
            $this->decPlayerScore($playerId, 2);
        }
          
        self::setGameStateValue(LAST_LOADED_ANIMAL_POSITION, $position);
        self::setGameStateValue(NOAH_NEXT_MOVE, $animal->power == POWER_DONT_MOVE_NOAH ? 0 : $animal->gender);

        if ($animal->power == POWER_LOOK_CARDS) {
            $soloMode = $this->isSoloMode(); 
            if ($soloMode) {
                if (intval($this->animals->countCardInLocation('deck')) > 0) {
                    $this->gamestate->nextState('reorderTopDeck');
                } else {
                    $this->gamestate->nextState('moveNoah');
                }
            } else {
                self::setGameStateValue(LOOK_OPPONENT_HAND, 1);
    
                $this->gamestate->nextState('chooseOpponent');
            }
        } else if ($animal->power == POWER_EXCHANGE_CARD) {
            $soloMode = $this->isSoloMode(); 
            if ($soloMode) {

                if (count($this->argReplaceOnTopDeck()['animals']) > 0) {
                    $this->gamestate->nextState('replaceOnTopDeck');
                } else {
                    $this->gamestate->nextState('moveNoah');
                }
            } else {
                self::setGameStateValue(EXCHANGE_CARD, 1);

                $this->gamestate->nextState('chooseOpponent');
            }
        } else {
            $this->gamestate->nextState('moveNoah');
        }
    }

    public function takeAllAnimals() {
        self::checkAction('takeAllAnimals');
        
        $playerId = self::getActivePlayerId();
        
        $position = $this->getNoahPosition();
        $location = 'table'.$position;
        $animalsInFerry = $this->getAnimalsFromDb($this->animals->getCardsInLocation($location));
        $this->animals->moveCards(array_map(fn($animal) => $animal->id, $animalsInFerry), 'hand', $playerId);

        self::notifyAllPlayers('ferryAnimalsTaken', clienttranslate('${player_name} takes back all animals present on the ferry'), [
            'playerId' => $playerId,
            'player_name' => self::getActivePlayerName(),
            'animals' => $animalsInFerry,
            'position' => $position,
        ]);

        self::incStat(1, 'takeAllAnimals');
        self::incStat(1, 'takeAllAnimals', $playerId);
        
        $this->gamestate->nextState('loadAnimal');
    }

    public function moveNoah(int $destination) {
        self::checkAction('moveNoah'); 

        $possiblePositions = $this->getPossiblePositions();
        if (array_search($destination,  $possiblePositions) === false) {
            throw new Error("Invalid destination for Noah");
        }
        
        $playerId = self::getActivePlayerId();

        self::setGameStateValue(NOAH_POSITION, $destination);

        self::notifyAllPlayers('noahMoved', '', [
            'position' => $destination,
        ]);

        $this->gamestate->nextState('checkOptimalLoading');
    }

    public function giveCards(array $giveCardsTo) {
        self::checkAction('giveCards'); 

        $playerId = intval(self::getActivePlayerId());

        $numberSelected = count($giveCardsTo);
        $numberExpected = $this->getNumberOfCardsToGive($playerId);
        if ($numberSelected != $numberExpected) {
            throw new Error("Invalid card count : expected $numberExpected, selected $numberSelected");
        }

        $handAnimals = $this->getAnimalsFromDb($this->animals->getCardsInLocation('hand', $playerId));

        foreach($giveCardsTo as $animalId => $toPlayerId) {
            if (!$this->array_some($handAnimals, fn($animal) => $animal->id == $animalId)) {
                throw new Error("You can't give a card which is not in your hand");
            }
        }

        foreach($giveCardsTo as $animalId => $toPlayerId) {
            $this->animals->moveCard($animalId, 'hand', $toPlayerId);
            $animal = $this->array_find($handAnimals, fn($animal) => $animal->id == $animalId);

            self::notifyAllPlayers('animalGiven', clienttranslate('${player_name} gives an animal to ${player_name2}'), [
                'playerId' => $playerId,
                'player_name' => self::getActivePlayerName(),
                'toPlayerId' => $toPlayerId,
                'player_name2' => self::getPlayerNameById($toPlayerId),
                '_private' => [          // Using "_private" keyword, all data inside this array will be made private
                    $playerId => [       // Using "active" keyword inside "_private", you select active player(s)
                        'animal' => $animal,
                    ],
                    $toPlayerId => [
                        'animal' => $animal,
                    ],
                ],
            ]);
        }

        $this->gamestate->nextState('nextPlayer');
    }

    public function lookCards(int $playerId) {
        self::checkAction('lookCards'); 

        $this->applyLookCards($playerId);
    }

    function applyLookCards(int $playerId) {
        self::setGameStateValue(LOOK_OPPONENT_HAND, $playerId);

        $this->gamestate->nextState('look');
    }

    public function exchangeCard(int $playerId) {
        self::checkAction('exchangeCard'); 

        $this->applyExchangeCard($playerId);
    }

    function applyExchangeCard(int $playerId) {
        self::setGameStateValue(EXCHANGE_CARD, $playerId);

        $this->gamestate->nextState('exchange');
    }

    public function giveCardFromFerry(int $playerId) {
        self::checkAction('giveCardFromFerry'); 

        $this->applyGiveCardFromFerry($playerId);
    }

    function getFirstAnimalFromFerry() {
        $position = $this->getNoahPosition();
        $location = 'table'.$position;
        $animalsInFerry = $this->getAnimalsFromDb($this->animals->getCardsInLocation($location, null, 'location_arg'));

        if (count($animalsInFerry) > 0) {
            return $animalsInFerry[0];
        } else {
            return null;
        }
    }

    function updateIndexesForFerry(int $position, $overIndex = 0) {
        $location = 'table'.$position;
        self::DbQuery("UPDATE animal SET `card_location_arg` = `card_location_arg` - 1 where `card_location` = '$location' and `card_location_arg` > $overIndex");
    }

    function applyGiveCardFromFerry(int $toPlayerId) {
        $animal = $this->getFirstAnimalFromFerry();
        if ($animal != null) {
            $playerId = intval(self::getActivePlayerId());

            $this->animals->moveCard($animal->id, 'hand', $toPlayerId);
            $position = $this->getNoahPosition();
            $this->updateIndexesForFerry($position);
            
            self::notifyAllPlayers('animalGivenFromFerry', clienttranslate('${player_name} makes first animal of ferry flee to ${player_name2}\'s hand'), [
                'playerId' => $playerId,
                'player_name' => self::getActivePlayerName(),
                'toPlayerId' => $toPlayerId,
                'player_name2' => self::getPlayerNameById($toPlayerId),
                'animal' => $animal,
            ]);
        }

        $this->applyLoadAnimal(intval(self::getGameStateValue(SELECTED_ANIMAL)));
    }

    function giveCard(int $cardId) {
        $playerId = self::getActivePlayerId();
        $opponentId = intval(self::getGameStateValue(EXCHANGE_CARD));

        $animal = $this->getAnimalFromDb($this->animals->getCard($cardId));

        self::notifyPlayer($playerId, 'removedCard', clienttranslate('Card ${animalName} was given to chosen opponent'), [
            'playerId' => $playerId,
            'animal' => $animal,
            'fromPlayerId' => $opponentId,
            'animalName' => $this->getAnimalName($animal->type),
        ]);

        $this->animals->moveCard($cardId, 'hand', $opponentId);

        self::notifyPlayer($opponentId, 'newCard', clienttranslate('${player_name} gives you ${animalName}'), [
            'animal' => $animal,
            'player_name' => self::getActivePlayerName(),
            'fromPlayerId' => $playerId,
            'animalName' => $this->getAnimalName($animal->type),
        ]);

        $this->gamestate->nextState('giveCard');
    }

    public function seen() {
        self::checkAction('seen');

        $this->gamestate->nextState('seen');
    }

    public function reorderTopDeck(array $reorderTopDeck) {
        self::checkAction('reorderTopDeck');

        foreach($reorderTopDeck as $id => $position) {
            $locationArg = 1000 - $position;
            self::DbQuery("UPDATE animal SET `card_location_arg` = $locationArg where `card_id` = $id");
        }

        $this->gamestate->nextState('moveNoah');
    }
        
    public function replaceOnTopDeck(int $cardId) {
        self::checkAction('replaceOnTopDeck');

        $animal = $this->getAnimalFromDb($this->animals->getCard($cardId));
        $position = intval(str_replace('table', '', $animal->location));
        $locationArg = $animal->location_arg;
        
        $this->animals->moveCard($animal->id, 'deck', intval($this->animals->countCardInLocation('deck')) + 1);
        $this->updateIndexesForFerry($position, $locationArg);
            
        self::notifyAllPlayers('animalGivenFromFerry', '', [
            'animal' => $animal,
        ]);

        $this->gamestate->nextState('moveNoah');
    }
        
    public function skipReplaceOnTopDeck() {
        self::checkAction('skipReplaceOnTopDeck');

        $this->gamestate->nextState('moveNoah');
    }
}
