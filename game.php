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

// Get fresh game data to check current mode
$pdo = Config::getDatabaseConnection();
$stmt = $pdo->prepare("SELECT * FROM games WHERE id = ?");
$stmt->execute([$player['game_id']]);
$gameData = $stmt->fetch();

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

function getTodayTheme() {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        $stmt = $pdo->prepare("
            SELECT theme_class FROM scheduled_themes 
            WHERE theme_date = ? 
            ORDER BY id ASC 
            LIMIT 1
        ");
        $stmt->execute([$today]);
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Error getting today's theme: " . $e->getMessage());
        return null;
    }
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
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $deckStatus = getDailyDeckStatus($player['game_id']);
            echo json_encode($deckStatus);
            exit;

        case 'generate_daily_deck':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $result = generateDailyDeck($player['game_id']);
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
            
            $result = drawCardToSlot($player['game_id'], $slotNumber);
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

        case 'get_status_effects':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
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

        case 'get_awards_info':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $playerStats = getPlayerStats($player['game_id'], $player['id']);
            $gameStats = getGameStats($player['game_id']);
            
            $nextSnapLevel = getNextSnapAwardLevel($playerStats['snap_cards_completed']);
            $nextSpicyLevel = getNextSpicyAwardLevel($playerStats['spicy_cards_completed']);
            
            echo json_encode([
                'success' => true,
                'player_stats' => $playerStats,
                'game_stats' => $gameStats,
                'next_snap_level' => $nextSnapLevel,
                'next_spicy_level' => $nextSpicyLevel
            ]);
            exit;

        case 'check_veto_wait':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $isWaiting = isPlayerWaitingVeto($player['game_id'], $player['id']);
            echo json_encode(['success' => true, 'is_waiting' => $isWaiting]);
            exit;

        case 'cleanup_expired_effects':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            cleanupExpiredEffects($player['game_id']);
            echo json_encode(['success' => true]);
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
            
            $start = new DateTime($startDate);
            $end = new DateTime($endDate);
            $today = new DateTime();
            $today->setTime(0, 0, 0);
            
            if ($start < $today) {
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
                $stmt = $pdo->prepare("
                    UPDATE games 
                    SET start_date = ?, end_date = ?, status = 'active', duration_days = ?
                    WHERE id = ?
                ");
                $stmt->execute([$startDate, $endDate, $daysDiff + 1, $player['game_id']]);
                
                // Initialize Travel Edition
                $initResult = initializeTravelEdition($player['game_id']);
                if (!$initResult['success']) {
                    echo json_encode(['success' => false, 'message' => 'Failed to initialize game']);
                    exit;
                }
                
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                error_log("Error setting game dates: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Failed to set dates']);
            }
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
            processExpiredTimers($player['game_id']);
            clearExpiredEffects($player['game_id']);
            
            $updatedPlayers = getGamePlayers($player['game_id']);
            $timers = getActiveTimers($player['game_id']);
            $history = getScoreHistory($player['game_id']);

            // Check if game has expired
            $now = new DateTime();
            $endDate = new DateTime($player['end_date']);
            $gameExpired = ($now >= $endDate && $player['status'] === 'active');
            
            echo json_encode([
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
                $stmt = $pdo->prepare("SELECT status, game_mode FROM games WHERE id = ?");
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
                
                // Award challenge master bonus before ending
                if ($gameMode === 'digital') {
                    awardChallengeMaster($player['game_id']);
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
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, viewport-fit=cover, maximum-scale=1.0">
    <title>The Couple's Quest</title>
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
    if($player['gender'] === 'male') { echo 'male'; } else { echo 'female'; } 
    $todayTheme = getTodayTheme();
    if ($todayTheme) { echo ' ' . $todayTheme; }
?>">
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
            
        <?php elseif ($gameStatus === 'waiting' && count($players) === 2 && $gameMode && !$gameData['start_date']): ?>
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
            
        <?php elseif ($gameStatus === 'completed'): ?>
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
            if ($gameMode === 'digital') {
                echo '<script>document.body.classList.add("digital");</script>';
            }
            
            if (!$currentPlayer || !$opponentPlayer) {
                echo '<div style="color: red; padding: 20px;">Error: Could not identify players correctly. Please contact support.</div>';
                exit;
            }
            ?>
            
            <!-- Game Timer -->
            <div class="game-timer visible">
                <?= $gameTimeText ?>
            </div>
            
            <!-- Hand Overlay (swipes down from top) -->
            <div class="hand-overlay" id="handOverlay">
                <div class="hand-content">
                    <div class="deck-selector">
                        <div class="deck-option snap-deck <?= $currentPlayer['gender'] === 'female' ? 'active' : '' ?>" onclick="selectDeck('snap')">
                            <div class="deck-header">
                                <div class="deck-title">
                                    <i class="fa-solid fa-camera-retro"></i>
                                    Snap
                                </div>
                            </div>
                            <div class="deck-count" id="snapDeckCount">24 Cards Remaining</div>
                        </div>
                        
                        <div class="deck-option spicy-deck <?= $currentPlayer['gender'] === 'male' ? 'active' : '' ?>" onclick="selectDeck('spicy')">
                            <div class="deck-header">
                                <div class="deck-title">
                                    <i class="fa-solid fa-pepper-hot"></i>
                                    Spicy
                                </div>
                            </div>
                            <div class="deck-count" id="spicyDeckCount">22 Cards Remaining</div>
                        </div>
                    </div>
                    
                    <div class="hand-cards" id="handCards">
                        <!-- Hand cards will be populated here -->
                    </div>
                </div>
            </div>
            
            <!-- Daily Deck Slots (center of screen) -->
            <div class="daily-deck-container" id="dailyDeckContainer">
                <div class="deck-message" id="deckMessage">
                    Draw your first 3 cards from today's Daily Deck
                </div>
                
                <div class="veto-wait-overlay" id="vetoWaitOverlay" style="display: none;">
                    <div class="veto-message">Game Play Blocked</div>
                    <div class="veto-countdown" id="vetoCountdown">5:23</div>
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
                    <div class="player-score-section opponent">
                        <div class="player-name"><?= htmlspecialchars($opponentPlayer['first_name']) ?></div>
                        <div class="player-score"><?= $opponentPlayer['score'] ?></div>
                        <div class="status-effects opponent-effects" id="opponentStatusEffects">
                            <!-- Status effect icons will be added here -->
                        </div>
                    </div>
                    
                    <div class="score-divider">
                        <i class="fa-solid fa-chevron-up" id="expandIcon"></i>
                    </div>
                    
                    <div class="player-score-section current">
                        <div class="player-name"><?= htmlspecialchars($currentPlayer['first_name']) ?></div>
                        <div class="player-score"><?= $currentPlayer['score'] ?></div>
                        <div class="status-effects player-effects" id="playerStatusEffects">
                            <!-- Status effect icons will be added here -->
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Expanded Score Bug -->
            <div class="score-bug-expanded" id="scoreBugExpanded">
                <div class="score-bug-expanded-content">
                    <!-- Awards Progress Section -->
                    <div class="awards-section">
                        <h3>Awards Progress</h3>
                        
                        <div class="award-row">
                            <div class="award-item">
                                <div class="award-icon snap-award">
                                    <i class="fa-solid fa-camera-retro"></i>
                                </div>
                                <div class="award-info">
                                    <div class="award-level">LEVEL 1</div>
                                    <div class="award-name">SNAPPY</div>
                                    <div class="award-progress">1/5 TO NEXT LEVEL</div>
                                </div>
                            </div>
                            
                            <div class="award-item">
                                <div class="award-icon spicy-award">
                                    <i class="fa-solid fa-pepper-hot"></i>
                                </div>
                                <div class="award-info">
                                    <div class="award-level">LEVEL 1</div>
                                    <div class="award-name">ROMANCE</div>
                                    <div class="award-progress">0/3 TO NEXT LEVEL</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="challenge-master">
                            <div class="challenge-master-icon">
                                <i class="fa-solid fa-trophy"></i>
                            </div>
                            <div class="challenge-master-info">
                                <div class="challenge-master-count">
                                    <span id="playerChallengeCount">12</span> CHALLENGE MASTER <span id="opponentChallengeCount">11</span>
                                </div>
                                <div class="challenge-master-desc">+25 to player with most challenges completed by end of game</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Adjust Score Section -->
                    <div class="adjust-score-section">
                        <h3>Adjust Score</h3>
                        
                        <div class="score-adjustment-row">
                            <div class="adjustment-column">
                                <button class="adjustment-btn add" onclick="adjustScore('<?= $currentPlayer['id'] ?>', 1)">
                                    <i class="fa-solid fa-plus"></i>
                                </button>
                                <button class="adjustment-btn subtract" onclick="adjustScore('<?= $currentPlayer['id'] ?>', -1)">
                                    <i class="fa-solid fa-minus"></i>
                                </button>
                                <button class="adjustment-btn steal" onclick="stealPoints('<?= $currentPlayer['id'] ?>', '<?= $opponentPlayer['id'] ?>', 1)">
                                    <i class="fa-solid fa-hand"></i>
                                </button>
                            </div>
                            
                            <div class="score-display">
                                <div class="score-display-opponent">
                                    <div class="score-value" id="expandedOpponentScore"><?= $opponentPlayer['score'] ?></div>
                                    <div class="score-name"><?= htmlspecialchars($opponentPlayer['first_name']) ?></div>
                                </div>
                                
                                <div class="score-divider-expanded">
                                    <i class="fa-solid fa-chevron-down" onclick="toggleScoreBugExpanded()"></i>
                                </div>
                                
                                <div class="score-display-current">
                                    <div class="score-value" id="expandedCurrentScore"><?= $currentPlayer['score'] ?></div>
                                    <div class="score-name"><?= htmlspecialchars($currentPlayer['first_name']) ?></div>
                                </div>
                            </div>
                            
                            <div class="adjustment-column">
                                <button class="adjustment-btn add" onclick="adjustScore('<?= $opponentPlayer['id'] ?>', 1)">
                                    <i class="fa-solid fa-plus"></i>
                                </button>
                                <button class="adjustment-btn subtract" onclick="adjustScore('<?= $opponentPlayer['id'] ?>', -1)">
                                    <i class="fa-solid fa-minus"></i>
                                </button>
                                <button class="adjustment-btn steal" onclick="stealPoints('<?= $opponentPlayer['id'] ?>', '<?= $currentPlayer['id'] ?>', 1)">
                                    <i class="fa-solid fa-hand"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Pass game data to JavaScript -->
            <script>
                window.gameDataFromPHP = {
                    currentPlayerId: <?= $currentPlayer['id'] ?>,
                    opponentPlayerId: <?= $opponentPlayer['id'] ?>,
                    gameStatus: '<?= $gameStatus ?>',
                    currentPlayerGender: '<?= $currentPlayer['gender'] ?>',
                    opponentPlayerGender: '<?= $opponentPlayer['gender'] ?>',
                    opponentPlayerName: '<?= htmlspecialchars($opponentPlayer['first_name']) ?>'
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
    
    <script src="https://www.gstatic.com/firebasejs/9.0.0/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/9.0.0/firebase-messaging-compat.js"></script>
    <script src="/game.js"></script>
</body>
</html>