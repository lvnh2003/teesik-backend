<?php

try {
    $dbPath = __DIR__ . '/database/database.sqlite';
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Drop old tables to ensure fresh schema
    echo "Dropping old tables...\n";
    $pdo->exec("DROP TABLE IF EXISTS product_images");
    $pdo->exec("DROP TABLE IF EXISTS product_variants");
    $pdo->exec("DROP TABLE IF EXISTS products");
    $pdo->exec("DROP TABLE IF EXISTS categories");
    $pdo->exec("DROP TABLE IF EXISTS users");

    // Create tables
    echo "Creating tables...\n";
    $queries = [
        "CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role VARCHAR(50) DEFAULT 'user',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255),
            description TEXT,
            category_id INTEGER,
            is_new BOOLEAN DEFAULT 0,
            is_featured BOOLEAN DEFAULT 0,
            is_active BOOLEAN DEFAULT 1,
            price DECIMAL(10,2) DEFAULT 0,
            original_price DECIMAL(10,2) DEFAULT 0,
            stock_quantity INTEGER DEFAULT 0,
            sku VARCHAR(100),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(category_id) REFERENCES categories(id)
        )",
        "CREATE TABLE product_variants (
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
        "CREATE TABLE product_images (
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
        )"
    ];

    foreach ($queries as $query) {
        $pdo->exec($query);
    }

    // Clear old data
    echo "Cleaning up old data...\n";
    $pdo->exec("DELETE FROM product_images");
    $pdo->exec("DELETE FROM product_variants");
    $pdo->exec("DELETE FROM products");
    $pdo->exec("DELETE FROM categories");
    $pdo->exec("DELETE FROM users");

    // 1. Users
    echo "Seeding users...\n";
    $users = [
        ['name' => 'Admin User', 'email' => 'admin@teesik.com', 'password' => password_hash('password', PASSWORD_BCRYPT), 'role' => 'admin'],
        ['name' => 'Khách Hàng', 'email' => 'user@teesik.com', 'password' => password_hash('password', PASSWORD_BCRYPT), 'role' => 'user'],
    ];
    $stmtUser = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (:name, :email, :password, :role)");
    foreach ($users as $user) {
        $stmtUser->execute($user);
    }

    // 2. Categories
    echo "Seeding categories...\n";
    $categories = [
        ['name' => 'Áo Thun (T-Shirts)', 'description' => 'Áo thun phong cách Basic, Graphic, Streetwear.'],
        ['name' => 'Áo Sơ Mi (Shirts)', 'description' => 'Sơ mi Oxford, Flannel, Denim các loại.'],
        ['name' => 'Quần (Pants)', 'description' => 'Jeans, Kaki, Cargo pants, Short.'],
        ['name' => 'Áo Khoác (Jackets)', 'description' => 'Hoodie, Bomber, Denim Jacket.'],
        ['name' => 'Phụ Kiện (Accessories)', 'description' => 'Mũ, túi, balo, tất.'],
    ];
    $stmtCat = $pdo->prepare("INSERT INTO categories (name, description) VALUES (:name, :description)");
    $catIds = [];
    foreach ($categories as $cat) {
        $stmtCat->execute($cat);
        $catIds[] = $pdo->lastInsertId();
    }

    // 3. Products
    echo "Seeding products...\n";
    $products = [
        [
            'name' => 'Basic Heavyweight Tee',
            'slug' => 'basic-heavyweight-tee',
            'description' => "Áo thun Basic Heavyweight với định lượng vải 250gsm dày dặn, đứng form.\nChất liệu 100% cotton 2 chiều cao cấp, thấm hút tốt, không bai dão.",
            'category_id' => $catIds[0],
            'price' => 350000,
            'original_price' => 0,
            'is_new' => 1,
            'is_featured' => 1,
            'stock' => 100,
            'sku' => 'TS-HV-001',
            'variants' => [
                ['sku' => 'TS-HV-BLK-S', 'price' => 350000, 'stock' => 20, 'attrs' => ['color' => 'Đen', 'size' => 'S']],
                ['sku' => 'TS-HV-BLK-M', 'price' => 350000, 'stock' => 20, 'attrs' => ['color' => 'Đen', 'size' => 'M']],
                ['sku' => 'TS-HV-BLK-L', 'price' => 350000, 'stock' => 15, 'attrs' => ['color' => 'Đen', 'size' => 'L']],
                ['sku' => 'TS-HV-WHT-S', 'price' => 350000, 'stock' => 15, 'attrs' => ['color' => 'Trắng', 'size' => 'S']],
                ['sku' => 'TS-HV-WHT-M', 'price' => 350000, 'stock' => 15, 'attrs' => ['color' => 'Trắng', 'size' => 'M']],
                ['sku' => 'TS-HV-WHT-L', 'price' => 350000, 'stock' => 15, 'attrs' => ['color' => 'Trắng', 'size' => 'L']],
            ],
            'images' => [
                'https://images.unsplash.com/photo-1521572163474-6864f9cf17ab?auto=format&fit=crop&q=80&w=800', // Mock white
                'https://images.unsplash.com/photo-1583743814966-8936f5b7be1a?auto=format&fit=crop&q=80&w=800', // Mock black
            ]
        ],
        [
            'name' => 'Vintage Washed Graphic Tee',
            'slug' => 'vintage-washed-graphic-tee',
            'description' => "Áo thun hiệu ứng wash vintage, in hình graphic retro.\nPhong cách bụi bặm, cá tính.",
            'category_id' => $catIds[0],
            'price' => 420000,
            'original_price' => 500000,
            'is_new' => 0,
            'is_featured' => 1,
            'stock' => 50,
            'sku' => 'TS-VIN-002',
            'variants' => [
                ['sku' => 'TS-VIN-GRY-M', 'price' => 420000, 'stock' => 10, 'attrs' => ['color' => 'Xám Chì', 'size' => 'M']],
                ['sku' => 'TS-VIN-GRY-L', 'price' => 420000, 'stock' => 10, 'attrs' => ['color' => 'Xám Chì', 'size' => 'L']],
                ['sku' => 'TS-VIN-BRN-M', 'price' => 420000, 'stock' => 10, 'attrs' => ['color' => 'Nâu đất', 'size' => 'M']],
            ],
            'images' => [
                'https://images.unsplash.com/photo-1576566588028-4147f3842f27?auto=format&fit=crop&q=80&w=800',
                'https://images.unsplash.com/photo-1529374255404-311a2a4f1fd9?auto=format&fit=crop&q=80&w=800',
            ]
        ],
        [
            'name' => 'Oxford Button-Down Shirt',
            'slug' => 'oxford-button-down-shirt',
            'description' => "Sơ mi Oxford cổ điển, form Regular fit.\nPhù hợp môi trường công sở hoặc đi chơi casual.",
            'category_id' => $catIds[1],
            'price' => 550000,
            'original_price' => 0,
            'is_new' => 1,
            'is_featured' => 1,
            'stock' => 80,
            'sku' => 'SH-OXF-001',
            'variants' => [
                ['sku' => 'SH-OXF-BLU-39', 'price' => 550000, 'stock' => 10, 'attrs' => ['color' => 'Xanh Pastel', 'size' => '39']],
                ['sku' => 'SH-OXF-BLU-40', 'price' => 550000, 'stock' => 10, 'attrs' => ['color' => 'Xanh Pastel', 'size' => '40']],
                ['sku' => 'SH-OXF-WHT-39', 'price' => 550000, 'stock' => 10, 'attrs' => ['color' => 'Trắng', 'size' => '39']],
                ['sku' => 'SH-OXF-WHT-40', 'price' => 550000, 'stock' => 10, 'attrs' => ['color' => 'Trắng', 'size' => '40']],
            ],
            'images' => [
                'https://images.unsplash.com/photo-1596755094514-f87e34085b2c?auto=format&fit=crop&q=80&w=800',
                'https://images.unsplash.com/photo-1598033129183-c4f50c736f10?auto=format&fit=crop&q=80&w=800',
            ]
        ],
        [
            'name' => 'Slim Fit Selvedge Jeans',
            'slug' => 'slim-fit-selvedge-jeans',
            'description' => "Quần Jeans Selvedge cao cấp, form Slim Fit.\nChất vải Denim Nhật Bản 14oz, bền bỉ theo thời gian.",
            'category_id' => $catIds[2],
            'price' => 1200000,
            'original_price' => 1500000,
            'is_new' => 0,
            'is_featured' => 1,
            'stock' => 30,
            'sku' => 'JN-SLV-001',
            'variants' => [
                ['sku' => 'JN-SLV-IND-30', 'price' => 1200000, 'stock' => 5, 'attrs' => ['color' => 'Indigo', 'size' => '30']],
                ['sku' => 'JN-SLV-IND-31', 'price' => 1200000, 'stock' => 5, 'attrs' => ['color' => 'Indigo', 'size' => '31']],
                ['sku' => 'JN-SLV-IND-32', 'price' => 1200000, 'stock' => 5, 'attrs' => ['color' => 'Indigo', 'size' => '32']],
                ['sku' => 'JN-SLV-BLK-30', 'price' => 1200000, 'stock' => 5, 'attrs' => ['color' => 'Black', 'size' => '30']],
            ],
            'images' => [
                'https://images.unsplash.com/photo-1542272617-08f086303b96?auto=format&fit=crop&q=80&w=800',
                'https://images.unsplash.com/photo-1541099649105-f69ad21f3246?auto=format&fit=crop&q=80&w=800',
            ]
        ],
        [
            'name' => 'Varsity Bomber Jacket',
            'slug' => 'varsity-bomber-jacket',
            'description' => "Áo khoác Varsity Bomber phối tay da.\nPhong cách học đường năng động, ấm áp.",
            'category_id' => $catIds[3],
            'price' => 850000,
            'original_price' => 0,
            'is_new' => 1,
            'is_featured' => 0,
            'stock' => 40,
            'sku' => 'JK-VAR-001',
            'variants' => [
                ['sku' => 'JK-VAR-GRN-M', 'price' => 850000, 'stock' => 10, 'attrs' => ['color' => 'Xanh Rêu', 'size' => 'M']],
                ['sku' => 'JK-VAR-GRN-L', 'price' => 850000, 'stock' => 10, 'attrs' => ['color' => 'Xanh Rêu', 'size' => 'L']],
            ],
            'images' => [
                'https://images.unsplash.com/photo-1551028919-ac66e6a39d44?auto=format&fit=crop&q=80&w=800',
                'https://images.unsplash.com/photo-1591047139829-d91aecb6caea?auto=format&fit=crop&q=80&w=800',
            ]
        ],
        [
            'name' => 'Minimalist Canvas Tote',
            'slug' => 'minimalist-canvas-tote',
            'description' => "Túi Tote vải Canvas dày dặn, thiết kế tối giản.\nĐựng vừa laptop 15 inch, có ngăn nhỏ bên trong.",
            'category_id' => $catIds[4],
            'price' => 180000,
            'original_price' => 0,
            'is_new' => 0,
            'is_featured' => 0,
            'stock' => 100,
            'sku' => 'AC-TOT-001',
            'variants' => [
                ['sku' => 'AC-TOT-BG', 'price' => 180000, 'stock' => 50, 'attrs' => ['color' => 'Be Trung Tính']],
                ['sku' => 'AC-TOT-BLK', 'price' => 180000, 'stock' => 50, 'attrs' => ['color' => 'Đen']],
            ],
            'images' => [
                'https://images.unsplash.com/photo-1544816155-12df9643f363?auto=format&fit=crop&q=80&w=800',
                'https://images.unsplash.com/photo-1590874103328-3da8eedeb86e?auto=format&fit=crop&q=80&w=800',
            ]
        ]
    ];

    $stmtProd = $pdo->prepare("INSERT INTO products (name, slug, description, category_id, is_new, is_featured, is_active, price, original_price, stock_quantity, sku) VALUES (:name, :slug, :description, :cat_id, :new, :featured, 1, :price, :orig_price, :stock, :sku)");
    $stmtVar = $pdo->prepare("INSERT INTO product_variants (product_id, sku, price, original_price, stock_quantity, attributes) VALUES (:pid, :sku, :price, :orig_price, :stock, :attrs)");
    $stmtImg = $pdo->prepare("INSERT INTO product_images (product_id, image_path, type) VALUES (:pid, :path, 'main')");

    foreach ($products as $p) {
        $stmtProd->execute([
            ':name' => $p['name'],
            ':slug' => $p['slug'],
            ':description' => $p['description'],
            ':cat_id' => $p['category_id'],
            ':new' => $p['is_new'],
            ':featured' => $p['is_featured'],
            ':price' => $p['price'],
            ':orig_price' => $p['original_price'],
            ':stock' => $p['stock'],
            ':sku' => $p['sku'],
        ]);
        $prodId = $pdo->lastInsertId();

        foreach ($p['variants'] as $v) {
            $stmtVar->execute([
                ':pid' => $prodId,
                ':sku' => $v['sku'],
                ':price' => $v['price'],
                ':orig_price' => $p['original_price'],
                ':stock' => $v['stock'],
                ':attrs' => json_encode($v['attrs']),
            ]);
        }

        foreach ($p['images'] as $img) {
            $stmtImg->execute([
                ':pid' => $prodId,
                ':path' => $img,
            ]);
        }
    }

    echo "Full database seeded successfully!\n";

} catch (PDOException $e) {
    echo "Seeding failed: " . $e->getMessage();
}
