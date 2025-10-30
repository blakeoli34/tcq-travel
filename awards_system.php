<?php

function initializePlayerStats($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Check if stats already exist
        $stmt = $pdo->prepare("SELECT id FROM player_stats WHERE game_id = ? AND player_id = ?");
        $stmt->execute([$gameId, $playerId]);
        
        if (!$stmt->fetch()) {
            $stmt = $pdo->prepare("
                INSERT INTO player_stats (game_id, player_id, challenges_completed, snap_cards_completed, spicy_cards_completed)
                VALUES (?, ?, 0, 0, 0)
            ");
            $stmt->execute([$gameId, $playerId]);
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("Error initializing player stats: " . $e->getMessage());
        return false;
    }
}

function updateSnapCardCompletion($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Update snap completion count
        $stmt = $pdo->prepare("
            UPDATE player_stats 
            SET snap_cards_completed = snap_cards_completed + 1
            WHERE game_id = ? AND player_id = ?
        ");
        $stmt->execute([$gameId, $playerId]);
        
        // Check for level-based awards
        $stmt = $pdo->prepare("SELECT snap_cards_completed FROM player_stats WHERE game_id = ? AND player_id = ?");
        $stmt->execute([$gameId, $playerId]);
        $snapCount = $stmt->fetchColumn();
        
        $awardPoints = calculateSnapAward($snapCount);
        if ($awardPoints > 0) {
            updateScore($gameId, $playerId, $awardPoints, $playerId);
            recordPlayerAward($gameId, $playerId, 'snap_level', $snapCount, $awardPoints);
            $players = getGamePlayers($gameId);
            foreach ($players as $p) {
                if ($p['fcm_token']) {
                    $stmt = $pdo->prepare("SELECT first_name FROM players WHERE id = ?");
                    $stmt->execute([$playerId]);
                    $playerName = $stmt->fetchColumn();
                    
                    $message = $p['id'] === $playerId 
                        ? "You leveled up! Earned {$awardPoints} points"
                        : "{$playerName} leveled up and earned {$awardPoints} points!";
                    
                    sendPushNotification($p['fcm_token'], 'Snap Award!', $message);
                }
            }
            return ['award_points' => $awardPoints, 'level' => $snapCount];
        }
        
        return ['award_points' => 0];
        
    } catch (Exception $e) {
        error_log("Error updating snap card completion: " . $e->getMessage());
        return ['award_points' => 0];
    }
}

function updateSpicyCardCompletion($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Update spicy completion count
        $stmt = $pdo->prepare("
            UPDATE player_stats 
            SET spicy_cards_completed = spicy_cards_completed + 1
            WHERE game_id = ? AND player_id = ?
        ");
        $stmt->execute([$gameId, $playerId]);
        
        // Check for level-based awards
        $stmt = $pdo->prepare("SELECT spicy_cards_completed FROM player_stats WHERE game_id = ? AND player_id = ?");
        $stmt->execute([$gameId, $playerId]);
        $spicyCount = $stmt->fetchColumn();
        
        $awardPoints = calculateSpicyAward($spicyCount);
        if ($awardPoints > 0) {
            updateScore($gameId, $playerId, $awardPoints, $playerId);
            recordPlayerAward($gameId, $playerId, 'spicy_level', $spicyCount, $awardPoints);
            $players = getGamePlayers($gameId);
            foreach ($players as $p) {
                if ($p['fcm_token']) {
                    $stmt = $pdo->prepare("SELECT first_name FROM players WHERE id = ?");
                    $stmt->execute([$playerId]);
                    $playerName = $stmt->fetchColumn();
                    
                    $message = $p['id'] === $playerId 
                        ? "You leveled up! Earned {$awardPoints} points"
                        : "{$playerName} leveled up and earned {$awardPoints} points!";
                    
                    sendPushNotification($p['fcm_token'], 'Spicy Award!', $message);
                }
            }
            return ['award_points' => $awardPoints, 'level' => $spicyCount];
        }
        
        return ['award_points' => 0];
        
    } catch (Exception $e) {
        error_log("Error updating spicy card completion: " . $e->getMessage());
        return ['award_points' => 0];
    }
}

function calculateSnapAward($snapCount) {
    // Award structure for snap cards
    $awards = [
        5 => 10,
        12 => 25,
        25 => 75
    ];
    
    return $awards[$snapCount] ?? 0;
}

function calculateSpicyAward($spicyCount) {
    // Award structure for spicy cards
    $awards = [
        5 => 15,
        12 => 50,
        25 => 100
    ];
    
    return $awards[$spicyCount] ?? 0;
}

function recordPlayerAward($gameId, $playerId, $awardType, $level, $points) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        $stmt = $pdo->prepare("
            INSERT INTO player_awards (game_id, player_id, award_type, award_level, points_awarded, awarded_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$gameId, $playerId, $awardType, $level, $points]);
        
        return true;
        
    } catch (Exception $e) {
        error_log("Error recording player award: " . $e->getMessage());
        return false;
    }
}

