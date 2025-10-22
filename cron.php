<?php
// cron.php - Travel Edition
require_once 'config.php';
require_once 'functions.php';

ini_set('log_errors', 1);
ini_set('error_log', '/path/to/your/logs/cron_errors.log');

$action = $argv[1] ?? 'all';

// Handle specific timer expiration
if (isset($argv[1]) && strpos($argv[1], 'timer_') === 0) {
    $timerId = str_replace('timer_', '', $argv[1]);
    checkExpiredTimers($timerId);
    exit;
}

switch ($action) {
    case 'timers':
        checkExpiredTimers();
        break;
    case 'daily':
        sendDailyNotifications();
        break;
    case 'end':
        endOfDayNotification();
        break;
    case 'cleanup':
        cleanupExpiredGames();
        break;
    case 'all':
    default:
        checkExpiredTimers();
        
        // Daily notifications at 8 AM
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $now = new DateTime('now', $timezone);
        if ($now->format('H:i') === '08:00') {
            sendDailyNotifications();
        }
        
        // Cleanup at midnight
        if ($now->format('H:i') === '00:00') {
            cleanupExpiredGames();
        }
        break;
}

function sendDailyNotifications() {
    error_log("Starting daily deck notifications...");
    
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $now = new DateTime('now', $timezone);
        
        // Activate games that have reached their start time
        $stmt = $pdo->query("
            SELECT id FROM games 
            WHERE status = 'waiting' 
            AND start_date IS NOT NULL 
            AND start_date <= NOW()
        ");
        $gamesToActivate = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($gamesToActivate as $gameId) {
            $stmt = $pdo->prepare("UPDATE games SET status = 'active' WHERE id = ?");
            $stmt->execute([$gameId]);
            
            // Initialize Travel Edition for this game
            initializeTravelEdition($gameId);
        }
        
        // Get players in active digital games that started today or earlier
        $stmt = $pdo->query("
            SELECT g.id as game_id, g.start_date, g.end_date,
                   p.id, p.first_name, p.gender, p.score, p.fcm_token,
                   opponent.first_name as opponent_name, opponent.score as opponent_score
            FROM games g
            JOIN players p ON g.id = p.game_id
            JOIN players opponent ON g.id = opponent.game_id AND opponent.id != p.id
            WHERE g.status = 'active' 
            AND g.game_mode = 'digital'
            AND g.start_date <= NOW()
            AND p.fcm_token IS NOT NULL 
            AND p.fcm_token != ''
        ");
        
        $players = $stmt->fetchAll();
        error_log("Found " . count($players) . " players for daily deck notifications");
        
        $notificationsSent = 0;
        
        foreach ($players as $player) {
            try {
                $endDate = new DateTime($player['end_date'], $timezone);
                $daysLeft = $now < $endDate ? $endDate->diff($now)->days + 1 : 0;
                
                if ($daysLeft <= 0) continue;
                
                $scoreStatus = '';
                if ($player['score'] > $player['opponent_score']) {
                    $scoreDiff = $player['score'] - $player['opponent_score'];
                    $scoreStatus = "You're leading by {$scoreDiff}! ";
                } elseif ($player['score'] < $player['opponent_score']) {
                    $scoreDiff = $player['opponent_score'] - $player['score'];
                    $scoreStatus = "You're down by {$scoreDiff}. ";
                } else {
                    $scoreStatus = "It's tied! ";
                }
                
                $dayText = $daysLeft === 1 ? 'day' : 'days';
                $message = "{$scoreStatus}Your Daily Deck is ready! {$daysLeft} {$dayText} left with {$player['opponent_name']}.";
                
                $result = sendPushNotification(
                    $player['fcm_token'],
                    'Daily Deck Ready! 🚨',
                    $message
                );
                
                if ($result) {
                    $notificationsSent++;
                    error_log("Daily deck notification sent to player {$player['id']}");
                }
                
            } catch (Exception $e) {
                error_log("Error sending notification to player {$player['id']}: " . $e->getMessage());
            }
        }
        
        error_log("Daily notifications completed: {$notificationsSent} sent");
        
    } catch (Exception $e) {
        error_log("Error in sendDailyNotifications: " . $e->getMessage());
    }
}

function endOfDayNotification() {
    $pdo = Config::getDatabaseConnection();
    // Get players in active digital games that started today or earlier
    $stmt = $pdo->query("
        SELECT g.id as game_id, g.start_date, g.end_date,
                p.id, p.first_name, p.gender, p.score, p.fcm_token,
                opponent.first_name as opponent_name, opponent.score as opponent_score
        FROM games g
        JOIN players p ON g.id = p.game_id
        JOIN players opponent ON g.id = opponent.game_id AND opponent.id != p.id
        WHERE g.status = 'active' 
        AND g.game_mode = 'digital'
        AND g.start_date <= NOW()
        AND p.fcm_token IS NOT NULL 
        AND p.fcm_token != ''
    ");
    
    $players = $stmt->fetchAll();
    error_log("Found " . count($players) . " players for daily deck notifications");

    foreach ($players as $player) {
        if ($player['fcm_token']) {
            sendPushNotification(
                $player['fcm_token'],
                'Game Day Ending Soon!',
                "Only a few hours left to complete challenges today!"
            );
        }
    }
}

function checkExpiredTimers($specificTimerId = null) {
    error_log("Checking expired timers" . ($specificTimerId ? " - ID: $specificTimerId" : ""));
    
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        
        if ($specificTimerId) {
            $stmt = $pdo->prepare("
                SELECT t.*, p.fcm_token, p.first_name, p.game_id
                FROM timers t
                JOIN players p ON t.player_id = p.id
                WHERE t.id = ? AND t.is_active = TRUE
            ");
            $stmt->execute([$specificTimerId]);
        } else {
            $stmt = $pdo->prepare("
                SELECT t.*, p.fcm_token, p.first_name, p.game_id
                FROM timers t
                JOIN players p ON t.player_id = p.id
                WHERE t.is_active = TRUE AND t.end_time <= UTC_TIMESTAMP()
            ");
            $stmt->execute();
        }
        
        $expiredTimers = $stmt->fetchAll();
        
        foreach ($expiredTimers as $timer) {
            try {
                // Check if linked to a curse effect
                $stmt = $pdo->prepare("
                    SELECT ace.*, c.card_name 
                    FROM active_curse_effects ace
                    JOIN cards c ON ace.card_id = c.id
                    WHERE ace.timer_id = ?
                ");
                $stmt->execute([$timer['id']]);
                $curseEffect = $stmt->fetch();
                
                if ($curseEffect) {
                    // Clear curse effect
                    $stmt = $pdo->prepare("DELETE FROM active_curse_effects WHERE id = ?");
                    $stmt->execute([$curseEffect['id']]);
                    
                    // Send notification
                    if ($timer['fcm_token']) {
                        sendPushNotification(
                            $timer['fcm_token'],
                            'Curse Expired! 💀',
                            "Your {$curseEffect['card_name']} curse has ended."
                        );
                    }
                    
                    error_log("Curse timer expired: {$curseEffect['card_name']} for player {$timer['player_id']}");
                } else {
                    // Regular timer notification
                    if ($timer['fcm_token']) {
                        sendPushNotification(
                            $timer['fcm_token'],
                            'Timer Expired ⏰',
                            $timer['description']
                        );
                    }
                }
                
                // Delete timer
                $stmt = $pdo->prepare("DELETE FROM timers WHERE id = ?");
                $stmt->execute([$timer['id']]);

                // In checkExpiredTimers, after clearing timer, add:
                if ($timer['fcm_token']) {
                    // Check if this is a veto wait timer
                    $stmt = $pdo->prepare("SELECT veto_wait_until FROM players WHERE id = ?");
                    $stmt->execute([$timer['player_id']]);
                    $vetoWait = $stmt->fetchColumn();
                    
                    if (!$vetoWait || strtotime($vetoWait) <= time()) {
                        notifyVetoWaitEnd($timer['game_id'], $timer['player_id']);
                    }
                }
                
            } catch (Exception $e) {
                error_log("Error processing timer {$timer['id']}: " . $e->getMessage());
            }
        }

        if ($timer['timer_type'] === 'siphon') {
            // Subtract score
            require_once 'travel_card_actions.php';
            updateScore($timer['game_id'], $timer['player_id'], -$timer['score_subtract'], $timer['player_id']);
            
            // Check if completion condition is met
            $shouldContinue = true;
            if ($timer['completion_type'] === 'first_trigger_any') {
                // Check if any challenge, snap, or spicy was completed
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM completed_cards 
                    WHERE game_id = ? AND player_id = ? AND card_type IN ('challenge', 'snap', 'spicy')
                    AND created_at > ?
                ");
                $stmt->execute([$timer['game_id'], $timer['player_id'], $timer['start_time']]);
                $shouldContinue = $stmt->fetchColumn() == 0;
            } elseif ($timer['completion_type'] === 'first_trigger') {
                // Check if spicy was completed
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM completed_cards 
                    WHERE game_id = ? AND player_id = ? AND card_type = 'spicy'
                    AND created_at > ?
                ");
                $stmt->execute([$timer['game_id'], $timer['player_id'], $timer['start_time']]);
                $shouldContinue = $stmt->fetchColumn() == 0;
            }
            
            if ($shouldContinue) {
                // Create new timer for next cycle
                createSiphonTimer($timer['game_id'], $timer['player_id'], $timer['description'], $timer['duration_minutes'], $timer['score_subtract'], $timer['completion_type']);
            }
        }
        
        return count($expiredTimers);
        
    } catch (Exception $e) {
        error_log("Error checking timers: " . $e->getMessage());
        return 0;
    }
}

function cleanupExpiredGames() {
    error_log("Starting cleanup...");
    
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $now = new DateTime('now', $timezone);
        
        // Mark games as completed if past end date
        $stmt = $pdo->prepare("
            UPDATE games 
            SET status = 'completed' 
            WHERE status = 'active' AND end_date < ?
        ");
        $stmt->execute([$now->format('Y-m-d H:i:s')]);
        $completedGames = $stmt->rowCount();
        
        // Delete old inactive timers (7+ days)
        $stmt = $pdo->prepare("
            DELETE FROM timers 
            WHERE is_active = FALSE AND end_time < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
        ");
        $stmt->execute();
        $deletedTimers = $stmt->rowCount();
        
        error_log("Cleanup: {$completedGames} games completed, {$deletedTimers} old timers deleted");
        
    } catch (Exception $e) {
        error_log("Error in cleanup: " . $e->getMessage());
    }
}