<?php

function playPowerCard($gameId, $playerId, $playerCardId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $pdo->beginTransaction();
        
        // Get power card details
        $stmt = $pdo->prepare("
            SELECT pc.*, c.*
            FROM player_cards pc
            JOIN cards c ON pc.card_id = c.id
            WHERE pc.id = ? AND pc.player_id = ? AND pc.game_id = ? AND pc.card_type = 'power'
        ");
        $stmt->execute([$playerCardId, $playerId, $gameId]);
        $card = $stmt->fetch();
        
        if (!$card) {
            throw new Exception("Power card not found in hand");
        }
        
        $effects = [];
        $opponentId = getOpponentPlayerId($gameId, $playerId);
        
        // Apply immediate effects
        if ($card['power_score_add']) {
            updateScore($gameId, $playerId, $card['power_score_add'], $playerId);
            $effects[] = "Gained {$card['power_score_add']} points";
        }
        
        if ($card['power_score_subtract']) {
            if ($card['target_opponent']) {
                updateScore($gameId, $opponentId, -$card['power_score_subtract'], $playerId);
                $effects[] = "Opponent lost {$card['power_score_subtract']} points";
            } else {
                updateScore($gameId, $playerId, -$card['power_score_subtract'], $playerId);
                $effects[] = "Lost {$card['power_score_subtract']} points";
            }
        }
        
        if ($card['power_score_steal']) {
            updateScore($gameId, $opponentId, -$card['power_score_steal'], $playerId);
            updateScore($gameId, $playerId, $card['power_score_steal'], $playerId);
            $effects[] = "Stole {$card['power_score_steal']} points from opponent";
        }
        
        // Handle special actions
        if ($card['skip_challenge']) {
            $effects[] = "Next challenge in daily deck will be automatically completed";
        }
        
        if ($card['clear_curse']) {
            clearPlayerCurseEffects($gameId, $playerId);
            $effects[] = "Cleared all active curse effects";
        }
        
        if ($card['shuffle_daily_deck']) {
            shuffleDailyDeck($gameId);
            $effects[] = "Daily deck shuffled";
        }
        
        if ($card['deck_peek']) {
            $effects[] = "You can see the next 3 cards in the daily deck";
        }
        
        if ($card['card_swap']) {
            $effects[] = "You can swap a card in the daily deck slots";
        }
        
        // Create ongoing effects if needed
        if ($card['power_challenge_modify'] || $card['power_snap_modify'] || 
            $card['power_spicy_modify'] || $card['power_score_modify'] !== 'none' ||
            $card['power_veto_modify'] !== 'none' || $card['power_wait']) {
            
            addActivePowerEffect($gameId, $playerId, $card['card_id'], $card);
            $effects[] = "Ongoing power effect activated";
        }
        
        // Remove card from hand
        if ($card['quantity'] > 1) {
            $stmt = $pdo->prepare("
                UPDATE player_cards 
                SET quantity = quantity - 1 
                WHERE id = ?
            ");
            $stmt->execute([$playerCardId]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM player_cards WHERE id = ?");
            $stmt->execute([$playerCardId]);
        }
        
        $pdo->commit();
        return ['success' => true, 'effects' => $effects];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error playing power card: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function completeSnapCard($gameId, $playerId, $playerCardId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $pdo->beginTransaction();
        
        // Get snap card details
        $stmt = $pdo->prepare("
            SELECT pc.*, c.*
            FROM player_cards pc
            JOIN cards c ON pc.card_id = c.id
            WHERE pc.id = ? AND pc.player_id = ? AND pc.game_id = ? AND pc.card_type = 'snap'
        ");
        $stmt->execute([$playerCardId, $playerId, $gameId]);
        $card = $stmt->fetch();
        
        if (!$card) {
            throw new Exception("Snap card not found in hand");
        }
        
        $effects = [];
        
        // Apply snap/spicy modifiers from active power/curse effects
        $finalPoints = applySnapSpicyModifiers($gameId, $playerId, $card['card_points'], 'snap');
        
        // Award points
        if ($finalPoints > 0) {
            updateScore($gameId, $playerId, $finalPoints, $playerId);
            $effects[] = "Gained {$finalPoints} points";
        }
        
        // Update snap completion stats and check for awards
        $awardResult = updateSnapCardCompletion($gameId, $playerId);
        if ($awardResult['award_points'] > 0) {
            $effects[] = "Level {$awardResult['level']} Award: {$awardResult['award_points']} bonus points!";
        }
        
        // Check if this completes any curse effects
        $clearedCurses = clearCursesByCompletion($gameId, $playerId, 'snap');
        if ($clearedCurses > 0) {
            $effects[] = "Cleared {$clearedCurses} curse effect(s)";
        }
        
        // Clear snap modify effects
        clearSnapModifiers($gameId, $playerId);
        
        // Remove card from hand
        if ($card['quantity'] > 1) {
            $stmt = $pdo->prepare("
                UPDATE player_cards 
                SET quantity = quantity - 1 
                WHERE id = ?
            ");
            $stmt->execute([$playerCardId]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM player_cards WHERE id = ?");
            $stmt->execute([$playerCardId]);
        }
        
        $pdo->commit();
        return ['success' => true, 'effects' => $effects, 'points_awarded' => $finalPoints];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error completing snap card: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function completeSpicyCard($gameId, $playerId, $playerCardId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $pdo->beginTransaction();
        
        // Get spicy card details
        $stmt = $pdo->prepare("
            SELECT pc.*, c.*
            FROM player_cards pc
            JOIN cards c ON pc.card_id = c.id
            WHERE pc.id = ? AND pc.player_id = ? AND pc.game_id = ? AND pc.card_type = 'spicy'
        ");
        $stmt->execute([$playerCardId, $playerId, $gameId]);
        $card = $stmt->fetch();
        
        if (!$card) {
            throw new Exception("Spicy card not found in hand");
        }
        
        $effects = [];
        
        // Apply snap/spicy modifiers from active power/curse effects
        $finalPoints = applySnapSpicyModifiers($gameId, $playerId, $card['card_points'], 'spicy');
        
        // Award points
        if ($finalPoints > 0) {
            updateScore($gameId, $playerId, $finalPoints, $playerId);
            $effects[] = "Gained {$finalPoints} points";
        }
        
        // Update spicy completion stats and check for awards
        $awardResult = updateSpicyCardCompletion($gameId, $playerId);
        if ($awardResult['award_points'] > 0) {
            $effects[] = "Level {$awardResult['level']} Award: {$awardResult['award_points']} bonus points!";
        }
        
        // Check if this completes any curse effects
        $clearedCurses = clearCursesByCompletion($gameId, $playerId, 'spicy');
        if ($clearedCurses > 0) {
            $effects[] = "Cleared {$clearedCurses} curse effect(s)";
        }
        
        // Clear spicy modify effects
        clearSpicyModifiers($gameId, $playerId);
        
        // Remove card from hand
        if ($card['quantity'] > 1) {
            $stmt = $pdo->prepare("
                UPDATE player_cards 
                SET quantity = quantity - 1 
                WHERE id = ?
            ");
            $stmt->execute([$playerCardId]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM player_cards WHERE id = ?");
            $stmt->execute([$playerCardId]);
        }
        
        $pdo->commit();
        return ['success' => true, 'effects' => $effects, 'points_awarded' => $finalPoints];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error completing spicy card: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function vetoSnapSpicyCard($gameId, $playerId, $playerCardId, $cardType) {
    try {
        $pdo = Config::getDatabaseConnection();
        $pdo->beginTransaction();
        
        // Get card details
        $stmt = $pdo->prepare("
            SELECT pc.*, c.*
            FROM player_cards pc
            JOIN cards c ON pc.card_id = c.id
            WHERE pc.id = ? AND pc.player_id = ? AND pc.game_id = ? AND pc.card_type = ?
        ");
        $stmt->execute([$playerCardId, $playerId, $gameId, $cardType]);
        $card = $stmt->fetch();
        
        if (!$card) {
            throw new Exception(ucfirst($cardType) . " card not found in hand");
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
        
        // Remove card from hand
        if ($card['quantity'] > 1) {
            $stmt = $pdo->prepare("
                UPDATE player_cards 
                SET quantity = quantity - 1 
                WHERE id = ?
            ");
            $stmt->execute([$playerCardId]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM player_cards WHERE id = ?");
            $stmt->execute([$playerCardId]);
        }
        
        $pdo->commit();
        return ['success' => true, 'penalties' => $penalties];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error vetoing {$cardType} card: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function drawFromSnapDeck($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Check hand space (max 6 cards)
        $handCount = getPlayerHandCount($gameId, $playerId);
        if ($handCount >= 6) {
            return ['success' => false, 'message' => 'Hand is full (6 cards maximum)'];
        }
        
        // Get player gender
        $stmt = $pdo->prepare("SELECT gender FROM players WHERE id = ?");
        $stmt->execute([$playerId]);
        $playerGender = $stmt->fetchColumn();
        
        // Get random snap card for player's gender
        $genderClause = $playerGender === 'male' ? "AND male = 1" : "AND female = 1";
        $stmt = $pdo->prepare("
            SELECT * FROM cards 
            WHERE card_category = 'snap' $genderClause
            ORDER BY RAND() 
            LIMIT 1
        ");
        $stmt->execute();
        $card = $stmt->fetch();
        
        if (!$card) {
            return ['success' => false, 'message' => 'No snap cards available'];
        }
        
        // Add to hand with points = 2 (standard for drawn cards)
        $addedCount = addSnapCards($gameId, $playerId, 1);
        
        if ($addedCount > 0) {
            return ['success' => true, 'card' => $card, 'message' => "Drew {$card['card_name']}"];
        } else {
            return ['success' => false, 'message' => 'Failed to add card to hand'];
        }
        
    } catch (Exception $e) {
        error_log("Error drawing from snap deck: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to draw card'];
    }
}

function drawFromSpicyDeck($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Check hand space (max 6 cards)
        $handCount = getPlayerHandCount($gameId, $playerId);
        if ($handCount >= 6) {
            return ['success' => false, 'message' => 'Hand is full (6 cards maximum)'];
        }
        
        // Get player gender
        $stmt = $pdo->prepare("SELECT gender FROM players WHERE id = ?");
        $stmt->execute([$playerId]);
        $playerGender = $stmt->fetchColumn();
        
        // Get random spicy card for player's gender
        $genderClause = $playerGender === 'male' ? "AND male = 1" : "AND female = 1";
        $stmt = $pdo->prepare("
            SELECT * FROM cards 
            WHERE card_category = 'spicy' $genderClause
            ORDER BY RAND() 
            LIMIT 1
        ");
        $stmt->execute();
        $card = $stmt->fetch();
        
        if (!$card) {
            return ['success' => false, 'message' => 'No spicy cards available'];
        }
        
        // Add to hand with appropriate points (2 for normal, 4 for extra spicy)
        $addedCount = addSpicyCards($gameId, $playerId, 1);
        
        if ($addedCount > 0) {
            return ['success' => true, 'card' => $card, 'message' => "Drew {$card['card_name']}"];
        } else {
            return ['success' => false, 'message' => 'Failed to add card to hand'];
        }
        
    } catch (Exception $e) {
        error_log("Error drawing from spicy deck: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to draw card'];
    }
}

function getPlayerHand($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        $stmt = $pdo->prepare("
            SELECT pc.*, c.card_name, c.card_description, c.card_category,
                   COALESCE(pc.card_points, c.card_points) as effective_points,
                   c.veto_subtract, c.veto_steal, c.veto_wait
            FROM player_cards pc
            JOIN cards c ON pc.card_id = c.id
            WHERE pc.game_id = ? AND pc.player_id = ? AND pc.card_type IN ('power', 'snap', 'spicy')
            ORDER BY pc.card_type, c.card_name
        ");
        $stmt->execute([$gameId, $playerId]);
        
        return $stmt->fetchAll();
        
    } catch (Exception $e) {
        error_log("Error getting player hand: " . $e->getMessage());
        return [];
    }
}

function applySnapSpicyModifiers($gameId, $playerId, $basePoints, $cardType) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Get active power/curse effects that modify snap/spicy cards
        $modifyField = $cardType . '_modify';
        $stmt = $pdo->prepare("
            SELECT ace.*, c.score_modify, c.power_score_modify
            FROM active_curse_effects ace
            JOIN cards c ON ace.card_id = c.id
            WHERE ace.game_id = ? AND ace.player_id = ? AND c.$modifyField = 1
            UNION
            SELECT ape.id, ape.game_id, ape.player_id, ape.card_id, ape.expires_at, 
                   c.score_modify, c.power_score_modify
            FROM active_power_effects ape
            JOIN cards c ON ape.card_id = c.id
            WHERE ape.game_id = ? AND ape.player_id = ? AND c.power_{$modifyField} = 1
        ");
        $stmt->execute([$gameId, $playerId, $gameId, $playerId]);
        $effects = $stmt->fetchAll();
        
        $finalPoints = $basePoints;
        
        foreach ($effects as $effect) {
            $scoreModify = $effect['power_score_modify'] ?? $effect['score_modify'];
            
            switch ($scoreModify) {
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
            }
        }
        
        return $finalPoints;
        
    } catch (Exception $e) {
        error_log("Error applying snap/spicy modifiers: " . $e->getMessage());
        return $basePoints;
    }
}

function clearSnapModifiers($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Clear curse effects that modify snap cards
        $stmt = $pdo->prepare("
            DELETE ace FROM active_curse_effects ace
            JOIN cards c ON ace.card_id = c.id
            WHERE ace.game_id = ? AND ace.player_id = ? AND c.snap_modify = 1
        ");
        $stmt->execute([$gameId, $playerId]);
        
        // Clear power effects that modify snap cards
        $stmt = $pdo->prepare("
            DELETE ape FROM active_power_effects ape
            JOIN cards c ON ape.card_id = c.id
            WHERE ape.game_id = ? AND ape.player_id = ? AND c.power_snap_modify = 1
        ");
        $stmt->execute([$gameId, $playerId]);
        
        return true;
        
    } catch (Exception $e) {
        error_log("Error clearing snap modifiers: " . $e->getMessage());
        return false;
    }
}

function clearSpicyModifiers($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Clear curse effects that modify spicy cards
        $stmt = $pdo->prepare("
            DELETE ace FROM active_curse_effects ace
            JOIN cards c ON ace.card_id = c.id
            WHERE ace.game_id = ? AND ace.player_id = ? AND c.spicy_modify = 1
        ");
        $stmt->execute([$gameId, $playerId]);
        
        // Clear power effects that modify spicy cards
        $stmt = $pdo->prepare("
            DELETE ape FROM active_power_effects ape
            JOIN cards c ON ape.card_id = c.id
            WHERE ape.game_id = ? AND ape.player_id = ? AND c.power_spicy_modify = 1
        ");
        $stmt->execute([$gameId, $playerId]);
        
        return true;
        
    } catch (Exception $e) {
        error_log("Error clearing spicy modifiers: " . $e->getMessage());
        return false;
    }
}

function clearCursesByCompletion($gameId, $playerId, $completionType) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        $completionField = 'complete_' . $completionType;
        
        // Get curse effects that are cleared by this completion type
        $stmt = $pdo->prepare("
            SELECT ace.id
            FROM active_curse_effects ace
            JOIN cards c ON ace.card_id = c.id
            WHERE ace.game_id = ? AND ace.player_id = ? AND c.$completionField = 1
        ");
        $stmt->execute([$gameId, $playerId]);
        $effectIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($effectIds)) {
            $placeholders = str_repeat('?,', count($effectIds) - 1) . '?';
            $stmt = $pdo->prepare("DELETE FROM active_curse_effects WHERE id IN ($placeholders)");
            $stmt->execute($effectIds);
        }
        
        return count($effectIds);
        
    } catch (Exception $e) {
        error_log("Error clearing curses by completion: " . $e->getMessage());
        return 0;
    }
}

function clearPlayerCurseEffects($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        $stmt = $pdo->prepare("DELETE FROM active_curse_effects WHERE game_id = ? AND player_id = ?");
        $stmt->execute([$gameId, $playerId]);
        
        return $stmt->rowCount();
        
    } catch (Exception $e) {
        error_log("Error clearing player curse effects: " . $e->getMessage());
        return 0;
    }
}

function shuffleDailyDeck($gameId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        // Clear all current slots
        $stmt = $pdo->prepare("
            UPDATE daily_deck_slots 
            SET card_id = NULL, drawn_at = NULL, completed_at = NULL, completed_by = NULL
            WHERE game_id = ? AND deck_date = ?
        ");
        $stmt->execute([$gameId, $today]);
        
        // Reset all deck cards to unused
        $stmt = $pdo->prepare("
            UPDATE daily_deck_cards ddc
            JOIN daily_decks dd ON ddc.deck_id = dd.id
            SET ddc.is_used = 0
            WHERE dd.game_id = ? AND dd.deck_date = ?
        ");
        $stmt->execute([$gameId, $today]);
        
        return true;
        
    } catch (Exception $e) {
        error_log("Error shuffling daily deck: " . $e->getMessage());
        return false;
    }
}
?>