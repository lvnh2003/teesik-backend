<?php

try {
    $pdo = new PDO('sqlite:' . __DIR__ . '/database/database.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $queries = [
        "CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role VARCHAR(50) DEFAULT 'user',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255),
            description TEXT,
            category_id INTEGER,
            is_new BOOLEAN DEFAULT 0,
            is_featured BOOLEAN DEFAULT 0,
            is_active BOOLEAN DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(category_id) REFERENCES categories(id)
        )",
        "CREATE TABLE IF NOT EXISTS product_variants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            sku VARCHAR(100),
            price DECIMAL(10,2) NOT NULL,
            original_price DECIMAL(10,2),
            stock_quantity INTEGER DEFAULT 0,
            attributes TEXT, -- JSON
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS product_images (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            product_variant_id INTEGER,
            image_path VARCHAR(255) NOT NULL,
            alt_text VARCHAR(255),
            sort_order INTEGER DEFAULT 0,
            type VARCHAR(50) DEFAULT 'main',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS orders (
           id INTEGER PRIMARY KEY AUTOINCREMENT,
           user_id INTEGER,
           customer_name VARCHAR(255),
           customer_email VARCHAR(255),
           customer_phone VARCHAR(50),
           shipping_address TEXT,
           total_amount DECIMAL(10,2) NOT NULL,
           status VARCHAR(50) DEFAULT 'pending',
           created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
           updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS order_items (
           id INTEGER PRIMARY KEY AUTOINCREMENT,
           order_id INTEGER NOT NULL,
           product_id INTEGER,
           product_variant_id INTEGER,
           product_name VARCHAR(255),
           quantity INTEGER NOT NULL,
           price DECIMAL(10,2) NOT NULL,
           created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
           updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
           FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE
        )",
        // OAuth tables required by Passport (simplified for basic token usage if user provider works)
        // Actually Passport uses its own tables. We might need them if we use Passport.
        // For now, let's use simple Sanctum-style token or just basic auth if Passport fails.
        // But the controller uses Passport.
        "CREATE TABLE IF NOT EXISTS oauth_clients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NULL,
            name VARCHAR(255) NOT NULL,
            secret VARCHAR(100) NULL,
            provider VARCHAR(255) NULL,
            redirect TEXT NOT NULL,
            personal_access_client BOOLEAN NOT NULL,
            password_client BOOLEAN NOT NULL,
            revoked BOOLEAN NOT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        )",
        "CREATE TABLE IF NOT EXISTS oauth_access_tokens (
            id VARCHAR(100) PRIMARY KEY,
            user_id INTEGER NULL,
            client_id INTEGER NOT NULL,
            name VARCHAR(255) NULL,
            scopes TEXT NULL,
            revoked BOOLEAN NOT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            expires_at DATETIME NULL
        )",
        "CREATE TABLE IF NOT EXISTS personal_access_clients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        )"
    ];

    foreach ($queries as $query) {
        $pdo->exec($query);
    }
    
    echo "Database setup completed successfully.";

} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
}
