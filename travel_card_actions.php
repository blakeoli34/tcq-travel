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
        
        // Get card in slot FOR THIS SPECIFIC PLAYER
        $stmt = $pdo->prepare("
            SELECT dds.*, c.*
            FROM daily_deck_slots dds
            JOIN cards c ON dds.card_id = c.id
            WHERE dds.game_id = ? AND dds.player_id = ? AND dds.deck_date = ? AND dds.slot_number = ? AND c.card_category = 'challenge'
        ");
        $stmt->execute([$gameId, $playerId, $today, $slotNumber]);
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
        
        // Mark slot as completed AND CLEAR IT
        completeClearSlot($gameId, $playerId, $slotNumber);
        
        // Clear challenge modify effects
        clearChallengeModifiers($gameId, $playerId);

        // Track as completed
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO completed_cards (game_id, player_id, card_id, card_type)
            VALUES (?, ?, ?, 'challenge')
        ");
        $stmt->execute([$gameId, $playerId, $card['card_id']]);

        // Notify opponent
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
                'Challenge Completed',
                "{$playerName} completed {$card['card_name']} and earned {$finalPoints} points"
            );
        }
        
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
            WHERE dds.game_id = ? AND dds.player_id = ? AND dds.deck_date = ? AND dds.slot_number = ? AND c.card_category = 'challenge'
        ");
        $stmt->execute([$gameId, $playerId, $today, $slotNumber]);
        $card = $stmt->fetch();
        
        if (!$card) {
            throw new Exception("No challenge card in this slot");
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
            // Apply veto penalties (existing code)
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
        }
        
        // Clear slot
        clearSlot($gameId, $playerId, $slotNumber);

        // Track as completed
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO completed_cards (game_id, player_id, card_id, card_type)
            VALUES (?, ?, ?, 'challenge')
        ");
        $stmt->execute([$gameId, $playerId, $card['card_id']]);

        // Notify opponent
        $opponentId = getOpponentPlayerId($gameId, $playerId);
        $stmt = $pdo->prepare("SELECT fcm_token FROM players WHERE id = ?");
        $stmt->execute([$opponentId]);
        $opponentToken = $stmt->fetchColumn();

        if ($opponentToken) {
            $stmt = $pdo->prepare("SELECT first_name FROM players WHERE id = ?");
            $stmt->execute([$playerId]);
            $playerName = $stmt->fetchColumn();
            
            $penaltyText = implode(', ', $penalties);
            sendPushNotification(
                $opponentToken,
                'Challenge Vetoed',
                "{$playerName} vetoed {$card['card_name']}. Penalties: {$penaltyText}"
            );
        }
        
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
            WHERE dds.game_id = ? AND dds.player_id = ? AND dds.deck_date = ? AND dds.slot_number = ? AND c.card_category = 'battle'
        ");
        $stmt->execute([$gameId, $playerId, $today, $slotNumber]);
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
        completeClearSlot($gameId, $playerId, $slotNumber);

        // Track as completed
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO completed_cards (game_id, player_id, card_id, card_type)
            VALUES (?, ?, ?, 'battle')
        ");
        $stmt->execute([$gameId, $playerId, $card['card_id']]);

        $players = getGamePlayers($gameId);
        foreach ($players as $p) {
            if ($p['fcm_token'] && $p['id'] === $opponentId) {
                $stmt = $pdo->prepare("SELECT first_name FROM players WHERE id = ?");
                $stmt->execute([$playerId]);
                $playerName = $stmt->fetchColumn();
                
                $message = $isWinner 
                    ? "{$playerName} won the battle: {$card['card_name']}"
                    : "{$playerName} lost the battle: {$card['card_name']}";
                
                sendPushNotification($p['fcm_token'], 'Battle Complete', $message);
            }
        }
        
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
        
        $pdo->beginTransaction();
        
        // Get card in slot
        $stmt = $pdo->prepare("
            SELECT dds.*, c.*
            FROM daily_deck_slots dds
            JOIN cards c ON dds.card_id = c.id
            WHERE dds.game_id = ? AND dds.player_id = ? AND dds.deck_date = ? AND dds.slot_number = ? AND c.card_category = 'curse'
        ");
        $stmt->execute([$gameId, $playerId, $today, $slotNumber]);
        $card = $stmt->fetch();
        
        if (!$card) {
            throw new Exception("No curse card in this slot");
        }
        
        // Process curse effects
        $curseResult = processCurseCard($gameId, $playerId, $card);
        $effects = $curseResult['effects'];

        // Create active curse effect only if not instant
        $effectId = null;
        if (!$curseResult['is_instant']) {
            $effectId = addActiveCurseEffect($gameId, $playerId, $card['card_id'], $card, $slotNumber, $curseResult['timer_id'] ?? null);
        }

        // Always clear the slot after activating curse
        completeClearSlot($gameId, $playerId, $slotNumber);

        // Track as completed
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO completed_cards (game_id, player_id, card_id, card_type)
            VALUES (?, ?, ?, 'curse')
        ");
        $stmt->execute([$gameId, $playerId, $card['card_id']]);
        
        $pdo->commit();
        return ['success' => true, 'effects' => $effects, 'effect_id' => $effectId];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error activating curse: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function completeCurseSlot($gameId, $playerId, $slotNumber) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        $stmt = $pdo->prepare("
            UPDATE daily_deck_slots 
            SET card_id = NULL, drawn_at = NULL, completed_at = NOW(), 
                completed_by_player_id = ?, curse_activated = FALSE
            WHERE game_id = ? AND player_id = ? AND deck_date = ? AND slot_number = ?
        ");
        $stmt->execute([$playerId, $gameId, $playerId, $today, $slotNumber]);
        
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false];
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
            WHERE dds.game_id = ? AND dds.player_id = ? AND dds.deck_date = ? AND dds.slot_number = ? AND c.card_category = 'power'
        ");
        $stmt->execute([$gameId, $playerId, $today, $slotNumber]);
        $card = $stmt->fetch();
        
        if (!$card) {
            throw new Exception("No power card in this slot");
        }
        
        // Check if player has room in hand (max 6 power/snap/spicy cards)
        $handCount = getPlayerHandCount($gameId, $playerId);
        if ($handCount >= 6) {
            throw new Exception("Hand is full (6 cards maximum)");
        }
        
        // Check if player already has this card in hand
        $stmt = $pdo->prepare("
            SELECT id, quantity FROM player_cards 
            WHERE game_id = ? AND player_id = ? AND card_id = ? AND card_type = 'power'
        ");
        $stmt->execute([$gameId, $playerId, $card['card_id']]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Update quantity
            $stmt = $pdo->prepare("
                UPDATE player_cards 
                SET quantity = quantity + 1 
                WHERE id = ?
            ");
            $stmt->execute([$existing['id']]);
        } else {
            // Add new card to hand
            $stmt = $pdo->prepare("
                INSERT INTO player_cards (game_id, player_id, card_id, card_type, quantity)
                VALUES (?, ?, ?, 'power', 1)
            ");
            $stmt->execute([$gameId, $playerId, $card['card_id']]);
        }

        // Track as completed
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO completed_cards (game_id, player_id, card_id, card_type)
            VALUES (?, ?, ?, 'power')
        ");
        $stmt->execute([$gameId, $playerId, $card['card_id']]);
        
        // Mark slot as completed and clear it
        completeClearSlot($gameId, $playerId, $slotNumber);
        
        $pdo->commit();
        return ['success' => true, 'message' => "Added {$card['card_name']} to hand"];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error claiming power: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function activatePowerFromSlot($gameId, $playerId, $slotNumber) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        $pdo->beginTransaction();
        
        // Get power card in slot
        $stmt = $pdo->prepare("
            SELECT dds.*, c.*
            FROM daily_deck_slots dds
            JOIN cards c ON dds.card_id = c.id
            WHERE dds.game_id = ? AND dds.player_id = ? AND dds.deck_date = ? AND dds.slot_number = ? AND c.card_category = 'power'
        ");
        $stmt->execute([$gameId, $playerId, $today, $slotNumber]);
        $card = $stmt->fetch();
        
        if (!$card) {
            throw new Exception("No power card in this slot");
        }
        
        $effects = [];
        $opponentId = getOpponentPlayerId($gameId, $playerId);
        $targetPlayerId = $card['target_opponent'] ? $opponentId : $playerId;
        
        // Apply immediate effects
        if ($card['power_score_add']) {
            // Only apply instantly if no snap/spicy/challenge modify
            if (!$card['power_snap_modify'] && !$card['power_spicy_modify'] && !$card['power_challenge_modify']) {
                updateScore($gameId, $targetPlayerId, $card['power_score_add'], $playerId);
                $targetName = $card['target_opponent'] ? "Opponent gained" : "Gained";
                $effects[] = "{$targetName} {$card['power_score_add']} points";
            } else {
                $effects[] = "Next completed card will give +{$card['power_score_add']} bonus points";
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
        
        if ($card['power_wait']) {
            applyVetoWait($gameId, $targetPlayerId, $card['power_wait']);
            $targetName = $card['target_opponent'] ? "Opponent" : "You";
            $effects[] = "{$targetName} cannot interact with deck for {$card['power_wait']} minutes";
        }
        
        if ($card['clear_curse']) {
            clearPlayerCurseEffects($gameId, $playerId);
            $effects[] = "Cleared all active curse effects";
        }
        
        if ($card['shuffle_daily_deck']) {
            shuffleDailyDeck($gameId, $playerId);
            $effects[] = "Daily deck shuffled";
        }
        
        // Create ongoing effects if needed
        if ($card['power_challenge_modify'] || $card['power_snap_modify'] || 
            $card['power_spicy_modify'] || $card['power_score_modify'] !== 'none' ||
            $card['power_veto_modify'] !== 'none' || $card['skip_challenge'] ||
            $card['deck_peek'] || $card['card_swap'] || $card['bypass_expiration']) {
            
            $effectId = addActivePowerEffect($gameId, $playerId, $card['card_id'], $card);
            error_log("Created active power effect ID: $effectId for card {$card['card_id']}");
            $effects[] = "Ongoing power effect activated";
        }

        // Track as completed
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO completed_cards (game_id, player_id, card_id, card_type)
            VALUES (?, ?, ?, 'power')
        ");
        $stmt->execute([$gameId, $playerId, $card['card_id']]);
        
        // Clear slot
        completeClearSlot($gameId, $playerId, $slotNumber);
        
        $pdo->commit();
        return ['success' => true, 'message' => implode(', ', $effects)];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error activating power from slot: " . $e->getMessage());
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
            WHERE dds.game_id = ? AND dds.player_id = ? AND dds.deck_date = ? AND dds.slot_number = ? AND c.card_category = 'power'
        ");
        $stmt->execute([$gameId, $playerId, $today, $slotNumber]);
        $card = $stmt->fetch();
        
        if (!$card) {
            return ['success' => false, 'message' => 'No power card in this slot'];
        }

        // Track as completed
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO completed_cards (game_id, player_id, card_id, card_type)
            VALUES (?, ?, ?, 'power')
        ");
        $stmt->execute([$gameId, $playerId, $card['card_id']]);
        
        // Clear slot (card is discarded)
        clearSlot($gameId, $playerId, $slotNumber);
        
        return ['success' => true, 'message' => "Discarded {$card['card_name']}"];
        
    } catch (Exception $e) {
        error_log("Error discarding power: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function addSnapCards($gameId, $playerId, $count) {
    try {
        $pdo = Config::getDatabaseConnection();
        
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
        
        $stmt = $pdo->prepare("
            SELECT c.* FROM cards c
            JOIN card_travel_modes ctm ON c.id = ctm.card_id
            WHERE c.card_category = 'snap' 
            AND ctm.mode_id = ? 
            $genderClause
            ORDER BY RAND() 
            LIMIT ?
        ");
        $stmt->execute([$gameInfo['travel_mode_id'], $count]);
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
        
        $stmt = $pdo->prepare("
            SELECT c.* FROM cards c
            JOIN card_travel_modes ctm ON c.id = ctm.card_id
            WHERE c.card_category = 'spicy' 
            AND ctm.mode_id = ? 
            $genderClause
            ORDER BY RAND() 
            LIMIT ?
        ");
        $stmt->execute([$gameInfo['travel_mode_id'], $count]);
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

        // Check for active power effects with score_add that modify challenges
        $stmt = $pdo->prepare("
            SELECT ape.*, c.power_score_add
            FROM active_power_effects ape
            JOIN cards c ON ape.power_card_id = c.id
            WHERE ape.game_id = ? AND ape.player_id = ? AND c.power_challenge_modify = 1 AND c.power_score_add > 0
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
        
        // Check for active power effects with score_modify that modify challenges
        $stmt = $pdo->prepare("
            SELECT ape.*, c.power_score_modify
            FROM active_power_effects ape
            JOIN cards c ON ape.power_card_id = c.id
            WHERE ape.game_id = ? AND ape.player_id = ? AND c.power_challenge_modify = 1 AND c.power_score_modify != 'none'
        ");
        $stmt->execute([$gameId, $playerId]);
        $powerModifyEffects = $stmt->fetchAll();
        
        $finalPoints = $basePoints;
        
        foreach ($powerModifyEffects as $effect) {
            switch ($effect['power_score_modify']) {
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
            
            // Remove the power effect after use
            $stmt = $pdo->prepare("DELETE FROM active_power_effects WHERE id = ?");
            $stmt->execute([$effect['id']]);
        }
        
        // If power effects were applied, return the result
        if (!empty($powerModifyEffects)) {
            return $finalPoints;
        }
        
        // Get active curse effects that modify challenges
        $stmt = $pdo->prepare("
            SELECT ace.*, c.score_modify
            FROM active_curse_effects ace
            JOIN cards c ON ace.card_id = c.id
            WHERE ace.game_id = ? AND ace.player_id = ? AND c.challenge_modify = 1
        ");
        $stmt->execute([$gameId, $playerId]);
        $effects = $stmt->fetchAll();
        
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
    $isInstant = true; // Track if this curse should be removed immediately
    
    // Instant effects
    if ($card['score_add']) {
        updateScore($gameId, $playerId, $card['score_add'], $playerId);
        $effects[] = "Gained {$card['score_add']} points";
    }
    
    if ($card['score_subtract']) {
        if ($card['repeat_count'] && $card['timer_completion_type']) {
            // This is a siphon card - don't subtract immediately, handle in recurring section
            $isInstant = false;
        } else {
            // Regular instant score subtract
            updateScore($gameId, $playerId, -$card['score_subtract'], $playerId);
            $effects[] = "Lost {$card['score_subtract']} points";
        }
    }
    
    if ($card['score_steal']) {
        $opponentId = getOpponentPlayerId($gameId, $playerId);
        updateScore($gameId, $playerId, -$card['score_steal'], $playerId);
        updateScore($gameId, $opponentId, $card['score_steal'], $playerId);
        $effects[] = "Lost {$card['score_steal']} points to opponent";
    }

    // Wait effects
    if ($card['wait']) {
        applyVetoWait($gameId, $playerId, $card['wait']);
        $effects[] = "Cannot interact with deck for {$card['wait']} minutes";
    }
    
    // Check for ongoing effects
    if ($card['challenge_modify'] || $card['snap_modify'] || $card['spicy_modify'] || $card['veto_modify'] || 
        $card['timer'] || $card['repeat_count'] || $card['roll_dice'] || 
        $card['complete_snap'] || $card['complete_spicy']) {
        $isInstant = false;
    }
    
    // Timer effects
    $timerId = null;
    if ($card['timer']) {
        $timerResult = createTimer($gameId, $playerId, $card['card_name'], $card['timer']);
        if ($timerResult['success']) {
            $timerId = $timerResult['timer_id'];
            $effects[] = "Timer started for {$card['timer']} minutes";
        }
    }

    // Recurring siphon effects (clock/spicy siphon)
    if ($card['score_subtract'] && $card['repeat_count'] && $card['timer_completion_type']) {
        // Siphon effect - subtract immediately then start recurring timer
        updateScore($gameId, $playerId, -$card['score_subtract'], $playerId);
        $effects[] = "Lost {$card['score_subtract']} points and will continue losing {$card['score_subtract']} points every {$card['repeat_count']} minutes until cleared";
        
        // Create siphon timer
        $siphonResult = createSiphonTimer($gameId, $playerId, $card['card_name'], $card['repeat_count'], $card['score_subtract'], $card['timer_completion_type']);
        if ($siphonResult['success']) {
            $timerId = $siphonResult['timer_id'];
        }
    }
    
    // Dice effects
    if ($card['roll_dice']) {
        $effects[] = "Roll dice to determine if curse is cleared";
        
        // Add to active effects first, then return special flag to trigger dice roll
        $effectId = addActiveCurseEffect($gameId, $playerId, $card['id'], $card, $slotNumber, $timerId);
        
        if ($effectId && $card['dice_condition']) {
            // Mark as pending dice roll
            $stmt = $pdo->prepare("
                UPDATE active_curse_effects 
                SET pending_dice_roll = TRUE 
                WHERE id = ?
            ");
            $stmt->execute([$effectId]);
            
            // Return special flag to trigger dice roll
            return [
                'effects' => $effects, 
                'is_instant' => false, 
                'timer_id' => $timerId,
                'requires_dice_roll' => true,
                'effect_id' => $effectId
            ];
        }
    }
    
    // Completion requirements
    if ($card['complete_snap']) {
        $effects[] = "Complete a snap card to clear this curse";
    }
    
    if ($card['complete_spicy']) {
        $effects[] = "Complete a spicy card to clear this curse";
    }
    
    return ['effects' => $effects, 'is_instant' => $isInstant, 'timer_id' => $timerId];
}

function addActiveCurseEffect($gameId, $playerId, $cardId, $card, $slotNumber = null, $timerId = null) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        $expiresAt = null;
        if ($card['timer']) {
            $timezone = new DateTimeZone('America/Indiana/Indianapolis');
            $expiresAt = (new DateTime('now', $timezone))
                ->add(new DateInterval('PT' . $card['timer'] . 'M'))
                ->format('Y-m-d H:i:s');
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO active_curse_effects (game_id, player_id, card_id, expires_at, slot_number, timer_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$gameId, $playerId, $cardId, $expiresAt, $slotNumber, $timerId]);
        
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
            INSERT INTO active_power_effects (game_id, player_id, power_card_id, expires_at, power_type)
            VALUES (?, ?, ?, ?, 'power')
        ");
        $stmt->execute([$gameId, $playerId, $cardId, $expiresAt]);
        
        return $pdo->lastInsertId();
        
    } catch (Exception $e) {
        error_log("Error adding active power effect: " . $e->getMessage());
        return false;
    }
}

function skipChallenge($gameId, $playerId, $slotNumber) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        $pdo->beginTransaction();
        
        // Check if player has skip power active
        $stmt = $pdo->prepare("
            SELECT ape.*, c.card_name
            FROM active_power_effects ape
            JOIN cards c ON ape.power_card_id = c.id
            WHERE ape.game_id = ? AND ape.player_id = ? AND c.skip_challenge = 1
        ");
        $stmt->execute([$gameId, $playerId]);
        $skipPower = $stmt->fetch();
        
        if (!$skipPower) {
            throw new Exception("No skip challenge power active");
        }
        
        // Get card in slot
        $stmt = $pdo->prepare("
            SELECT dds.*, c.*
            FROM daily_deck_slots dds
            JOIN cards c ON dds.card_id = c.id
            WHERE dds.game_id = ? AND dds.player_id = ? AND dds.deck_date = ? AND dds.slot_number = ?
        ");
        $stmt->execute([$gameId, $playerId, $today, $slotNumber]);
        $card = $stmt->fetch();
        
        if (!$card) {
            throw new Exception("No card in this slot");
        }
        
        // Return card to daily deck
        $stmt = $pdo->prepare("
            UPDATE daily_deck_cards ddc
            JOIN daily_decks dd ON ddc.deck_id = dd.id
            SET ddc.is_used = 0
            WHERE dd.game_id = ? AND dd.player_id = ? AND dd.deck_date = ? AND ddc.card_id = ?
        ");
        $stmt->execute([$gameId, $playerId, $today, $card['card_id']]);
        
        // Clear slot
        clearSlot($gameId, $playerId, $slotNumber);
        
        // Remove skip power effect
        $stmt = $pdo->prepare("DELETE FROM active_power_effects WHERE id = ?");
        $stmt->execute([$skipPower['id']]);
        
        $pdo->commit();
        return ['success' => true, 'message' => "Skipped {$card['card_name']} using {$skipPower['card_name']}"];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error skipping challenge: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function getCurseTimers($gameId, $playerId, $opponentId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        
        // Get player curse timer with card name
        $stmt = $pdo->prepare("
            SELECT ace.expires_at, c.card_name
            FROM active_curse_effects ace
            JOIN cards c ON ace.card_id = c.id
            WHERE ace.game_id = ? AND ace.player_id = ? AND ace.expires_at IS NOT NULL AND ace.expires_at > NOW()
            ORDER BY ace.expires_at ASC
            LIMIT 1
        ");
        $stmt->execute([$gameId, $playerId]);
        $playerTimer = $stmt->fetch();
        
        // Get opponent curse timer with card name
        $stmt = $pdo->prepare("
            SELECT ace.expires_at, c.card_name
            FROM active_curse_effects ace
            JOIN cards c ON ace.card_id = c.id
            WHERE ace.game_id = ? AND ace.player_id = ? AND ace.expires_at IS NOT NULL AND ace.expires_at > NOW()
            ORDER BY ace.expires_at ASC
            LIMIT 1
        ");
        $stmt->execute([$gameId, $opponentId]);
        $opponentTimer = $stmt->fetch();
        
        // Convert to UTC for JavaScript
        if ($playerTimer && $playerTimer['expires_at']) {
            $expires = new DateTime($playerTimer['expires_at'], $timezone);
            $expires->setTimezone(new DateTimeZone('UTC'));
            $playerTimer['expires_at'] = $expires->format('Y-m-d\TH:i:s.000\Z');
        }
        
        if ($opponentTimer && $opponentTimer['expires_at']) {
            $expires = new DateTime($opponentTimer['expires_at'], $timezone);
            $expires->setTimezone(new DateTimeZone('UTC'));
            $opponentTimer['expires_at'] = $expires->format('Y-m-d\TH:i:s.000\Z');
        }
        
        return [
            'success' => true,
            'player_timer' => $playerTimer ?: null,
            'opponent_timer' => $opponentTimer ?: null
        ];
        
    } catch (Exception $e) {
        error_log("Error getting curse timers: " . $e->getMessage());
        return ['success' => false];
    }
}

function getActiveModifiers($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Get active curse modifiers
        $stmt = $pdo->prepare("
            SELECT c.card_name, c.challenge_modify, c.snap_modify, c.spicy_modify, 'curse' as type
            FROM active_curse_effects ace
            JOIN cards c ON ace.card_id = c.id
            WHERE ace.game_id = ? AND ace.player_id = ? 
            AND (c.challenge_modify = 1 OR c.snap_modify = 1 OR c.spicy_modify = 1)
        ");
        $stmt->execute([$gameId, $playerId]);
        $curseModifiers = $stmt->fetchAll();
        
        // Get active power modifiers
        $stmt = $pdo->prepare("
            SELECT c.card_name, c.power_challenge_modify as challenge_modify, 
                c.power_snap_modify as snap_modify, c.power_spicy_modify as spicy_modify, 
                c.skip_challenge, c.bypass_expiration, c.power_veto_modify as veto_modify, 
                c.deck_peek, 'power' as type
            FROM active_power_effects ape
            JOIN cards c ON ape.power_card_id = c.id
            WHERE ape.game_id = ? AND ape.player_id = ? 
            AND (c.power_challenge_modify = 1 OR c.power_snap_modify = 1 OR c.power_spicy_modify = 1 
                OR c.skip_challenge = 1 OR c.bypass_expiration = 1 OR c.power_veto_modify != 'none'
                OR c.deck_peek = 1)
        ");
        $stmt->execute([$gameId, $playerId]);
        $powerModifiers = $stmt->fetchAll();
        
        return [
            'success' => true,
            'modifiers' => array_merge($curseModifiers, $powerModifiers)
        ];
        
    } catch (Exception $e) {
        error_log("Error getting active modifiers: " . $e->getMessage());
        return ['success' => false, 'modifiers' => []];
    }
}

function storeChallengeCard($gameId, $playerId, $slotNumber) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        $pdo->beginTransaction();

        // Check hand space
        $handCount = getPlayerHandCount($gameId, $playerId);
        if ($handCount >= 10) {
            throw new Exception("Hand is full, cannot store challenge.");
        }
        
        // Check if player has bypass expiration power
        $stmt = $pdo->prepare("
            SELECT ape.*, c.card_name
            FROM active_power_effects ape
            JOIN cards c ON ape.power_card_id = c.id
            WHERE ape.game_id = ? AND ape.player_id = ? AND c.bypass_expiration = 1
        ");
        $stmt->execute([$gameId, $playerId]);
        $bypassPower = $stmt->fetch();
        
        if (!$bypassPower) {
            throw new Exception("No bypass expiration power active");
        }
        
        // Get challenge card in slot
        $stmt = $pdo->prepare("
            SELECT dds.*, c.*
            FROM daily_deck_slots dds
            JOIN cards c ON dds.card_id = c.id
            WHERE dds.game_id = ? AND dds.player_id = ? AND dds.deck_date = ? AND dds.slot_number = ? AND c.card_category = 'challenge'
        ");
        $stmt->execute([$gameId, $playerId, $today, $slotNumber]);
        $card = $stmt->fetch();
        
        if (!$card) {
            throw new Exception("No challenge card in this slot");
        }
        
        // Add to player's hand as stored challenge
        $stmt = $pdo->prepare("
            INSERT INTO player_cards (game_id, player_id, card_id, card_type, quantity, card_points)
            VALUES (?, ?, ?, 'stored_challenge', 1, ?)
        ");
        $stmt->execute([$gameId, $playerId, $card['card_id'], $card['card_points']]);

        // Track as completed
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO completed_cards (game_id, player_id, card_id, card_type)
            VALUES (?, ?, ?, 'challenge')
        ");
        $stmt->execute([$gameId, $playerId, $card['card_id']]);
        
        // Clear slot
        clearSlot($gameId, $playerId, $slotNumber);
        
        // Remove bypass power
        $stmt = $pdo->prepare("DELETE FROM active_power_effects WHERE id = ?");
        $stmt->execute([$bypassPower['id']]);
        
        $pdo->commit();
        return ['success' => true, 'message' => "Stored {$card['card_name']} for later"];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error storing challenge: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function peekDailyDeck($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        // Check if player has deck peek power
        $stmt = $pdo->prepare("
            SELECT ape.*, c.card_name
            FROM active_power_effects ape
            JOIN cards c ON ape.power_card_id = c.id
            WHERE ape.game_id = ? AND ape.player_id = ? AND c.deck_peek = 1
        ");
        $stmt->execute([$gameId, $playerId]);
        $peekPower = $stmt->fetch();
        
        if (!$peekPower) {
            return ['success' => false, 'message' => 'No deck peek power active'];
        }
        
        // Get next 3 cards from daily deck
        $stmt = $pdo->prepare("
            SELECT c.*, ddc.id as deck_card_id
            FROM daily_deck_cards ddc
            JOIN daily_decks dd ON ddc.deck_id = dd.id
            JOIN cards c ON ddc.card_id = c.id
            WHERE dd.game_id = ? AND dd.player_id = ? AND dd.deck_date = ? 
            AND ddc.is_used = 0 AND dd.id NOT IN (
                SELECT DISTINCT deck_id FROM daily_deck_slots dds 
                WHERE dds.game_id = ? AND dds.player_id = ? AND dds.deck_date = ? AND dds.card_id IS NOT NULL
            )
            ORDER BY RAND()
            LIMIT 3
        ");
        $stmt->execute([$gameId, $playerId, $today, $gameId, $playerId, $today]);
        $cards = $stmt->fetchAll();
        
        // Remove deck peek power after use
        $stmt = $pdo->prepare("DELETE FROM active_power_effects WHERE id = ?");
        $stmt->execute([$peekPower['id']]);
        
        return ['success' => true, 'cards' => $cards, 'power_name' => $peekPower['card_name']];
        
    } catch (Exception $e) {
        error_log("Error peeking deck: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function cleanupOrphanedCurseSlots($gameId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        // Get all players in this game
        $stmt = $pdo->prepare("SELECT id FROM players WHERE game_id = ?");
        $stmt->execute([$gameId]);
        $playerIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($playerIds as $playerId) {
            // Get slots with curse cards that are activated
            $stmt = $pdo->prepare("
                SELECT dds.slot_number, dds.card_id
                FROM daily_deck_slots dds
                JOIN cards c ON dds.card_id = c.id
                WHERE dds.game_id = ? AND dds.player_id = ? AND dds.deck_date = ?
                AND c.card_category = 'curse' AND dds.curse_activated = TRUE
            ");
            $stmt->execute([$gameId, $playerId, $today]);
            $curseSlots = $stmt->fetchAll();
            
            foreach ($curseSlots as $slot) {
                // Check if there's an active curse effect for this slot
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM active_curse_effects 
                    WHERE game_id = ? AND player_id = ? AND slot_number = ?
                ");
                $stmt->execute([$gameId, $playerId, $slot['slot_number']]);
                $hasEffect = $stmt->fetchColumn();
                
                // If no active effect, clear the slot
                if (!$hasEffect) {
                    error_log("Cleaning orphaned curse slot {$slot['slot_number']} for player {$playerId}");
                    completeCurseSlot($gameId, $playerId, $slot['slot_number']);
                }
            }
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("Error cleaning up orphaned curse slots: " . $e->getMessage());
        return false;
    }
}
?>