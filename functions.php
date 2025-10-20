<?php
require_once 'daily_deck_functions.php';
require_once 'travel_card_actions.php';
require_once 'awards_system.php';
require_once 'travel_hand_functions.php';

function registerPlayer($inviteCode, $gender, $firstName) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Check if invite code exists and is valid
        $stmt = $pdo->prepare("SELECT * FROM invite_codes WHERE code = ? AND is_used = FALSE");
        $stmt->execute([$inviteCode]);
        $invite = $stmt->fetch();
        
        if (!$invite) {
            return ['success' => false, 'message' => 'Invalid or expired invite code.'];
        }
        
        // Find or create game
        $stmt = $pdo->prepare("SELECT * FROM games WHERE invite_code = ?");
        $stmt->execute([$inviteCode]);
        $game = $stmt->fetch();
        
        if (!$game) {
            // Create new game
            $stmt = $pdo->prepare("INSERT INTO games (invite_code, status) VALUES (?, 'waiting')");
            $stmt->execute([$inviteCode]);
            $gameId = $pdo->lastInsertId();
        } else {
            $gameId = $game['id'];
        }
        
        // Check if someone with this gender already joined
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM players WHERE game_id = ? AND gender = ?");
        $stmt->execute([$gameId, $gender]);
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            return ['success' => false, 'message' => 'Someone with this gender has already joined this game.'];
        }
        
        // Generate device ID
        $deviceId = Config::generateDeviceId();
        
        // Register player
        $stmt = $pdo->prepare("INSERT INTO players (game_id, device_id, first_name, gender) VALUES (?, ?, ?, ?)");
        $stmt->execute([$gameId, $deviceId, $firstName, $gender]);
        $playerId = $pdo->lastInsertId();
        
        // Initialize player stats for Travel Edition
        initializePlayerStats($gameId, $playerId);
        
        // Check if both players have joined
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM players WHERE game_id = ?");
        $stmt->execute([$gameId]);
        $playerCount = $stmt->fetchColumn();
        
        if ($playerCount == 2) {
            // Mark invite code as used
            $stmt = $pdo->prepare("UPDATE invite_codes SET is_used = TRUE WHERE code = ?");
            $stmt->execute([$inviteCode]);
        }
        
        return ['success' => true, 'device_id' => $deviceId];
        
    } catch (Exception $e) {
        error_log("Error registering player: " . $e->getMessage());
        return ['success' => false, 'message' => 'Registration failed. Please try again.'];
    }
}

function getPlayerByDeviceId($deviceId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("
            SELECT p.id as player_id, p.device_id, p.first_name, p.gender, p.score, p.fcm_token, p.joined_at, p.veto_wait_until,
                   g.id as game_id, g.invite_code, g.duration_days, g.start_date, g.end_date, g.status, g.created_at, g.game_mode
            FROM players p 
            JOIN games g ON p.game_id = g.id 
            WHERE p.device_id = ?
        ");
        $stmt->execute([$deviceId]);
        $result = $stmt->fetch();
        
        if ($result) {
            // Rename player_id back to id for compatibility
            $result['id'] = $result['player_id'];
        }
        
        return $result;
    } catch (Exception $e) {
        error_log("Error getting player: " . $e->getMessage());
        return null;
    }
}

