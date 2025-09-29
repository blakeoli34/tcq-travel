<?php

function generateDailyDeck($gameId, $playerId) {
    clearExpiredDailyDecks($gameId);

    // PREVENT INFINITE GENERATION
    $cacheKey = "deck_gen_{$gameId}_{$playerId}_" . date('Y-m-d');
    $lockFile = sys_get_temp_dir() . "/$cacheKey.lock";
    
    if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 60) {
        return ['success' => false, 'message' => 'Deck generation in progress'];
    }
    
    touch($lockFile);

    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
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
        
        // Calculate cards needed for today's deck
        $cardsPerDay = calculateCardsPerDay($gameId, $playerId, $daysRemaining);
        
        // Get used cards for this specific player
        $usedCardIds = getUsedCardIds($gameId, $playerId);
        
        // Select cards for this player - pass playerId to avoid conflicts
        $challengeCards = selectRandomCardsForPlayer($gameId, $playerId, 'challenge', $cardsPerDay['challenge'], $usedCardIds);
        $curseCards = selectRandomCardsForPlayer($gameId, $playerId, 'curse', $cardsPerDay['curse'], $usedCardIds);
        $powerCards = selectRandomCardsForPlayer($gameId, $playerId, 'power', $cardsPerDay['power'], $usedCardIds);

        // Select ONE battle card for the entire game today
        $stmt = $pdo->prepare("
            SELECT id FROM daily_battle_card 
            WHERE game_id = ? AND battle_date = ?
        ");
        $stmt->execute([$gameId, $today]);
        $battleCardId = $stmt->fetchColumn();

        if (!$battleCardId) {
            // No battle card selected yet, choose one for today
            $battleCards = selectRandomCardsForPlayer($gameId, $playerId, 'battle', 1, $usedCardIds);
            if (!empty($battleCards)) {
                $battleCardId = $battleCards[0]['id'];
                
                // Store this as the battle card for today for this game
                $stmt = $pdo->prepare("
                    INSERT INTO daily_battle_card (game_id, battle_date, card_id)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$gameId, $today, $battleCardId]);
            }
            $battleCards = selectRandomCardsForPlayer($gameId, $playerId, 'battle', 1, $usedCardIds);
        } else {
            // Use the existing battle card
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
        unlink($lockFile);
        return ['success' => true, 'deck_id' => $deckId, 'cards_generated' => $totalCards];
        
    } catch (Exception $e) {
        unlink($lockFile);
        $pdo->rollBack();
        error_log("Error generating daily deck: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to generate daily deck'];
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
                c.veto_subtract, c.veto_steal, c.veto_wait, c.veto_snap, c.veto_spicy, c.timer
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
            WHERE dd.game_id = ? AND dd.deck_date = ? AND ddc.is_used = 0
        ");
        $stmt->execute([$gameId, $today]);
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
            ORDER BY RAND()
            LIMIT 1
        ");
        $stmt->execute([$gameId, $playerId, $today]);
        $card = $stmt->fetch();
        
        if (!$card) {
            throw new Exception("No cards remaining in deck");
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
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $waitUntil = new DateTime('now', $timezone);
        $waitUntil->add(new DateInterval('PT' . $minutes . 'M'));
        
        $stmt = $pdo->prepare("
            UPDATE players 
            SET veto_wait_until = ?
            WHERE game_id = ? AND id = ?
        ");
        $stmt->execute([$waitUntil->format('Y-m-d H:i:s'), $gameId, $playerId]);
        
        // Debug logging
        error_log("Applied veto wait for player {$playerId}: until " . $waitUntil->format('Y-m-d H:i:s'));
        
        return true;
    } catch (Exception $e) {
        error_log("Error applying veto wait: " . $e->getMessage());
        return false;
    }
}

function clearExpiredDailyDecks($gameId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        // Clear slots from previous days
        $stmt = $pdo->prepare("
            UPDATE daily_deck_slots 
            SET card_id = NULL, drawn_at = NULL, completed_at = NULL, completed_by_player_id = NULL
            WHERE game_id = ? AND deck_date < ?
        ");
        $stmt->execute([$gameId, $today]);
        
        // Mark all previous day deck cards as unused
        $stmt = $pdo->prepare("
            UPDATE daily_deck_cards ddc
            JOIN daily_decks dd ON ddc.deck_id = dd.id
            SET ddc.is_used = 0
            WHERE dd.game_id = ? AND dd.deck_date < ?
        ");
        $stmt->execute([$gameId, $today]);
        
        return true;
        
    } catch (Exception $e) {
        error_log("Error clearing expired daily decks: " . $e->getMessage());
        return false;
    }
}
?>