<?php
// change_admin_password.php - Run this to change the admin password
require_once 'config.php';

// Set your new password here
$newPassword = 'KmBa030416!';

try {
    $pdo = Config::getDatabaseConnection();
    
    // Generate password hash
    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    
    // Update the admin password
    $stmt = $pdo->prepare("UPDATE admin_users SET password_hash = ? WHERE username = 'admin'");
    $stmt->execute([$passwordHash]);
    
    if ($stmt->rowCount() > 0) {
        echo "✅ Admin password updated successfully!\n";
        echo "New password: $newPassword\n";
    } else {
        echo "❌ Failed to update password - admin user not found\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

// Optional: Delete this file after running for security
// unlink(__FILE__);
?>