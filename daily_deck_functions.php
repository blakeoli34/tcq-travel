<?php

function generateDailyDeck($gameId, $playerId) {
    clearExpiredDailyDecks($gameId);
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        // Check downtime - prevent generation between midnight and 8am
        $now = new DateTime('now', $timezone);
        $hours = (int)$now->format('H');

        // Skip downtime check if testing mode
        $stmt = $pdo->prepare("SELECT testing_mode FROM games WHERE id = ?");
        $stmt->execute([$gameId]);
        $testingMode = (bool)$stmt->fetchColumn();

        if (!$testingMode && $hours >= 0 && $hours < 8) {
            return ['success' => false, 'message' => 'Cannot generate deck during downtime'];
        }
        
        // Use database lock instead of file lock
        $lockKey = "deck_gen_{$gameId}_{$playerId}_{$today}";
        $stmt = $pdo->prepare("SELECT GET_LOCK(?, 10) as got_lock");
        $stmt->execute([$lockKey]);
        $lock = $stmt->fetch();
        
        if (!$lock['got_lock']) {
            return ['success' => false, 'message' => 'Deck generation in progress'];
        }
        
        try {
            // Check if daily deck already exists for this player today
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_decks WHERE game_id = ? AND player_id = ? AND deck_date = ?");
            $stmt->execute([$gameId, $playerId, $today]);
            if ($stmt->fetchColumn() > 0) {
                return ['success' => true, 'message' => 'Daily deck already exists'];
            }
            
            // Get game end date to calculate days remaining
            $stmt = $pdo->prepare("SELECT end_date FROM games WHERE id = ?");
            $stmt->execute([$gameId]);
            $endDate = new DateTime($stmt->fetchColumn(), $timezone);
            $daysRemaining = (new DateTime('now', $timezone))->diff($endDate)->days + 1;
            
            if ($daysRemaining <= 0) {
                return ['success' => false, 'message' => 'Game has ended'];
            }

            $pdo->beginTransaction();
            
            // Get available cards (excluding used/completed cards)
            $availableCards = getAvailableCardsForDeck($gameId, $playerId);
            
            // Calculate cards per day (round down)
            $challengePerDay = floor($availableCards['challenge_count'] / $daysRemaining);
            $cursePerDay = floor($availableCards['curse_count'] / $daysRemaining);
            $powerPerDay = floor($availableCards['power_count'] / $daysRemaining);
            
            // Ensure at least 1 of each if available
            $challengePerDay = max(1, $challengePerDay);
            $cursePerDay = max(1, $cursePerDay);
            $powerPerDay = max(1, $powerPerDay);
            
            // Select cards for this player
            $challengeCards = selectRandomCardsForPlayer($gameId, $playerId, 'challenge', $challengePerDay, $availableCards['used_card_ids']);
            $curseCards = selectRandomCardsForPlayer($gameId, $playerId, 'curse', $cursePerDay, $availableCards['used_card_ids']);
            $powerCards = selectRandomCardsForPlayer($gameId, $playerId, 'power', $powerPerDay, $availableCards['used_card_ids']);

            // Get or create battle card for today
            $stmt = $pdo->prepare("
                SELECT card_id FROM daily_battle_card 
                WHERE game_id = ? AND battle_date = ? AND drawn_by_player_id IS NULL
            ");
            $stmt->execute([$gameId, $today]);
            $battleCardId = $stmt->fetchColumn();

            if (!$battleCardId) {
                // No battle card selected yet, choose one for today
                $battleCards = selectRandomCardsForPlayer($gameId, $playerId, 'battle', 1, []);
                if (!empty($battleCards)) {
                    $battleCardId = $battleCards[0]['id'];
                    
                    // Store this as the battle card for today for this game
                    $stmt = $pdo->prepare("
                        INSERT INTO daily_battle_card (game_id, battle_date, card_id)
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([$gameId, $today, $battleCardId]);
                }
            } else {
                // Use the existing battle card (still available)
                $stmt = $pdo->prepare("SELECT * FROM cards WHERE id = ?");
                $stmt->execute([$battleCardId]);
                $battleCards = [$stmt->fetch()];
            }

            $stmt = $pdo->prepare("SELECT start_date FROM games WHERE id = ?");
            $stmt->execute([$gameId]);
            $gameStartDate = $stmt->fetchColumn();
            
            // Insert daily deck record for this player
            $stmt = $pdo->prepare("INSERT INTO daily_decks (game_id, player_id, deck_date, game_date, total_cards) VALUES (?, ?, ?, ?, ?)");
            $totalCards = count($challengeCards) + count($curseCards) + count($powerCards) + count($battleCards);
            $stmt->execute([$gameId, $playerId, $today, $gameStartDate, $totalCards]);
            $deckId = $pdo->lastInsertId();
            
            // Insert deck slots for this player
            for ($slot = 1; $slot <= 3; $slot++) {
                $stmt = $pdo->prepare("INSERT INTO daily_deck_slots (game_id, player_id, deck_date, game_date, slot_number) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$gameId, $playerId, $today, $gameStartDate, $slot]);
            }
            
            // Store remaining cards for this player
            $allCards = array_merge($challengeCards, $curseCards, $powerCards, $battleCards);
            shuffle($allCards);
            
            foreach ($allCards as $card) {
                $stmt = $pdo->prepare("INSERT INTO daily_deck_cards (deck_id, player_id, card_id, is_used) VALUES (?, ?, ?, 0)");
                $stmt->execute([$deckId, $playerId, $card['id']]);
            }
            
            $pdo->commit();
            return ['success' => true, 'deck_id' => $deckId, 'cards_generated' => $totalCards];
            
        } finally {
            // Always release lock
            $stmt = $pdo->prepare("SELECT RELEASE_LOCK(?)");
            $stmt->execute([$lockKey]);
        }
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error generating daily deck: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to generate daily deck'];
    }
}

function generateDailyDeckWithLogging($gameId, $playerId) {
    error_log("=== DAILY DECK GENERATION START ===");
    error_log("Game ID: $gameId, Player ID: $playerId");
    
    $available = getAvailableCardsForDeck($gameId, $playerId);
    
    error_log("Available cards (with quantities):");
    error_log("  Challenge: {$available['challenge_count']}");
    error_log("  Curse: {$available['curse_count']}");
    error_log("  Power: {$available['power_count']}");
    error_log("  Excluded card IDs: " . implode(', ', $available['used_card_ids']));
    
    $pdo = Config::getDatabaseConnection();
    $timezone = new DateTimeZone('America/Indiana/Indianapolis');
    
    $stmt = $pdo->prepare("SELECT end_date FROM games WHERE id = ?");
    $stmt->execute([$gameId]);
    $endDate = new DateTime($stmt->fetchColumn(), $timezone);
    $daysRemaining = (new DateTime('now', $timezone))->diff($endDate)->days + 1;
    
    error_log("Days remaining: $daysRemaining");
    
    $challengePerDay = floor($available['challenge_count'] / $daysRemaining);
    $cursePerDay = floor($available['curse_count'] / $daysRemaining);
    $powerPerDay = floor($available['power_count'] / $daysRemaining);
    
    error_log("Cards per day (calculated):");
    error_log("  Challenge: $challengePerDay");
    error_log("  Curse: $cursePerDay");
    error_log("  Power: $powerPerDay");
    
    $result = generateDailyDeck($gameId, $playerId);
    
    if ($result['success']) {
        error_log("Cards actually added to deck:");
        error_log("  Total cards: {$result['cards_generated']}");
        
        // Log breakdown by category
        $stmt = $pdo->prepare("
            SELECT c.card_category, COUNT(*) as count
            FROM daily_deck_cards ddc
            JOIN daily_decks dd ON ddc.deck_id = dd.id
            JOIN cards c ON ddc.card_id = c.id
            WHERE dd.id = ?
            GROUP BY c.card_category
        ");
        $stmt->execute([$result['deck_id']]);
        $breakdown = $stmt->fetchAll();
        
        foreach ($breakdown as $cat) {
            error_log("    {$cat['card_category']}: {$cat['count']}");
        }
    }
    
    error_log("Generation result: " . ($result['success'] ? 'SUCCESS' : 'FAILED'));
    error_log("=== DAILY DECK GENERATION END ===");
    
    return $result;
}

function getAvailableCardsForDeck($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        $usedCardIds = [];

        // Get completed cards for this player
        $stmt = $pdo->prepare("
            SELECT DISTINCT card_id 
            FROM completed_cards 
            WHERE game_id = ? AND player_id = ?
        ");
        $stmt->execute([$gameId, $playerId]);
        $usedCardIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Add power cards in hand
        $stmt = $pdo->prepare("
            SELECT DISTINCT card_id
            FROM player_cards
            WHERE game_id = ? AND player_id = ? AND card_type = 'power'
        ");
        $stmt->execute([$gameId, $playerId]);
        $usedCardIds = array_merge($usedCardIds, $stmt->fetchAll(PDO::FETCH_COLUMN));
        
        // Remove duplicates
        $usedCardIds = array_unique($usedCardIds);
        
        // Get counts of available cards by category
        $excludeClause = "";
        if (!empty($usedCardIds)) {
            $placeholders = str_repeat('?,', count($usedCardIds) - 1) . '?';
            $excludeClause = "AND c.id NOT IN ($placeholders)";
        }
        
        $counts = [];
        foreach (['challenge', 'curse', 'power'] as $category) {
            $sql = "
                SELECT SUM(c.quantity)
                FROM cards c
                JOIN card_travel_modes ctm ON c.id = ctm.card_id
                JOIN games g ON g.travel_mode_id = ctm.mode_id
                WHERE c.card_category = ? AND g.id = ? $excludeClause
            ";
            
            $params = array_merge([$category, $gameId], $usedCardIds);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $counts[$category . '_count'] = $stmt->fetchColumn();
        }
        
        $counts['used_card_ids'] = $usedCardIds;
        
        return $counts;
        
    } catch (Exception $e) {
        error_log("Error getting available cards: " . $e->getMessage());
        return [
            'challenge_count' => 0,
            'curse_count' => 0,
            'power_count' => 0,
            'used_card_ids' => []
        ];
    }
}

function clearDailyDeck($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        $pdo->beginTransaction();
        
        // Get deck ID
        $stmt = $pdo->prepare("SELECT id FROM daily_decks WHERE game_id = ? AND player_id = ? AND deck_date = ?");
        $stmt->execute([$gameId, $playerId, $today]);
        $deckId = $stmt->fetchColumn();
        
        if ($deckId) {
            // Clear deck cards
            $stmt = $pdo->prepare("DELETE FROM daily_deck_cards WHERE deck_id = ?");
            $stmt->execute([$deckId]);
            
            // Clear deck slots
            $stmt = $pdo->prepare("DELETE FROM daily_deck_slots WHERE game_id = ? AND player_id = ? AND deck_date = ?");
            $stmt->execute([$gameId, $playerId, $today]);
            
            // Clear deck
            $stmt = $pdo->prepare("DELETE FROM daily_decks WHERE id = ?");
            $stmt->execute([$deckId]);
        }
        
        // If this player drew the battle card, clear that too
        $stmt = $pdo->prepare("DELETE FROM daily_battle_card WHERE game_id = ? AND battle_date = ? AND drawn_by_player_id = ?");
        $stmt->execute([$gameId, $today, $playerId]);
        
        $pdo->commit();
        return ['success' => true];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error clearing daily deck: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function selectRandomCardsForPlayer($gameId, $playerId, $category, $count, $excludeIds = []) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        $excludeClause = "";
        $params = [$category, $gameId];
        
        if (!empty($excludeIds)) {
            $placeholders = str_repeat('?,', count($excludeIds) - 1) . '?';
            $excludeClause = "AND c.id NOT IN ($placeholders)";
            $params = array_merge($params, $excludeIds);
        }
        
        $sql = "
            SELECT c.* FROM cards c
            JOIN card_travel_modes ctm ON c.id = ctm.card_id
            JOIN games g ON g.travel_mode_id = ctm.mode_id
            WHERE c.card_category = ? AND g.id = ? $excludeClause
            ORDER BY RAND() 
            LIMIT ?
        ";
        
        $stmt = $pdo->prepare($sql);
        $params[] = $count;
        $stmt->execute($params);
        
        $result = $stmt->fetchAll();
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Error selecting random cards for player: " . $e->getMessage());
        return [];
    }
}

function calculateCardsPerDay($gameId, $playerId, $daysRemaining) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Get total available cards by category
        $stmt = $pdo->prepare("
            SELECT card_category, SUM(quantity) as total 
            FROM cards 
            WHERE card_category IN ('challenge', 'curse', 'power') 
            GROUP BY card_category
        ");
        $stmt->execute();
        $totals = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Get already used cards by category
        $stmt = $pdo->prepare("
            SELECT c.card_category, COUNT(*) as used
            FROM daily_deck_cards ddc
            JOIN daily_decks dd ON ddc.deck_id = dd.id
            JOIN cards c ON ddc.card_id = c.id
            WHERE dd.game_id = ? AND dd.player_id = ? AND c.card_category IN ('challenge', 'curse', 'power')
            GROUP BY c.card_category
        ");
        $stmt->execute([$gameId, $playerId]);
        $used = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $remaining = [
            'challenge' => ($totals['challenge'] ?? 0) - ($used['challenge'] ?? 0),
            'curse' => ($totals['curse'] ?? 0) - ($used['curse'] ?? 0),
            'power' => ($totals['power'] ?? 0) - ($used['power'] ?? 0)
        ];
        
        // Distribute cards across remaining days
        return [
            'challenge' => max(1, floor($remaining['challenge'] / $daysRemaining)),
            'curse' => max(1, floor($remaining['curse'] / $daysRemaining)),
            'power' => max(1, floor($remaining['power'] / $daysRemaining))
        ];
        
    } catch (Exception $e) {
        error_log("Error calculating cards per day: " . $e->getMessage());
        return ['challenge' => 2, 'curse' => 2, 'power' => 1]; // Default fallback
    }
}

function getUsedCardIds($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("
            SELECT DISTINCT ddc.card_id
            FROM daily_deck_cards ddc
            JOIN daily_decks dd ON ddc.deck_id = dd.id
            WHERE dd.game_id = ? AND dd.player_id = ?
        ");
        $stmt->execute([$gameId, $playerId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return [];
    }
}

function getDailyDeckStatus($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        // Get deck info for this specific player
        $stmt = $pdo->prepare("SELECT * FROM daily_decks WHERE game_id = ? AND player_id = ? AND deck_date = ?");
        $stmt->execute([$gameId, $playerId, $today]);
        $deck = $stmt->fetch();
        
        if (!$deck) {
            return ['success' => false, 'message' => 'No deck for today'];
        }
        
        // Get slot status
        $stmt = $pdo->prepare("
            SELECT dds.*, c.card_name, c.card_category, c.card_description, c.card_points,
                c.veto_subtract, c.veto_steal, c.veto_wait, c.veto_snap, c.veto_spicy, c.timer,
                c.roll_dice, c.dice_condition, c.dice_threshold, c.challenge_modify, c.score_modify,
                c.veto_modify, c.snap_modify, c.spicy_modify, c.wait, c.timer_completion_type,
                c.complete_snap, c.complete_spicy, c.repeat_count, c.score_add, c.score_subtract, c.score_steal
            FROM daily_deck_slots dds
            LEFT JOIN cards c ON dds.card_id = c.id
            WHERE dds.game_id = ? AND dds.player_id = ? AND dds.deck_date = ?
            ORDER BY dds.slot_number
        ");
        $stmt->execute([$gameId, $playerId, $today]);
        $slots = $stmt->fetchAll();
        
        // Get remaining cards count
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM daily_deck_cards ddc
            JOIN daily_decks dd ON ddc.deck_id = dd.id
            WHERE dd.game_id = ? AND dd.player_id = ? AND dd.deck_date = ? AND ddc.is_used = 0
        ");
        $stmt->execute([$gameId, $playerId, $today]);
        $remainingCards = $stmt->fetchColumn();

        return [
            'success' => true,
            'deck' => $deck,
            'slots' => $slots,
            'remaining_cards' => $remainingCards
        ];
        
    } catch (Exception $e) {
        error_log("Error getting daily deck status: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to get deck status'];
    }
}

function isDeckEmpty($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        // Check if all slots are empty
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM daily_deck_slots 
            WHERE game_id = ? AND player_id = ? AND deck_date = ? AND card_id IS NOT NULL
        ");
        $stmt->execute([$gameId, $playerId, $today]);
        $filledSlots = $stmt->fetchColumn();
        
        // Check if deck has remaining cards
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM daily_deck_cards ddc
            JOIN daily_decks dd ON ddc.deck_id = dd.id
            WHERE dd.game_id = ? AND dd.player_id = ? AND dd.deck_date = ? AND ddc.is_used = 0
        ");
        $stmt->execute([$gameId, $playerId, $today]);
        $remainingCards = $stmt->fetchColumn();
        
        return $filledSlots === 0 && $remainingCards === 0;
    } catch (Exception $e) {
        return false;
    }
}

function drawCardToSlot($gameId, $playerId, $slotNumber) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        $pdo->beginTransaction();
        
        // Check if slot is empty for this player
        $stmt = $pdo->prepare("
            SELECT card_id FROM daily_deck_slots 
            WHERE game_id = ? AND player_id = ? AND deck_date = ? AND slot_number = ?
        ");
        $stmt->execute([$gameId, $playerId, $today, $slotNumber]);
        if ($stmt->fetchColumn()) {
            throw new Exception("Slot is already occupied");
        }
        
        // Get next available card for THIS PLAYER
        $stmt = $pdo->prepare("
            SELECT ddc.card_id, c.*
            FROM daily_deck_cards ddc
            JOIN daily_decks dd ON ddc.deck_id = dd.id
            JOIN cards c ON ddc.card_id = c.id
            WHERE dd.game_id = ? AND dd.player_id = ? AND dd.deck_date = ? AND ddc.is_used = 0
            ORDER BY ddc.id
            LIMIT 1
        ");
        $stmt->execute([$gameId, $playerId, $today]);
        $card = $stmt->fetch();
        
        if (!$card) {
            throw new Exception("No cards remaining in deck");
        }

        // If this is a battle card, check if opponent already drew it
        if ($card['card_category'] === 'battle') {
            $stmt = $pdo->prepare("
                SELECT drawn_by_player_id FROM daily_battle_card 
                WHERE game_id = ? AND battle_date = ? AND card_id = ?
            ");
            $stmt->execute([$gameId, $today, $card['card_id']]);
            $drawnBy = $stmt->fetchColumn();
            
            if ($drawnBy && $drawnBy != $playerId) {
                throw new Exception("Battle card already drawn by opponent");
            }
        }
        
        // Assign card to slot
        $stmt = $pdo->prepare("
            UPDATE daily_deck_slots 
            SET card_id = ?, drawn_at = NOW() 
            WHERE game_id = ? AND player_id = ? AND deck_date = ? AND slot_number = ?
        ");
        $stmt->execute([$card['card_id'], $gameId, $playerId, $today, $slotNumber]);

        // If this is a battle card, mark it as drawn and remove from other player's deck
        if ($card['card_category'] === 'battle') {
            $stmt = $pdo->prepare("
                UPDATE daily_battle_card 
                SET drawn_by_player_id = ?
                WHERE game_id = ? AND battle_date = ? AND card_id = ?
            ");
            $stmt->execute([$playerId, $gameId, $today, $card['card_id']]);
            
            // Remove from opponent's deck
            $opponentId = getOpponentPlayerId($gameId, $playerId);
            $stmt = $pdo->prepare("
                UPDATE daily_deck_cards ddc
                JOIN daily_decks dd ON ddc.deck_id = dd.id
                SET ddc.is_used = 1
                WHERE dd.game_id = ? AND dd.player_id = ? AND dd.deck_date = ? 
                AND ddc.card_id = ? AND ddc.is_used = 0
            ");
            $stmt->execute([$gameId, $opponentId, $today, $card['card_id']]);

            // Send push notification to opponent
            $stmt = $pdo->prepare("SELECT first_name FROM players WHERE id = ?");
            $stmt->execute([$playerId]);
            $playerName = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("SELECT fcm_token FROM players WHERE id = ?");
            $stmt->execute([$opponentId]);
            $opponentToken = $stmt->fetchColumn();
            
            if ($opponentToken && $playerName) {
                sendPushNotification(
                    $opponentToken,
                    'Battle Card Drawn!',
                    $playerName . ' drew today\'s Battle card! Prepare for battle and go tell them you are ready.'
                );
            }
        }
        
        // Mark card as used for THIS PLAYER
        $stmt = $pdo->prepare("
            UPDATE daily_deck_cards ddc
            JOIN daily_decks dd ON ddc.deck_id = dd.id
            SET ddc.is_used = 1
            WHERE dd.game_id = ? AND dd.player_id = ? AND dd.deck_date = ? AND ddc.card_id = ?
        ");
        $stmt->execute([$gameId, $playerId, $today, $card['card_id']]);
        
        $pdo->commit();
        return ['success' => true, 'card' => $card];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error drawing card to slot: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function clearSlot($gameId, $playerId, $slotNumber) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        $stmt = $pdo->prepare("
            UPDATE daily_deck_slots 
            SET card_id = NULL, drawn_at = NULL, completed_at = NULL, completed_by_player_id = NULL
            WHERE game_id = ? AND player_id = ? AND deck_date = ? AND slot_number = ?
        ");
        $stmt->execute([$gameId, $playerId, $today, $slotNumber]);
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Error clearing slot: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to clear slot'];
    }
}

function completeClearSlot($gameId, $playerId, $slotNumber) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        $stmt = $pdo->prepare("
            UPDATE daily_deck_slots 
            SET card_id = NULL, drawn_at = NULL, completed_at = NOW(), completed_by_player_id = ?
            WHERE game_id = ? AND player_id = ? AND deck_date = ? AND slot_number = ?
        ");
        $stmt->execute([$playerId, $gameId, $playerId, $today, $slotNumber]);
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Error clearing slot: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to clear slot'];
    }
}

function isPlayerWaitingVeto($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $now = new DateTime('now', $timezone);
        
        $stmt = $pdo->prepare("
            SELECT veto_wait_until FROM players 
            WHERE game_id = ? AND id = ? AND veto_wait_until > ?
        ");
        $stmt->execute([$gameId, $playerId, $now->format('Y-m-d H:i:s')]);
        return $stmt->fetchColumn() !== false;
    } catch (Exception $e) {
        return false;
    }
}

function applyVetoWait($gameId, $playerId, $minutes) {
    try {
        $pdo = Config::getDatabaseConnection();

        if($minutes === 0) {
            $stmt = $pdo->prepare("
                UPDATE players 
                SET veto_wait_until = ?
                WHERE game_id = ? AND id = ?
            ");
            $stmt->execute([NULL, $gameId, $playerId]);

            return true;
        }
        
        // Check testing mode
        $stmt = $pdo->prepare("SELECT testing_mode FROM games WHERE id = ?");
        $stmt->execute([$gameId]);
        $testingMode = (bool)$stmt->fetchColumn();
        
        // Override to 5 minutes in testing mode
        if ($testingMode && $minutes > 5) {
            $minutes = 5;
        }
        
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        
        // Check for existing veto wait
        $stmt = $pdo->prepare("SELECT veto_wait_until FROM players WHERE game_id = ? AND id = ?");
        $stmt->execute([$gameId, $playerId]);
        $existingWait = $stmt->fetchColumn();
        
        if ($existingWait) {
            // Add to existing wait time
            $waitUntil = new DateTime($existingWait, $timezone);
            $now = new DateTime('now', $timezone);
            
            // Only add if existing wait is still in the future
            if ($waitUntil > $now) {
                $waitUntil->add(new DateInterval('PT' . $minutes . 'M'));
            } else {
                // Existing wait expired, start fresh
                $waitUntil = new DateTime('now', $timezone);
                $waitUntil->add(new DateInterval('PT' . $minutes . 'M'));
            }
        } else {
            // No existing wait, start fresh
            $waitUntil = new DateTime('now', $timezone);
            $waitUntil->add(new DateInterval('PT' . $minutes . 'M'));
        }
        
        $stmt = $pdo->prepare("
            UPDATE players 
            SET veto_wait_until = ?
            WHERE game_id = ? AND id = ?
        ");
        $stmt->execute([$waitUntil->format('Y-m-d H:i:s'), $gameId, $playerId]);

        // Schedule notification for when veto wait ends
        $endTimeLocal = clone $waitUntil;
        $endTimeLocal->add(new DateInterval('PT10S'));
        $atTime = $endTimeLocal->format('H:i M j, Y');
        $seconds = $endTimeLocal->format('s');

        $atCommand = "sleep {$seconds} && /usr/bin/php /var/www/travel/cron.php veto_wait_end {$gameId} {$playerId}";
        $atJob = shell_exec("echo '{$atCommand}' | at {$atTime} 2>&1");

        error_log("Scheduled veto wait notification for player {$playerId} at {$atTime} +{$seconds}s - Result: {$atJob}");
        
        error_log("Applied veto wait for player {$playerId}: until " . $waitUntil->format('Y-m-d H:i:s') . " (Indianapolis time)");
        
        return true;
    } catch (Exception $e) {
        error_log("Error applying veto wait: " . $e->getMessage());
        return false;
    }
}

function notifyVetoWaitEnd($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("SELECT fcm_token FROM players WHERE id = ?");
        $stmt->execute([$playerId]);
        $token = $stmt->fetchColumn();
        
        if ($token) {
            sendPushNotification($token, 'Wait Period Over', 'You can now interact with your daily deck!');
        }
    } catch (Exception $e) {
        error_log("Error sending veto wait notification: " . $e->getMessage());
    }
}

function clearExpiredDailyDecks($gameId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        $pdo->beginTransaction();
        
        // Get all players in this game
        $stmt = $pdo->prepare("SELECT id FROM players WHERE game_id = ?");
        $stmt->execute([$gameId]);
        $playerIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($playerIds as $playerId) {            
            // Clear slots from previous days
            $stmt = $pdo->prepare("
                UPDATE daily_deck_slots 
                SET card_id = NULL, drawn_at = NULL, completed_at = NULL, 
                    completed_by_player_id = NULL, curse_activated = FALSE
                WHERE game_id = ? AND player_id = ? AND deck_date < ?
            ");
            $stmt->execute([$gameId, $playerId, $today]);
        }
        
        $pdo->commit();
        return true;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error clearing expired daily decks: " . $e->getMessage());
        return false;
    }
}
?>