function getGamePlayers($gameId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("
            SELECT id, device_id, first_name, gender, score, fcm_token, joined_at, game_id, veto_wait_until
            FROM players 
            WHERE game_id = ? 
            ORDER BY joined_at ASC
        ");
        $stmt->execute([$gameId]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Error getting game players: " . $e->getMessage());
        return [];
    }
}

function updateScore($gameId, $playerId, $pointsToAdd, $modifiedBy) {
    try {
        $pdo = Config::getDatabaseConnection();
        $pdo->beginTransaction();
        
        // Get current scores before update
        $stmt = $pdo->prepare("SELECT id, score FROM players WHERE game_id = ?");
        $stmt->execute([$gameId]);
        $oldScores = $stmt->fetchAll();
        
        // Get current score for the player being updated
        $stmt = $pdo->prepare("SELECT score FROM players WHERE id = ?");
        $stmt->execute([$playerId]);
        $currentScore = $stmt->fetchColumn();
        
        $newScore = $currentScore + $pointsToAdd;
        
        // Update score
        $stmt = $pdo->prepare("UPDATE players SET score = ? WHERE id = ?");
        $stmt->execute([$newScore, $playerId]);
        
        // Record history
        $stmt = $pdo->prepare("
            INSERT INTO score_history (game_id, player_id, modified_by_player_id, old_score, new_score, points_changed) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$gameId, $playerId, $modifiedBy, $currentScore, $newScore, $pointsToAdd]);

        // If points are negative (stolen/lost), notify
        if ($pointsToAdd < 0) {
            $stmt = $pdo->prepare("SELECT fcm_token, first_name FROM players WHERE id = ?");
            $stmt->execute([$playerId]);
            $loser = $stmt->fetch();
            
            $stmt = $pdo->prepare("SELECT first_name FROM players WHERE id = ?");
            $stmt->execute([$modifiedBy]);
            $modifierName = $stmt->fetchColumn();
            
            if ($loser['fcm_token'] && $modifiedBy !== $playerId) {
                sendPushNotification(
                    $loser['fcm_token'],
                    'Points Stolen!',
                    "{$modifierName} stole " . abs($pointsToAdd) . " points from you"
                );
            }
        }
        
        $pdo->commit();

        // Check for lead changes before committing
        checkAndNotifyLeadChange($gameId, $oldScores);
        return ['success' => true];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error updating score: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to update score.'];
    }
}

