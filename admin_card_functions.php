<?php
// admin_card_functions.php - Card management functions for Travel Edition

function saveCard($data) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        $id = !empty($data['id']) ? intval($data['id']) : null;
        $cardCategory = $data['card_category'];
        $cardName = trim($data['card_name']);
        $cardDescription = trim($data['card_description']);
        $quantity = intval($data['quantity']) ?: 1;
        
        if (empty($cardName) || empty($cardDescription)) {
            return ['success' => false, 'message' => 'Card name and description are required'];
        }
        
        // Base parameters for all cards
        $baseParams = [$cardName, $cardDescription, $quantity];
        
        if ($id) {
            // Update existing card
            $sql = "UPDATE cards SET card_name = ?, card_description = ?, quantity = ?";
            $params = $baseParams;
            
            // Add category-specific fields
            $categoryFields = getCategorySpecificFields($cardCategory, $data);
            $sql .= $categoryFields['sql'];
            $params = array_merge($params, $categoryFields['params']);
            
            $sql .= " WHERE id = ?";
            $params[] = $id;
            
        } else {
            // Insert new card
            $sql = "INSERT INTO cards (card_name, card_description, quantity, card_category";
            $params = array_merge($baseParams, [$cardCategory]);
            
            // Add category-specific fields
            $categoryFields = getCategorySpecificFields($cardCategory, $data);
            $sql .= $categoryFields['columns'] . ") VALUES (?, ?, ?, ?" . $categoryFields['placeholders'] . ")";
            $params = array_merge($params, $categoryFields['params']);
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // Get the card ID for travel mode assignments
        if ($id) {
            $cardId = $id;
        } else {
            $cardId = $pdo->lastInsertId();
        }

        // Clear existing mode assignments for both new and updated cards
        $stmt = $pdo->prepare("DELETE FROM card_travel_modes WHERE card_id = ?");
        $stmt->execute([$cardId]);

        // Add travel mode assignments
        $travelModes = $_POST['travel_modes'] ?? [];
        if (empty($travelModes)) {
            // If no modes selected, assign to all modes
            $stmt = $pdo->prepare("SELECT id FROM travel_modes");
            $stmt->execute();
            $travelModes = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        foreach ($travelModes as $modeId) {
            $stmt = $pdo->prepare("INSERT INTO card_travel_modes (card_id, mode_id) VALUES (?, ?)");
            $stmt->execute([$cardId, intval($modeId)]);
        }
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Error saving card: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to save card'];
    }
}

