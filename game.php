<?php

require_once 'config.php';
require_once 'functions.php';

$deviceId = $_GET['device_id'] ?? $_COOKIE['device_id'] ?? null;

// If device ID is in URL parameter, set the cookie for future visits
if (isset($_GET['device_id']) && $_GET['device_id']) {
    $deviceId = $_GET['device_id'];
    setcookie('device_id', $deviceId, time() + (365 * 24 * 60 * 60), '/', '', true, true);
    
    // Redirect to clean URL without parameter
    header('Location: game.php');
    exit;
}

if (!$deviceId) {
    header('Location: index.php');
    exit;
}

$player = getPlayerByDeviceId($deviceId);
if (!$player) {
    header('Location: index.php');
    exit;
}

$pdo = Config::getDatabaseConnection();

// Check for testing mode parameter
if (isset($_GET['testing']) && $_GET['testing'] === '1') {
    $stmt = $pdo->prepare("UPDATE games SET testing_mode = 1 WHERE id = ?");
    $stmt->execute([$player['game_id']]);
    header('Location: game.php');
    exit;
}

if (isset($_GET['testing']) && $_GET['testing'] === 'false') {
    $stmt = $pdo->prepare("UPDATE games SET testing_mode = 0 WHERE id = ?");
    $stmt->execute([$player['game_id']]);
    header('Location: game.php');
    exit;
}

// Get testing mode status
$stmt = $pdo->prepare("SELECT testing_mode FROM games WHERE id = ?");
$stmt->execute([$player['game_id']]);
$testingMode = (bool)$stmt->fetchColumn();

// Get fresh game data to check current mode
$stmt = $pdo->prepare("SELECT * FROM games WHERE id = ?");
$stmt->execute([$player['game_id']]);
$gameData = $stmt->fetch();

if ($gameData['status'] === 'active' && $gameData['start_date']) {
    $timezone = new DateTimeZone('America/Indiana/Indianapolis');
    $now = new DateTime('now', $timezone);
    $startDate = new DateTime($gameData['start_date'], $timezone);
    
    if ($now < $startDate) {
        // Reset to waiting
        $stmt = $pdo->prepare("UPDATE games SET status = 'waiting' WHERE id = ?");
        $stmt->execute([$player['game_id']]);
        $gameData['status'] = 'waiting';
        $gameStatus = 'waiting';
    }
}

// Auto-activate games that have reached their start time
if ($gameData['status'] === 'waiting' && $gameData['start_date']) {
    $timezone = new DateTimeZone('America/Indiana/Indianapolis');
    $now = new DateTime('now', $timezone);
    $startDate = new DateTime($gameData['start_date'], $timezone);
    
    if ($now >= $startDate) {
        // Activate the game
        $stmt = $pdo->prepare("UPDATE games SET status = 'active' WHERE id = ?");
        $stmt->execute([$player['game_id']]);
        $gameData['status'] = 'active';
        $gameStatus = 'active';
        
        // Initialize Travel Edition
        initializeTravelEdition($player['game_id']);
    }
}

$players = getGamePlayers($player['game_id']);
$currentPlayer = null;
$opponentPlayer = null;

foreach ($players as $p) {
    if ($p['device_id'] === $deviceId) {
        $currentPlayer = $p;
    } else {
        $opponentPlayer = $p;
    }
}
$gameStatus = $gameData['status'];
$gameMode = $gameData['game_mode'];

// Get travel mode name for body class
$travelModeClass = '';
if ($gameData['travel_mode_id']) {
    $stmt = $pdo->prepare("SELECT mode_title FROM travel_modes WHERE id = ?");
    $stmt->execute([$gameData['travel_mode_id']]);
    $modeTitle = $stmt->fetchColumn();
    if ($modeTitle) {
        $travelModeClass = strtolower(str_replace(' ', '-', $modeTitle));
    }
}

$timezone = new DateTimeZone('America/Indiana/Indianapolis');
$now = new DateTime('now', $timezone);
$endDate = new DateTime($player['end_date'], $timezone);
$timeRemaining = $now < $endDate ? $endDate->diff($now) : null;