function cleanupIncorrectHandCards($gameId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $pdo->beginTransaction();
        
        // Get all players in this game with their travel mode
        $stmt = $pdo->prepare("
            SELECT p.id, p.gender, g.travel_mode_id 
            FROM players p 
            JOIN games g ON p.game_id = g.id 
            WHERE g.id = ?
        ");
        $stmt->execute([$gameId]);
        $players = $stmt->fetchAll();
        
        foreach ($players as $player) {
            $genderClause = $player['gender'] === 'male' ? "AND c.male = 1" : "AND c.female = 1";
            
            // Get valid card IDs for this player's gender and travel mode
            $stmt = $pdo->prepare("
                SELECT c.id FROM cards c
                JOIN card_travel_modes ctm ON c.id = ctm.card_id
                WHERE c.card_category IN ('snap', 'spicy') 
                AND ctm.mode_id = ? 
                $genderClause
            ");
            $stmt->execute([$player['travel_mode_id']]);
            $validCardIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (empty($validCardIds)) continue;
            
            // Remove cards that aren't valid for this player
            $placeholders = str_repeat('?,', count($validCardIds) - 1) . '?';
            $stmt = $pdo->prepare("
                DELETE FROM player_cards 
                WHERE game_id = ? AND player_id = ? 
                AND card_type IN ('snap', 'spicy') 
                AND card_id NOT IN ($placeholders)
            ");
            $params = array_merge([$gameId, $player['id']], $validCardIds);
            $stmt->execute($params);
        }
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error cleaning up hand cards: " . $e->getMessage());
        return false;
    }
}

function setGameDuration($gameId, $durationDays) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        
        $startDate = new DateTime('now', $timezone);
        $startDate->setTime(8, 0, 0); // Set to 8am
        $endDate = clone $startDate;
        $endDate->add(new DateInterval('P' . $durationDays . 'D'));
        $endDate->setTime(23, 59, 59); // Set to 11:59:59pm
        
        $stmt = $pdo->prepare("
            UPDATE games 
            SET duration_days = ?, start_date = ?, end_date = ?, status = 'active' 
            WHERE id = ?
        ");
        $stmt->execute([
            $durationDays, 
            $startDate->format('Y-m-d H:i:s'), 
            $endDate->format('Y-m-d H:i:s'), 
            $gameId
        ]);
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Error setting game duration: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to set game duration.'];
    }
}

function getScoreHistory($gameId, $hours = 24) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("
            SELECT sh.*, p1.first_name as player_name, p2.first_name as modified_by_name
            FROM score_history sh
            JOIN players p1 ON sh.player_id = p1.id
            JOIN players p2 ON sh.modified_by_player_id = p2.id
            WHERE sh.game_id = ? AND sh.timestamp >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ORDER BY sh.timestamp DESC
        ");
        $stmt->execute([$gameId, $hours]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Error getting score history: " . $e->getMessage());
        return [];
    }
}

function createTimer($gameId, $playerId, $description, $durationMinutes) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Check testing mode
        $stmt = $pdo->prepare("SELECT testing_mode FROM games WHERE id = ?");
        $stmt->execute([$gameId]);
        $testingMode = (bool)$stmt->fetchColumn();
        
        // Override to 5 minutes in testing mode for curse waits
        if ($testingMode && strpos($description, 'curse') !== false) {
            $durationMinutes = 5;
        }
        
        $startTime = new DateTime('now', new DateTimeZone('UTC'));
        $endTime = clone $startTime;
        $seconds = $durationMinutes * 60;
        $endTime->add(new DateInterval('PT' . $seconds . 'S'));
        
        $stmt = $pdo->prepare("
            INSERT INTO timers (game_id, player_id, description, duration_minutes, start_time, end_time) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $gameId, 
            $playerId, 
            $description, 
            $durationMinutes, 
            $startTime->format('Y-m-d H:i:s'), 
            $endTime->format('Y-m-d H:i:s')
        ]);
        
        $timerId = $pdo->lastInsertId();

        // Add dynamic at job for timer expiration
        $endTimeLocal = clone $endTime;
        $endTimeLocal->setTimezone(new DateTimeZone('America/Indiana/Indianapolis'));

        $atTime = $endTimeLocal->format('H:i M j, Y');
        $seconds = $endTimeLocal->format('s');

        $atCommand = "sleep {$seconds} && /usr/bin/php /var/www/thecouplesquest/cron.php timer_{$timerId}";

        $atJob = shell_exec("echo '{$atCommand}' | at {$atTime} 2>&1");

        error_log("Created at job for timer {$timerId}: {$atTime} +{$seconds}s - Result: {$atJob}");

        return ['success' => true, 'timer_id' => $timerId];
        
    } catch (Exception $e) {
        error_log("Error creating timer: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to create timer.'];
    }
}