function getCategorySpecificFields($category, $data) {
    $fields = [];
    $params = [];
    $columns = [];
    $placeholders = [];
    
    switch ($category) {
        case 'challenge':
            // Challenge: card_points, veto_subtract, veto_steal, veto_wait, veto_snap, veto_spicy
            $challengeFields = [
                'card_points' => 'card_points',
                'veto_subtract' => 'veto_subtract', 
                'veto_steal' => 'veto_steal',
                'veto_wait' => 'veto_wait',
                'veto_snap' => 'veto_snap',
                'veto_spicy' => 'veto_spicy'
            ];
            
            foreach ($challengeFields as $dataKey => $dbColumn) {
                $fields[] = "$dbColumn = ?";
                $columns[] = $dbColumn;
                $placeholders[] = '?';
                $params[] = !empty($data[$dataKey]) ? intval($data[$dataKey]) : null;
            }
            break;
            
        case 'battle':
            // Battle: only card_points
            $fields[] = "card_points = ?";
            $columns[] = 'card_points';
            $placeholders[] = '?';
            $params[] = !empty($data['card_points']) ? intval($data['card_points']) : null;
            break;
            
        case 'curse':
            // Curse: many fields as specified
            $curseFields = [
                'challenge_modify' => ['challenge_modify', 'boolean'],
                'score_modify' => ['score_modify', 'enum'],
                'veto_modify' => ['veto_modify', 'enum'],
                'snap_modify' => ['snap_modify', 'boolean'],
                'spicy_modify' => ['spicy_modify', 'boolean'],
                'wait' => ['wait', 'int'],
                'timer' => ['timer', 'int'],
                'timer_completion_type' => ['timer_completion_type', 'enum'],
                'complete_snap' => ['complete_snap', 'boolean'],
                'complete_spicy' => ['complete_spicy', 'boolean'],
                'roll_dice' => ['roll_dice', 'boolean'],
                'dice_condition' => ['dice_condition', 'enum'],
                'dice_threshold' => ['dice_threshold', 'int'],
                'repeat_count' => ['repeat_count', 'int'],
                'score_add' => ['score_add', 'int'],
                'score_subtract' => ['score_subtract', 'int'],
                'score_steal' => ['score_steal', 'int']
            ];
            
            foreach ($curseFields as $dataKey => $fieldInfo) {
                list($dbColumn, $type) = $fieldInfo;
                $fields[] = "$dbColumn = ?";
                $columns[] = $dbColumn;
                $placeholders[] = '?';
                
                switch ($type) {
                    case 'boolean':
                        $params[] = !empty($data[$dataKey]) ? 1 : 0;
                        break;
                    case 'int':
                        $params[] = !empty($data[$dataKey]) ? intval($data[$dataKey]) : null;
                        break;
                    case 'enum':
                        // Special handling for dice_condition
                        if ($dataKey === 'dice_condition') {
                            if (empty($data[$dataKey]) || $data[$dataKey] === '') {
                                $params[] = null;
                            } else {
                                // Validate against allowed values
                                $validValues = ['even', 'odd', 'doubles', 'above', 'below'];
                                if (in_array($data[$dataKey], $validValues)) {
                                    $params[] = $data[$dataKey];
                                } else {
                                    error_log("Invalid dice_condition: " . $data[$dataKey]);
                                    $params[] = null;
                                }
                            }
                        } else {
                            // Handle other enum fields
                            $defaultValues = [
                                'score_modify' => 'none',
                                'veto_modify' => 'none', 
                                'timer_completion_type' => 'timer_expires'
                            ];
                            $params[] = !empty($data[$dataKey]) ? $data[$dataKey] : ($defaultValues[$dataKey] ?? 'none');
                        }
                        break;
                }
            }
            break;
            
        case 'power':
            // Power: extensive field list
            $powerFields = [
                'power_score_add' => ['power_score_add', 'int'],
                'power_score_subtract' => ['power_score_subtract', 'int'],
                'power_score_steal' => ['power_score_steal', 'int'],
                'power_challenge_modify' => ['power_challenge_modify', 'boolean'],
                'power_snap_modify' => ['power_snap_modify', 'boolean'],
                'power_spicy_modify' => ['power_spicy_modify', 'boolean'],
                'power_wait' => ['power_wait', 'int'],
                'power_score_modify' => ['power_score_modify', 'enum'],
                'power_veto_modify' => ['power_veto_modify', 'enum'],
                'target_opponent' => ['target_opponent', 'boolean'],
                'skip_challenge' => ['skip_challenge', 'boolean'],
                'clear_curse' => ['clear_curse', 'boolean'],
                'shuffle_daily_deck' => ['shuffle_daily_deck', 'boolean'],
                'deck_peek' => ['deck_peek', 'boolean'],
                'card_swap' => ['card_swap', 'boolean'],
                'bypass_expiration' => ['bypass_expiration', 'boolean']
            ];
            
            foreach ($powerFields as $dataKey => $fieldInfo) {
                list($dbColumn, $type) = $fieldInfo;
                $fields[] = "$dbColumn = ?";
                $columns[] = $dbColumn;
                $placeholders[] = '?';
                
                switch ($type) {
                    case 'boolean':
                        $params[] = !empty($data[$dataKey]) ? 1 : 0;
                        break;
                    case 'int':
                        $params[] = !empty($data[$dataKey]) ? intval($data[$dataKey]) : null;
                        break;
                    case 'enum':
                        $params[] = !empty($data[$dataKey]) ? $data[$dataKey] : 'none';
                        break;
                }
            }
            break;
            
        case 'snap':
        case 'spicy':
            // Snap & Spicy: card_points, veto_subtract, veto_steal, veto_wait, male, female
            $snapSpicyFields = [
                'card_points' => 'card_points',
                'veto_subtract' => 'veto_subtract',
                'veto_steal' => 'veto_steal', 
                'veto_wait' => 'veto_wait',
                'male' => 'male',
                'female' => 'female'
            ];
            
            foreach ($snapSpicyFields as $dataKey => $dbColumn) {
                $fields[] = "$dbColumn = ?";
                $columns[] = $dbColumn;
                $placeholders[] = '?';
                
                if (in_array($dataKey, ['male', 'female'])) {
                    $params[] = !empty($data[$dataKey]) ? 1 : 0;
                } else {
                    $params[] = !empty($data[$dataKey]) ? intval($data[$dataKey]) : null;
                }
            }
            break;
    }
    
    return [
        'sql' => $fields ? ', ' . implode(', ', $fields) : '',
        'columns' => $columns ? ', ' . implode(', ', $columns) : '',
        'placeholders' => $placeholders ? ', ' . implode(', ', $placeholders) : '',
        'params' => $params
    ];
}

