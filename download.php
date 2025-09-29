<?php
// download.php - Mobile config download page
require_once 'config.php';
require_once 'functions.php';

$deviceId = $_COOKIE['device_id'] ?? null;

if (!$deviceId) {
    header('Location: index.php');
    exit;
}

$player = getPlayerByDeviceId($deviceId);
if (!$player) {
    header('Location: index.php');
    exit;
}

// Generate mobileconfig file
if (isset($_GET['download'])) {
    $mobileconfig = generateMobileConfig($deviceId);
    
    header('Content-Type: application/x-apple-aspen-config');
    header('Content-Disposition: attachment; filename="CouplesQuest.mobileconfig"');
    echo $mobileconfig;
    exit;
}

function generateMobileConfig($deviceId) {
    $uuid1 = generateUUID();
    $uuid2 = generateUUID();
    $uuid3 = generateUUID();
    
    $config = '<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>PayloadContent</key>
    <array>
        <dict>
            <key>PayloadDescription</key>
            <string>Adds TCQ Travel web app to home screen</string>
            <key>PayloadDisplayName</key>
            <string>TCQ Travel</string>
            <key>PayloadIdentifier</key>
            <string>com.tcqtravel.webapp.' . $uuid2 . '</string>
            <key>PayloadType</key>
            <string>com.apple.webClip.managed</string>
            <key>PayloadUUID</key>
            <string>' . $uuid2 . '</string>
            <key>PayloadVersion</key>
            <integer>1</integer>
            <key>URL</key>
            <string>' . Config::SITE_URL . '/game.php?device_id=' . urlencode($deviceId) . '</string>
            <key>Label</key>
            <string>TCQ</string>
            <key>Icon</key>
            <data>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</data>
            <key>IsRemovable</key>
            <false/>
            <key>FullScreen</key>
            <true/>
        </dict>
    </array>
    <key>PayloadDescription</key>
    <string>Install TCQ Travel web app</string>
    <key>PayloadDisplayName</key>
    <string>TCQ Travel</string>
    <key>PayloadIdentifier</key>
    <string>com.tcqtravel.webapp.' . $uuid1 . '</string>
    <key>PayloadRemovalDisallowed</key>
    <false/>
    <key>PayloadType</key>
    <string>Configuration</string>
    <key>PayloadUUID</key>
    <string>' . $uuid1 . '</string>
    <key>PayloadVersion</key>
    <integer>1</integer>
</dict>
</plist>';
    
    return $config;
}

function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Download - TCQ Travel</title>
    <link rel="stylesheet" href="https://use.typekit.net/oqm2ymj.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'museo-sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, <?= Config::COLOR_GRAY ?> 0%, <?= Config::COLOR_BLACK ?> 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
            text-align: center;
        }
        
        h1 {
            color: #333;
            margin-bottom: 20px;
            font-size: 2em;
        }
        
        .instruction {
            color: #666;
            margin-bottom: 30px;
            line-height: 1.5;
        }
        
        .download-btn {
            display: inline-block;
            padding: 15px 30px;
            background: linear-gradient(135deg, <?= Config::COLOR_PINK ?>, <?= Config::COLOR_BLUE ?>);
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            transition: transform 0.2s;
        }
        
        .download-btn:hover {
            transform: translateY(-2px);
        }
        
        .skip-link {
            display: block;
            color: #999;
            text-decoration: none;
            margin-top: 20px;
        }
        
        .steps {
            text-align: left;
            margin-top: 20px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        
        .steps h3 {
            margin-bottom: 15px;
            color: #333;
        }
        
        .steps ol {
            margin-left: 20px;
        }
        
        .steps li {
            margin-bottom: 10px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Almost Ready!</h1>
        
        <p class="instruction">
            Download the configuration file to install The TCQ Travel Edition PWA (Progressive Web App) on your home screen for the best experience.
        </p>
        
        <a href="?download=1" class="download-btn">Download PWA Config</a>
        <a href="os-beta-updates://" class="download-btn">Go to Settings</a>
        
        <div class="steps">
            <h3>After downloading:</h3>
            <ol>
                <li>Install the Profile in Settings > General > VPN & Device Management</li>
                <li>Follow the prompts to install</li>
                <li>Find TCQ app on your home screen</li>
                <li>Enable notifications for the best experience</li>
                <li>Begin and enjoy your game!</li>
            </ol>
        </div>
    </div>
</body>
</html>