function getActiveTimers($gameId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("
            SELECT t.*, p.first_name, p.gender 
            FROM timers t
            JOIN players p ON t.player_id = p.id
            WHERE t.game_id = ? AND t.is_active = TRUE AND t.end_time > UTC_TIMESTAMP()
            ORDER BY t.end_time ASC
        ");
        $stmt->execute([$gameId]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Error getting active timers: " . $e->getMessage());
        return [];
    }
}

function deleteTimer($timerId, $gameId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("DELETE FROM timers WHERE id = ? AND game_id = ?");
        $stmt->execute([$timerId, $gameId]);

        // Clean up any chance effects linked to this timer
        $stmt = $pdo->prepare("DELETE FROM active_curse_effects WHERE timer_id = ?");
        $stmt->execute([$timerId]);
        
        // Remove at job - find and remove jobs containing our timer ID
        $atJobs = shell_exec('atq 2>/dev/null') ?: '';
        $lines = explode("\n", trim($atJobs));
        
        foreach ($lines as $line) {
            if (empty($line)) continue;
            
            $parts = explode("\t", $line);
            if (count($parts) >= 1) {
                $jobId = trim($parts[0]);
                
                // Check if this job contains our timer command
                $jobContent = shell_exec("at -c {$jobId} 2>/dev/null | tail -1");
                if (strpos($jobContent, "timer_{$timerId}") !== false) {
                    shell_exec("atrm {$jobId} 2>/dev/null");
                    error_log("Removed at job {$jobId} for timer {$timerId}");
                    break;
                }
            }
        }
        
        return ['success' => true];
    } catch (Exception $e) {
        error_log("Error deleting timer: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to delete timer.'];
    }
}

function sendPushNotification($fcmToken, $title, $body, $data = [], $retryCount = 0) {
    if (!$fcmToken || empty($fcmToken)) {
        error_log("FCM: No token provided");
        return false;
    }

    // Basic token validation
    if (strlen($fcmToken) < 50 || strlen($fcmToken) > 500) {
        error_log("FCM: Invalid token length");
        return false;
    }
    
    $data = [
        'title' => $title,
        'body' => $body
    ];
    
    $url = 'https://fcm.googleapis.com/v1/projects/' . Config::FCM_PROJECT_ID . '/messages:send';
    
    $message = [
        'message' => [
            'token' => $fcmToken,
            'data' => $data,
            'webpush' => [
                'headers' => [
                    'TTL' => '3600'
                ]
            ]
        ]
    ];
    
    $accessToken = getAccessToken();
    if (!$accessToken) {
        error_log("Failed to get FCM access token");
        return false;
    }
    
    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        error_log("FCM cURL error: $curlError");
        return false;
    }
    
    if ($httpCode !== 200) {
        error_log("FCM API error: HTTP $httpCode - $result");
        
        $errorData = json_decode($result, true);
        if ($errorData && isset($errorData['error'])) {
            error_log("FCM error details: " . $errorData['error']['message']);
            
            // Handle token errors with automatic refresh
            if (isset($errorData['error']['details'])) {
                foreach ($errorData['error']['details'] as $detail) {
                    if (isset($detail['errorCode']) && 
                        in_array($detail['errorCode'], ['UNREGISTERED', 'INVALID_ARGUMENT'])) {
                        
                        // If this is first retry, attempt to refresh token
                        if ($retryCount === 0) {
                            error_log("FCM token invalid, attempting refresh and retry");
                            $refreshed = refreshExpiredFcmToken($fcmToken);
                            if ($refreshed) {
                                // Retry with new token
                                return sendPushNotification($refreshed, $title, $body, $data, 1);
                            }
                        }
                        
                        // Clear invalid token after retry fails
                        clearInvalidFcmToken($fcmToken);
                        return false;
                    }
                }
            }
        }
        return false;
    }
    
    error_log("FCM notification sent successfully");
    return true;
}

function refreshExpiredFcmToken($oldToken) {
    try {
        // This would need to be called from client-side to get fresh token
        // For now, we'll mark the token for refresh and return false
        // The client will get a fresh token on next page load/interaction
        
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("UPDATE players SET fcm_token = NULL, needs_token_refresh = TRUE WHERE fcm_token = ?");
        $stmt->execute([$oldToken]);
        
        error_log("Marked token for refresh on next client interaction");
        return false;
    } catch (Exception $e) {
        error_log("Error marking token for refresh: " . $e->getMessage());
        return false;
    }
}

// Function to clear invalid tokens
function clearInvalidFcmToken($fcmToken) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("UPDATE players SET fcm_token = NULL WHERE fcm_token = ?");
        $stmt->execute([$fcmToken]);
        error_log("Cleared invalid FCM token from database");
    } catch (Exception $e) {
        error_log("Error clearing invalid FCM token: " . $e->getMessage());
    }
}

// Enhanced token update with validation
function updateFcmToken($deviceId, $fcmToken) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("UPDATE players SET fcm_token = ?, fcm_token_updated = NOW() WHERE device_id = ?");
        $stmt->execute([$fcmToken, $deviceId]);
        
        error_log("FCM token updated successfully for device: " . substr($deviceId, 0, 8) . "...");
        return true;
    } catch (Exception $e) {
        error_log("Error updating FCM token: " . $e->getMessage());
        return false;
    }
}

