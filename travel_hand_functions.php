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
        $targetPlayerId = $card['target_opponent'] ? $opponentId : $playerId;
        
        // Apply immediate effects
        if ($card['power_score_add']) {
            // Only apply instantly if no challenge/snap/spicy modify
            if (!$card['power_snap_modify'] && !$card['power_spicy_modify'] && !$card['power_challenge_modify']) {
                updateScore($gameId, $targetPlayerId, $card['power_score_add'], $playerId);
                $targetName = $card['target_opponent'] ? "Opponent gained" : "Gained";
                $effects[] = "{$targetName} {$card['power_score_add']} points";
            } else {
                $effects[] = "Next snap/spicy card will give +{$card['power_score_add']} bonus points";
            }
        }

        if ($card['power_score_subtract']) {
            updateScore($gameId, $targetPlayerId, -$card['power_score_subtract'], $playerId);
            $targetName = $card['target_opponent'] ? "Opponent lost" : "Lost";
            $effects[] = "{$targetName} {$card['power_score_subtract']} points";
        }

        if ($card['power_score_steal']) {
            updateScore($gameId, $opponentId, -$card['power_score_steal'], $playerId);
            updateScore($gameId, $playerId, $card['power_score_steal'], $playerId);
            $effects[] = "Stole {$card['power_score_steal']} points from opponent";
        }

        // Apply wait to target
        if ($card['power_wait']) {
            applyVetoWait($gameId, $targetPlayerId, $card['power_wait']);
            sendPushNotification($targetPlayerId, "You Must Wait", "Your opponent just used a Power card to make you wait for {$card['power_wait']} minutes.");
            $targetName = $card['target_opponent'] ? "Opponent" : "You";
            $effects[] = "{$targetName} cannot interact with deck for {$card['power_wait']} minutes";
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
            shuffleDailyDeck($gameId, $playerId);
            $effects[] = "Daily deck shuffled";
        }
        
        if ($card['deck_peek']) {
            $effects[] = "You can see the next 3 cards in the daily deck";
        }
        
        if ($card['card_swap']) {
            applyVetoWait($gameId, $playerId, 0);
            $effects[] = "Wait penalty cleared!";
        }
        
        // Create ongoing effects if needed
        if ($card['power_challenge_modify'] || $card['power_snap_modify'] || 
            $card['power_spicy_modify'] || $card['power_score_modify'] !== 'none' ||
            $card['power_veto_modify'] !== 'none' || $card['skip_challenge'] ||
            $card['deck_peek'] || $card['bypass_expiration']) {
            
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

        $opponentId = getOpponentPlayerId($gameId, $playerId);
        $stmt = $pdo->prepare("SELECT fcm_token, first_name FROM players WHERE id = ?");
        $stmt->execute([$opponentId]);
        $opponent = $stmt->fetch();

        if ($opponent['fcm_token']) {
            $stmt = $pdo->prepare("SELECT first_name FROM players WHERE id = ?");
            $stmt->execute([$playerId]);
            $playerName = $stmt->fetchColumn();
            
            sendPushNotification(
                $opponent['fcm_token'],
                'Power Card Activated',
                "{$playerName} used: {$card['card_name']}"
            );
        }
        
        $pdo->commit();
        return ['success' => true, 'effects' => $effects];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error playing power card: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function applyPowerScoreBonus($gameId, $playerId, $cardType) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        $modifyField = 'power_' . $cardType . '_modify';
        $stmt = $pdo->prepare("
            SELECT ape.*, c.power_score_add
            FROM active_power_effects ape
            JOIN cards c ON ape.power_card_id = c.id
            WHERE ape.game_id = ? AND ape.player_id = ? AND c.$modifyField = 1 AND c.power_score_add > 0
        ");
        $stmt->execute([$gameId, $playerId]);
        $effects = $stmt->fetchAll();
        
        $bonus = 0;
        foreach ($effects as $effect) {
            $bonus += $effect['power_score_add'];
            
            // Remove this power effect after use
            $stmt = $pdo->prepare("DELETE FROM active_power_effects WHERE id = ?");
            $stmt->execute([$effect['id']]);
        }
        
        if ($bonus > 0) {
            updateScore($gameId, $playerId, $bonus, $playerId);
        }
        
        return $bonus;
        
    } catch (Exception $e) {
        error_log("Error applying power score bonus: " . $e->getMessage());
        return 0;
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

        $powerBonus = applyPowerScoreBonus($gameId, $playerId, 'snap');
        if ($powerBonus > 0) {
            $effects[] = "Power bonus: +{$powerBonus} points";
        }
        
        // Update snap completion stats and check for awards (this handles scoring)
        $awardResult = updateSnapCardCompletion($gameId, $playerId);
        if ($awardResult['award_points'] > 0) {
            $effects[] = "Level {$awardResult['level']} Award: {$awardResult['award_points']} bonus points!";
        }
        
        $effects[] = "Gained {$finalPoints} points";
        
        // Check if this completes any curse effects
        $clearedCurses = clearCursesByCompletion($gameId, $playerId, 'snap');
        if ($clearedCurses > 0) {
            $effects[] = "Cleared {$clearedCurses} curse effect(s)";
        }
        
        // Clear snap modify effects
        clearSnapModifiers($gameId, $playerId);

        // Track this card as completed
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO completed_cards (game_id, player_id, card_id, card_type)
            VALUES (?, ?, ?, 'snap')
        ");
        $stmt->execute([$gameId, $playerId, $card['card_id']]);
        
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

        $opponentId = getOpponentPlayerId($gameId, $playerId);
        $stmt = $pdo->prepare("SELECT fcm_token, first_name FROM players WHERE id = ?");
        $stmt->execute([$opponentId]);
        $opponent = $stmt->fetch();

        if ($opponent['fcm_token']) {
            $stmt = $pdo->prepare("SELECT first_name FROM players WHERE id = ?");
            $stmt->execute([$playerId]);
            $playerName = $stmt->fetchColumn();
            
            sendPushNotification(
                $opponent['fcm_token'],
                'Snap Card Completed',
                "{$playerName} completed: {$card['card_name']}"
            );
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

        $powerBonus = applyPowerScoreBonus($gameId, $playerId, 'spicy');
        if ($powerBonus > 0) {
            $effects[] = "Power bonus: +{$powerBonus} points";
        }
        
        // Update spicy completion stats and check for awards (this handles scoring)
        $awardResult = updateSpicyCardCompletion($gameId, $playerId);
        if ($awardResult['award_points'] > 0) {
            $effects[] = "Level {$awardResult['level']} Award: {$awardResult['award_points']} bonus points!";
        }
        
        $effects[] = "Gained {$finalPoints} points";
        
        // Check if this completes any curse effects
        $clearedCurses = clearCursesByCompletion($gameId, $playerId, 'spicy');
        if ($clearedCurses > 0) {
            $effects[] = "Cleared {$clearedCurses} curse effect(s)";
        }
        
        // Clear spicy modify effects
        clearSpicyModifiers($gameId, $playerId);

        // Track this card as completed
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO completed_cards (game_id, player_id, card_id, card_type)
            VALUES (?, ?, ?, 'spicy')
        ");
        $stmt->execute([$gameId, $playerId, $card['card_id']]);
        
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

        $opponentId = getOpponentPlayerId($gameId, $playerId);
        $stmt = $pdo->prepare("SELECT fcm_token, first_name FROM players WHERE id = ?");
        $stmt->execute([$opponentId]);
        $opponent = $stmt->fetch();

        if ($opponent['fcm_token']) {
            $stmt = $pdo->prepare("SELECT first_name FROM players WHERE id = ?");
            $stmt->execute([$playerId]);
            $playerName = $stmt->fetchColumn();
            
            sendPushNotification(
                $opponent['fcm_token'],
                'Spicy Card Completed',
                "{$playerName} completed: {$card['card_name']}"
            );
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
        
        // Check for veto skip effects
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM active_power_effects ape
            JOIN cards c ON ape.power_card_id = c.id
            WHERE ape.game_id = ? AND ape.player_id = ? AND c.power_veto_modify = 'skip'
        ");
        $stmt->execute([$gameId, $playerId]);
        $hasVetoSkip = $stmt->fetchColumn() > 0;

        // Check for veto double effects from curses
        $stmt = $pdo->prepare("
            SELECT ace.*, c.card_name 
            FROM active_curse_effects ace
            JOIN cards c ON ace.card_id = c.id
            WHERE ace.game_id = ? AND ace.player_id = ? AND c.veto_modify = 'double'
        ");
        $stmt->execute([$gameId, $playerId]);
        $vetoDoubleEffects = $stmt->fetchAll();

        $vetoMultiplier = 1;
        if (!empty($vetoDoubleEffects)) {
            $vetoMultiplier = 2;
            
            // Remove the veto double effects after use
            foreach ($vetoDoubleEffects as $effect) {
                $stmt = $pdo->prepare("DELETE FROM active_curse_effects WHERE id = ?");
                $stmt->execute([$effect['id']]);
            }
        }

        if ($hasVetoSkip) {
            $penalties[] = "Veto penalties skipped";
            
            // Remove the veto skip effect
            $stmt = $pdo->prepare("
                DELETE ape FROM active_power_effects ape
                JOIN cards c ON ape.power_card_id = c.id
                WHERE ape.game_id = ? AND ape.player_id = ? AND c.power_veto_modify = 'skip'
            ");
            $stmt->execute([$gameId, $playerId]);
        } else {
            // Apply veto penalties with multiplier
            if ($card['veto_subtract']) {
                $penalty = $card['veto_subtract'] * $vetoMultiplier;
                updateScore($gameId, $playerId, -$penalty, $playerId);
                $penalties[] = "Lost {$penalty} points";
            }

            if ($card['veto_steal']) {
                $penalty = $card['veto_steal'] * $vetoMultiplier;
                $opponentId = getOpponentPlayerId($gameId, $playerId);
                updateScore($gameId, $playerId, -$penalty, $playerId);
                updateScore($gameId, $opponentId, $penalty, $playerId);
                $penalties[] = "Lost {$penalty} points to opponent";
            }

            if ($card['veto_wait']) {
                $penalty = $card['veto_wait'] * $vetoMultiplier;
                applyVetoWait($gameId, $playerId, $penalty);
                $penalties[] = "Cannot interact with deck for {$penalty} minutes";
            }
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

function completeStoredChallenge($gameId, $playerId, $playerCardId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $pdo->beginTransaction();
        
        // Get stored challenge details
        $stmt = $pdo->prepare("
            SELECT pc.*, c.*
            FROM player_cards pc
            JOIN cards c ON pc.card_id = c.id
            WHERE pc.id = ? AND pc.player_id = ? AND pc.game_id = ? AND pc.card_type = 'stored_challenge'
        ");
        $stmt->execute([$playerCardId, $playerId, $gameId]);
        $card = $stmt->fetch();
        
        if (!$card) {
            throw new Exception("Stored challenge not found in hand");
        }
        
        // Apply modifiers and award points
        $finalPoints = applyModifiersToChallenge($gameId, $playerId, $card['card_points']);
        
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
        
        // Clear challenge modify effects
        clearChallengeModifiers($gameId, $playerId);
        
        // Remove from hand
        if ($card['quantity'] > 1) {
            $stmt = $pdo->prepare("UPDATE player_cards SET quantity = quantity - 1 WHERE id = ?");
            $stmt->execute([$playerCardId]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM player_cards WHERE id = ?");
            $stmt->execute([$playerCardId]);
        }
        
        $pdo->commit();
        return ['success' => true, 'points_awarded' => $finalPoints];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error completing stored challenge: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function vetoStoredChallenge($gameId, $playerId, $playerCardId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $pdo->beginTransaction();
        
        // Get stored challenge details
        $stmt = $pdo->prepare("
            SELECT pc.*, c.*
            FROM player_cards pc
            JOIN cards c ON pc.card_id = c.id
            WHERE pc.id = ? AND pc.player_id = ? AND pc.game_id = ? AND pc.card_type = 'stored_challenge'
        ");
        $stmt->execute([$playerCardId, $playerId, $gameId]);
        $card = $stmt->fetch();
        
        if (!$card) {
            throw new Exception("Stored challenge not found in hand");
        }

        // Check if veto would draw cards but hand is full
        $handCount = getPlayerHandCount($gameId, $playerId);
        if ($handCount >= 10 && ($card['veto_snap'] || $card['veto_spicy'])) {
            throw new Exception("Cannot veto: hand is full and this would draw additional cards");
        }
        
        $penalties = [];
        
        // Check for veto skip effects
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM active_power_effects ape
            JOIN cards c ON ape.power_card_id = c.id
            WHERE ape.game_id = ? AND ape.player_id = ? AND c.power_veto_modify = 'skip'
        ");
        $stmt->execute([$gameId, $playerId]);
        $hasVetoSkip = $stmt->fetchColumn() > 0;

        // Check for veto double effects from curses
        $stmt = $pdo->prepare("
            SELECT ace.*, c.card_name 
            FROM active_curse_effects ace
            JOIN cards c ON ace.card_id = c.id
            WHERE ace.game_id = ? AND ace.player_id = ? AND c.veto_modify = 'double'
        ");
        $stmt->execute([$gameId, $playerId]);
        $vetoDoubleEffects = $stmt->fetchAll();

        $vetoMultiplier = 1;
        if (!empty($vetoDoubleEffects)) {
            $vetoMultiplier = 2;
            
            // Remove the veto double effects after use
            foreach ($vetoDoubleEffects as $effect) {
                $stmt = $pdo->prepare("DELETE FROM active_curse_effects WHERE id = ?");
                $stmt->execute([$effect['id']]);
            }
        }

        if ($hasVetoSkip) {
            $penalties[] = "Veto penalties skipped";
            
            // Remove the veto skip effect
            $stmt = $pdo->prepare("
                DELETE ape FROM active_power_effects ape
                JOIN cards c ON ape.power_card_id = c.id
                WHERE ape.game_id = ? AND ape.player_id = ? AND c.power_veto_modify = 'skip'
            ");
            $stmt->execute([$gameId, $playerId]);
        } else {
            // Apply veto penalties with multiplier
            if ($card['veto_subtract']) {
                $penalty = $card['veto_subtract'] * $vetoMultiplier;
                updateScore($gameId, $playerId, -$penalty, $playerId);
                $penalties[] = "Lost {$penalty} points";
            }

            if ($card['veto_steal']) {
                $penalty = $card['veto_steal'] * $vetoMultiplier;
                $opponentId = getOpponentPlayerId($gameId, $playerId);
                updateScore($gameId, $playerId, -$penalty, $playerId);
                updateScore($gameId, $opponentId, $penalty, $playerId);
                $penalties[] = "Lost {$penalty} points to opponent";
            }

            if ($card['veto_wait']) {
                $penalty = $card['veto_wait'] * $vetoMultiplier;
                applyVetoWait($gameId, $playerId, $penalty);
                $penalties[] = "Cannot interact with deck for {$penalty} minutes";
            }

            if ($card['veto_snap']) {
                $penalty = $card['veto_snap'] * $vetoMultiplier;
                addSnapCards($gameId, $playerId, $penalty);
                $penalties[] = "Drew {$penalty} snap card(s)";
            }

            if ($card['veto_spicy']) {
                $penalty = $card['veto_spicy'] * $vetoMultiplier;
                addSpicyCards($gameId, $playerId, $penalty);
                $penalties[] = "Drew {$penalty} spicy card(s)";
            }
        }
        
        // Remove from hand
        if ($card['quantity'] > 1) {
            $stmt = $pdo->prepare("UPDATE player_cards SET quantity = quantity - 1 WHERE id = ?");
            $stmt->execute([$playerCardId]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM player_cards WHERE id = ?");
            $stmt->execute([$playerCardId]);
        }
        
        $pdo->commit();
        return ['success' => true, 'penalties' => $penalties];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error vetoing stored challenge: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function drawFromSnapDeck($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Check hand space
        $handCount = getPlayerHandCount($gameId, $playerId);
        if ($handCount >= 10) {
            return ['success' => false, 'message' => 'Hand is full (10 cards maximum)'];
        }
        
        // Get player gender and travel mode
        $stmt = $pdo->prepare("
            SELECT p.gender, g.travel_mode_id 
            FROM players p 
            JOIN games g ON p.game_id = g.id 
            WHERE p.id = ?
        ");
        $stmt->execute([$playerId]);
        $gameInfo = $stmt->fetch();
        
        $genderClause = $gameInfo['gender'] === 'male' ? "AND c.male = 1" : "AND c.female = 1";
        
        // Get cards already in hand
        $stmt = $pdo->prepare("
            SELECT card_id FROM player_cards 
            WHERE game_id = ? AND player_id = ? AND card_type = 'snap'
        ");
        $stmt->execute([$gameId, $playerId]);
        $inHand = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Get completed snap cards
        $stmt = $pdo->prepare("
            SELECT card_id FROM completed_cards 
            WHERE game_id = ? AND player_id = ? AND card_type = 'snap'
        ");
        $stmt->execute([$gameId, $playerId]);
        $completed = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Exclude cards in hand OR completed
        $excludeIds = array_merge($inHand, $completed);
        $excludeClause = "";
        if (!empty($excludeIds)) {
            $placeholders = str_repeat('?,', count($excludeIds) - 1) . '?';
            $excludeClause = "AND c.id NOT IN ($placeholders)";
        }
        
        $sql = "
            SELECT c.* FROM cards c
            JOIN card_travel_modes ctm ON c.id = ctm.card_id
            WHERE c.card_category = 'snap' 
            AND ctm.mode_id = ? 
            $genderClause
            $excludeClause
            ORDER BY RAND() 
            LIMIT 1
        ";
        
        $params = array_merge([$gameInfo['travel_mode_id']], $excludeIds);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $card = $stmt->fetch();
        
        if (!$card) {
            return ['success' => false, 'message' => 'No snap cards available'];
        }
        
        // Add to hand
        $stmt = $pdo->prepare("
            INSERT INTO player_cards (game_id, player_id, card_id, card_type, quantity, card_points)
            VALUES (?, ?, ?, 'snap', 1, ?)
        ");
        $stmt->execute([$gameId, $playerId, $card['id'], $card['card_points']]);
        
        return ['success' => true, 'card' => $card, 'message' => "Drew {$card['card_name']}"];
        
    } catch (Exception $e) {
        error_log("Error drawing from snap deck: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to draw card'];
    }
}

function drawFromSpicyDeck($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Check hand space
        $handCount = getPlayerHandCount($gameId, $playerId);
        if ($handCount >= 10) {
            return ['success' => false, 'message' => 'Hand is full (10 cards maximum)'];
        }
        
        // Get player gender and travel mode
        $stmt = $pdo->prepare("
            SELECT p.gender, g.travel_mode_id 
            FROM players p 
            JOIN games g ON p.game_id = g.id 
            WHERE p.id = ?
        ");
        $stmt->execute([$playerId]);
        $gameInfo = $stmt->fetch();
        
        $genderClause = $gameInfo['gender'] === 'male' ? "AND c.male = 1" : "AND c.female = 1";
        
        // Get cards already in hand
        $stmt = $pdo->prepare("
            SELECT card_id FROM player_cards 
            WHERE game_id = ? AND player_id = ? AND card_type = 'spicy'
        ");
        $stmt->execute([$gameId, $playerId]);
        $inHand = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Get completed spicy cards
        $stmt = $pdo->prepare("
            SELECT card_id FROM completed_cards 
            WHERE game_id = ? AND player_id = ? AND card_type = 'spicy'
        ");
        $stmt->execute([$gameId, $playerId]);
        $completed = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Exclude cards in hand OR completed
        $excludeIds = array_merge($inHand, $completed);
        $excludeClause = "";
        if (!empty($excludeIds)) {
            $placeholders = str_repeat('?,', count($excludeIds) - 1) . '?';
            $excludeClause = "AND c.id NOT IN ($placeholders)";
        }
        
        $sql = "
            SELECT c.* FROM cards c
            JOIN card_travel_modes ctm ON c.id = ctm.card_id
            WHERE c.card_category = 'spicy' 
            AND ctm.mode_id = ? 
            $genderClause
            $excludeClause
            ORDER BY RAND() 
            LIMIT 1
        ";
        
        $params = array_merge([$gameInfo['travel_mode_id']], $excludeIds);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $card = $stmt->fetch();
        
        if (!$card) {
            return ['success' => false, 'message' => 'No spicy cards available'];
        }
        
        // Add to hand
        $stmt = $pdo->prepare("
            INSERT INTO player_cards (game_id, player_id, card_id, card_type, quantity, card_points)
            VALUES (?, ?, ?, 'spicy', 1, ?)
        ");
        $stmt->execute([$gameId, $playerId, $card['id'], $card['card_points']]);
        
        return ['success' => true, 'card' => $card, 'message' => "Drew {$card['card_name']}"];
        
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
            WHERE pc.game_id = ? AND pc.player_id = ? AND pc.card_type IN ('power', 'snap', 'spicy', 'stored_challenge')
            ORDER BY pc.id
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

        // Check for active power effects with score_add that modify this card type
        $stmt = $pdo->prepare("
            SELECT ape.*, c.power_score_add
            FROM active_power_effects ape
            JOIN cards c ON ape.power_card_id = c.id
            WHERE ape.game_id = ? AND ape.player_id = ? AND c.power_{$cardType}_modify = 1 AND c.power_score_add > 0
        ");
        $stmt->execute([$gameId, $playerId]);
        $powerEffects = $stmt->fetchAll();

        // If there are power effects with score_add, use that value instead
        if (!empty($powerEffects)) {
            $finalPoints = $powerEffects[0]['power_score_add']; // Use the power's score_add value
            
            // Remove the power effect after use
            $stmt = $pdo->prepare("DELETE FROM active_power_effects WHERE id = ?");
            $stmt->execute([$powerEffects[0]['id']]);
            
            return $finalPoints;
        }
        
        // Get active power/curse effects that modify snap/spicy cards
        $modifyField = $cardType . '_modify';
        $stmt = $pdo->prepare("
            SELECT ace.id, ace.game_id, ace.player_id, ace.card_id, ace.expires_at, 
                   c.score_modify, NULL as power_score_modify
            FROM active_curse_effects ace
            JOIN cards c ON ace.card_id = c.id
            WHERE ace.game_id = ? AND ace.player_id = ? AND c.$modifyField = 1
            UNION
            SELECT ape.id, ape.game_id, ape.player_id, ape.power_card_id as card_id, ape.expires_at, 
                   NULL as score_modify, c.power_score_modify
            FROM active_power_effects ape
            JOIN cards c ON ape.power_card_id = c.id
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
        
        // Get earliest snap modifier effect
        $stmt = $pdo->prepare("
            (SELECT 'curse' as effect_type, ace.id, ace.created_at
             FROM active_curse_effects ace
             JOIN cards c ON ace.card_id = c.id
             WHERE ace.game_id = ? AND ace.player_id = ? AND c.snap_modify = 1)
            UNION ALL
            (SELECT 'power' as effect_type, ape.id, ape.created_at
             FROM active_power_effects ape
             JOIN cards c ON ape.power_card_id = c.id
             WHERE ape.game_id = ? AND ape.player_id = ? AND c.power_snap_modify = 1)
            ORDER BY created_at ASC
            LIMIT 1
        ");
        $stmt->execute([$gameId, $playerId, $gameId, $playerId]);
        $earliestEffect = $stmt->fetch();
        
        if ($earliestEffect) {
            if ($earliestEffect['effect_type'] === 'curse') {
                $stmt = $pdo->prepare("DELETE FROM active_curse_effects WHERE id = ?");
            } else {
                $stmt = $pdo->prepare("DELETE FROM active_power_effects WHERE id = ?");
            }
            $stmt->execute([$earliestEffect['id']]);
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("Error clearing snap modifiers: " . $e->getMessage());
        return false;
    }
}

function clearSpicyModifiers($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Get earliest spicy modifier effect
        $stmt = $pdo->prepare("
            (SELECT 'curse' as effect_type, ace.id, ace.created_at
             FROM active_curse_effects ace
             JOIN cards c ON ace.card_id = c.id
             WHERE ace.game_id = ? AND ace.player_id = ? AND c.spicy_modify = 1)
            UNION ALL
            (SELECT 'power' as effect_type, ape.id, ape.created_at
             FROM active_power_effects ape
             JOIN cards c ON ape.power_card_id = c.id
             WHERE ape.game_id = ? AND ape.player_id = ? AND c.power_spicy_modify = 1)
            ORDER BY created_at ASC
            LIMIT 1
        ");
        $stmt->execute([$gameId, $playerId, $gameId, $playerId]);
        $earliestEffect = $stmt->fetch();
        
        if ($earliestEffect) {
            if ($earliestEffect['effect_type'] === 'curse') {
                $stmt = $pdo->prepare("DELETE FROM active_curse_effects WHERE id = ?");
            } else {
                $stmt = $pdo->prepare("DELETE FROM active_power_effects WHERE id = ?");
            }
            $stmt->execute([$earliestEffect['id']]);
        }
        
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
        $modifyField = $completionType . '_modify';
        $timerIdToDelete = NULL;
        
        // Get curses that require this card type to be completed (snap/spicy block)
        $stmt = $pdo->prepare("
            SELECT ace.id, ace.slot_number
            FROM active_curse_effects ace
            JOIN cards c ON ace.card_id = c.id
            WHERE ace.game_id = ? AND ace.player_id = ? 
            AND c.$completionField = 1
        ");
        $stmt->execute([$gameId, $playerId]);
        $effects = $stmt->fetchAll();

        if($completionType === 'snap') {
            $stmt = $pdo->prepare("
                SELECT id FROM timers WHERE game_id = ? AND player_id = ? AND timer_type = 'siphon' AND completion_type = 'first_trigger_any'
            ");
            $stmt->execute([$gameId, $playerId]);
            $timerIdToDelete = $stmt->fetchColumn();
        }

        if($completionType === 'spicy') {
            $stmt = $pdo->prepare("
                SELECT id FROM timers WHERE game_id = ? AND player_id = ? AND timer_type = 'siphon'
            ");
            $stmt->execute([$gameId, $playerId]);
            $timerIdToDelete = $stmt->fetchColumn();
        }

        if($timerIdToDelete !== NULL) {
            deleteTimer($timerIdToDelete, $gameId);
        }
        
        if (!empty($effects)) {
            $effectIds = array_column($effects, 'id');
            $placeholders = str_repeat('?,', count($effectIds) - 1) . '?';
            $stmt = $pdo->prepare("DELETE FROM active_curse_effects WHERE id IN ($placeholders)");
            $stmt->execute($effectIds);
        }
        
        return count($effects);
        
    } catch (Exception $e) {
        error_log("Error clearing curses by completion: " . $e->getMessage());
        return 0;
    }
}

function clearPlayerCurseEffects($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Get slot numbers before deleting
        $stmt = $pdo->prepare("SELECT slot_number FROM active_curse_effects WHERE game_id = ? AND player_id = ?");
        $stmt->execute([$gameId, $playerId]);
        $slotNumbers = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $stmt = $pdo->prepare("DELETE FROM timers WHERE game_id = ? AND player_id = ?");
        $stmt->execute([$gameId, $playerId]);
        
        $stmt = $pdo->prepare("DELETE FROM active_curse_effects WHERE game_id = ? AND player_id = ?");
        $stmt->execute([$gameId, $playerId]);
        
        return $stmt->rowCount();
        
    } catch (Exception $e) {
        error_log("Error clearing player curse effects: " . $e->getMessage());
        return 0;
    }
}

function shuffleDailyDeck($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        $pdo->beginTransaction();
        
        // Get deck ID
        $stmt = $pdo->prepare("SELECT id FROM daily_decks WHERE game_id = ? AND player_id = ? AND deck_date = ?");
        $stmt->execute([$gameId, $playerId, $today]);
        $deckId = $stmt->fetchColumn();
        
        if (!$deckId) {
            throw new Exception("No deck found for today");
        }
        
        // Get cards currently in slots
        $stmt = $pdo->prepare("
            SELECT card_id FROM daily_deck_slots 
            WHERE game_id = ? AND player_id = ? AND deck_date = ? AND card_id IS NOT NULL
        ");
        $stmt->execute([$gameId, $playerId, $today]);
        $slotCards = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Get all cards with their current status
        $stmt = $pdo->prepare("
            SELECT card_id, is_used FROM daily_deck_cards 
            WHERE deck_id = ?
            ORDER BY id
        ");
        $stmt->execute([$deckId]);
        $allCardData = $stmt->fetchAll();
        
        // Extract just the card IDs for shuffling
        $allCards = array_column($allCardData, 'card_id');
        
        // Create status lookup
        $cardStatus = array_column($allCardData, 'is_used', 'card_id');
        
        // Shuffle the card order
        shuffle($allCards);
        
        // Delete existing deck cards
        $stmt = $pdo->prepare("DELETE FROM daily_deck_cards WHERE deck_id = ?");
        $stmt->execute([$deckId]);
        
        // Re-insert cards in new shuffled order
        foreach ($allCards as $cardId) {
            // Cards in slots become unused, others keep original status
            $isUsed = in_array($cardId, $slotCards) ? 0 : $cardStatus[$cardId];
            $stmt = $pdo->prepare("INSERT INTO daily_deck_cards (deck_id, player_id, card_id, is_used) VALUES (?, ?, ?, ?)");
            $stmt->execute([$deckId, $playerId, $cardId, $isUsed]);
        }
        
        // Clear all slots for this player
        $stmt = $pdo->prepare("
            UPDATE daily_deck_slots 
            SET card_id = NULL, drawn_at = NULL, completed_at = NULL, completed_by_player_id = NULL, curse_activated = 0
            WHERE game_id = ? AND player_id = ? AND deck_date = ?
        ");
        $stmt->execute([$gameId, $playerId, $today]);
        
        $pdo->commit();
        return true;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error shuffling daily deck: " . $e->getMessage());
        return false;
    }
}
?>