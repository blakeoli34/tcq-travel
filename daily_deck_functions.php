<?php

function generateDailyDeck($gameId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        // Check if daily deck already exists for today
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_decks WHERE game_id = ? AND deck_date = ?");
        $stmt->execute([$gameId, $today]);
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
        
        // Calculate cards needed for today's deck
        $cardsPerDay = calculateCardsPerDay($gameId, $daysRemaining);
        
        // Get available cards from master deck (exclude used cards)
        $usedCardIds = getUsedCardIds($gameId);
        $placeholders = str_repeat('?,', count($usedCardIds) - 1) . '?';
        $usedClause = !empty($usedCardIds) ? "AND c.id NOT IN ($placeholders)" : "";
        
        $pdo->beginTransaction();
        
        // Select challenge cards
        $challengeCards = selectRandomCards($gameId, 'challenge', $cardsPerDay['challenge'], $usedCardIds);
        
        // Select curse cards  
        $curseCards = selectRandomCards($gameId, 'curse', $cardsPerDay['curse'], $usedCardIds);
        
        // Select power cards
        $powerCards = selectRandomCards($gameId, 'power', $cardsPerDay['power'], $usedCardIds);
        
        // Select one battle card (required)
        $battleCards = selectRandomCards($gameId, 'battle', 1, $usedCardIds);
        
        // Insert daily deck record
        $stmt = $pdo->prepare("INSERT INTO daily_decks (game_id, deck_date, total_cards) VALUES (?, ?, ?)");
        $totalCards = count($challengeCards) + count($curseCards) + count($powerCards) + count($battleCards);
        $stmt->execute([$gameId, $today, $totalCards]);
        $deckId = $pdo->lastInsertId();
        
        // Insert deck slots (3 slots available)
        $allCards = array_merge($challengeCards, $curseCards, $powerCards, $battleCards);
        shuffle($allCards);
        
        for ($slot = 1; $slot <= 3; $slot++) {
            $stmt = $pdo->prepare("INSERT INTO daily_deck_slots (game_id, deck_date, slot_number) VALUES (?, ?, ?)");
            $stmt->execute([$gameId, $today, $slot]);
        }
        
        // Store remaining cards for future draws
        foreach ($allCards as $card) {
            $stmt = $pdo->prepare("INSERT INTO daily_deck_cards (deck_id, card_id, is_used) VALUES (?, ?, 0)");
            $stmt->execute([$deckId, $card['id']]);
        }
        
        $pdo->commit();
        return ['success' => true, 'deck_id' => $deckId, 'cards_generated' => $totalCards];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error generating daily deck: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to generate daily deck'];
    }
}

function calculateCardsPerDay($gameId, $daysRemaining) {
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
            WHERE dd.game_id = ? AND c.card_category IN ('challenge', 'curse', 'power')
            GROUP BY c.card_category
        ");
        $stmt->execute([$gameId]);
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

function selectRandomCards($gameId, $category, $count, $excludeIds = []) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        $excludeClause = "";
        $params = [$category];
        
        if (!empty($excludeIds)) {
            $placeholders = str_repeat('?,', count($excludeIds) - 1) . '?';
            $excludeClause = "AND id NOT IN ($placeholders)";
            $params = array_merge($params, $excludeIds);
        }
        
        $stmt = $pdo->prepare("
            SELECT * FROM cards 
            WHERE card_category = ? $excludeClause
            ORDER BY RAND() 
            LIMIT ?
        ");
        $params[] = $count;
        $stmt->execute($params);
        
        return $stmt->fetchAll();
        
    } catch (Exception $e) {
        error_log("Error selecting random cards: " . $e->getMessage());
        return [];
    }
}