function getAccessToken() {
    
    $cacheFile = '/tmp/fcm_access_token.json';
    
    // Check if we have a cached token that's still valid
    if ($cacheFile && file_exists($cacheFile)) {
        try {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if ($cached && isset($cached['expires_at']) && $cached['expires_at'] > time()) {
                return $cached['access_token'];
            }
        } catch (Exception $e) {
            error_log("Error reading cached token: " . $e->getMessage());
        }
    }
    
    // Path to your service account JSON file
    $serviceAccountPath = Config::FCM_SERVICE_ACCOUNT_PATH;
    
    if (!file_exists($serviceAccountPath)) {
        error_log("Service account file not found: $serviceAccountPath");
        return false;
    }
    
    $serviceAccount = json_decode(file_get_contents($serviceAccountPath), true);
    
    if (!$serviceAccount) {
        error_log("Failed to parse service account JSON");
        return false;
    }
    
    // Create JWT
    $header = json_encode(['typ' => 'JWT', 'alg' => 'RS256']);
    $now = time();
    $payload = json_encode([
        'iss' => $serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600
    ]);
    
    $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
    
    $signature = '';
    $privateKey = openssl_pkey_get_private($serviceAccount['private_key']);
    
    if (!$privateKey) {
        error_log("Failed to parse private key from service account");
        return false;
    }
    
    if (!openssl_sign($base64Header . "." . $base64Payload, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        error_log("Failed to sign JWT");
        return false;
    }
    
    $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    $jwt = $base64Header . "." . $base64Payload . "." . $base64Signature;
    
    // Exchange JWT for access token
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        error_log("OAuth cURL error: $curlError");
        return false;
    }
    
    if ($httpCode !== 200) {
        error_log("OAuth token error: HTTP $httpCode - $response");
        return false;
    }
    
    $tokenData = json_decode($response, true);
    
    if (!isset($tokenData['access_token'])) {
        error_log("No access token in response: $response");
        return false;
    }
    
    // Try to cache the token if we have a writable location
    if ($cacheFile) {
        try {
            $cacheData = [
                'access_token' => $tokenData['access_token'],
                'expires_at' => time() + $tokenData['expires_in'] - 300 // 5 minutes buffer
            ];
            
            $result = file_put_contents($cacheFile, json_encode($cacheData));
            if ($result === false) {
                error_log("Failed to cache FCM token to: $cacheFile");
            }
        } catch (Exception $e) {
            error_log("Error caching FCM token: " . $e->getMessage());
        }
    } else {
        error_log("No writable directory found for FCM token cache");
    }
    
    return $tokenData['access_token'];
}

function sendBumpNotification($gameId, $senderPlayerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Get sender info
        $stmt = $pdo->prepare("SELECT first_name FROM players WHERE id = ?");
        $stmt->execute([$senderPlayerId]);
        $senderName = $stmt->fetchColumn();
        
        if (!$senderName) {
            return [
                'success' => false, 
                'message' => 'Sender not found'
            ];
        }
        
        // Get other player's FCM token
        $stmt = $pdo->prepare("
            SELECT fcm_token, first_name, id
            FROM players 
            WHERE game_id = ? AND id != ?
        ");
        $stmt->execute([$gameId, $senderPlayerId]);
        $recipient = $stmt->fetch();
        
        if (!$recipient) {
            return [
                'success' => false, 
                'message' => 'Partner not found in game'
            ];
        }
        
        if (!$recipient['fcm_token']) {
            return [
                'success' => true, 
                'message' => 'Bump sent! (Partner hasn\'t enabled notifications yet)'
            ];
        }
        
        // Send actual FCM notification
        $result = sendPushNotification(
            $recipient['fcm_token'],
            'Bump!',
            $senderName . ' wants to play. Check the daily deck!'
        );
        
        return [
            'success' => $result, 
            'message' => $result 
                ? 'Bump sent to ' . $recipient['first_name'] . '! 📱'
                : 'Failed to send notification to ' . $recipient['first_name']
        ];
        
    } catch (Exception $e) {
        error_log("Error sending bump notification: " . $e->getMessage());
        return [
            'success' => false, 
            'message' => 'Failed to send bump: ' . $e->getMessage()
        ];
    }
}