function getPlayerStats($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        $stmt = $pdo->prepare("
            SELECT ps.*, 
                   COUNT(DISTINCT pa.id) as total_awards,
                   COALESCE(SUM(pa.points_awarded), 0) as total_award_points
            FROM player_stats ps
            LEFT JOIN player_awards pa ON ps.game_id = pa.game_id AND ps.player_id = pa.player_id
            WHERE ps.game_id = ? AND ps.player_id = ?
            GROUP BY ps.id
        ");
        $stmt->execute([$gameId, $playerId]);
        
        return $stmt->fetch();
        
    } catch (Exception $e) {
        error_log("Error getting player stats: " . $e->getMessage());
        return null;
    }
}

function getGameStats($gameId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Get all player stats
        $stmt = $pdo->prepare("
            SELECT ps.*, p.first_name, p.gender, p.score,
                   COUNT(DISTINCT pa.id) as total_awards,
                   COALESCE(SUM(pa.points_awarded), 0) as total_award_points
            FROM player_stats ps
            JOIN players p ON ps.player_id = p.id
            LEFT JOIN player_awards pa ON ps.game_id = pa.game_id AND ps.player_id = pa.player_id
            WHERE ps.game_id = ?
            GROUP BY ps.id
            ORDER BY p.id
        ");
        $stmt->execute([$gameId]);
        $playerStats = $stmt->fetchAll();
        
        // Determine challenge master
        $challengeMaster = null;
        $maxChallenges = 0;
        
        foreach ($playerStats as $stat) {
            if ($stat['challenges_completed'] > $maxChallenges) {
                $maxChallenges = $stat['challenges_completed'];
                $challengeMaster = $stat;
            } elseif ($stat['challenges_completed'] === $maxChallenges && $maxChallenges > 0) {
                // Tie - no challenge master
                $challengeMaster = null;
            }
        }
        
        return [
            'player_stats' => $playerStats,
            'challenge_master' => $challengeMaster
        ];
        
    } catch (Exception $e) {
        error_log("Error getting game stats: " . $e->getMessage());
        return ['player_stats' => [], 'challenge_master' => null];
    }
}

function awardChallengeMaster($gameId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $gameStats = getGameStats($gameId);
        
        if ($gameStats['challenge_master']) {
            $masterId = $gameStats['challenge_master']['player_id'];
            $challengeCount = $gameStats['challenge_master']['challenges_completed'];
            
            // Award 25 points for being challenge master
            $awardPoints = 25;
            updateScore($gameId, $masterId, $awardPoints, $masterId);
            recordPlayerAward($gameId, $masterId, 'challenge_master', $challengeCount, $awardPoints);

            $players = getGamePlayers($gameId);

            foreach($players as $player) {
                if($player['id'] == $masterId) {
                    if($player['fcm_token']) {
                        sendPushNotification($player['fcm_token'], 'Challenge Master!', 'You completed the most challenges and have been awarded 25 points!');
                    }
                }
            }
            
            return [
                'success' => true, 
                'master' => $gameStats['challenge_master'],
                'points_awarded' => $awardPoints
            ];
        }
        
        return ['success' => false, 'message' => 'No clear challenge master'];
        
    } catch (Exception $e) {
        error_log("Error awarding challenge master: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to award challenge master'];
    }
}

function getPlayerAwards($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        $stmt = $pdo->prepare("
            SELECT * FROM player_awards 
            WHERE game_id = ? AND player_id = ?
            ORDER BY awarded_at DESC
        ");
        $stmt->execute([$gameId, $playerId]);
        
        return $stmt->fetchAll();
        
    } catch (Exception $e) {
        error_log("Error getting player awards: " . $e->getMessage());
        return [];
    }
}