function getUsedCardIds($gameId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("
            SELECT DISTINCT ddc.card_id
            FROM daily_deck_cards ddc
            JOIN daily_decks dd ON ddc.deck_id = dd.id
            WHERE dd.game_id = ?
        ");
        $stmt->execute([$gameId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return [];
    }
}

function getDailyDeckStatus($gameId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        // Get deck info
        $stmt = $pdo->prepare("SELECT * FROM daily_decks WHERE game_id = ? AND deck_date = ?");
        $stmt->execute([$gameId, $today]);
        $deck = $stmt->fetch();
        
        if (!$deck) {
            return ['success' => false, 'message' => 'No deck for today'];
        }
        
        // Get slot status
        $stmt = $pdo->prepare("
            SELECT dds.*, c.card_name, c.card_category, c.card_description, c.card_points
            FROM daily_deck_slots dds
            LEFT JOIN cards c ON dds.card_id = c.id
            WHERE dds.game_id = ? AND dds.deck_date = ?
            ORDER BY dds.slot_number
        ");
        $stmt->execute([$gameId, $today]);
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

function drawCardToSlot($gameId, $slotNumber) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        $pdo->beginTransaction();
        
        // Check if slot is empty
        $stmt = $pdo->prepare("
            SELECT card_id FROM daily_deck_slots 
            WHERE game_id = ? AND deck_date = ? AND slot_number = ?
        ");
        $stmt->execute([$gameId, $today, $slotNumber]);
        if ($stmt->fetchColumn()) {
            throw new Exception("Slot is already occupied");
        }
        
        // Get next available card
        $stmt = $pdo->prepare("
            SELECT ddc.card_id, c.*
            FROM daily_deck_cards ddc
            JOIN daily_decks dd ON ddc.deck_id = dd.id
            JOIN cards c ON ddc.card_id = c.id
            WHERE dd.game_id = ? AND dd.deck_date = ? AND ddc.is_used = 0
            ORDER BY RAND()
            LIMIT 1
        ");
        $stmt->execute([$gameId, $today]);
        $card = $stmt->fetch();
        
        if (!$card) {
            throw new Exception("No cards remaining in deck");
        }
        
        // Assign card to slot
        $stmt = $pdo->prepare("
            UPDATE daily_deck_slots 
            SET card_id = ?, drawn_at = NOW() 
            WHERE game_id = ? AND deck_date = ? AND slot_number = ?
        ");
        $stmt->execute([$card['card_id'], $gameId, $today, $slotNumber]);
        
        // Mark card as used
        $stmt = $pdo->prepare("
            UPDATE daily_deck_cards ddc
            JOIN daily_decks dd ON ddc.deck_id = dd.id
            SET ddc.is_used = 1
            WHERE dd.game_id = ? AND dd.deck_date = ? AND ddc.card_id = ?
        ");
        $stmt->execute([$gameId, $today, $card['card_id']]);
        
        $pdo->commit();
        return ['success' => true, 'card' => $card];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error drawing card to slot: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function clearSlot($gameId, $slotNumber) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        $stmt = $pdo->prepare("
            UPDATE daily_deck_slots 
            SET card_id = NULL, drawn_at = NULL, completed_at = NULL, completed_by = NULL
            WHERE game_id = ? AND deck_date = ? AND slot_number = ?
        ");
        $stmt->execute([$gameId, $today, $slotNumber]);
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Error clearing slot: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to clear slot'];
    }
}

function isPlayerWaitingVeto($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("
            SELECT veto_wait_until FROM players 
            WHERE game_id = ? AND id = ? AND veto_wait_until > NOW()
        ");
        $stmt->execute([$gameId, $playerId]);
        return $stmt->fetchColumn() !== false;
    } catch (Exception $e) {
        return false;
    }
}

function applyVetoWait($gameId, $playerId, $minutes) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("
            UPDATE players 
            SET veto_wait_until = DATE_ADD(NOW(), INTERVAL ? MINUTE)
            WHERE game_id = ? AND id = ?
        ");
        $stmt->execute([$minutes, $gameId, $playerId]);
        return true;
    } catch (Exception $e) {
        error_log("Error applying veto wait: " . $e->getMessage());
        return false;
    }
}
?>