function checkAndNotifyLeadChange($gameId, $oldScores) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Get current scores
        $stmt = $pdo->prepare("SELECT id, first_name, score, fcm_token FROM players WHERE game_id = ? ORDER BY id ASC");
        $stmt->execute([$gameId]);
        $players = $stmt->fetchAll();
        
        if (count($players) !== 2) {
            error_log("Not exactly 2 players found");
            return;
        }
        
        // Determine old leader
        $oldLeader = null;
        if ($oldScores[0]['score'] > $oldScores[1]['score']) {
            $oldLeader = $oldScores[0]['id'];
        } elseif ($oldScores[1]['score'] > $oldScores[0]['score']) {
            $oldLeader = $oldScores[1]['id'];
        }
        
        // Determine new leader
        $newLeader = null;
        if ($players[0]['score'] > $players[1]['score']) {
            $newLeader = $players[0]['id'];
        } elseif ($players[1]['score'] > $players[0]['score']) {
            $newLeader = $players[1]['id'];
        }
        
        // Check if leadership changed
        if ($oldLeader !== $newLeader && $newLeader !== null) {
            $leader = $players[0]['score'] > $players[1]['score'] ? $players[0] : $players[1];
            $follower = $players[0]['score'] > $players[1]['score'] ? $players[1] : $players[0];
            
            // Send notification to follower
            if ($follower['fcm_token']) {
                $difference = $leader['score'] - $follower['score'];
                $result = sendPushNotification(
                    $follower['fcm_token'],
                    'Lead Change!',
                    $leader['first_name'] . ' has taken the lead! You\'re behind by ' . $difference . ' point' . ($difference === 1 ? '' : 's') . '.'
                );
            } else {
                error_log("No FCM token for follower");
            }
        } else {
            error_log("No lead change detected");
        }
    } catch (Exception $e) {
        error_log("Error checking lead change: " . $e->getMessage());
    }
}

function sendTestNotification($deviceId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Get player's FCM token
        $stmt = $pdo->prepare("SELECT fcm_token, first_name FROM players WHERE device_id = ?");
        $stmt->execute([$deviceId]);
        $player = $stmt->fetch();
        
        if ($player && $player['fcm_token']) {
            error_log("Test notification - Token from DB: " . substr($player['fcm_token'], 0, 20) . "... (length: " . strlen($player['fcm_token']) . ")");
            $result = sendPushNotification(
                $player['fcm_token'],
                'Test Notification',
                'Hello ' . $player['first_name'] . '! Notifications are working! 🎉'
            );
            
            return [
                'success' => $result,
                'message' => $result ? 'Test notification sent' : 'Failed to send test notification'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'No FCM token found for this device'
            ];
        }
        
    } catch (Exception $e) {
        error_log("Error sending test notification: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Failed to send test: ' . $e->getMessage()
        ];
    }
}

function markPlayerReadyForNewGame($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("UPDATE players SET ready_for_new_game = TRUE WHERE game_id = ? AND id = ?");
        $stmt->execute([$gameId, $playerId]);
        
        // Check if both players are ready
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM players WHERE game_id = ? AND ready_for_new_game = TRUE");
        $stmt->execute([$gameId]);
        $readyCount = $stmt->fetchColumn();
        
        return ['success' => true, 'both_ready' => $readyCount >= 2];
    } catch (Exception $e) {
        error_log("Error marking player ready: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to mark ready.'];
    }
}

