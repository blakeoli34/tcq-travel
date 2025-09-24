<?php
// admin.php - Admin interface for TCQ Travel Edition
require_once 'config.php';
require_once 'functions.php';
require_once 'admin_card_functions.php';

session_start();

// Handle login
if ($_POST && isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (authenticateAdmin($username, $password)) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: admin.php');
        exit;
    } else {
        $loginError = 'Invalid credentials';
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// Check if logged in
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    showLoginForm($loginError ?? null);
    exit;
}

// Handle card management actions
if ($_POST && isset($_POST['action'])) {
    switch($_POST['action']) {    
        case 'get_card':
            $id = intval($_POST['id']);
            $card = getCardById($id);
            echo json_encode(['success' => true, 'card' => $card]);
            exit;
            
        case 'save_card':
            $result = saveCard($_POST);
            echo json_encode($result);
            exit;
            
        case 'delete_card':
            $id = intval($_POST['id']);
            $result = deleteCard($id);
            echo json_encode($result);
            exit;

        case 'get_cards':
            $category = $_POST['category'];
            $cards = getCardsByCategory($category);
            $counts = getCardCounts($category);
            echo json_encode(['success' => true, 'cards' => $cards, 'counts' => $counts]);
            exit;

        case 'get_travel_mode':
            try {
                $id = intval($_POST['id']);
                $pdo = Config::getDatabaseConnection();
                $stmt = $pdo->prepare("SELECT * FROM travel_modes WHERE id = ?");
                $stmt->execute([$id]);
                $mode = $stmt->fetch();
                
                if ($mode) {
                    echo json_encode(['success' => true, 'mode' => $mode]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Travel mode not found']);
                }
            } catch (Exception $e) {
                error_log("Error getting travel mode: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Failed to get travel mode']);
            }
            exit;

        case 'save_travel_mode':
            try {
                $pdo = Config::getDatabaseConnection();
                
                $id = !empty($_POST['id']) ? intval($_POST['id']) : null;
                $modeTitle = trim($_POST['mode_title']);
                $modeDescription = trim($_POST['mode_description']);
                $modeIcon = trim($_POST['mode_icon']);
                
                if (empty($modeTitle)) {
                    echo json_encode(['success' => false, 'message' => 'Mode title is required']);
                    exit;
                }
                
                if ($id) {
                    $stmt = $pdo->prepare("UPDATE travel_modes SET mode_title = ?, mode_description = ?, mode_icon = ? WHERE id = ?");
                    $stmt->execute([$modeTitle, $modeDescription, $modeIcon, $id]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO travel_modes (mode_title, mode_description, mode_icon) VALUES (?, ?, ?)");
                    $stmt->execute([$modeTitle, $modeDescription, $modeIcon]);
                }
                
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                error_log("Error saving travel mode: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Failed to save travel mode']);
            }
            exit;

        case 'delete_travel_mode':
            try {
                $id = intval($_POST['id']);
                $pdo = Config::getDatabaseConnection();
                $stmt = $pdo->prepare("DELETE FROM travel_modes WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                error_log("Error deleting travel mode: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Failed to delete travel mode']);
            }
            exit;
    }
}

// Handle generate invite code
if ($_POST && isset($_POST['generate_code'])) {
    $code = generateNewInviteCode();
    if(!$code) {
        $code = 'error';
    }
    header('Location: admin.php?code=' . $code);
}

if(isset($_GET['code'])) {
    if($_GET['code'] !== 'error') {
        $message = 'Generated invite code: <strong>' . $_GET['code'] . '</strong>';
    } else {
        $message = 'Failed to generate code';
    }
}

// Handle delete invite code
if ($_POST && isset($_POST['delete_code'])) {
    $codeId = intval($_POST['code_id']);
    $result = deleteInviteCode($codeId);
    if($result) {
        header('Location: admin.php?deletecode=1');
    } else {
        header('Location: admin.php?deletecode=0');
    }
}

if(isset($_GET['deletecode'])) {
    if($_GET['deletecode'] === '1') {
        $message = 'Invite code deleted successfully';
    } else {
        $message = 'Failed to delete invite code';
    }
}

// Handle delete game
if ($_POST && isset($_POST['delete_game'])) {
    $gameId = intval($_POST['game_id']);
    $result = deleteGame($gameId);
    if($result) {
        header('Location: admin.php?deletegame=1');
    } else {
        header('Location: admin.php?deletegame=0');
    }
}

if(isset($_GET['deletegame'])) {
    if($_GET['deletegame'] === '1') {
        $message = 'Game deleted successfully';
    } else {
        $message = 'Failed to delete game';
    }
}

// Handle save rules
if ($_POST && isset($_POST['save_rules'])) {
    try {
        $content = $_POST['rules_content'] ?? '';
        
        // Check if rules exist
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->query("SELECT COUNT(*) FROM game_rules");
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            $stmt = $pdo->prepare("UPDATE game_rules SET content = ? WHERE id = (SELECT * FROM (SELECT MIN(id) FROM game_rules) AS tmp)");
        } else {
            $stmt = $pdo->prepare("INSERT INTO game_rules (content) VALUES (?)");
        }
        
        $stmt->execute([$content]);
        $message = 'Rules saved successfully';
        
    } catch (Exception $e) {
        error_log("Error saving rules: " . $e->getMessage());
        $message = 'Failed to save rules';
    }
    
    header('Location: admin.php?rules_saved=1');
    exit;
}

if (isset($_GET['rules_saved'])) {
    $message = 'Rules saved successfully';
}

// Get statistics
$stats = getGameStatistics();

$rulesContent = '';
try {
    $pdo = Config::getDatabaseConnection();
    $stmt = $pdo->query("SELECT content FROM game_rules ORDER BY id LIMIT 1");
    $rules = $stmt->fetch();
    $rulesContent = $rules ? $rules['content'] : '';
} catch (Exception $e) {
    error_log("Error loading rules: " . $e->getMessage());
}

try {
    $stmt = $pdo->query("
        SELECT tm.*, 
               COALESCE(SUM(c.quantity), 0) as total_cards,
               COALESCE(SUM(CASE WHEN c.card_category = 'challenge' THEN c.quantity ELSE 0 END), 0) as challenge_count,
               COALESCE(SUM(CASE WHEN c.card_category = 'curse' THEN c.quantity ELSE 0 END), 0) as curse_count,
               COALESCE(SUM(CASE WHEN c.card_category = 'power' THEN c.quantity ELSE 0 END), 0) as power_count,
               COALESCE(SUM(CASE WHEN c.card_category = 'battle' THEN c.quantity ELSE 0 END), 0) as battle_count,
               COALESCE(SUM(CASE WHEN c.card_category = 'snap' AND c.male = 1 THEN c.quantity ELSE 0 END), 0) as snap_male_count,
               COALESCE(SUM(CASE WHEN c.card_category = 'snap' AND c.female = 1 THEN c.quantity ELSE 0 END), 0) as snap_female_count,
               COALESCE(SUM(CASE WHEN c.card_category = 'spicy' AND c.male = 1 THEN c.quantity ELSE 0 END), 0) as spicy_male_count,
               COALESCE(SUM(CASE WHEN c.card_category = 'spicy' AND c.female = 1 THEN c.quantity ELSE 0 END), 0) as spicy_female_count
        FROM travel_modes tm
        LEFT JOIN card_travel_modes ctm ON tm.id = ctm.mode_id
        LEFT JOIN cards c ON ctm.card_id = c.id
        GROUP BY tm.id
        ORDER BY tm.mode_title
    ");
    $travelModes = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error loading travel modes: " . $e->getMessage());
    $travelModes = [];
}


function authenticateAdmin($username, $password) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("SELECT password_hash FROM admin_users WHERE username = ?");
        $stmt->execute([$username]);
        $hash = $stmt->fetchColumn();
        
        return $hash && password_verify($password, $hash);
    } catch (Exception $e) {
        error_log("Admin auth error: " . $e->getMessage());
        return false;
    }
}

function generateNewInviteCode() {
    try {
        $pdo = Config::getDatabaseConnection();
        $code = Config::generateInviteCode();
        
        $stmt = $pdo->prepare("INSERT INTO invite_codes (code) VALUES (?)");
        $stmt->execute([$code]);
        
        return $code;
    } catch (Exception $e) {
        error_log("Error generating invite code: " . $e->getMessage());
        return false;
    }
}

function deleteInviteCode($codeId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Check if code is unused
        $stmt = $pdo->prepare("SELECT is_used FROM invite_codes WHERE id = ?");
        $stmt->execute([$codeId]);
        $code = $stmt->fetch();
        
        if (!$code || $code['is_used']) {
            return false; // Can't delete used codes
        }
        
        $stmt = $pdo->prepare("DELETE FROM invite_codes WHERE id = ? AND is_used = FALSE");
        $stmt->execute([$codeId]);
        
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        error_log("Error deleting invite code: " . $e->getMessage());
        return false;
    }
}

function deleteGame($gameId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $pdo->beginTransaction();
        
        // Delete related records first (due to foreign key constraints)
        $stmt = $pdo->prepare("DELETE FROM score_history WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        $stmt = $pdo->prepare("DELETE FROM timers WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        $stmt = $pdo->prepare("DELETE FROM daily_decks WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        $stmt = $pdo->prepare("DELETE FROM daily_deck_slots WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        $stmt = $pdo->prepare("DELETE FROM player_cards WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        $stmt = $pdo->prepare("DELETE FROM player_stats WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        $stmt = $pdo->prepare("DELETE FROM player_awards WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        $stmt = $pdo->prepare("DELETE FROM active_curse_effects WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        $stmt = $pdo->prepare("DELETE FROM active_power_effects WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        $stmt = $pdo->prepare("DELETE FROM players WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        // Get invite code to mark as unused
        $stmt = $pdo->prepare("SELECT invite_code FROM games WHERE id = ?");
        $stmt->execute([$gameId]);
        $inviteCode = $stmt->fetchColumn();
        
        // Delete the game
        $stmt = $pdo->prepare("DELETE FROM games WHERE id = ?");
        $stmt->execute([$gameId]);
        
        // Mark invite code as unused if game is deleted
        if ($inviteCode) {
            $stmt = $pdo->prepare("UPDATE invite_codes SET is_used = FALSE WHERE code = ?");
            $stmt->execute([$inviteCode]);
        }
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error deleting game: " . $e->getMessage());
        return false;
    }
}

function getGameStatistics() {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Total games
        $stmt = $pdo->query("SELECT COUNT(*) FROM games");
        $totalGames = $stmt->fetchColumn();
        
        // Active games
        $stmt = $pdo->query("SELECT COUNT(*) FROM games WHERE status = 'active'");
        $activeGames = $stmt->fetchColumn();
        
        // Completed games
        $stmt = $pdo->query("SELECT COUNT(*) FROM games WHERE status = 'completed'");
        $completedGames = $stmt->fetchColumn();
        
        // Waiting games
        $stmt = $pdo->query("SELECT COUNT(*) FROM games WHERE status = 'waiting'");
        $waitingGames = $stmt->fetchColumn();
        
        // Total players
        $stmt = $pdo->query("SELECT COUNT(*) FROM players");
        $totalPlayers = $stmt->fetchColumn();
        
        // Unused invite codes
        $stmt = $pdo->query("SELECT COUNT(*) FROM invite_codes WHERE is_used = FALSE");
        $unusedCodes = $stmt->fetchColumn();
        
        // Recent games with player details including device IDs
        $stmt = $pdo->query("
            SELECT g.*, 
                COUNT(p.id) as player_count,
                GROUP_CONCAT(
                    CONCAT(
                        p.first_name, ' (', p.gender, ') - Device: ', 
                        SUBSTRING(p.device_id, 1, 8), '...'
                    ) 
                    ORDER BY p.joined_at 
                    SEPARATOR ' | '
                ) as players_with_devices
            FROM games g
            LEFT JOIN players p ON g.id = p.game_id
            GROUP BY g.id
            ORDER BY g.created_at DESC
            LIMIT 20
        ");
        $recentGames = $stmt->fetchAll();

        // Unused invite codes
        $stmt = $pdo->query("
            SELECT * FROM invite_codes 
            WHERE is_used = FALSE 
            ORDER BY created_at DESC
        ");
        $unusedCodesList = $stmt->fetchAll();
        
        // Active players with last activity
        $stmt = $pdo->query("
            SELECT p.*, g.invite_code, g.status as game_status,
                   SUBSTRING(p.device_id, 1, 12) as short_device_id
            FROM players p
            JOIN games g ON p.game_id = g.id
            WHERE g.status IN ('active', 'waiting')
            ORDER BY p.joined_at DESC
        ");
        $activePlayers = $stmt->fetchAll();
        
        return [
            'totalGames' => $totalGames,
            'activeGames' => $activeGames,
            'completedGames' => $completedGames,
            'waitingGames' => $waitingGames,
            'totalPlayers' => $totalPlayers,
            'unusedCodes' => $unusedCodes,
            'recentGames' => $recentGames,
            'unusedCodesList' => $unusedCodesList,
            'activePlayers' => $activePlayers
        ];
    } catch (Exception $e) {
        error_log("Error getting statistics: " . $e->getMessage());
        return [];
    }
}

function showLoginForm($error = null) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - TCQ Travel Edition</title>
    <link rel="stylesheet" href="https://use.typekit.net/oqm2ymj.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'museo-sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, <?= Config::COLOR_PINK ?> 0%, <?= Config::COLOR_BLUE ?> 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
        }
        
        h1 {
            text-align: center;
            margin-bottom: 30px;
            color: #333;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            color: #555;
            font-weight: 500;
        }
        
        input {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 10px;
            font-size: 16px;
        }
        
        button {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, <?= Config::COLOR_PINK ?>, <?= Config::COLOR_BLUE ?>);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
        }
        
        .error {
            background: #ffebee;
            color: #c62828;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>Travel Edition Admin</h1>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required>
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit" name="login">Login</button>
        </form>
    </div>
</body>
</html>
<?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/aquawolf04/font-awesome-pro@5cd1511/css/all.css">
    <title>Admin Dashboard - TCQ Travel Edition</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
        }
        
        .header {
            background: white;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            color: #333;
        }
        
        .logout-btn {
            padding: 10px 20px;
            background: #dc3545;
            color: white;
            text-decoration: none;
            border-radius: 5px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: 900;
            color: <?= Config::COLOR_BLUE ?>;
            margin-bottom: 10px;
        }
        
        .stat-label {
            color: #666;
            font-weight: 500;
        }
        
        .section {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            overflow-x: auto;
        }
        
        .section h2 {
            margin-bottom: 20px;
            color: #333;
        }
        
        .generate-form {
            display: flex;
            gap: 15px;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .btn {
            padding: 12px 24px;
            background: linear-gradient(135deg, <?= Config::COLOR_PINK ?>, <?= Config::COLOR_BLUE ?>);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }
        
        .message {
            padding: 15px;
            background: #d4edda;
            color: #155724;
            border-radius: 8px;
            margin-bottom: 20px;
            position: fixed;
            left: -100%;
            opacity: 0;
            animation: notify 6s ease;
        }

        @keyframes notify {
            0%, 100% {
                left: -100%;
                opacity: 0;
            }
            16%, 84% {
                left: 20px;
                opacity: 1;
            }
        }
        
        .games-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .games-table th,
        .games-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .games-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        
        .status-waiting {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-completed {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-small {
            padding: 5px 10px;
            font-size: 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #212529;
        }
        
        .btn-warning:hover {
            background: #e0a800;
        }

        .code-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        
        .code-details {
            flex: 1;
        }
        
        .code-value {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            font-size: 18px;
            color: #333;
        }
        
        .code-meta {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        
        .player-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        
        .player-details {
            flex: 1;
        }
        
        .player-name {
            font-weight: bold;
            font-size: 16px;
            color: #333;
        }
        
        .player-meta {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        
        .device-id {
            font-family: 'Courier New', monospace;
            background: #e9ecef;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
        }
        
        .card-tabs {
            display: flex;
            border-bottom: 2px solid #eee;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .card-tab {
            padding: 12px 24px;
            background: none;
            border: none;
            color: #666;
            font-weight: 600;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
        }
        
        .card-tab.active {
            color: #333;
            border-bottom-color: <?= Config::COLOR_BLUE ?>;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .cards-list {
            display: flex;
            flex-flow: row wrap;
            align-items: stretch;
            justify-content: space-between;
        }
        
        .card-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 16px;
            border-left: 4px solid <?= Config::COLOR_BLUE ?>;
            width: calc(50% - 8px);
        }

        @media screen and (max-width: 1000px) {
            .card-item {
                width: 100%;
            }
        }
        
        .card-item h4 {
            margin-bottom: 10px;
            color: #333;
        }
        
        .card-item p {
            color: #666;
            margin-bottom: 15px;
            line-height: 1.4;
        }
        
        .card-meta {
            display: flex;
            gap: 15px;
            font-size: 12px;
            color: #888;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }
        
        .card-actions {
            display: flex;
            gap: 10px;
        }
        
        .confirm-dialog {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .confirm-dialog.active {
            display: flex;
        }
        
        .confirm-content {
            background: white;
            padding: 30px;
            border-radius: 15px;
            text-align: center;
            max-width: 600px;
            margin: 20px;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .confirm-buttons {
            display: flex;
            gap: 15px;
            margin-top: 20px;
            justify-content: center;
        }
        
        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 10px;
            font-size: 16px;
            -webkit-appearance: none;
            appearance: none;
            background: transparent;
            color: #333;
            outline: none;
            font-family: inherit;
        }
        
        .form-row {
            display: flex;
            gap: 15px;
        }
        
        .form-row .form-group {
            flex: 1;
        }
        
        .checkbox-group {
            display: flex;
            gap: 20px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .checkbox-item input[type="checkbox"] {
            width: auto;
        }
        
        .btn-secondary {
            background: #6b7280;
        }

        .category-challenge { border-left-color: #F39237; }
        .category-curse { border-left-color: #67597A; }
        .category-power { border-left-color: #68B684; }
        .category-battle { border-left-color: #A1CDF4; }
        .category-snap { border-left-color: <?= Config::COLOR_PINK ?>; }
        .category-spicy { border-left-color: <?= Config::COLOR_BLUE ?>; }
    </style>
</head>
<body>
    <div class="header">
        <h1>TCQ Travel Edition Admin</h1>
        <a href="?logout=1" class="logout-btn">Logout</a>
    </div>
    
    <div class="container">
        <?php if (isset($message)): ?>
            <div class="message"><?= $message ?></div>
        <?php endif; ?>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= $stats['totalGames'] ?></div>
                <div class="stat-label">Total Games</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['activeGames'] ?></div>
                <div class="stat-label">Active Games</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['waitingGames'] ?></div>
                <div class="stat-label">Waiting Games</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['completedGames'] ?></div>
                <div class="stat-label">Completed Games</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['totalPlayers'] ?></div>
                <div class="stat-label">Total Players</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['unusedCodes'] ?></div>
                <div class="stat-label">Unused Codes</div>
            </div>
        </div>
        
        <!-- Generate Invite Code -->
        <div class="section">
            <h2>Generate Invite Code</h2>
            <form method="POST" class="generate-form">
                <button type="submit" name="generate_code" class="btn">Generate New Invite Code</button>
            </form>
        </div>

        <!-- Unused Invite Codes -->
        <div class="section">
            <h2>Unused Invite Codes (<?= count($stats['unusedCodesList']) ?>)</h2>
            
            <?php if (empty($stats['unusedCodesList'])): ?>
                <p style="color: #666; text-align: center; padding: 20px;">No unused invite codes</p>
            <?php else: ?>
                <?php foreach ($stats['unusedCodesList'] as $code): ?>
                    <div class="code-item">
                        <div class="code-details">
                            <div class="code-value"><?= htmlspecialchars($code['code']) ?></div>
                            <div class="code-meta">
                                Created: <?= date('M j, Y g:i A', strtotime($code['created_at'])) ?>
                                <?php if ($code['expires_at']): ?>
                                    | Expires: <?= date('M j, Y g:i A', strtotime($code['expires_at'])) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="actions">
                            <button class="btn-small btn-danger" onclick="confirmDeleteCode(<?= $code['id'] ?>, '<?= htmlspecialchars($code['code']) ?>')">
                                Delete
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Card Management -->
        <div class="section">
            <h2>Card Management</h2>
            
            <!-- Card Category Tabs -->
            <div class="card-tabs">
                <button class="card-tab active" onclick="showCardCategory('challenge')">Challenge Cards</button>
                <button class="card-tab" onclick="showCardCategory('curse')">Curse Cards</button>
                <button class="card-tab" onclick="showCardCategory('power')">Power Cards</button>
                <button class="card-tab" onclick="showCardCategory('battle')">Battle Cards</button>
                <button class="card-tab" onclick="showCardCategory('snap')">Snap Cards</button>
                <button class="card-tab" onclick="showCardCategory('spicy')">Spicy Cards</button>
            </div>
            
            <div class="card-type-content" id="challenge-cards">
                <div class="card-header">
                    <h3>Challenge Cards</h3>
                    <button class="btn" onclick="openCardModal('challenge')">Add New Challenge Card</button>
                </div>
                <div class="cards-list" id="challenge-cards-list">
                    <!-- Cards will be loaded here -->
                </div>
            </div>
            
            <div class="card-type-content" id="curse-cards" style="display: none;">
                <div class="card-header">
                    <h3>Curse Cards</h3>
                    <button class="btn" onclick="openCardModal('curse')">Add New Curse Card</button>
                </div>
                <div class="cards-list" id="curse-cards-list">
                    <!-- Cards will be loaded here -->
                </div>
            </div>
            
            <div class="card-type-content" id="power-cards" style="display: none;">
                <div class="card-header">
                    <h3>Power Cards</h3>
                    <button class="btn" onclick="openCardModal('power')">Add New Power Card</button>
                </div>
                <div class="cards-list" id="power-cards-list">
                    <!-- Cards will be loaded here -->
                </div>
            </div>
            
            <div class="card-type-content" id="battle-cards" style="display: none;">
                <div class="card-header">
                    <h3>Battle Cards</h3>
                    <button class="btn" onclick="openCardModal('battle')">Add New Battle Card</button>
                </div>
                <div class="cards-list" id="battle-cards-list">
                    <!-- Cards will be loaded here -->
                </div>
            </div>
            
            <div class="card-type-content" id="snap-cards" style="display: none;">
                <div class="card-header">
                    <h3>Snap Cards</h3>
                    <button class="btn" onclick="openCardModal('snap')">Add New Snap Card</button>
                </div>
                <div class="cards-list" id="snap-cards-list">
                    <!-- Cards will be loaded here -->
                </div>
            </div>
            
            <div class="card-type-content" id="spicy-cards" style="display: none;">
                <div class="card-header">
                    <h3>Spicy Cards</h3>
                    <button class="btn" onclick="openCardModal('spicy')">Add New Spicy Card</button>
                </div>
                <div class="cards-list" id="spicy-cards-list">
                    <!-- Cards will be loaded here -->
                </div>
            </div>
        </div>

        <!-- Travel Modes Management -->
        <div class="section">
            <h2>Travel Modes Management</h2>
            
            <div class="card-header">
                <h3>Travel Modes</h3>
                <button class="btn" onclick="openTravelModeModal()">Add Travel Mode</button>
            </div>
            
            <?php if (empty($travelModes)): ?>
                <p style="color: #666; text-align: center; padding: 20px;">No travel modes created</p>
            <?php else: ?>
                <?php foreach ($travelModes as $mode): ?>
                    <div class="code-item">
                        <div class="code-details">
                            <div class="code-value">
                                <i class="fa-solid <?= htmlspecialchars($mode['mode_icon']) ?>"></i>
                                <?= htmlspecialchars($mode['mode_title']) ?>
                            </div>
                            <div class="code-meta">
                                <?= htmlspecialchars($mode['mode_description']) ?>
                                <br>
                                <strong>Cards:</strong> 
                                Challenge: <?= $mode['challenge_count'] ?> | 
                                Curse: <?= $mode['curse_count'] ?> | 
                                Power: <?= $mode['power_count'] ?> | 
                                Battle: <?= $mode['battle_count'] ?> | 
                                Snap: M<?= $mode['snap_male_count'] ?>/F<?= $mode['snap_female_count'] ?> | 
                                Spicy: M<?= $mode['spicy_male_count'] ?>/F<?= $mode['spicy_female_count'] ?> 
                                (Total: <?= $mode['total_cards'] ?>)
                            </div>
                        </div>
                        <div class="actions">
                            <button class="btn-small btn-warning" onclick="openTravelModeModal(<?= $mode['id'] ?>)">Edit</button>
                            <button class="btn-small btn-danger" onclick="confirmDeleteTravelMode(<?= $mode['id'] ?>, '<?= htmlspecialchars($mode['mode_title']) ?>')">
                                Delete
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Active Players -->
        <div class="section">
            <h2>Active Players (<?= count($stats['activePlayers']) ?>)</h2>
            
            <?php if (empty($stats['activePlayers'])): ?>
                <p style="color: #666; text-align: center; padding: 20px;">No active players</p>
            <?php else: ?>
                <?php foreach ($stats['activePlayers'] as $player): ?>
                    <div class="player-item">
                        <div class="player-details">
                            <div class="player-name">
                                <?= htmlspecialchars($player['first_name']) ?> 
                                (<?= ucfirst($player['gender']) ?>) - Score: <?= $player['score'] ?>
                            </div>
                            <div class="player-meta">
                                Game: <strong><?= htmlspecialchars($player['invite_code']) ?></strong> 
                                (<?= ucfirst($player['game_status']) ?>) | 
                                Device: <span class="device-id"><?= htmlspecialchars($player['device_id']) ?></span> |
                                Joined: <?= date('M j, Y g:i A', strtotime($player['joined_at'])) ?>
                                <?php if ($player['fcm_token']): ?>
                                    | <span style="color: #28a745;">📱 Notifications enabled</span>
                                <?php else: ?>
                                    | <span style="color: #dc3545;">🔕 No notifications</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Recent Games -->
        <div class="section">
            <h2>Recent Games (<?= count($stats['recentGames']) ?>)</h2>
            
            <table class="games-table">
                <thead>
                    <tr>
                        <th>Invite Code</th>
                        <th>Status</th>
                        <th>Players & Devices</th>
                        <th>Duration</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats['recentGames'] as $game): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($game['invite_code']) ?></strong></td>
                            <td>
                                <span class="status-badge status-<?= $game['status'] ?>">
                                    <?= ucfirst($game['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?= $game['players_with_devices'] ? htmlspecialchars($game['players_with_devices']) : 'None' ?>
                                (<?= $game['player_count'] ?>/2)
                            </td>
                            <td>
                                <?= $game['duration_days'] ? $game['duration_days'] . ' days' : 'Not set' ?>
                            </td>
                            <td><?= date('M j, Y g:i A', strtotime($game['created_at'])) ?></td>
                            <td>
                                <div class="actions">
                                    <button class="btn-small btn-danger" onclick="confirmDeleteGame(<?= $game['id'] ?>, '<?= htmlspecialchars($game['invite_code']) ?>')">
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Game Rules Management -->
        <div class="section">
            <h2>Game Rules</h2>            
            <form method="POST" action="admin.php">
                <div class="form-group">
                    <label for="rulesContent">Rules Content (HTML)</label>
                    <textarea id="rulesContent" name="rules_content" rows="15" style="width: 100%; font-family: monospace;"><?= htmlspecialchars($rulesContent) ?></textarea>
                </div>
                <button type="submit" name="save_rules" class="btn">Save Rules</button>
            </form>
        </div>
    </div>

    <!-- Card Management Modal -->
    <div class="confirm-dialog" id="cardModal">
        <div class="confirm-content">
            <h3 id="cardModalTitle">Add Card</h3>
            <form id="cardForm" class="modal-form">
                <input type="hidden" id="cardId">
                <input type="hidden" id="cardCategory">

                <!-- Travel Modes Selection -->
                <label>Available in Travel Modes</label>
                <div class="checkbox-group" id="travelModesCheckboxes">
                    <?php foreach ($travelModes as $mode): ?>
                        <div class="checkbox-item">
                            <input type="checkbox" id="mode_<?= $mode['id'] ?>" name="travel_modes[]" value="<?= $mode['id'] ?>" checked>
                            <label for="mode_<?= $mode['id'] ?>">
                                <i class="fa-solid <?= htmlspecialchars($mode['mode_icon']) ?>"></i>
                                <?= htmlspecialchars($mode['mode_title']) ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="form-group">
                    <label for="cardName">Card Name</label>
                    <input type="text" id="cardName" required>
                </div>
                
                <div class="form-group">
                    <label for="cardDescription">Card Description</label>
                    <textarea id="cardDescription" rows="3" required></textarea>
                </div>

                <div class="form-group">
                    <label for="cardQuantity">Quantity</label>
                    <input type="number" id="cardQuantity" min="1" value="1" required>
                </div>
                
                <!-- Challenge Card Fields -->
                <div id="challengeFields" style="display: none;">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="cardPoints">Card Points</label>
                            <input type="number" id="cardPoints" min="0">
                        </div>
                        <div class="form-group">
                            <label for="vetoSubtract">Veto Subtract</label>
                            <input type="number" id="vetoSubtract" min="0">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="vetoSteal">Veto Steal</label>
                            <input type="number" id="vetoSteal" min="0">
                        </div>
                        <div class="form-group">
                            <label for="vetoWait">Veto Wait (minutes)</label>
                            <input type="number" id="vetoWait" min="0">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="vetoSnap">Veto Snap Cards</label>
                            <input type="number" id="vetoSnap" min="0">
                        </div>
                        <div class="form-group">
                            <label for="vetoSpicy">Veto Spicy Cards</label>
                            <input type="number" id="vetoSpicy" min="0">
                        </div>
                    </div>
                </div>
                
                <!-- Battle Card Fields -->
                <div id="battleFields" style="display: none;">
                    <div class="form-group">
                        <label for="battlePoints">Card Points</label>
                        <input type="number" id="battlePoints" min="0">
                    </div>
                </div>
                
                <!-- Curse Card Fields -->
                <div id="curseFields" style="display: none;">
                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" id="challengeModify">
                            <label for="challengeModify">Challenge Modify</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="snapModify">
                            <label for="snapModify">Snap Modify</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="spicyModify">
                            <label for="spicyModify">Spicy Modify</label>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="scoreModify">Score Modify</label>
                            <select id="scoreModify">
                                <option value="none">None</option>
                                <option value="half">Half</option>
                                <option value="double">Double</option>
                                <option value="zero">Zero</option>
                                <option value="extra_point">Extra Point</option>
                                <option value="challenge_reward_opponent">Challenge Reward Opponent</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="vetoModify">Veto Modify</label>
                            <select id="vetoModify">
                                <option value="none">None</option>
                                <option value="double">Double</option>
                                <option value="skip">Skip</option>
                                <option value="opponent_reward">Opponent Reward</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="curseWait">Wait (minutes)</label>
                            <input type="number" id="curseWait" min="0">
                        </div>
                        <div class="form-group">
                            <label for="timer">Timer (minutes)</label>
                            <input type="number" id="timer" min="0">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="timerCompletionType">Timer Completion Type</label>
                        <select id="timerCompletionType">
                            <option value="timer_expires">Timer Expires</option>
                            <option value="first_trigger">First Trigger</option>
                            <option value="first_trigger_any">First Trigger Any</option>
                        </select>
                    </div>
                    
                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" id="completeSnap">
                            <label for="completeSnap">Complete Snap</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="completeSpicy">
                            <label for="completeSpicy">Complete Spicy</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="rollDice">
                            <label for="rollDice">Roll Dice</label>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="diceCondition">Dice Condition</label>
                            <select id="diceCondition">
                                <option value="">None</option>
                                <option value="even">Even</option>
                                <option value="odd">Odd</option>
                                <option value="doubles">Doubles</option>
                                <option value="above">Above</option>
                                <option value="below">Below</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="diceThreshold">Dice Threshold</label>
                            <input type="number" id="diceThreshold" min="2" max="12">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="repeatCount">Repeat</label>
                            <input type="number" id="repeatCount" min="0">
                        </div>
                        <div class="form-group">
                            <label for="scoreAdd">Score Add</label>
                            <input type="number" id="scoreAdd">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="scoreSubtract">Score Subtract</label>
                            <input type="number" id="scoreSubtract">
                        </div>
                        <div class="form-group">
                            <label for="scoreSteal">Score Steal</label>
                            <input type="number" id="scoreSteal">
                        </div>
                    </div>
                </div>
                
                <!-- Power Card Fields -->
                <div id="powerFields" style="display: none;">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="powerScoreAdd">Score Add</label>
                            <input type="number" id="powerScoreAdd">
                        </div>
                        <div class="form-group">
                            <label for="powerScoreSubtract">Score Subtract</label>
                            <input type="number" id="powerScoreSubtract">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="powerScoreSteal">Score Steal</label>
                            <input type="number" id="powerScoreSteal">
                        </div>
                        <div class="form-group">
                            <label for="powerWait">Wait (minutes)</label>
                            <input type="number" id="powerWait" min="0">
                        </div>
                    </div>
                    
                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" id="powerChallengeModify">
                            <label for="powerChallengeModify">Challenge Modify</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="powerSnapModify">
                            <label for="powerSnapModify">Snap Modify</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="powerSpicyModify">
                            <label for="powerSpicyModify">Spicy Modify</label>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="powerScoreModify">Score Modify</label>
                            <select id="powerScoreModify">
                                <option value="none">None</option>
                                <option value="half">Half</option>
                                <option value="double">Double</option>
                                <option value="zero">Zero</option>
                                <option value="extra_point">Extra Point</option>
                                <option value="challenge_reward_opponent">Challenge Reward Opponent</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="powerVetoModify">Veto Modify</label>
                            <select id="powerVetoModify">
                                <option value="none">None</option>
                                <option value="double">Double</option>
                                <option value="skip">Skip</option>
                                <option value="opponent_reward">Opponent Reward</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" id="targetOpponent">
                            <label for="targetOpponent">Target Opponent</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="skipChallenge">
                            <label for="skipChallenge">Skip Challenge</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="clearCurse">
                            <label for="clearCurse">Clear Curse</label>
                        </div>
                    </div>
                    
                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" id="shuffleDailyDeck">
                            <label for="shuffleDailyDeck">Shuffle Daily Deck</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="deckPeek">
                            <label for="deckPeek">Deck Peek</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="cardSwap">
                            <label for="cardSwap">Card Swap</label>
                        </div>
                    </div>
                    
                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" id="bypassExpiration">
                            <label for="bypassExpiration">Bypass Expiration</label>
                        </div>
                    </div>
                </div>
                
                <!-- Snap & Spicy Card Fields -->
                <div id="snapSpicyFields" style="display: none;">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="snapSpicyPoints">Card Points</label>
                            <input type="number" id="snapSpicyPoints" min="0">
                        </div>
                        <div class="form-group">
                            <label for="snapSpicyVetoSubtract">Veto Subtract</label>
                            <input type="number" id="snapSpicyVetoSubtract" min="0">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="snapSpicyVetoSteal">Veto Steal</label>
                            <input type="number" id="snapSpicyVetoSteal" min="0">
                        </div>
                        <div class="form-group">
                            <label for="snapSpicyVetoWait">Veto Wait (minutes)</label>
                            <input type="number" id="snapSpicyVetoWait" min="0">
                        </div>
                    </div>
                    
                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" id="maleCard" checked>
                            <label for="maleCard">Male</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="femaleCard" checked>
                            <label for="femaleCard">Female</label>
                        </div>
                    </div>
                </div>
                
            </form>
            
            <div class="confirm-buttons">
                <button class="btn btn-secondary" onclick="closeCardModal()">Cancel</button>
                <button class="btn" onclick="saveCard()">Save Card</button>
            </div>
        </div>
    </div>

    <!-- Travel Mode Management Modal -->
    <div class="confirm-dialog" id="travelModeModal">
        <div class="confirm-content" style="max-width: 500px;">
            <h3 id="travelModeModalTitle">Add Travel Mode</h3>
            <form id="travelModeForm" class="modal-form">
                <input type="hidden" id="travelModeId">
                
                <div class="form-group">
                    <label for="modeTitle">Mode Title</label>
                    <input type="text" id="modeTitle" required>
                </div>
                
                <div class="form-group">
                    <label for="modeDescription">Mode Description</label>
                    <textarea id="modeDescription" rows="3"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="modeIcon">Mode Icon (Font Awesome class)</label>
                    <input type="text" id="modeIcon" placeholder="fa-plane">
                </div>
            </form>
            
            <div class="confirm-buttons">
                <button class="btn btn-secondary" onclick="closeTravelModeModal()">Cancel</button>
                <button class="btn" onclick="saveTravelMode()">Save Mode</button>
            </div>
        </div>
    </div>

    <!-- Confirmation Dialogs -->
    <div class="confirm-dialog" id="confirmDeleteCode">
        <div class="confirm-content">
            <h3>Delete Invite Code</h3>
            <p>Are you sure you want to delete invite code <strong id="deleteCodeValue"></strong>?</p>
            <p style="color: #666; font-size: 14px;">This action cannot be undone.</p>
            <div class="confirm-buttons">
                <button class="btn btn-secondary" onclick="closeConfirmDialog()">Cancel</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="code_id" id="deleteCodeId">
                    <button type="submit" name="delete_code" class="btn" style="background: #dc3545;">Delete Code</button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="confirm-dialog" id="confirmDeleteGame">
        <div class="confirm-content">
            <h3>Delete Game</h3>
            <p>Are you sure you want to delete the game with invite code <strong id="deleteGameValue"></strong>?</p>
            <p style="color: #666; font-size: 14px;">This will delete all players, scores, timers, and history. This action cannot be undone.</p>
            <div class="confirm-buttons">
                <button class="btn btn-secondary" onclick="closeConfirmDialog()">Cancel</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="game_id" id="deleteGameId">
                    <button type="submit" name="delete_game" class="btn" style="background: #dc3545;">Delete Game</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        let currentCardCategory = 'challenge';

        // Travel mode management functions
        function openTravelModeModal(modeId = null) {
            document.getElementById('travelModeForm').reset();
            document.getElementById('travelModeId').value = modeId || '';
            
            if (modeId) {
                document.getElementById('travelModeModalTitle').textContent = 'Edit Travel Mode';
                loadTravelModeData(modeId);
            } else {
                document.getElementById('travelModeModalTitle').textContent = 'Add Travel Mode';
            }
            
            document.getElementById('travelModeModal').classList.add('active');
        }

        function closeTravelModeModal() {
            document.getElementById('travelModeModal').classList.remove('active');
        }

        function saveTravelMode() {
            const formData = new FormData();
            formData.append('action', 'save_travel_mode');
            formData.append('id', document.getElementById('travelModeId').value);
            formData.append('mode_title', document.getElementById('modeTitle').value);
            formData.append('mode_description', document.getElementById('modeDescription').value);
            formData.append('mode_icon', document.getElementById('modeIcon').value);
            
            fetch('admin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeTravelModeModal();
                    location.reload();
                } else {
                    alert('Error saving travel mode: ' + (data.message || 'Unknown error'));
                }
            });
        }

        function loadTravelModeData(modeId) {
            fetch('admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_travel_mode&id=' + modeId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('modeTitle').value = data.mode.mode_title;
                    document.getElementById('modeDescription').value = data.mode.mode_description;
                    document.getElementById('modeIcon').value = data.mode.mode_icon;
                }
            });
        }

        function confirmDeleteTravelMode(modeId, modeTitle) {
            if (confirm(`Are you sure you want to delete the travel mode "${modeTitle}"?`)) {
                deleteTravelMode(modeId);
            }
        }

        function deleteTravelMode(modeId) {
            fetch('admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=delete_travel_mode&id=' + modeId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error deleting travel mode: ' + (data.message || 'Unknown error'));
                }
            });
        }

        function showCardCategory(category) {
            currentCardCategory = category;
            
            // Update tab states
            document.querySelectorAll('.card-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Show/hide content
            document.querySelectorAll('.card-type-content').forEach(content => {
                content.style.display = 'none';
            });
            document.getElementById(category + '-cards').style.display = 'block';
            
            // Load cards for this category
            loadCards(category);
        }

        function loadCards(category) {
            fetch('admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_cards&category=' + category
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayCards(category, data.cards);
                    updateCardCounts(category, data.counts);
                }
            });
        }

        function updateCardCounts(category, counts) {
            const header = document.querySelector(`#${category}-cards .card-header h3`);
            let countText = '';
            
            if (category === 'snap' || category === 'spicy') {
                const maleCount = counts.male_count || 0;
                const femaleCount = counts.female_count || 0;
                const bothCount = counts.both_count || 0;
                countText = ` (Male: ${maleCount}, Female: ${femaleCount}, Both: ${bothCount})`;
            } else {
                countText = ` (${counts.total || 0} cards)`;
            }
            
            const baseTitle = category.charAt(0).toUpperCase() + category.slice(1) + ' Cards';
            header.textContent = baseTitle + countText;
        }

        function displayCards(category, cards) {
            const container = document.getElementById(category + '-cards-list');
            const header = document.querySelector(`#${category}-cards .card-header h3`);
            container.innerHTML = '';
            
            if (cards.length === 0) {
                container.innerHTML = '<p style="text-align: center; color: #666; padding: 20px;">No cards yet</p>';
                return;
            }
            
            cards.forEach(card => {
                const cardElement = document.createElement('div');
                cardElement.className = `card-item category-${category}`;
                cardElement.innerHTML = `
                    <h4>${card.card_name}</h4>
                    <p>${card.card_description}</p>
                    <div class="card-meta">
                        ${getCardMeta(card)}
                    </div>
                    <div class="card-actions">
                        <button class="btn-small btn-warning" onclick="editCard(${card.id})">Edit</button>
                        <button class="btn-small btn-danger" onclick="confirmDeleteCard(${card.id}, '${card.card_name.replace(/'/g, "\\'")}')">Delete</button>
                    </div>
                `;
                container.appendChild(cardElement);
            });
        }

        function getCardMeta(card) {
            let meta = [];
            
            // Points
            if (card.card_points) meta.push(`${card.card_points} points`);
            
            // Category-specific metadata
            switch (card.card_category) {
                case 'challenge':
                    if (card.veto_subtract) meta.push(`Veto: -${card.veto_subtract}`);
                    if (card.veto_steal) meta.push(`Veto steal: ${card.veto_steal}`);
                    if (card.veto_wait) meta.push(`Veto wait: ${card.veto_wait}min`);
                    if (card.veto_snap) meta.push(`Veto snap: ${card.veto_snap}`);
                    if (card.veto_spicy) meta.push(`Veto spicy: ${card.veto_spicy}`);
                    break;
                    
                case 'curse':
                    if (card.challenge_modify) meta.push('Challenge modify');
                    if (card.score_modify && card.score_modify !== 'none') meta.push(`Score: ${card.score_modify}`);
                    if (card.veto_modify && card.veto_modify !== 'none') meta.push(`Veto: ${card.veto_modify}`);
                    if (card.timer) meta.push(`${card.timer}min timer`);
                    if (card.wait) meta.push(`${card.wait}min wait`);
                    break;
                    
                case 'power':
                    if (card.power_score_add) meta.push(`+${card.power_score_add} score`);
                    if (card.power_score_subtract) meta.push(`-${card.power_score_subtract} score`);
                    if (card.power_score_steal) meta.push(`Steal ${card.power_score_steal}`);
                    if (card.target_opponent) meta.push('Targets opponent');
                    if (card.clear_curse) meta.push('Clears curse');
                    if (card.shuffle_daily_deck) meta.push('Shuffles deck');
                    break;
                    
                case 'snap':
                case 'spicy':
                    if (card.male && card.female) meta.push('Both genders');
                    else if (card.male) meta.push('Male only');
                    else if (card.female) meta.push('Female only');
                    if (card.veto_subtract) meta.push(`Veto: -${card.veto_subtract}`);
                    if (card.veto_wait) meta.push(`Veto wait: ${card.veto_wait}min`);
                    break;
            }

            if (card.quantity && card.quantity > 1) meta.push(`${card.quantity}x`);

            if (card.travel_mode_icons) meta.push(card.travel_mode_icons);
            
            return meta.join(' • ');
        }

        function openCardModal(category, cardId = null) {
            currentCardCategory = category;
            const modal = document.getElementById('cardModal');
            const title = document.getElementById('cardModalTitle');
            
            // Reset form
            document.getElementById('cardForm').reset();
            document.getElementById('cardId').value = cardId || '';
            document.getElementById('cardCategory').value = category;
            
            // Show/hide field groups
            document.getElementById('challengeFields').style.display = category === 'challenge' ? 'block' : 'none';
            document.getElementById('battleFields').style.display = category === 'battle' ? 'block' : 'none';
            document.getElementById('curseFields').style.display = category === 'curse' ? 'block' : 'none';
            document.getElementById('powerFields').style.display = category === 'power' ? 'block' : 'none';
            document.getElementById('snapSpicyFields').style.display = (category === 'snap' || category === 'spicy') ? 'block' : 'none';
            
            title.textContent = cardId ? 'Edit ' + category.charAt(0).toUpperCase() + category.slice(1) + ' Card' : 'Add ' + category.charAt(0).toUpperCase() + category.slice(1) + ' Card';
            
            if (cardId) {
                loadCardData(cardId);
            }
            
            modal.classList.add('active');
        }

        function loadCardData(cardId) {
            fetch('admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_card&id=' + cardId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    populateForm(data.card);
                }
            });
        }

        function populateForm(card) {
            document.getElementById('cardName').value = card.card_name;
            document.getElementById('cardDescription').value = card.card_description;
            if (card.quantity) document.getElementById('cardQuantity').value = card.quantity;

            // Clear travel mode checkboxes
            document.querySelectorAll('#travelModesCheckboxes input[type="checkbox"]').forEach(cb => {
                cb.checked = false;
            });

            // Load card's travel modes
            if (card.travel_modes) {
                card.travel_modes.forEach(modeId => {
                    const checkbox = document.getElementById('mode_' + modeId);
                    if (checkbox) checkbox.checked = true;
                });
            }
            
            // Populate category-specific fields
            switch (card.card_category) {
                case 'challenge':
                    if (card.card_points) document.getElementById('cardPoints').value = card.card_points;
                    if (card.veto_subtract) document.getElementById('vetoSubtract').value = card.veto_subtract;
                    if (card.veto_steal) document.getElementById('vetoSteal').value = card.veto_steal;
                    if (card.veto_wait) document.getElementById('vetoWait').value = card.veto_wait;
                    if (card.veto_snap) document.getElementById('vetoSnap').value = card.veto_snap;
                    if (card.veto_spicy) document.getElementById('vetoSpicy').value = card.veto_spicy;
                    break;
                    
                case 'battle':
                    if (card.card_points) document.getElementById('battlePoints').value = card.card_points;
                    break;
                    
                case 'curse':
                    document.getElementById('challengeModify').checked = card.challenge_modify;
                    document.getElementById('snapModify').checked = card.snap_modify;
                    document.getElementById('spicyModify').checked = card.spicy_modify;
                    if (card.score_modify) document.getElementById('scoreModify').value = card.score_modify;
                    if (card.veto_modify) document.getElementById('vetoModify').value = card.veto_modify;
                    if (card.wait) document.getElementById('curseWait').value = card.wait;
                    if (card.timer) document.getElementById('timer').value = card.timer;
                    if (card.timer_completion_type) document.getElementById('timerCompletionType').value = card.timer_completion_type;
                    document.getElementById('completeSnap').checked = card.complete_snap;
                    document.getElementById('completeSpicy').checked = card.complete_spicy;
                    document.getElementById('rollDice').checked = card.roll_dice;
                    if (card.dice_condition) document.getElementById('diceCondition').value = card.dice_condition;
                    if (card.dice_threshold) document.getElementById('diceThreshold').value = card.dice_threshold;
                    if (card.repeat_count) document.getElementById('repeatCount').value = card.repeat_count;
                    if (card.score_add) document.getElementById('scoreAdd').value = card.score_add;
                    if (card.score_subtract) document.getElementById('scoreSubtract').value = card.score_subtract;
                    if (card.score_steal) document.getElementById('scoreSteal').value = card.score_steal;
                    break;
                    
                case 'power':
                    if (card.power_score_add) document.getElementById('powerScoreAdd').value = card.power_score_add;
                    if (card.power_score_subtract) document.getElementById('powerScoreSubtract').value = card.power_score_subtract;
                    if (card.power_score_steal) document.getElementById('powerScoreSteal').value = card.power_score_steal;
                    if (card.power_wait) document.getElementById('powerWait').value = card.power_wait;
                    document.getElementById('powerChallengeModify').checked = card.power_challenge_modify;
                    document.getElementById('powerSnapModify').checked = card.power_snap_modify;
                    document.getElementById('powerSpicyModify').checked = card.power_spicy_modify;
                    if (card.power_score_modify) document.getElementById('powerScoreModify').value = card.power_score_modify;
                    if (card.power_veto_modify) document.getElementById('powerVetoModify').value = card.power_veto_modify;
                    document.getElementById('targetOpponent').checked = card.target_opponent;
                    document.getElementById('skipChallenge').checked = card.skip_challenge;
                    document.getElementById('clearCurse').checked = card.clear_curse;
                    document.getElementById('shuffleDailyDeck').checked = card.shuffle_daily_deck;
                    document.getElementById('deckPeek').checked = card.deck_peek;
                    document.getElementById('cardSwap').checked = card.card_swap;
                    document.getElementById('bypassExpiration').checked = card.bypass_expiration;
                    break;
                    
                case 'snap':
                case 'spicy':
                    if (card.card_points) document.getElementById('snapSpicyPoints').value = card.card_points;
                    if (card.veto_subtract) document.getElementById('snapSpicyVetoSubtract').value = card.veto_subtract;
                    if (card.veto_steal) document.getElementById('snapSpicyVetoSteal').value = card.veto_steal;
                    if (card.veto_wait) document.getElementById('snapSpicyVetoWait').value = card.veto_wait;
                    document.getElementById('maleCard').checked = card.male;
                    document.getElementById('femaleCard').checked = card.female;
                    break;
            }
        }

        function closeCardModal() {
            document.getElementById('cardModal').classList.remove('active');
        }

        function saveCard() {
            const formData = new FormData();
            formData.append('action', 'save_card');
            
            // Basic fields
            formData.append('id', document.getElementById('cardId').value);
            formData.append('card_category', document.getElementById('cardCategory').value);
            formData.append('card_name', document.getElementById('cardName').value);
            formData.append('card_description', document.getElementById('cardDescription').value);
            formData.append('quantity', document.getElementById('cardQuantity').value || '1');

            // Add travel modes
            const selectedModes = [];
            document.querySelectorAll('#travelModesCheckboxes input[type="checkbox"]:checked').forEach(cb => {
                selectedModes.push(cb.value);
            });
            selectedModes.forEach(modeId => {
                formData.append('travel_modes[]', modeId);
            });
            
            const category = document.getElementById('cardCategory').value;
            
            // Category-specific fields
            switch (category) {
                case 'challenge':
                    formData.append('card_points', document.getElementById('cardPoints').value || '');
                    formData.append('veto_subtract', document.getElementById('vetoSubtract').value || '');
                    formData.append('veto_steal', document.getElementById('vetoSteal').value || '');
                    formData.append('veto_wait', document.getElementById('vetoWait').value || '');
                    formData.append('veto_snap', document.getElementById('vetoSnap').value || '');
                    formData.append('veto_spicy', document.getElementById('vetoSpicy').value || '');
                    break;
                    
                case 'battle':
                    formData.append('card_points', document.getElementById('battlePoints').value || '');
                    break;
                    
                case 'curse':
                    formData.append('challenge_modify', document.getElementById('challengeModify').checked ? '1' : '0');
                    formData.append('snap_modify', document.getElementById('snapModify').checked ? '1' : '0');
                    formData.append('spicy_modify', document.getElementById('spicyModify').checked ? '1' : '0');
                    formData.append('score_modify', document.getElementById('scoreModify').value);
                    formData.append('veto_modify', document.getElementById('vetoModify').value);
                    formData.append('wait', document.getElementById('curseWait').value || '');
                    formData.append('timer', document.getElementById('timer').value || '');
                    formData.append('timer_completion_type', document.getElementById('timerCompletionType').value);
                    formData.append('complete_snap', document.getElementById('completeSnap').checked ? '1' : '0');
                    formData.append('complete_spicy', document.getElementById('completeSpicy').checked ? '1' : '0');
                    formData.append('roll_dice', document.getElementById('rollDice').checked ? '1' : '0');
                    formData.append('dice_condition', document.getElementById('diceCondition').value || '');
                    formData.append('dice_threshold', document.getElementById('diceThreshold').value || '');
                    formData.append('repeat_count', document.getElementById('repeatCount').value || '');
                    formData.append('score_add', document.getElementById('scoreAdd').value || '');
                    formData.append('score_subtract', document.getElementById('scoreSubtract').value || '');
                    formData.append('score_steal', document.getElementById('scoreSteal').value || '');
                    break;
                    
                case 'power':
                    formData.append('power_score_add', document.getElementById('powerScoreAdd').value || '');
                    formData.append('power_score_subtract', document.getElementById('powerScoreSubtract').value || '');
                    formData.append('power_score_steal', document.getElementById('powerScoreSteal').value || '');
                    formData.append('power_wait', document.getElementById('powerWait').value || '');
                    formData.append('power_challenge_modify', document.getElementById('powerChallengeModify').checked ? '1' : '0');
                    formData.append('power_snap_modify', document.getElementById('powerSnapModify').checked ? '1' : '0');
                    formData.append('power_spicy_modify', document.getElementById('powerSpicyModify').checked ? '1' : '0');
                    formData.append('power_score_modify', document.getElementById('powerScoreModify').value);
                    formData.append('power_veto_modify', document.getElementById('powerVetoModify').value);
                    formData.append('target_opponent', document.getElementById('targetOpponent').checked ? '1' : '0');
                    formData.append('skip_challenge', document.getElementById('skipChallenge').checked ? '1' : '0');
                    formData.append('clear_curse', document.getElementById('clearCurse').checked ? '1' : '0');
                    formData.append('shuffle_daily_deck', document.getElementById('shuffleDailyDeck').checked ? '1' : '0');
                    formData.append('deck_peek', document.getElementById('deckPeek').checked ? '1' : '0');
                    formData.append('card_swap', document.getElementById('cardSwap').checked ? '1' : '0');
                    formData.append('bypass_expiration', document.getElementById('bypassExpiration').checked ? '1' : '0');
                    break;
                    
                case 'snap':
                case 'spicy':
                    formData.append('card_points', document.getElementById('snapSpicyPoints').value || '');
                    formData.append('veto_subtract', document.getElementById('snapSpicyVetoSubtract').value || '');
                    formData.append('veto_steal', document.getElementById('snapSpicyVetoSteal').value || '');
                    formData.append('veto_wait', document.getElementById('snapSpicyVetoWait').value || '');
                    formData.append('male', document.getElementById('maleCard').checked ? '1' : '0');
                    formData.append('female', document.getElementById('femaleCard').checked ? '1' : '0');
                    break;
            }
            
            fetch('admin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeCardModal();
                    loadCards(currentCardCategory);
                } else {
                    alert('Error saving card: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error saving card');
            });
        }

        function editCard(cardId) {
            openCardModal(currentCardCategory, cardId);
        }

        function confirmDeleteCard(cardId, cardName) {
            if (confirm(`Are you sure you want to delete the card "${cardName}"?`)) {
                deleteCard(cardId);
            }
        }

        function deleteCard(cardId) {
            fetch('admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=delete_card&id=' + cardId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadCards(currentCardCategory);
                } else {
                    alert('Error deleting card: ' + (data.message || 'Unknown error'));
                }
            });
        }

        function confirmDeleteCode(codeId, codeValue) {
            document.getElementById('deleteCodeId').value = codeId;
            document.getElementById('deleteCodeValue').textContent = codeValue;
            document.getElementById('confirmDeleteCode').classList.add('active');
        }

        function confirmDeleteGame(gameId, gameCode) {
            document.getElementById('deleteGameId').value = gameId;
            document.getElementById('deleteGameValue').textContent = gameCode;
            document.getElementById('confirmDeleteGame').classList.add('active');
        }

        function closeConfirmDialog() {
            document.querySelectorAll('.confirm-dialog').forEach(dialog => {
                dialog.classList.remove('active');
            });
        }

        // Close dialog when clicking outside
        document.querySelectorAll('.confirm-dialog').forEach(dialog => {
            dialog.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeConfirmDialog();
                }
            });
        });

        // Load initial cards when page loads
        document.addEventListener('DOMContentLoaded', function() {
            loadCards('challenge');
        });
    </script>
</body>
</html>