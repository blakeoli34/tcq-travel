<?php

function completeChallenge($gameId, $playerId, $slotNumber) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        // Check veto wait
        if (isPlayerWaitingVeto($gameId, $playerId)) {
            return ['success' => false, 'message' => 'Cannot interact with deck during veto wait period'];
        }
        
        $pdo->beginTransaction();
        
        // Get card in slot
        $stmt = $pdo->prepare("
            SELECT dds.*, c.*
            FROM daily_deck_slots dds
            JOIN cards c ON dds.card_id = c.id
            WHERE dds.game_id = ? AND dds.deck_date = ? AND dds.slot_number = ? AND c.card_category = 'challenge'
        ");
        $stmt->execute([$gameId, $today, $slotNumber]);
        $card = $stmt->fetch();
        
        if (!$card) {
            throw new Exception("No challenge card in this slot");
        }
        
        // Apply curse/power modifiers
        $finalPoints = applyModifiersToChallenge($gameId, $playerId, $card['card_points']);
        
        // Award points
        if ($finalPoints > 0) {
            updateScore($gameId, $playerId, $finalPoints, $playerId);
        }
        
        // Update challenge completion count
        $stmt = $pdo->prepare("
            UPDATE player_stats 
            SET challenges_completed = challenges_completed + 1
            WHERE game_id = ? AND player_id = ?
        ");
        $stmt->execute([$gameId, $playerId]);
        
        // Mark slot as completed
        $stmt = $pdo->prepare("
            UPDATE daily_deck_slots 
            SET completed_at = NOW(), completed_by = ?
            WHERE game_id = ? AND deck_date = ? AND slot_number = ?
        ");
        $stmt->execute([$playerId, $gameId, $today, $slotNumber]);
        
        // Clear challenge modify effects
        clearChallengeModifiers($gameId, $playerId);
        
        $pdo->commit();
        return ['success' => true, 'points_awarded' => $finalPoints];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error completing challenge: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function vetoChallenge($gameId, $playerId, $slotNumber) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        // Check veto wait
        if (isPlayerWaitingVeto($gameId, $playerId)) {
            return ['success' => false, 'message' => 'Cannot interact with deck during veto wait period'];
        }
        
        $pdo->beginTransaction();
        
        // Get card in slot
        $stmt = $pdo->prepare("
            SELECT dds.*, c.*
            FROM daily_deck_slots dds
            JOIN cards c ON dds.card_id = c.id
            WHERE dds.game_id = ? AND dds.deck_date = ? AND dds.slot_number = ? AND c.card_category = 'challenge'
        ");
        $stmt->execute([$gameId, $today, $slotNumber]);
        $card = $stmt->fetch();
        
        if (!$card) {
            throw new Exception("No challenge card in this slot");
        }
        
        $penalties = [];
        
        // Apply veto penalties
        if ($card['veto_subtract']) {
            updateScore($gameId, $playerId, -$card['veto_subtract'], $playerId);
            $penalties[] = "Lost {$card['veto_subtract']} points";
        }
        
        if ($card['veto_steal']) {
            $opponentId = getOpponentPlayerId($gameId, $playerId);
            updateScore($gameId, $playerId, -$card['veto_steal'], $playerId);
            updateScore($gameId, $opponentId, $card['veto_steal'], $playerId);
            $penalties[] = "Lost {$card['veto_steal']} points to opponent";
        }
        
        if ($card['veto_wait']) {
            applyVetoWait($gameId, $playerId, $card['veto_wait']);
            $penalties[] = "Cannot interact with deck for {$card['veto_wait']} minutes";
        }
        
        if ($card['veto_snap']) {
            addSnapCards($gameId, $playerId, $card['veto_snap']);
            $penalties[] = "Drew {$card['veto_snap']} snap card(s)";
        }
        
        if ($card['veto_spicy']) {
            addSpicyCards($gameId, $playerId, $card['veto_spicy']);
            $penalties[] = "Drew {$card['veto_spicy']} spicy card(s)";
        }
        
        // Clear slot
        clearSlot($gameId, $slotNumber);
        
        $pdo->commit();
        return ['success' => true, 'penalties' => $penalties];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error vetoing challenge: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function completeBattle($gameId, $playerId, $slotNumber, $isWinner) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        // Check veto wait
        if (isPlayerWaitingVeto($gameId, $playerId)) {
            return ['success' => false, 'message' => 'Cannot interact with deck during veto wait period'];
        }
        
        $pdo->beginTransaction();
        
        // Get card in slot
        $stmt = $pdo->prepare("
            SELECT dds.*, c.*
            FROM daily_deck_slots dds
            JOIN cards c ON dds.card_id = c.id
            WHERE dds.game_id = ? AND dds.deck_date = ? AND dds.slot_number = ? AND c.card_category = 'battle'
        ");
        $stmt->execute([$gameId, $today, $slotNumber]);
        $card = $stmt->fetch();
        
        if (!$card) {
            throw new Exception("No battle card in this slot");
        }
        
        $opponentId = getOpponentPlayerId($gameId, $playerId);
        $results = [];
        
        if ($isWinner) {
            // Winner gets points
            if ($card['card_points']) {
                updateScore($gameId, $playerId, $card['card_points'], $playerId);
                $results[] = "Won {$card['card_points']} points";
            }
            
            // Loser gets veto penalties
            if ($card['veto_subtract']) {
                updateScore($gameId, $opponentId, -$card['veto_subtract'], $playerId);
                $results[] = "Opponent lost {$card['veto_subtract']} points";
            }
            
            if ($card['veto_snap']) {
                addSnapCards($gameId, $opponentId, $card['veto_snap']);
                $results[] = "Opponent drew {$card['veto_snap']} snap card(s)";
            }
            
            if ($card['veto_spicy']) {
                addSpicyCards($gameId, $opponentId, $card['veto_spicy']);
                $results[] = "Opponent drew {$card['veto_spicy']} spicy card(s)";
            }
        } else {
            // Loser gets veto penalties
            if ($card['veto_subtract']) {
                updateScore($gameId, $playerId, -$card['veto_subtract'], $playerId);
                $results[] = "Lost {$card['veto_subtract']} points";
            }
            
            if ($card['veto_snap']) {
                addSnapCards($gameId, $playerId, $card['veto_snap']);
                $results[] = "Drew {$card['veto_snap']} snap card(s)";
            }
            
            if ($card['veto_spicy']) {
                addSpicyCards($gameId, $playerId, $card['veto_spicy']);
                $results[] = "Drew {$card['veto_spicy']} spicy card(s)";
            }
            
            // Winner gets points
            if ($card['card_points']) {
                updateScore($gameId, $opponentId, $card['card_points'], $playerId);
                $results[] = "Opponent won {$card['card_points']} points";
            }
        }
        
        // Mark slot as completed
        $stmt = $pdo->prepare("
            UPDATE daily_deck_slots 
            SET completed_at = NOW(), completed_by = ?
            WHERE game_id = ? AND deck_date = ? AND slot_number = ?
        ");
        $stmt->execute([$playerId, $gameId, $today, $slotNumber]);
        
        $pdo->commit();
        return ['success' => true, 'results' => $results];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error completing battle: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function activateCurse($gameId, $playerId, $slotNumber) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        // Check veto wait
        if (isPlayerWaitingVeto($gameId, $playerId)) {
            return ['success' => false, 'message' => 'Cannot interact with deck during veto wait period'];
        }
        
        $pdo->beginTransaction();
        
        // Get card in slot
        $stmt = $pdo->prepare("
            SELECT dds.*, c.*
            FROM daily_deck_slots dds
            JOIN cards c ON dds.card_id = c.id
            WHERE dds.game_id = ? AND dds.deck_date = ? AND dds.slot_number = ? AND c.card_category = 'curse'
        ");
        $stmt->execute([$gameId, $today, $slotNumber]);
        $card = $stmt->fetch();
        
        if (!$card) {
            throw new Exception("No curse card in this slot");
        }
        
        // Process curse effects
        $effects = processCurseCard($gameId, $playerId, $card);
        
        // Create active curse effect
        $effectId = addActiveCurseEffect($gameId, $playerId, $card['card_id'], $card);
        
        // Mark slot as completed
        $stmt = $pdo->prepare("
            UPDATE daily_deck_slots 
            SET completed_at = NOW(), completed_by = ?
            WHERE game_id = ? AND deck_date = ? AND slot_number = ?
        ");
        $stmt->execute([$playerId, $gameId, $today, $slotNumber]);
        
        $pdo->commit();
        return ['success' => true, 'effects' => $effects, 'effect_id' => $effectId];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error activating curse: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function claimPower($gameId, $playerId, $slotNumber) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        // Check veto wait
        if (isPlayerWaitingVeto($gameId, $playerId)) {
            return ['success' => false, 'message' => 'Cannot interact with deck during veto wait period'];
        }
        
        $pdo->beginTransaction();
        
        // Get card in slot
        $stmt = $pdo->prepare("
            SELECT dds.*, c.*
            FROM daily_deck_slots dds
            JOIN cards c ON dds.card_id = c.id
            WHERE dds.game_id = ? AND dds.deck_date = ? AND dds.slot_number = ? AND c.card_category = 'power'
        ");
        $stmt->execute([$gameId, $today, $slotNumber]);
        $card = $stmt->fetch();
        
        if (!$card) {
            throw new Exception("No power card in this slot");
        }
        
        // Check if player has room in hand (max 6 power/snap/spicy cards)
        $handCount = getPlayerHandCount($gameId, $playerId);
        if ($handCount >= 6) {
            throw new Exception("Hand is full (6 cards maximum)");
        }
        
        // Add to player's hand
        $stmt = $pdo->prepare("
            INSERT INTO player_cards (game_id, player_id, card_id, card_type, quantity)
            VALUES (?, ?, ?, 'power', 1)
        ");
        $stmt->execute([$gameId, $playerId, $card['card_id']]);
        
        // Mark slot as completed
        $stmt = $pdo->prepare("
            UPDATE daily_deck_slots 
            SET completed_at = NOW(), completed_by = ?
            WHERE game_id = ? AND deck_date = ? AND slot_number = ?
        ");
        $stmt->execute([$playerId, $gameId, $today, $slotNumber]);
        
        $pdo->commit();
        return ['success' => true, 'message' => "Added {$card['card_name']} to hand"];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error claiming power: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function discardPower($gameId, $playerId, $slotNumber) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        // Check veto wait
        if (isPlayerWaitingVeto($gameId, $playerId)) {
            return ['success' => false, 'message' => 'Cannot interact with deck during veto wait period'];
        }
        
        // Get card in slot
        $stmt = $pdo->prepare("
            SELECT dds.*, c.*
            FROM daily_deck_slots dds
            JOIN cards c ON dds.card_id = c.id
            WHERE dds.game_id = ? AND dds.deck_date = ? AND dds.slot_number = ? AND c.card_category = 'power'
        ");
        $stmt->execute([$gameId, $today, $slotNumber]);
        $card = $stmt->fetch();
        
        if (!$card) {
            return ['success' => false, 'message' => 'No power card in this slot'];
        }
        
        // Clear slot (card is discarded)
        clearSlot($gameId, $slotNumber);
        
        return ['success' => true, 'message' => "Discarded {$card['card_name']}"];
        
    } catch (Exception $e) {
        error_log("Error discarding power: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function addSnapCards($gameId, $playerId, $count) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Get player gender for filtering
        $stmt = $pdo->prepare("SELECT gender FROM players WHERE id = ?");
        $stmt->execute([$playerId]);
        $playerGender = $stmt->fetchColumn();
        
        // Get random snap cards appropriate for player gender
        $genderClause = $playerGender === 'male' ? "AND male = 1" : "AND female = 1";
        $stmt = $pdo->prepare("
            SELECT * FROM cards 
            WHERE card_category = 'snap' $genderClause
            ORDER BY RAND() 
            LIMIT ?
        ");
        $stmt->execute([$count]);
        $cards = $stmt->fetchAll();
        
        foreach ($cards as $card) {
            // Check if player already has this card in hand
            $stmt = $pdo->prepare("
                SELECT quantity FROM player_cards 
                WHERE game_id = ? AND player_id = ? AND card_id = ? AND card_type = 'snap'
            ");
            $stmt->execute([$gameId, $playerId, $card['id']]);
            $existing = $stmt->fetchColumn();
            
            if ($existing) {
                // Update quantity
                $stmt = $pdo->prepare("
                    UPDATE player_cards 
                    SET quantity = quantity + 1 
                    WHERE game_id = ? AND player_id = ? AND card_id = ? AND card_type = 'snap'
                ");
                $stmt->execute([$gameId, $playerId, $card['id']]);
            } else {
                // Add new card to hand
                $stmt = $pdo->prepare("
                    INSERT INTO player_cards (game_id, player_id, card_id, card_type, quantity, card_points)
                    VALUES (?, ?, ?, 'snap', 1, ?)
                ");
                $stmt->execute([$gameId, $playerId, $card['id'], $card['card_points']]);
            }
        }
        
        return count($cards);
        
    } catch (Exception $e) {
        error_log("Error adding snap cards: " . $e->getMessage());
        return 0;
    }
}

function addSpicyCards($gameId, $playerId, $count) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Get player gender for filtering
        $stmt = $pdo->prepare("SELECT gender FROM players WHERE id = ?");
        $stmt->execute([$playerId]);
        $playerGender = $stmt->fetchColumn();
        
        // Get random spicy cards appropriate for player gender
        $genderClause = $playerGender === 'male' ? "AND male = 1" : "AND female = 1";
        $stmt = $pdo->prepare("
            SELECT * FROM cards 
            WHERE card_category = 'spicy' $genderClause
            ORDER BY RAND() 
            LIMIT ?
        ");
        $stmt->execute([$count]);
        $cards = $stmt->fetchAll();
        
        foreach ($cards as $card) {
            // Check if player already has this card in hand
            $stmt = $pdo->prepare("
                SELECT quantity FROM player_cards 
                WHERE game_id = ? AND player_id = ? AND card_id = ? AND card_type = 'spicy'
            ");
            $stmt->execute([$gameId, $playerId, $card['id']]);
            $existing = $stmt->fetchColumn();
            
            if ($existing) {
                // Update quantity
                $stmt = $pdo->prepare("
                    UPDATE player_cards 
                    SET quantity = quantity + 1 
                    WHERE game_id = ? AND player_id = ? AND card_id = ? AND card_type = 'spicy'
                ");
                $stmt->execute([$gameId, $playerId, $card['id']]);
            } else {
                // Add new card to hand
                $stmt = $pdo->prepare("
                    INSERT INTO player_cards (game_id, player_id, card_id, card_type, quantity, card_points)
                    VALUES (?, ?, ?, 'spicy', 1, ?)
                ");
                $stmt->execute([$gameId, $playerId, $card['id'], $card['card_points']]);
            }
        }
        
        return count($cards);
        
    } catch (Exception $e) {
        error_log("Error adding spicy cards: " . $e->getMessage());
        return 0;
    }
}

function getPlayerHandCount($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(quantity), 0) 
            FROM player_cards 
            WHERE game_id = ? AND player_id = ? AND card_type IN ('power', 'snap', 'spicy')
        ");
        $stmt->execute([$gameId, $playerId]);
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function applyModifiersToChallenge($gameId, $playerId, $basePoints) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Get active curse effects that modify challenges
        $stmt = $pdo->prepare("
            SELECT ace.*, c.score_modify
            FROM active_curse_effects ace
            JOIN cards c ON ace.card_id = c.id
            WHERE ace.game_id = ? AND ace.player_id = ? AND c.challenge_modify = 1
        ");
        $stmt->execute([$gameId, $playerId]);
        $effects = $stmt->fetchAll();
        
        $finalPoints = $basePoints;
        
        foreach ($effects as $effect) {
            switch ($effect['score_modify']) {
                case 'half':
                    $finalPoints = floor($finalPoints / 2);
                    break;
                case 'double':
                    $finalPoints *= 2;
                    break;
                case 'zero':
                    $finalPoints = 0;
                    break;
                case 'extra_point':
                    $finalPoints += 1;
                    break;
                case 'challenge_reward_opponent':
                    // Opponent gets the points instead
                    $opponentId = getOpponentPlayerId($gameId, $playerId);
                    updateScore($gameId, $opponentId, $finalPoints, $playerId);
                    $finalPoints = 0;
                    break;
            }
        }
        
        return $finalPoints;
        
    } catch (Exception $e) {
        error_log("Error applying modifiers to challenge: " . $e->getMessage());
        return $basePoints;
    }
}

function clearChallengeModifiers($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Remove challenge modify effects
        $stmt = $pdo->prepare("
            DELETE ace FROM active_curse_effects ace
            JOIN cards c ON ace.card_id = c.id
            WHERE ace.game_id = ? AND ace.player_id = ? AND c.challenge_modify = 1
        ");
        $stmt->execute([$gameId, $playerId]);
        
        return true;
        
    } catch (Exception $e) {
        error_log("Error clearing challenge modifiers: " . $e->getMessage());
        return false;
    }
}

function processCurseCard($gameId, $playerId, $card) {
    $effects = [];
    
    // Immediate effects
    if ($card['score_add']) {
        updateScore($gameId, $playerId, $card['score_add'], $playerId);
        $effects[] = "Gained {$card['score_add']} points";
    }
    
    if ($card['score_subtract']) {
        updateScore($gameId, $playerId, -$card['score_subtract'], $playerId);
        $effects[] = "Lost {$card['score_subtract']} points";
    }
    
    if ($card['score_steal']) {
        $opponentId = getOpponentPlayerId($gameId, $playerId);
        updateScore($gameId, $playerId, -$card['score_steal'], $playerId);
        updateScore($gameId, $opponentId, $card['score_steal'], $playerId);
        $effects[] = "Lost {$card['score_steal']} points to opponent";
    }
    
    // Recurring effects
    if ($card['repeat_count']) {
        $effects[] = "Will lose 1 point every {$card['repeat_count']} minutes until cleared";
    }
    
    // Timer effects
    if ($card['timer']) {
        createTimer($gameId, $playerId, $card['card_name'], $card['timer']);
        $effects[] = "Timer started for {$card['timer']} minutes";
    }
    
    // Dice effects
    if ($card['roll_dice']) {
        $effects[] = "Roll dice to determine if curse is cleared";
    }
    
    // Completion requirements
    if ($card['complete_snap']) {
        $effects[] = "Complete a snap card to clear this curse";
    }
    
    if ($card['complete_spicy']) {
        $effects[] = "Complete a spicy card to clear this curse";
    }
    
    return $effects;
}

function addActiveCurseEffect($gameId, $playerId, $cardId, $card) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        $expiresAt = null;
        if ($card['timer']) {
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$card['timer']} minutes"));
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO active_curse_effects (game_id, player_id, card_id, expires_at)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$gameId, $playerId, $cardId, $expiresAt]);
        
        return $pdo->lastInsertId();
        
    } catch (Exception $e) {
        error_log("Error adding active curse effect: " . $e->getMessage());
        return false;
    }
}

function addActivePowerEffect($gameId, $playerId, $cardId, $card) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        $expiresAt = null;
        if ($card['power_wait']) {
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$card['power_wait']} minutes"));
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO active_power_effects (game_id, player_id, card_id, expires_at)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$gameId, $playerId, $cardId, $expiresAt]);
        
        return $pdo->lastInsertId();
        
    } catch (Exception $e) {
        error_log("Error adding active power effect: " . $e->getMessage());
        return false;
    }
}
?>