function resetGameForNewRound($gameId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $pdo->beginTransaction();
        
        // Reset players: scores to 0, ready status to false
        $stmt = $pdo->prepare("UPDATE players SET score = 0, ready_for_new_game = FALSE, veto_wait_until = NULL WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        // Reset game: status to waiting, clear dates, mode and duration
        $stmt = $pdo->prepare("UPDATE games SET status = 'waiting', duration_days = NULL, start_date = NULL, end_date = NULL, game_mode = 'digital', travel_mode_id = NULL WHERE id = ?");
        $stmt->execute([$gameId]);
        
        // Clear timers
        $stmt = $pdo->prepare("DELETE FROM timers WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        // Clear score history
        $stmt = $pdo->prepare("DELETE FROM score_history WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        // Clear Travel Edition specific data - in correct order for foreign keys
        $stmt = $pdo->prepare("DELETE FROM daily_deck_cards WHERE deck_id IN (SELECT id FROM daily_decks WHERE game_id = ?)");
        $stmt->execute([$gameId]);
        
        $stmt = $pdo->prepare("DELETE FROM daily_decks WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        $stmt = $pdo->prepare("DELETE FROM daily_deck_slots WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        $stmt = $pdo->prepare("DELETE FROM player_cards WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        $stmt = $pdo->prepare("DELETE FROM active_curse_effects WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        $stmt = $pdo->prepare("DELETE FROM active_power_effects WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        $stmt = $pdo->prepare("DELETE FROM player_stats WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        $stmt = $pdo->prepare("DELETE FROM player_awards WHERE game_id = ?");
        $stmt->execute([$gameId]);

        $stmt = $pdo->prepare("DELETE FROM completed_cards WHERE game_id = ?");
        $stmt->execute([$gameId]);

        $pdo->commit();
        return ['success' => true];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error resetting game: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to reset game.'];
    }
}

function getNewGameReadyStatus($gameId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("
            SELECT first_name, ready_for_new_game 
            FROM players 
            WHERE game_id = ? 
            ORDER BY id ASC
        ");
        $stmt->execute([$gameId]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Error getting ready status: " . $e->getMessage());
        return [];
    }
}

function getOpponentPlayerId($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("SELECT id FROM players WHERE game_id = ? AND id != ?");
        $stmt->execute([$gameId, $playerId]);
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Error getting opponent ID: " . $e->getMessage());
        return null;
    }
}

// Travel Edition specific initialization
function initializeTravelEdition($gameId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Initialize player stats for both players
        $stmt = $pdo->prepare("SELECT id FROM players WHERE game_id = ?");
        $stmt->execute([$gameId]);
        $playerIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($playerIds as $playerId) {
            initializePlayerStats($gameId, $playerId);
            $deckResult = generateDailyDeck($gameId, $playerId);
            if (!$deckResult['success']) {
                error_log("Failed to generate daily deck for player {$playerId}: " . $deckResult['message']);
            }
        }
        
        
        return $deckResult;
        
    } catch (Exception $e) {
        error_log("Error initializing Travel Edition: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to initialize Travel Edition'];
    }
}

// Clean up expired effects periodically
function cleanupExpiredEffects($gameId) {
    clearExpiredEffects($gameId);
    
    // Clean up expired veto waits
    try {
        $pdo = Config::getDatabaseConnection();
        
        // DEBUG: Check what's being cleared
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $now = new DateTime('now', $timezone);
        
        $stmt = $pdo->prepare("SELECT id, first_name, veto_wait_until FROM players WHERE game_id = ? AND veto_wait_until IS NOT NULL");
        $stmt->execute([$gameId]);
        $activePlayers = $stmt->fetchAll();
        
        foreach ($activePlayers as $p) {
            $waitTime = new DateTime($p['veto_wait_until'], $timezone);
            $expired = $now >= $waitTime;
        }
        
        $stmt = $pdo->prepare("UPDATE players SET veto_wait_until = NULL WHERE game_id = ? AND veto_wait_until <= ?");
        $stmt->execute([$gameId, $now->format('Y-m-d H:i:s')]);
        $clearedCount = $stmt->rowCount();
        
        if ($clearedCount > 0) {
            error_log("CLEARED {$clearedCount} veto waits in cleanupExpiredEffects");
        }
    } catch (Exception $e) {
        error_log("Error cleaning up veto waits: " . $e->getMessage());
    }
    
    cleanupOrphanedCurseSlots($gameId);
}
?>