$gameTimeText = '';
if ($timeRemaining) {
    $parts = [];
    
    if ($timeRemaining->days > 1) {
        $gameTimeText = $timeRemaining->days . ' Days Remaining';
    } elseif ($timeRemaining->days == 1) {
        $gameTimeText = '1 Day Remaining';
    } else {
        // Last day - show hours and minutes
        $hours = $timeRemaining->h;
        $minutes = $timeRemaining->i;
        $gameTimeText = $hours . 'h ' . $minutes . 'm Remaining';
    }
} else {
    $gameTimeText = 'Game Ended';
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // Get fresh game mode for AJAX calls
    $pdo = Config::getDatabaseConnection();
    $stmt = $pdo->prepare("SELECT game_mode FROM games WHERE id = ?");
    $stmt->execute([$player['game_id']]);
    $gameMode = $stmt->fetchColumn();
    
    switch ($_POST['action']) {
        // New Travel Edition AJAX endpoints
        case 'get_daily_deck':
            // Check if game is actually active first
            if ($gameStatus !== 'active') {
                echo json_encode(['success' => false, 'message' => 'Game not active yet']);
                exit;
            }
            
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            // Debug: Check if deck exists for this player
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_decks WHERE game_id = ? AND player_id = ?");
            $stmt->execute([$player['game_id'], $player['id']]);
            $deckExists = $stmt->fetchColumn();
            
            $deckStatus = getDailyDeckStatus($player['game_id'], $player['id']);
            
            if (!$deckStatus['success']) {
                error_log("Debug: No deck status for player {$player['id']}, attempting to generate");
                $generateResult = generateDailyDeck($player['game_id'], $player['id']);
                error_log("Debug: Generate result: " . json_encode($generateResult));
                
                if ($generateResult['success']) {
                    $deckStatus = getDailyDeckStatus($player['game_id'], $player['id']);
                }
            }
            
            echo json_encode($deckStatus);
            exit;

        case 'generate_daily_deck':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $result = generateDailyDeck($player['game_id'], $player['id']);
            echo json_encode($result);
            exit;

        case 'clear_daily_deck':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $result = clearDailyDeck($player['game_id'], $player['id']);
            echo json_encode($result);
            exit;

        case 'draw_to_slot':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $slotNumber = intval($_POST['slot_number']);
            if ($slotNumber < 1 || $slotNumber > 3) {
                echo json_encode(['success' => false, 'message' => 'Invalid slot number']);
                exit;
            }
            
            $result = drawCardToSlot($player['game_id'], $player['id'], $slotNumber);
            echo json_encode($result);
            exit;

        case 'complete_challenge':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $slotNumber = intval($_POST['slot_number']);
            $result = completeChallenge($player['game_id'], $player['id'], $slotNumber);
            echo json_encode($result);
            exit;

        case 'veto_challenge':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $slotNumber = intval($_POST['slot_number']);
            $result = vetoChallenge($player['game_id'], $player['id'], $slotNumber);
            echo json_encode($result);
            exit;

        case 'complete_stored_challenge':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $playerCardId = intval($_POST['player_card_id']);
            $result = completeStoredChallenge($player['game_id'], $player['id'], $playerCardId);
            echo json_encode($result);
            exit;

        case 'veto_stored_challenge':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $playerCardId = intval($_POST['player_card_id']);
            $result = vetoStoredChallenge($player['game_id'], $player['id'], $playerCardId);
            echo json_encode($result);
            exit;

        case 'complete_battle':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $slotNumber = intval($_POST['slot_number']);
            $isWinner = $_POST['is_winner'] === 'true';
            $result = completeBattle($player['game_id'], $player['id'], $slotNumber, $isWinner);
            echo json_encode($result);
            exit;

        case 'activate_curse':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $slotNumber = intval($_POST['slot_number']);
            $result = activateCurse($player['game_id'], $player['id'], $slotNumber);
            echo json_encode($result);
            exit;

        case 'claim_power':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $slotNumber = intval($_POST['slot_number']);
            $result = claimPower($player['game_id'], $player['id'], $slotNumber);
            echo json_encode($result);
            exit;

        case 'activate_power':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $slotNumber = intval($_POST['slot_number']);
            $result = activatePowerFromSlot($player['game_id'], $player['id'], $slotNumber);
            echo json_encode($result);
            exit;

        case 'discard_power':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $slotNumber = intval($_POST['slot_number']);
            $result = discardPower($player['game_id'], $player['id'], $slotNumber);
            echo json_encode($result);
            exit;

        case 'play_power_card':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $playerCardId = intval($_POST['player_card_id']);
            $result = playPowerCard($player['game_id'], $player['id'], $playerCardId);
            echo json_encode($result);
            exit;

        case 'complete_snap_card':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $playerCardId = intval($_POST['player_card_id']);
            $result = completeSnapCard($player['game_id'], $player['id'], $playerCardId);
            echo json_encode($result);
            exit;

        case 'complete_spicy_card':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $playerCardId = intval($_POST['player_card_id']);
            $result = completeSpicyCard($player['game_id'], $player['id'], $playerCardId);
            echo json_encode($result);
            exit;

        case 'veto_snap_card':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $playerCardId = intval($_POST['player_card_id']);
            $result = vetoSnapSpicyCard($player['game_id'], $player['id'], $playerCardId, 'snap');
            echo json_encode($result);
            exit;

        case 'veto_spicy_card':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $playerCardId = intval($_POST['player_card_id']);
            $result = vetoSnapSpicyCard($player['game_id'], $player['id'], $playerCardId, 'spicy');
            echo json_encode($result);
            exit;

        case 'draw_snap_card':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $result = drawFromSnapDeck($player['game_id'], $player['id']);
            echo json_encode($result);
            exit;

        case 'draw_spicy_card':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $result = drawFromSpicyDeck($player['game_id'], $player['id']);
            echo json_encode($result);
            exit;

        case 'get_player_hand':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $hand = getPlayerHand($player['game_id'], $player['id']);
            echo json_encode(['success' => true, 'hand' => $hand]);
            exit;

        case 'get_deck_counts':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            try {
                $pdo = Config::getDatabaseConnection();
                
                // Get player gender and game travel mode
                $stmt = $pdo->prepare("
                    SELECT p.gender, g.travel_mode_id 
                    FROM players p 
                    JOIN games g ON p.game_id = g.id 
                    WHERE p.id = ?
                ");
                $stmt->execute([$player['id']]);
                $gameInfo = $stmt->fetch();
                
                $genderClause = $gameInfo['gender'] === 'male' ? "AND c.male = 1" : "AND c.female = 1";
                
                // Get total cards available for this travel mode
                $stmt = $pdo->prepare("
                    SELECT SUM(c.quantity) 
                    FROM cards c
                    JOIN card_travel_modes ctm ON c.id = ctm.card_id
                    WHERE c.card_category = 'snap' AND ctm.mode_id = ? $genderClause
                ");
                $stmt->execute([$gameInfo['travel_mode_id']]);
                $totalSnap = $stmt->fetchColumn();
                
                $stmt = $pdo->prepare("
                    SELECT SUM(c.quantity) 
                    FROM cards c
                    JOIN card_travel_modes ctm ON c.id = ctm.card_id
                    WHERE c.card_category = 'spicy' AND ctm.mode_id = ? $genderClause
                ");
                $stmt->execute([$gameInfo['travel_mode_id']]);
                $totalSpicy = $stmt->fetchColumn();
                
                // Get cards already in player's hand
                $stmt = $pdo->prepare("
                    SELECT 
                        SUM(CASE WHEN card_type = 'snap' THEN quantity ELSE 0 END) as snap_in_hand,
                        SUM(CASE WHEN card_type = 'spicy' THEN quantity ELSE 0 END) as spicy_in_hand
                    FROM player_cards 
                    WHERE game_id = ? AND player_id = ? AND card_type IN ('snap', 'spicy')
                ");
                $stmt->execute([$player['game_id'], $player['id']]);
                $inHand = $stmt->fetch();
                
                $stmt = $pdo->prepare("
                    SELECT 
                        snap_cards_completed,
                        spicy_cards_completed
                    FROM player_stats 
                    WHERE game_id = ? AND player_id = ?
                ");
                $stmt->execute([$player['game_id'], $player['id']]);
                $completed = $stmt->fetch();

                $snapRemaining = $totalSnap - ($inHand['snap_in_hand'] ?: 0) - ($completed['snap_cards_completed'] ?: 0);
                $spicyRemaining = $totalSpicy - ($inHand['spicy_in_hand'] ?: 0) - ($completed['spicy_cards_completed'] ?: 0);
                
                echo json_encode([
                    'success' => true,
                    'snap_count' => max(0, $snapRemaining),
                    'spicy_count' => max(0, $spicyRemaining)
                ]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Failed to get deck counts']);
            }
            exit;

        case 'get_daily_deck_count':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            try {
                $pdo = Config::getDatabaseConnection();
                $timezone = new DateTimeZone('America/Indiana/Indianapolis');
                $today = (new DateTime('now', $timezone))->format('Y-m-d');
                
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM daily_deck_cards ddc
                    JOIN daily_decks dd ON ddc.deck_id = dd.id
                    WHERE dd.game_id = ? AND dd.player_id = ? AND dd.deck_date = ? AND ddc.is_used = 0
                ");
                $stmt->execute([$player['game_id'], $player['id'], $today]);
                $remaining = $stmt->fetchColumn();
                
                echo json_encode(['success' => true, 'remaining' => $remaining]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'remaining' => 0]);
            }
            exit;

        case 'get_status_effects':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            // Check if we have an opponent player (game is active)
            if (!$opponentPlayer) {
                echo json_encode([
                    'success' => true,
                    'player_effects' => [],
                    'opponent_effects' => []
                ]);
                exit;
            }
            
            $icons = getStatusEffectIcons($player['game_id'], $player['id']);
            $opponentIcons = getStatusEffectIcons($player['game_id'], $opponentPlayer['id']);
            
            echo json_encode([
                'success' => true,
                'player_effects' => $icons,
                'opponent_effects' => $opponentIcons
            ]);
            exit;

        case 'get_active_effects_details':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            // Default to current player
            $targetPlayerId = $player['id'];
            if (isset($_POST['target']) && $_POST['target'] === 'opponent') {
                $targetPlayerId = $opponentPlayer['id'];
            }
            
            try {
                $pdo = Config::getDatabaseConnection();
                
                // Get active curse effects for target player
                $stmt = $pdo->prepare("
                    SELECT ace.*, c.card_name, c.card_description, c.challenge_modify, c.snap_modify, c.spicy_modify,
                        c.score_modify, c.timer
                    FROM active_curse_effects ace
                    JOIN cards c ON ace.card_id = c.id
                    WHERE ace.game_id = ? AND ace.player_id = ?
                ");
                $stmt->execute([$player['game_id'], $targetPlayerId]);
                $curseEffects = $stmt->fetchAll();
                
                // Get active power effects for target player
                $stmt = $pdo->prepare("
                    SELECT ape.*, c.card_name, c.card_description, c.power_challenge_modify, c.power_snap_modify, 
                        c.power_spicy_modify, c.power_score_modify, c.power_wait
                    FROM active_power_effects ape
                    JOIN cards c ON ape.power_card_id = c.id
                    WHERE ape.game_id = ? AND ape.player_id = ?
                ");
                $stmt->execute([$player['game_id'], $targetPlayerId]);
                $powerEffects = $stmt->fetchAll();
                
                echo json_encode([
                    'success' => true,
                    'curse_effects' => $curseEffects,
                    'power_effects' => $powerEffects
                ]);
            } catch (Exception $e) {
                error_log("Error getting effects: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;

        case 'get_active_timers':
            try {
                $pdo = Config::getDatabaseConnection();
                $timezone = new DateTimeZone('America/Indiana/Indianapolis');
                
                // Get active timers for both players
                $stmt = $pdo->prepare("
                    SELECT t.*, ace.player_id, c.card_name
                    FROM timers t
                    JOIN active_curse_effects ace ON t.id = ace.timer_id
                    JOIN cards c ON ace.card_id = c.id
                    WHERE t.game_id = ? AND t.is_active = TRUE
                ");
                $stmt->execute([$player['game_id']]);
                $timers = $stmt->fetchAll();
                
                $playerTimers = [];
                $opponentTimers = [];
                
                foreach ($timers as $timer) {
                    $endTime = new DateTime($timer['end_time'], new DateTimeZone('UTC'));
                    $endTime->setTimezone($timezone);
                    
                    $timerData = [
                        'id' => $timer['id'],
                        'card_name' => $timer['card_name'],
                        'end_time' => $endTime->format('Y-m-d\TH:i:s.000\Z')
                    ];
                    
                    if ($timer['player_id'] == $player['id']) {
                        $playerTimers[] = $timerData;
                    } else {
                        $opponentTimers[] = $timerData;
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'player_timers' => $playerTimers,
                    'opponent_timers' => $opponentTimers
                ]);
                exit;
                
            } catch (Exception $e) {
                error_log("Error getting active timers: " . $e->getMessage());
                echo json_encode(['success' => false]);
                exit;
            }

        case 'get_awards_info':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $playerStats = getPlayerStats($player['game_id'], $player['id']);
            $opponentStats = getPlayerStats($player['game_id'], $opponentPlayer['id']);
            $gameStats = getGameStats($player['game_id']);
            
            $playerSnapNext = getNextSnapAwardLevel($playerStats['snap_cards_completed']);
            $playerSpicyNext = getNextSpicyAwardLevel($playerStats['spicy_cards_completed']);
            $opponentSnapNext = getNextSnapAwardLevel($opponentStats['snap_cards_completed']);
            $opponentSpicyNext = getNextSpicyAwardLevel($opponentStats['spicy_cards_completed']);
            
            echo json_encode([
                'success' => true,
                'player_stats' => $playerStats,
                'opponent_stats' => $opponentStats,
                'game_stats' => $gameStats,
                'player_snap_next' => $playerSnapNext,
                'player_spicy_next' => $playerSpicyNext,
                'opponent_snap_next' => $opponentSnapNext,
                'opponent_spicy_next' => $opponentSpicyNext
            ]);
            exit;

        case 'check_veto_wait':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $timezone = new DateTimeZone('America/Indiana/Indianapolis');
            $now = new DateTime('now', $timezone);
            
            $stmt = $pdo->prepare("
                SELECT veto_wait_until FROM players 
                WHERE game_id = ? AND id = ?
            ");
            $stmt->execute([$player['game_id'], $player['id']]);
            $waitUntil = $stmt->fetchColumn();
            
            $isWaiting = false;
            if ($waitUntil) {
                $waitTime = new DateTime($waitUntil, $timezone);
                $isWaiting = $now < $waitTime;
                
                // Convert to UTC for JavaScript
                if ($isWaiting) {
                    $waitTime->setTimezone(new DateTimeZone('UTC'));
                    $waitUntil = $waitTime->format('Y-m-d\TH:i:s.000\Z');
                }
            }
            
            echo json_encode([
                'success' => true, 
                'is_waiting' => $isWaiting,
                'wait_until' => $isWaiting ? $waitUntil : null
            ]);
            exit;

        case 'check_curse_dice':
            $effectId = intval($_POST['effect_id']);
            $die1 = intval($_POST['die1']);
            $die2 = intval($_POST['die2']);
            $total = intval($_POST['total']);
            
            try {
                $pdo = Config::getDatabaseConnection();
                
                // Get curse effect details
                $stmt = $pdo->prepare("
                    SELECT ace.*, c.dice_condition, c.dice_threshold, c.slot_number
                    FROM active_curse_effects ace
                    JOIN cards c ON ace.card_id = c.id
                    WHERE ace.id = ?
                ");
                $stmt->execute([$effectId]);
                $curse = $stmt->fetch();
                
                if (!$curse) {
                    echo json_encode(['success' => false, 'message' => 'Curse not found']);
                    exit;
                }
                
                $cleared = false;
                
                switch ($curse['dice_condition']) {
                    case 'even':
                        $cleared = ($total % 2 === 0);
                        break;
                    case 'odd':
                        $cleared = ($total % 2 !== 0);
                        break;
                    case 'doubles':
                        $cleared = ($die1 === $die2);
                        break;
                    case 'above':
                        $cleared = ($total > $curse['dice_threshold']);
                        break;
                    case 'below':
                        $cleared = ($total < $curse['dice_threshold']);
                        break;
                }
                
                if ($cleared) {
                    // Clear curse
                    $stmt = $pdo->prepare("DELETE FROM active_curse_effects WHERE id = ?");
                    $stmt->execute([$effectId]);
                    
                    echo json_encode([
                        'success' => true,
                        'cleared' => true,
                        'message' => 'Curse cleared!'
                    ]);
                } else {
                    echo json_encode([
                        'success' => true,
                        'cleared' => false,
                        'message' => 'Curse remains active'
                    ]);
                }
                
            } catch (Exception $e) {
                error_log("Error checking curse dice: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;

        case 'check_blocking_curses':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            try {
                $pdo = Config::getDatabaseConnection();
                
                $stmt = $pdo->prepare("
                    SELECT c.card_name, c.complete_snap, c.complete_spicy
                    FROM active_curse_effects ace
                    JOIN cards c ON ace.card_id = c.id
                    WHERE ace.game_id = ? AND ace.player_id = ?
                    AND (c.complete_snap = 1 OR c.complete_spicy = 1)
                    LIMIT 1
                ");
                $stmt->execute([$player['game_id'], $player['id']]);
                $blockingCurse = $stmt->fetch();
                
                if ($blockingCurse) {
                    $cardType = $blockingCurse['complete_snap'] ? 'snap' : 'spicy';
                    echo json_encode([
                        'success' => true,
                        'is_blocked' => true,
                        'curse_name' => $blockingCurse['card_name'],
                        'card_type' => $cardType
                    ]);
                } else {
                    echo json_encode([
                        'success' => true,
                        'is_blocked' => false
                    ]);
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;

        case 'cleanup_expired_effects':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            cleanupExpiredEffects($player['game_id']);
            echo json_encode(['success' => true]);
            exit;

        case 'skip_challenge':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $slotNumber = intval($_POST['slot_number']);
            $result = skipChallenge($player['game_id'], $player['id'], $slotNumber);
            echo json_encode($result);
            exit;

        case 'get_curse_timers':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            // Check if opponent exists (game is active)
            if (!$opponentPlayer) {
                echo json_encode([
                    'success' => true,
                    'player_timer' => null,
                    'opponent_timer' => null
                ]);
                exit;
            }
            
            $result = getCurseTimers($player['game_id'], $player['id'], $opponentPlayer['id']);
            echo json_encode($result);
            exit;

        case 'get_active_modifiers':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $result = getActiveModifiers($player['game_id'], $player['id']);
            echo json_encode($result);
            exit;

        case 'set_travel_mode':
            $modeId = intval($_POST['mode_id']);
            try {
                $pdo = Config::getDatabaseConnection();
                $stmt = $pdo->prepare("UPDATE games SET travel_mode_id = ? WHERE id = ?");
                $stmt->execute([$modeId, $player['game_id']]);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                error_log("Error setting travel mode: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Failed to set travel mode']);
            }
            exit;

        case 'set_game_dates':
            $startDate = $_POST['start_date'];
            $endDate = $_POST['end_date'];
            
            // Set start date to 8am, end date to 11:59:59pm
            $timezone = new DateTimeZone('America/Indiana/Indianapolis');
            $start = new DateTime($startDate . ' 08:00:00', $timezone);
            $end = new DateTime($endDate . ' 23:59:59', $timezone);
            $today = new DateTime('now', $timezone);
            $today->setTime(0, 0, 0);
            
            $startCheck = clone $start;
            $startCheck->setTime(0, 0, 0);
            
            if ($startCheck < $today) {
                echo json_encode(['success' => false, 'message' => 'Start date cannot be in the past']);
                exit;
            }
            
            $daysDiff = $start->diff($end)->days;
            
            if ($daysDiff < 1 || $daysDiff > 14) {
                echo json_encode(['success' => false, 'message' => 'Game must be 1-14 days long']);
                exit;
            }
            
            try {
                $pdo = Config::getDatabaseConnection();

                $status = $start <= new DateTime('now', $timezone) ? 'active' : 'waiting';

                $stmt = $pdo->prepare("
                    UPDATE games 
                    SET start_date = ?, end_date = ?, status = ?, duration_days = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $start->format('Y-m-d H:i:s'), 
                    $end->format('Y-m-d H:i:s'),
                    $status,
                    $daysDiff + 1, 
                    $player['game_id']
                ]);
                
                // Only initialize if status is active
                if ($status === 'active') {
                    $initResult = initializeTravelEdition($player['game_id']);
                    if (!$initResult['success']) {
                        echo json_encode(['success' => false, 'message' => 'Failed to initialize game']);
                        exit;
                    }
                }
                
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                error_log("Error setting game dates: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Failed to set dates']);
            }
            exit;

        case 'store_challenge':
            $slotNumber = intval($_POST['slot_number']);
            $result = storeChallengeCard($player['game_id'], $player['id'], $slotNumber);
            echo json_encode($result);
            exit;

        case 'peek_deck':
            $result = peekDailyDeck($player['game_id'], $player['id']);
            echo json_encode($result);
            exit;
                    
        case 'update_score':
            $playerId = intval($_POST['player_id']);
            $points = intval($_POST['points']);
            $result = updateScore($player['game_id'], $playerId, $points, $player['id']);
            echo json_encode($result);
            exit;
            
        case 'create_timer':
            $description = trim($_POST['description']);
            $minutes = floatval($_POST['minutes']);
            $result = createTimer($player['game_id'], $player['id'], $description, $minutes);
            echo json_encode($result);
            exit;

        case 'delete_timer':
            $timerId = intval($_POST['timer_id']);
            $result = deleteTimer($timerId, $player['game_id']);
            echo json_encode($result);
            exit;
            
        case 'send_bump':
            $result = sendBumpNotification($player['game_id'], $player['id']);
            echo json_encode($result);
            exit;

        case 'test_notification':
            $result = sendTestNotification($deviceId);
            echo json_encode($result);
            exit;
            
        case 'update_fcm_token':
            $token = $_POST['fcm_token'];
            $result = updateFcmToken($deviceId, $token);
            echo json_encode(['success' => $result]);
            exit;

        case 'check_token_refresh_needed':
            try {
                $pdo = Config::getDatabaseConnection();
                $stmt = $pdo->prepare("SELECT needs_token_refresh FROM players WHERE device_id = ?");
                $stmt->execute([$deviceId]);
                $needsRefresh = $stmt->fetchColumn();
                
                if ($needsRefresh) {
                    // Clear the flag
                    $stmt = $pdo->prepare("UPDATE players SET needs_token_refresh = FALSE WHERE device_id = ?");
                    $stmt->execute([$deviceId]);
                }
                
                echo json_encode(['success' => true, 'needs_refresh' => (bool)$needsRefresh]);
            } catch (Exception $e) {
                echo json_encode(['success' => false]);
            }
            break;
            
        case 'get_game_data':
            clearExpiredEffects($player['game_id']);
            
            $updatedPlayers = getGamePlayers($player['game_id']);
            $timers = getActiveTimers($player['game_id']);
            $history = getScoreHistory($player['game_id']);

            // Check if game has expired
            $now = new DateTime();
            $endDate = new DateTime($player['end_date']);
            $gameExpired = ($now >= $endDate && $player['status'] === 'active');
            
            echo json_encode([
                'success' => true,
                'players' => $updatedPlayers,
                'timers' => $timers,
                'history' => $history,
                'gametime' => $gameTimeText,
                'game_expired' => $gameExpired
            ]);
            exit;

        case 'check_game_status':
            try {
                $pdo = Config::getDatabaseConnection();
                $gameId = $player['game_id'];
                
                // Get updated game info including mode
                $stmt = $pdo->prepare("SELECT status, travel_mode_id FROM games WHERE id = ?");
                $stmt->execute([$gameId]);
                $gameInfo = $stmt->fetch();
                
                $currentPlayers = getGamePlayers($gameId);
                
                echo json_encode([
                    'success' => true,
                    'status' => $gameInfo['status'],
                    'travel_mode_id' => $gameInfo['travel_mode_id'],
                    'player_count' => count($currentPlayers)
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]);
            }
            exit;

        case 'end_game':
            try {
                $pdo = Config::getDatabaseConnection();

                // Award challenge master bonus before ending
                if ($gameMode === 'digital') {
                    awardChallengeMaster($player['game_id']);
                }
                
                // Get final scores before ending the game
                $players = getGamePlayers($player['game_id']);
                
                // Determine winner
                $winner = null;
                $loser = null;
                $isTie = false;
                
                if (count($players) === 2) {
                    if ($players[0]['score'] > $players[1]['score']) {
                        $winner = $players[0];
                        $loser = $players[1];
                    } elseif ($players[1]['score'] > $players[0]['score']) {
                        $winner = $players[1];
                        $loser = $players[0];
                    } else {
                        $isTie = true;
                    }
                }
                
                // End the game
                $stmt = $pdo->prepare("UPDATE games SET status = 'completed', end_date = NOW() WHERE id = ?");
                $stmt->execute([$player['game_id']]);
                
                // Send notifications to both players
                foreach ($players as $p) {
                    if ($p['fcm_token']) {
                        if ($isTie) {
                            $title = "Game Over - It's a Tie!";
                            $body = "Final score: " . $players[0]['score'] . " points each. Great game!";
                        } else {
                            if ($p['id'] === $winner['id']) {
                                $title = "🎉 You Won!";
                                $body = "Final score: " . $winner['score'] . "-" . $loser['score'] . ". Congratulations!";
                            } else {
                                $title = "Game Over";
                                $body = $winner['first_name'] . " won " . $winner['score'] . "-" . $loser['score'] . ". Better luck next time!";
                            }
                        }
                        
                        sendPushNotification($p['fcm_token'], $title, $body);
                    }
                }
                
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                error_log("Error ending game: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Failed to end game.']);
            }
            exit;

        case 'ready_for_new_game':
            $result = markPlayerReadyForNewGame($player['game_id'], $player['id']);
            if ($result['success'] && $result['both_ready']) {
                $resetResult = resetGameForNewRound($player['game_id']);
                if ($resetResult['success']) {
                    echo json_encode(['success' => true, 'redirect' => true]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to reset game']);
                }
            } else {
                echo json_encode($result);
            }
            exit;

        case 'get_new_game_status':
            // Check if game has been reset (status = 'waiting')
            try {
                $pdo = Config::getDatabaseConnection();
                $stmt = $pdo->prepare("SELECT status FROM games WHERE id = ?");
                $stmt->execute([$player['game_id']]);
                $gameStatus = $stmt->fetchColumn();
                
                echo json_encode([
                    'success' => true, 
                    'game_reset' => ($gameStatus === 'waiting')
                ]);
            } catch (Exception $e) {
                echo json_encode(['success' => false]);
            }
            exit;

        case 'cleanup_hands':
            // Temporary cleanup - remove after running once
            $result = cleanupIncorrectHandCards($player['game_id']);
            echo json_encode(['success' => $result]);
            exit;

        case 'check_deck_empty':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false]);
                exit;
            }
            
            $isEmpty = isDeckEmpty($player['game_id'], $player['id']);
            echo json_encode(['success' => true, 'is_empty' => $isEmpty]);
            exit;

        case 'get_debug_info':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false]);
                exit;
            }
            
            try {
                // Get available cards (same logic as deck generation)
                $available = getAvailableCardsForDeck($player['game_id'], $player['id']);
                
                // Get total cards in travel mode
                $stmt = $pdo->prepare("SELECT travel_mode_id FROM games WHERE id = ?");
                $stmt->execute([$player['game_id']]);
                $modeId = $stmt->fetchColumn();
                
                $total = [];
                foreach (['challenge', 'curse', 'power', 'battle'] as $category) {
                    $stmt = $pdo->prepare("
                        SELECT SUM(c.quantity)
                        FROM cards c
                        JOIN card_travel_modes ctm ON c.id = ctm.card_id
                        WHERE c.card_category = ? AND ctm.mode_id = ?
                    ");
                    $stmt->execute([$category, $modeId]);
                    $total[$category] = $stmt->fetchColumn() ?: 0;
                }
                
                // Get today's deck breakdown
                $timezone = new DateTimeZone('America/Indiana/Indianapolis');
                $today = (new DateTime('now', $timezone))->format('Y-m-d');

                $stmt = $pdo->prepare("
                    SELECT c.card_name, c.card_category, COUNT(*) as count
                    FROM daily_deck_cards ddc
                    JOIN daily_decks dd ON ddc.deck_id = dd.id
                    JOIN cards c ON ddc.card_id = c.id
                    WHERE dd.game_id = ? AND dd.player_id = ? AND dd.deck_date = ?
                    GROUP BY c.id, c.card_name, c.card_category
                    ORDER BY 
                        CASE c.card_category 
                            WHEN 'challenge' THEN 1 
                            WHEN 'curse' THEN 2 
                            WHEN 'power' THEN 3 
                            WHEN 'battle' THEN 4 
                        END, 
                        c.card_name
                ");
                $stmt->execute([$player['game_id'], $player['id'], $today]);
                $deckCards = $stmt->fetchAll();

                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as total
                    FROM daily_deck_cards ddc
                    JOIN daily_decks dd ON ddc.deck_id = dd.id
                    WHERE dd.game_id = ? AND dd.player_id = ? AND dd.deck_date = ?
                ");
                $stmt->execute([$player['game_id'], $player['id'], $today]);
                $deckTotal = $stmt->fetchColumn();

                echo json_encode([
                    'success' => true,
                    'available' => [
                        'challenge' => $available['challenge_count'],
                        'curse' => $available['curse_count'],
                        'power' => $available['power_count']
                    ],
                    'total' => $total,
                    'deck_breakdown' => [
                        'total' => $deckTotal,
                        'cards' => $deckCards
                    ]
                ]);
            } catch (Exception $e) {
                echo json_encode(['success' => false]);
            }
            exit;

        case 'end_game_day_debug':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            // Check testing mode
            $stmt = $pdo->prepare("SELECT testing_mode FROM games WHERE id = ?");
            $stmt->execute([$player['game_id']]);
            if (!$stmt->fetchColumn()) {
                echo json_encode(['success' => false, 'message' => 'Testing mode not enabled']);
                exit;
            }
            
            try {
                $pdo->beginTransaction();
                
                // Clear all daily deck slots
                $timezone = new DateTimeZone('America/Indiana/Indianapolis');
                $today = (new DateTime('now', $timezone))->format('Y-m-d');

                // Delete daily deck slots entirely (will be recreated)
                $stmt = $pdo->prepare("DELETE FROM daily_deck_slots WHERE game_id = ? AND deck_date = ?");
                $stmt->execute([$player['game_id'], $today]);
                
                // Clear active curse effects
                $stmt = $pdo->prepare("DELETE FROM active_curse_effects WHERE game_id = ?");
                $stmt->execute([$player['game_id']]);
                
                // Delete daily deck cards first (child records)
                $stmt = $pdo->prepare("
                    DELETE ddc FROM daily_deck_cards ddc
                    JOIN daily_decks dd ON ddc.deck_id = dd.id
                    WHERE dd.game_id = ? AND dd.deck_date = ?
                ");
                $stmt->execute([$player['game_id'], $today]);

                // Then delete daily decks (parent records)
                $stmt = $pdo->prepare("DELETE FROM daily_decks WHERE game_id = ? AND deck_date = ?");
                $stmt->execute([$player['game_id'], $today]);
                
                $pdo->commit();
                
                // Generate new deck with logging
                ob_start();
                $result = generateDailyDeckWithLogging($player['game_id'], $player['id']);
                $log = ob_get_clean();
                
                if ($result['success']) {
                    echo json_encode([
                        'success' => true,
                        'log' => $log
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => $result['message']
                    ]);
                }
                
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, maximum-scale=1.0">
    <title>TCQ Travel Edition</title>
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="<?= Config::COLOR_BLUE ?>">
    <link rel="stylesheet" href="https://use.typekit.net/oqm2ymj.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/aquawolf04/font-awesome-pro@5cd1511/css/all.css">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/icon-180x180.png">
    <meta name="apple-mobile-web-app-title" content="TCQ">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.3/dist/confetti.browser.min.js"></script>
    <style>
        :root {
            --color-blue: <?= Config::COLOR_BLUE ?>;
            --color-pink: <?= Config::COLOR_PINK ?>;
            --color-blue-dark: <?= Config::COLOR_BLUE_DARK ?>;
            --color-pink-dark: <?= Config::COLOR_PINK_DARK ?>;
            --color-blue-mid: <?= Config::COLOR_BLUE_MID ?>;
            --color-pink-mid: <?= Config::COLOR_PINK_MID ?>;
            --color-blue-light: <?= Config::COLOR_BLUE_LIGHT ?>;
            --color-pink-light: <?= Config::COLOR_PINK_LIGHT ?>;
            --animation-spring: cubic-bezier(0.2, 0.8, 0.3, 1.1);
        }
    </style>
    <link rel="stylesheet" href="/game.css">
</head>
<body class="<?php 
    $classes = [];
    if($player['gender'] === 'male') { $classes[] = 'male'; } else { $classes[] = 'female'; }
    if($travelModeClass) { $classes[] = $travelModeClass; }
    echo implode(' ', $classes);
?>">
    <div class="background-container cruise">
        <div class="ocean">
            <div class="wave"></div>
            <div class="wave"></div>
        </div>

        <!-- Clouds -->
        <div class="cloud cloud-1"></div>
        <div class="cloud cloud-2"></div>
        <div class="cloud cloud-3"></div>

        <!-- Cruise Ship -->
        <div class="cruise-ship">
            <div class="ship-hull"></div>
            <div class="ship-stripe"></div>
            
            <div class="ship-deck-1">
                <div class="porthole porthole-1-1"></div>
                <div class="porthole porthole-1-2"></div>
                <div class="porthole porthole-1-3"></div>
                <div class="porthole porthole-1-4"></div>
                <div class="porthole porthole-1-5"></div>
                <div class="porthole porthole-1-6"></div>
                <div class="porthole porthole-1-7"></div>
            </div>
            
            <div class="ship-deck-2">
                <div class="porthole porthole-2-1"></div>
                <div class="porthole porthole-2-2"></div>
                <div class="porthole porthole-2-3"></div>
                <div class="porthole porthole-2-4"></div>
                <div class="porthole porthole-2-5"></div>
                <div class="porthole porthole-2-6"></div>
            </div>
            
            <div class="ship-deck-3">
                <div class="porthole porthole-3-1"></div>
                <div class="porthole porthole-3-2"></div>
                <div class="porthole porthole-3-3"></div>
                <div class="porthole porthole-3-4"></div>
                <div class="porthole porthole-3-5"></div>
            </div>
            
            <div class="ship-bridge">
                <div class="bridge-window bridge-window-1"></div>
                <div class="bridge-window bridge-window-2"></div>
                <div class="bridge-window bridge-window-3"></div>
            </div>
            
            <div class="ship-funnel funnel-1"></div>
            <div class="ship-funnel funnel-2"></div>
            <div class="smoke"></div>
            <div class="smoke"></div>
        </div>
    </div>
    <div class="container">
        <?php if ($gameStatus === 'waiting' && count($players) < 2): ?>
            <!-- Waiting for other player -->
            <div class="waiting-screen no-opponent">
                <h2>Waiting for Opponent</h2>
                <p>Share your invite code with your opponent to start the game!</p>
                <p><strong>Invite Code: <?= htmlspecialchars($player['invite_code']) ?></strong></p>
                <div class="notify-bubble" style="margin-top: 30px; padding: 20px; border-radius: 15px;">
                    <h3 style="margin-bottom: 15px;">🔔 Enable Notifications</h3>
                    <p style="margin-bottom: 15px; font-size: 14px;">Get notified when your partner bumps you or when timers expire!</p>
                    <button id="enableNotificationsBtn" class="btn" onclick="enableNotifications()">
                        Enable Notifications
                    </button>
                    <div id="notificationStatus" style="margin-top: 10px; font-size: 14px;"></div>
                </div>
            </div>
            
        <?php elseif ($gameStatus === 'waiting' && count($players) === 2 && (!$gameData['travel_mode_id'])): ?>
            <!-- Select travel mode -->
            <div class="waiting-screen mode-selection">
                <h2>Choose Travel Mode</h2>
                <p>What type of adventure are you taking?</p>
                <div class="mode-options">
                    <?php
                    $stmt = $pdo->prepare("SELECT * FROM travel_modes ORDER BY mode_title");
                    $stmt->execute();
                    $modes = $stmt->fetchAll();
                    
                    foreach ($modes as $mode):
                    ?>
                        <div class="mode-btn" data-mode="<?= $mode['id'] ?>">
                            <div class="mode-icon">
                                <i class="fa-solid <?= htmlspecialchars($mode['mode_icon']) ?>"></i>
                            </div>
                            <div class="mode-title"><?= htmlspecialchars($mode['mode_title']) ?></div>
                            <div class="mode-description"><?= htmlspecialchars($mode['mode_description']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
        <?php elseif ($gameStatus === 'waiting' && count($players) === 2 && $gameData['travel_mode_id'] && !$gameData['start_date']): ?>
            <!-- Set game dates -->
            <div class="waiting-screen duration">
                <div class="notify-bubble" style="margin-bottom: 30px; padding: 20px; border-radius: 15px;">
                    <h3 style="margin-bottom: 15px;">🔔 Enable Notifications</h3>
                    <p style="margin-bottom: 15px; font-size: 14px;">Get notified when your partner bumps you or when timers expire!</p>
                    <button id="enableNotificationsBtn" class="btn" onclick="enableNotifications()">
                        Enable Notifications
                    </button>
                    <div id="notificationStatus" style="margin-top: 10px; font-size: 14px;"></div>
                </div>
                <h2>Set Game Dates</h2>
                <p>Choose your adventure dates (1-14 days)</p>
                
                <div class="form-group">
                    <label for="startDate">Start Date:</label>
                    <input type="date" id="startDate" required>
                </div>
                
                <div class="form-group">
                    <label for="endDate">End Date:</label>
                    <input type="date" id="endDate" required>
                </div>
                
                <button class="btn" onclick="setGameDates()" id="setDatesBtn">Start Adventure</button>
            </div>
            <script>

            // Set default dates using Indianapolis timezone
            document.addEventListener('DOMContentLoaded', function() {
                const startInput = document.getElementById('startDate');
                const endInput = document.getElementById('endDate');
                
                if (startInput && endInput) {
                    // Get current time in Indianapolis timezone
                    const indianaTime = new Date().toLocaleString("en-US", {timeZone: "America/Indiana/Indianapolis"});
                    const today = new Date(indianaTime);
                    
                    // Set start date to today
                    const year = today.getFullYear();
                    const month = String(today.getMonth() + 1).padStart(2, '0');
                    const day = String(today.getDate()).padStart(2, '0');
                    startInput.value = `${year}-${month}-${day}`;
                    
                    // Set end date to 7 days from today
                    const endDate = new Date(today);
                    endDate.setDate(today.getDate() + 7);
                    const endYear = endDate.getFullYear();
                    const endMonth = String(endDate.getMonth() + 1).padStart(2, '0');
                    const endDay = String(endDate.getDate()).padStart(2, '0');
                    endInput.value = `${endYear}-${endMonth}-${endDay}`;
                }
            });
            </script>

        <?php elseif ($gameStatus === 'waiting' && $gameData['start_date']): 
            $timezone = new DateTimeZone('America/Indiana/Indianapolis');
            $now = new DateTime('now', $timezone);
            $startDate = new DateTime($gameData['start_date'], $timezone);
            
            if ($now < $startDate):
        ?>
            <!-- Waiting for start date -->
            <div class="waiting-screen start-date-wait">
                <h2>Adventure Starts Soon!</h2>
                <p>Your game will begin at 8:00 AM on</p>
                <p><strong><?= (new DateTime($gameData['start_date']))->format('F j, Y') ?></strong></p>
                <div id="startCountdown" style="font-size: 48px; font-weight: 900; margin: 30px 0;"></div>
            </div>
            
        <?php endif; // Close the $now < $startDate check
        elseif ($gameStatus === 'completed'): ?>
            <!-- Game ended -->
            <?php 
            $winner = $players[0]['score'] > $players[1]['score'] ? $players[0] : $players[1];
            $loser = $players[0]['score'] > $players[1]['score'] ? $players[1] : $players[0];
            if ($players[0]['score'] === $players[1]['score']) $winner = null;
            
            $readyStatus = getNewGameReadyStatus($player['game_id']);
            $currentPlayerReady = false;
            $opponentPlayerReady = false;
            
            foreach ($readyStatus as $status) {
                if ($status['first_name'] === $currentPlayer['first_name']) {
                    $currentPlayerReady = $status['ready_for_new_game'];
                } else {
                    $opponentPlayerReady = $status['ready_for_new_game'];
                }
            }
            ?>
            <div class="game-ended">
                <div class="confetti"></div>
                <?php if ($winner): ?>
                    <div class="winner <?= $winner['gender'] ?>">
                        🎉 <?= htmlspecialchars($winner['first_name']) ?> Wins! 🎉
                    </div>
                    <p>Final Score: <?= $winner['score'] ?>-<?= $loser['score'] ?></p>
                <?php else: ?>
                    <div class="winner">
                        🤝 It's a Tie! 🤝
                    </div>
                    <p>Final Score: <?= $players[0]['score'] ?> points each</p>
                <?php endif; ?>

                <div style="margin-top: 40px;">
                    <?php if ($currentPlayerReady && $opponentPlayerReady): ?>
                        <p style="color: #51cf66; margin-bottom: 20px;">Both players ready! Creating new game...</p>
                    <?php elseif ($currentPlayerReady): ?>
                        <p style="color: #ffd43b; margin-bottom: 20px;">Waiting for opponent to be ready...</p>
                    <?php elseif ($opponentPlayerReady): ?>
                        <p style="color: #ffd43b; margin-bottom: 20px;">Your opponent is ready for a new game!</p>
                    <?php endif; ?>
                    
                    <button id="newGameBtn" class="btn" onclick="readyForNewGame()" 
                            <?= $currentPlayerReady ? 'disabled style="background: #51cf66;"' : '' ?>>
                        <?= $currentPlayerReady ? 'Ready ✓' : 'Start New Game' ?>
                    </button>
                </div>
            </div>
            
        <?php else: ?>
            <!-- Active Travel Edition Game -->
            <?php
            if (!$currentPlayer || !$opponentPlayer) {
                echo '<div style="color: red; padding: 20px;">Error: Could not identify players correctly. Please contact support.</div>';
                exit;
            }
            ?>
            
            <!-- Combined Game Timer and Hand Indicator -->
            <div class="game-timer-hand" id="gameTimerHand" onclick="toggleHandOverlay()">
                <div class="timer-section">
                    <span class="game-time"><?= $gameTimeText ?></span>
                </div>
                <div class="hand-section">
                    <i class="fa-solid fa-cards-blank"></i>
                    <span id="handCardCount">0</span>
                </div>
            </div>
            
            <!-- Hand Overlay (swipes down from top) -->
            <div class="hand-overlay" id="handOverlay">
                <div class="hand-content">
                    <!-- Draw Decks -->
                    <div class="deck-selector">
                        <div class="deck-option snap-deck" onclick="drawSnapCard()">
                            <div class="deck-header">
                                <div class="deck-title">
                                    <i class="fa-solid fa-camera-retro"></i>
                                    Draw Snap
                                </div>
                            </div>
                            <div class="deck-count">Tap to draw</div>
                        </div>
                        
                        <div class="deck-option spicy-deck" onclick="drawSpicyCard()">
                            <div class="deck-header">
                                <div class="deck-title">
                                    <i class="fa-solid fa-pepper-hot"></i>
                                    Draw Spicy
                                </div>
                            </div>
                            <div class="deck-count">Tap to draw</div>
                        </div>
                    </div>
                    
                    <!-- 10 Card Slots Display -->
                    <div class="hand-slots" id="handSlots">
                        <div class="hand-slot empty" data-slot="1">
                            <div class="empty-slot-indicator">Empty</div>
                        </div>
                        <div class="hand-slot empty" data-slot="2">
                            <div class="empty-slot-indicator">Empty</div>
                        </div>
                        <div class="hand-slot empty" data-slot="3">
                            <div class="empty-slot-indicator">Empty</div>
                        </div>
                        <div class="hand-slot empty" data-slot="4">
                            <div class="empty-slot-indicator">Empty</div>
                        </div>
                        <div class="hand-slot empty" data-slot="5">
                            <div class="empty-slot-indicator">Empty</div>
                        </div>
                        <div class="hand-slot empty" data-slot="6">
                            <div class="empty-slot-indicator">Empty</div>
                        </div>
                        <div class="hand-slot empty" data-slot="7">
                            <div class="empty-slot-indicator">Empty</div>
                        </div>
                        <div class="hand-slot empty" data-slot="8">
                            <div class="empty-slot-indicator">Empty</div>
                        </div>
                        <div class="hand-slot empty" data-slot="9">
                            <div class="empty-slot-indicator">Empty</div>
                        </div>
                        <div class="hand-slot empty" data-slot="10">
                            <div class="empty-slot-indicator">Empty</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Daily Deck Slots (center of screen) -->
            <div class="daily-deck-container" id="dailyDeckContainer">
                <div class="deck-message-overlay" id="deckMessageOverlay" onclick="drawAllSlots()" style="display: none;">
                    <div class="deck-message-content">
                        <div class="deck-message-icon"><i class="fa-solid fa-cards-blank"></i></div>
                        <div class="deck-message-text">Tap to Draw 3 Cards</div>
                    </div>
                </div>
                
                <div class="veto-wait-overlay" id="vetoWaitOverlay" style="display: none;">
                    <div class="veto-message">Wait to Play</div>
                    <div class="veto-countdown" id="vetoCountdown">0:00</div>
                </div>

                <div class="curse-block-overlay" id="curseBlockOverlay" style="display: none;">
                    <div class="curse-block-message" id="curseBlockMessage">Curse Active</div>
                    <div class="curse-block-requirement" id="curseBlockRequirement">Complete a snap card to clear this curse</div>
                </div>

                <div class="daily-deck-count" id="dailyDeckCount" style="display: none;">
                    <span id="deckCountText">Cards remaining: 0</span>
                </div>
                
                <div class="daily-slots" id="dailySlots">
                    <div class="daily-slot" data-slot="1" onclick="handleSlotInteraction(1)">
                        <div class="slot-content">
                            <div class="empty-slot">TAP TO DRAW A CARD</div>
                        </div>
                    </div>
                    
                    <div class="daily-slot" data-slot="2" onclick="handleSlotInteraction(2)">
                        <div class="slot-content">
                            <div class="empty-slot">TAP TO DRAW A CARD</div>
                        </div>
                    </div>
                    
                    <div class="daily-slot" data-slot="3" onclick="handleSlotInteraction(3)">
                        <div class="slot-content">
                            <div class="empty-slot">TAP TO DRAW A CARD</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Score Bug (bottom) -->
            <div class="score-bug" id="scoreBug" onclick="toggleScoreBugExpanded()">
                <div class="score-bug-content">
                    <!-- Expanded Content (hidden above) -->
                    <div class="score-bug-expanded-content" id="scoreBugExpandedContent">
                        <div class="sound-toggle-section">
                            <i class="fa-solid fa-volume-high" id="soundToggleIcon" onclick="event.stopPropagation(); toggleSound()"></i>
                        </div>
                        <!-- Dice Roller -->
                        <div class="dice-roller-section">
                            <i class="fa-solid fa-dice" onclick="event.stopPropagation(); openDicePopover()"></i>
                        </div>
                        <!-- Awards Progress Section -->
                        <div class="awards-section">
                            <h3>Awards Progress</h3>
                            
                            <!-- Snap Award -->
                            <div class="award-row">
                                <div class="award-column opponent">
                                    <div class="award-icon snap-award">
                                        <i class="fa-solid fa-camera-retro"></i>
                                    </div>
                                    <div class="award-count" id="opponentSnapProgress">-</div>
                                </div>
                                <div class="award-label"><span class="award-badge" id="opponentSnapBadge"></span>SNAP<span class="award-badge" id="playerSnapBadge"></span></div>
                                <div class="award-column current">
                                    <div class="award-icon snap-award">
                                        <i class="fa-solid fa-camera-retro"></i>
                                    </div>
                                    <div class="award-count" id="playerSnapProgress">-</div>
                                </div>
                            </div>
                            
                            <!-- Spicy Award -->
                            <div class="award-row">
                                <div class="award-column opponent">
                                    <div class="award-icon spicy-award">
                                        <i class="fa-solid fa-pepper-hot"></i>
                                    </div>
                                    <div class="award-count" id="opponentSpicyProgress">-</div>
                                </div>
                                <div class="award-label"><span class="award-badge" id="opponentSpicyBadge"></span>SPICY<span class="award-badge" id="playerSpicyBadge"></span></div>
                                <div class="award-column current">
                                    <div class="award-icon spicy-award">
                                        <i class="fa-solid fa-pepper-hot"></i>
                                    </div>
                                    <div class="award-count" id="playerSpicyProgress">-</div>
                                </div>
                            </div>
                            
                            <!-- Challenge Master -->
                            <div class="award-row">
                                <div class="award-column opponent">
                                    <div class="award-icon challenge-master">
                                        <i class="fa-solid fa-trophy"></i>
                                    </div>
                                    <div class="award-count" id="opponentChallengeCount">-</div>
                                </div>
                                <div class="award-label master">MASTER<br><span class="award-badge">+25</span></div>
                                <div class="award-column current">
                                    <div class="award-icon challenge-master">
                                        <i class="fa-solid fa-trophy"></i>
                                    </div>
                                    <div class="award-count" id="playerChallengeCount">-</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Adjust Score Section -->
                        <div class="adjust-score-section">
                            <h3>Adjust Score</h3>
                            
                            <div class="score-adjustment-row">
                                <div class="adjustment-column">
                                    <button class="adjustment-btn add" onclick="adjustScoreWithInput('<?= $opponentPlayer['id'] ?>', 1)">
                                        <i class="fa-solid fa-plus"></i>
                                    </button>
                                    <button class="adjustment-btn subtract" onclick="adjustScoreWithInput('<?= $opponentPlayer['id'] ?>', -1)">
                                        <i class="fa-solid fa-minus"></i>
                                    </button>
                                    <button class="adjustment-btn steal" onclick="stealPointsWithInput('<?= $opponentPlayer['id'] ?>', '<?= $currentPlayer['id'] ?>')">
                                        <i class="fa-solid fa-hand"></i>
                                    </button>
                                </div>
                                
                                <div class="score-input-container">
                                    <input type="number" id="scoreAdjustInput" min="1" placeholder="Amount" pattern="\d*" style="width: 80px; text-align: center; padding: 8px; border-radius: 8px; border: 2px solid #ddd;">
                                </div>
                                
                                <div class="adjustment-column">
                                    <button class="adjustment-btn add" onclick="adjustScoreWithInput('<?= $currentPlayer['id'] ?>', 1)">
                                        <i class="fa-solid fa-plus"></i>
                                    </button>
                                    <button class="adjustment-btn subtract" onclick="adjustScoreWithInput('<?= $currentPlayer['id'] ?>', -1)">
                                        <i class="fa-solid fa-minus"></i>
                                    </button>
                                    <button class="adjustment-btn steal" onclick="stealPointsWithInput('<?= $currentPlayer['id'] ?>', '<?= $opponentPlayer['id'] ?>')">
                                        <i class="fa-solid fa-hand"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="score-area">
                        <!-- Regular Score Content -->
                        <div class="status-effects opponent-effects" id="opponentStatusEffects">
                            <!-- Status effect icons will be added here -->
                        </div>
                        <div class="player-score-section opponent">
                            <div class="player-score"><?= $opponentPlayer['score'] ?></div>
                            <div class="player-name"><?= htmlspecialchars($opponentPlayer['first_name']) ?></div>
                        </div>
                        
                        <div class="score-divider">
                            <div class="daily-game-clock" id="dailyGameClock">
                                <div class="clock-time">16:00:00</div>
                                <i class="fa-solid fa-chevron-up" id="expandIcon"></i>
                                <div class="game-day">Day 1</div>
                            </div>
                        </div>
                        
                        <div class="player-score-section current">
                            <div class="player-score"><?= $currentPlayer['score'] ?></div>
                            <div class="player-name"><?= htmlspecialchars($currentPlayer['first_name']) ?></div>
                        </div>
                        <div class="status-effects player-effects" id="playerStatusEffects">
                            <!-- Status effect icons will be added here -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- Curse Timers -->
            <div class="curse-timer left" id="opponentCurseTimer" style="display: none;" onclick="showActiveEffectsPopover()">
                <i class="fa-solid fa-skull-crossbones"></i>
                <span id="playerCurseTime">0:00</span>
            </div>

            <div class="curse-timer right" id="playerCurseTimer" style="display: none;" onclick="showActiveEffectsPopover()">
                <i class="fa-solid fa-skull-crossbones"></i>
                <span id="opponentCurseTime">0:00</span>
            </div>
            
            <!-- Pass game data to JavaScript -->
            <script>
                window.gameDataFromPHP = {
                currentPlayerId: <?= $currentPlayer['id'] ?>,
                opponentPlayerId: <?= $opponentPlayer['id'] ?>,
                gameStatus: '<?= $gameStatus ?>',
                gameId: '<?= $currentPlayer['game_id'] ?>',
                startDate: '<?= $gameData['start_date'] ?>',
                currentPlayerGender: '<?= $currentPlayer['gender'] ?>',
                opponentPlayerGender: '<?= $opponentPlayer['gender'] ?>',
                opponentPlayerName: '<?= htmlspecialchars($opponentPlayer['first_name']) ?>',
                testingMode: <?= $testingMode ? 'true' : 'false' ?>
            };
            </script>
        <?php endif; ?>
    </div>

    <div class="iAN">
        <div class="iAN-title"></div>
        <div class="iAN-body"></div>
    </div>

    <!-- Notify Modal -->
    <div class="modal" id="notifyModal">
        <div class="modal-content">
            <div class="modal-title">🔔 Notification Settings</div>
            
            <div id="notificationModalStatus" class="notification-status disabled">
                <span id="notificationModalStatusText">Checking notification status...</span>
            </div>
            
            <div class="notification-info">
                <h4>What you'll receive:</h4>
                <ul>
                    <li>Daily Deck Updates</li>
                    <li>Opponent Card Actions</li>
                    <li>Score Changes & Awards</li>
                    <li>Timer Expiration Alerts</li>
                    <li>Bump Notifications</li>
                </ul>
            </div>
            
            <button id="enableNotificationsModalBtn" class="btn" onclick="enableNotificationsFromModal()">
                Enable Notifications
            </button>
            
            <button id="testNotificationBtn" class="btn btn-test" onclick="testNotification()" style="display: none;">
                Send Test Notification
            </button>
            
            <button class="btn btn-secondary" onclick="closeModal('notifyModal')">Close</button>
        </div>
    </div>
    
    <!-- Timer Modal -->
    <div class="modal" id="timerModal">
        <div class="modal-content">
            <div class="modal-title">Create Timer</div>
            
            <div class="form-group">
                <label>Description</label>
                <input type="text" id="timerDescription" placeholder="What is this timer for?">
            </div>
            
            <div class="form-group">
                <label>Duration</label>
                <select id="timerDuration">
                    <option value="0.5">30 seconds</option>
                    <option value="1">1 minute</option>
                    <option value="5">5 minutes</option>
                    <option value="10">10 minutes</option>
                    <option value="15">15 minutes</option>
                    <option value="30">30 minutes</option>
                    <option value="60">1 hour</option>
                    <option value="120">2 hours</option>
                    <option value="240">4 hours</option>
                    <option value="480">8 hours</option>
                    <option value="720">12 hours</option>
                    <option value="1440">24 hours</option>
                    <option value="10080">7 days</option>
                </select>
            </div>
            
            <button class="btn" onclick="createTimer()">Create Timer</button>
            <button class="btn btn-secondary" onclick="closeModal('timerModal')">Cancel</button>
        </div>
    </div>

    <!-- History Modal -->
    <div class="modal" id="historyModal">
        <div class="modal-content">
            <div class="modal-title">Score History (24h)</div>
            <div id="historyContent"></div>
            <button class="btn btn-secondary" onclick="closeModal('historyModal')" style="margin-top: 12px;">Close</button>
        </div>
    </div>

    <!-- End Game Modal -->
    <div class="modal" id="endGameModal">
        <div class="modal-content">
            <div class="modal-title">Are you sure you want to end this game now?</div>
            <div class="modal-subtitle">This action cannot be undone.</div>
            <div class="modal-buttons">
                <button class="btn dark" onclick="closeModal('endGameModal')">No</button>
                <button class="btn red" onclick="endGame()">Yes</button>
            </div>
        </div>
    </div>
    <!-- Dice Overlay -->
    <div class="dice-popover" id="dicePopover">
        <div id="dicePopoverContainer"></div>
    </div>

    <!-- Hidden template for dice HTML -->
    <div id="diceTemplate" style="display: none;">
        <div class="dice-container" id="diceContainer">
            <div class="die male" id="die1">
                <div class="die-face front face-1"><div class="die-dot"></div></div>
                <div class="die-face back face-6">
                    <div class="die-dot"></div><div class="die-dot"></div><div class="die-dot"></div>
                    <div class="die-dot"></div><div class="die-dot"></div><div class="die-dot"></div>
                </div>
                <div class="die-face right face-3">
                    <div class="die-dot"></div><div class="die-dot"></div><div class="die-dot"></div>
                </div>
                <div class="die-face left face-4">
                    <div class="die-dot"></div><div class="die-dot"></div>
                    <div class="die-dot"></div><div class="die-dot"></div>
                </div>
                <div class="die-face top face-2">
                    <div class="die-dot"></div><div class="die-dot"></div>
                </div>
                <div class="die-face bottom face-5">
                    <div class="die-dot"></div><div class="die-dot"></div><div class="die-dot"></div>
                    <div class="die-dot"></div><div class="die-dot"></div>
                </div>
            </div>
            <div class="die male two" id="die2">
                <div class="die-face front face-1"><div class="die-dot"></div></div>
                <div class="die-face back face-6">
                    <div class="die-dot"></div><div class="die-dot"></div><div class="die-dot"></div>
                    <div class="die-dot"></div><div class="die-dot"></div><div class="die-dot"></div>
                </div>
                <div class="die-face right face-3">
                    <div class="die-dot"></div><div class="die-dot"></div><div class="die-dot"></div>
                </div>
                <div class="die-face left face-4">
                    <div class="die-dot"></div><div class="die-dot"></div>
                    <div class="die-dot"></div><div class="die-dot"></div>
                </div>
                <div class="die-face top face-2">
                    <div class="die-dot"></div><div class="die-dot"></div>
                </div>
                <div class="die-face bottom face-5">
                    <div class="die-dot"></div><div class="die-dot"></div><div class="die-dot"></div>
                    <div class="die-dot"></div><div class="die-dot"></div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($testingMode): ?>
    <div class="debug-toggle" onclick="toggleDebugPanel()">DEBUG</div>
    <div class="debug-panel" id="debugPanel">
        <div class="debug-section">
            <h3>Testing Mode Active</h3>
            <p style="color: #ff0;">All time restrictions removed. Wait periods set to 5min.</p>
        </div>
        
        <div class="debug-section">
            <h3>Master Deck Status</h3>
            <div id="debugDeckCounts">Loading...</div>
        </div>
        
        <div class="debug-section">
            <h3>Actions</h3>
            <button class="debug-btn" onclick="endGameDay()">End Current Day</button>
        </div>
        
        <div class="debug-section">
            <h3>Today's Deck Breakdown</h3>
            <div id="debugDeckBreakdown">Loading...</div>
        </div>
    </div>
    <?php endif; ?>
    
    <script src="https://www.gstatic.com/firebasejs/9.0.0/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/9.0.0/firebase-messaging-compat.js"></script>
    <script src="/game.js"></script>
</body>
</html>