function getNextSnapAwardLevel($currentCount) {
    $levels = [5, 12, 25];
    
    foreach ($levels as $level) {
        if ($currentCount < $level) {
            return [
                'next_level' => $level,
                'cards_needed' => $level - $currentCount,
                'points_reward' => calculateSnapAward($level)
            ];
        }
    }
    
    return null; // Max level reached
}

function getNextSpicyAwardLevel($currentCount) {
    $levels = [5, 12, 25];
    
    foreach ($levels as $level) {
        if ($currentCount < $level) {
            return [
                'next_level' => $level,
                'cards_needed' => $level - $currentCount,
                'points_reward' => calculateSpicyAward($level)
            ];
        }
    }
    
    return null; // Max level reached
}

function hasActiveStatusEffects($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Check for active curse effects
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM active_curse_effects 
            WHERE game_id = ? AND player_id = ? AND (expires_at IS NULL OR expires_at > NOW())
        ");
        $stmt->execute([$gameId, $playerId]);
        $curseCount = $stmt->fetchColumn();
        
        // Check for active power effects
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM active_power_effects 
            WHERE game_id = ? AND player_id = ? AND (expires_at IS NULL OR expires_at > NOW())
        ");
        $stmt->execute([$gameId, $playerId]);
        $powerCount = $stmt->fetchColumn();
        
        return [
            'has_curse' => $curseCount > 0,
            'has_power' => $powerCount > 0,
            'total_effects' => $curseCount + $powerCount
        ];
        
    } catch (Exception $e) {
        error_log("Error checking status effects: " . $e->getMessage());
        return ['has_curse' => false, 'has_power' => false, 'total_effects' => 0];
    }
}

function getStatusEffectIcons($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $icons = [];
        
        // Check for active curse effects
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM active_curse_effects 
            WHERE game_id = ? AND player_id = ? AND (expires_at IS NULL OR expires_at > NOW())
        ");
        $stmt->execute([$gameId, $playerId]);
        $curseCount = $stmt->fetchColumn();
        
        if ($curseCount > 0) {
            $icons[] = ['type' => 'curse', 'icon' => '💀', 'color' => '#67597A'];
        }
        
        // Check for active power effects
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM active_power_effects 
            WHERE game_id = ? AND player_id = ? AND (expires_at IS NULL OR expires_at > NOW())
        ");
        $stmt->execute([$gameId, $playerId]);
        $powerCount = $stmt->fetchColumn();
        
        if ($powerCount > 0) {
            $icons[] = ['type' => 'power', 'icon' => '⚡', 'color' => '#68B684'];
        }
        
        // Check for veto wait
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $now = new DateTime('now', $timezone);
        
        $stmt = $pdo->prepare("
            SELECT veto_wait_until FROM players 
            WHERE game_id = ? AND id = ? AND veto_wait_until > ?
        ");
        $stmt->execute([$gameId, $playerId, $now->format('Y-m-d H:i:s')]);
        
        if ($stmt->fetchColumn()) {
            $icons[] = ['type' => 'wait', 'icon' => 'fa-circle-pause', 'color' => '#c62828'];
        }
        
        return $icons;
        
    } catch (Exception $e) {
        error_log("Error getting status effect icons: " . $e->getMessage());
        return [];
    }
}

function clearExpiredEffects($gameId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Clear expired curse effects EXCEPT siphon effects (those are handled by cron)
        $stmt = $pdo->prepare("
            DELETE FROM active_curse_effects 
            WHERE game_id = ? AND expires_at IS NOT NULL AND expires_at <= NOW()
            AND timer_id NOT IN (SELECT id FROM timers WHERE timer_type = 'siphon')
        ");
        $stmt->execute([$gameId]);
        $clearedCurses = $stmt->rowCount();
        
        // Clear expired power effects
        $stmt = $pdo->prepare("
            DELETE FROM active_power_effects 
            WHERE game_id = ? AND expires_at IS NOT NULL AND expires_at <= NOW()
        ");
        $stmt->execute([$gameId]);
        $clearedPowers = $stmt->rowCount();
        
        if ($clearedCurses > 0 || $clearedPowers > 0) {
            error_log("Cleared $clearedCurses curse effects and $clearedPowers power effects for game $gameId");
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("Error clearing expired effects: " . $e->getMessage());
        return false;
    }
}
?>