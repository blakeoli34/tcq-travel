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
        5 => 5,   // 5 snap cards = 5 bonus points
        10 => 10, // 10 snap cards = 10 bonus points
        15 => 15, // 15 snap cards = 15 bonus points
        20 => 20, // 20 snap cards = 20 bonus points
        25 => 25, // 25 snap cards = 25 bonus points
        30 => 30, // 30 snap cards = 30 bonus points
        40 => 40, // 40 snap cards = 40 bonus points
        50 => 50  // 50 snap cards = 50 bonus points
    ];
    
    return $awards[$snapCount] ?? 0;
}

function calculateSpicyAward($spicyCount) {
    // Award structure for spicy cards
    $awards = [
        3 => 5,   // 3 spicy cards = 5 bonus points
        6 => 10,  // 6 spicy cards = 10 bonus points
        10 => 15, // 10 spicy cards = 15 bonus points
        15 => 20, // 15 spicy cards = 20 bonus points
        20 => 25, // 20 spicy cards = 25 bonus points
        25 => 30, // 25 spicy cards = 30 bonus points
        30 => 40, // 30 spicy cards = 40 bonus points
        40 => 50  // 40 spicy cards = 50 bonus points
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
            
            // Award 10 points for being challenge master
            $awardPoints = 10;
            updateScore($gameId, $masterId, $awardPoints, $masterId);
            recordPlayerAward($gameId, $masterId, 'challenge_master', $challengeCount, $awardPoints);
            
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
    $levels = [5, 10, 15, 20, 25, 30, 40, 50];
    
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
    $levels = [3, 6, 10, 15, 20, 25, 30, 40];
    
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
        $effects = hasActiveStatusEffects($gameId, $playerId);
        $icons = [];
        
        if ($effects['has_curse']) {
            $icons[] = ['type' => 'curse', 'icon' => '💀', 'color' => '#67597A'];
        }
        
        if ($effects['has_power']) {
            $icons[] = ['type' => 'power', 'icon' => '⚡', 'color' => '#68B684'];
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
        
        // Clear expired curse effects
        $stmt = $pdo->prepare("
            DELETE FROM active_curse_effects 
            WHERE game_id = ? AND expires_at IS NOT NULL AND expires_at <= NOW()
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