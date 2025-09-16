<?php
// setup_database.php - Run this once to create all tables
require_once 'config.php';

try {
    $pdo = Config::getDatabaseConnection();
    
    echo "Creating TCQ Travel Edition database tables...\n\n";
    
    // Read and execute the SQL schema
    $sql = file_get_contents('schema.sql'); // You'll save the SQL from the artifact as schema.sql
    
    // Split into individual statements
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            try {
                $pdo->exec($statement);
                echo "✓ Executed: " . substr($statement, 0, 50) . "...\n";
            } catch (PDOException $e) {
                echo "✗ Error executing statement: " . $e->getMessage() . "\n";
                echo "Statement: " . substr($statement, 0, 100) . "...\n\n";
            }
        }
    }
    
    echo "\n🎉 Database setup complete!\n";
    echo "Default admin login: admin / admin123\n";
    
} catch (Exception $e) {
    echo "❌ Setup failed: " . $e->getMessage() . "\n";
}
?>