function getCardsByCategory($category) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("SELECT * FROM cards WHERE card_category = ? ORDER BY card_name ASC");
        $stmt->execute([$category]);
        $cards = $stmt->fetchAll();
        
        // Add travel mode icons to each card
        foreach ($cards as &$card) {
            $card['travel_mode_icons'] = getCardTravelModeIcons($card['id']);
        }
        
        return $cards;
    } catch (Exception $e) {
        error_log("Error getting cards: " . $e->getMessage());
        return [];
    }
}

function getCardById($id) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("SELECT * FROM cards WHERE id = ?");
        $stmt->execute([$id]);
        $card = $stmt->fetch();
        
        if ($card) {
            $card['travel_modes'] = getCardTravelModes($card['id']);
        }
        
        return $card;
    } catch (Exception $e) {
        error_log("Error getting card: " . $e->getMessage());
        return null;
    }
}

function getCardTravelModes($cardId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("SELECT mode_id FROM card_travel_modes WHERE card_id = ?");
        $stmt->execute([$cardId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return [];
    }
}

function getCardTravelModeIcons($cardId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Get total travel modes count
        $stmt = $pdo->query("SELECT COUNT(*) FROM travel_modes");
        $totalModes = $stmt->fetchColumn();
        
        // Get card's travel modes with icons
        $stmt = $pdo->prepare("
            SELECT tm.mode_icon 
            FROM card_travel_modes ctm
            JOIN travel_modes tm ON ctm.mode_id = tm.id
            WHERE ctm.card_id = ?
        ");
        $stmt->execute([$cardId]);
        $cardModes = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (count($cardModes) === $totalModes) {
            return '<i class="fa-solid fa-globe" title="All modes"></i>';
        } else {
            $icons = array_map(function($icon) {
                return '<i class="fa-solid ' . htmlspecialchars($icon) . '"></i>';
            }, $cardModes);
            return implode(' ', $icons);
        }
    } catch (Exception $e) {
        return '';
    }
}

function deleteCard($id) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("DELETE FROM cards WHERE id = ?");
        $stmt->execute([$id]);
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Error deleting card: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to delete card'];
    }
}

function getCardCounts($category) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        if (in_array($category, ['snap', 'spicy'])) {
            $stmt = $pdo->prepare("
                SELECT 
                    SUM(CASE WHEN male = 1 THEN quantity ELSE 0 END) as male_count,
                    SUM(CASE WHEN female = 1 THEN quantity ELSE 0 END) as female_count,
                    SUM(CASE WHEN male = 1 AND female = 1 THEN quantity ELSE 0 END) as both_count
                FROM cards WHERE card_category = ?
            ");
            $stmt->execute([$category]);
            return $stmt->fetch();
        } else {
            $stmt = $pdo->prepare("SELECT SUM(quantity) as total FROM cards WHERE card_category = ?");
            $stmt->execute([$category]);
            $total = $stmt->fetchColumn();
            return ['total' => $total ?: 0];
        }
    } catch (Exception $e) {
        return ['total' => 0];
    }
}

// Helper function to get enum options for dropdowns
function getEnumOptions($field) {
    switch ($field) {
        case 'score_modify':
        case 'power_score_modify':
            return [
                'none' => 'None',
                'half' => 'Half Points',
                'double' => 'Double Points', 
                'zero' => 'Zero Points',
                'extra_point' => 'Extra Point',
                'challenge_reward_opponent' => 'Reward Opponent'
            ];
            
        case 'veto_modify':
        case 'power_veto_modify':
            return [
                'none' => 'None',
                'double' => 'Double Penalty',
                'skip' => 'Skip Penalty',
                'opponent_reward' => 'Opponent Reward'
            ];
            
        case 'timer_completion_type':
            return [
                'timer_expires' => 'Timer Expires',
                'first_trigger' => 'First Trigger',
                'first_trigger_any' => 'First Trigger Any'
            ];
            
        case 'dice_condition':
            return [
                'even' => 'Even',
                'odd' => 'Odd',
                'doubles' => 'Doubles',
                'above' => 'Above Threshold',
                'below' => 'Below Threshold'
            ];
            
        default:
            return [];
    }
}
?>