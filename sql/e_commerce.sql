CREATE TABLE product (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    image VARCHAR(200) DEFAULT 'default.jpg',
    video VARCHAR(255),
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    sale_price DECIMAL(10,2) DEFAULT 0.00,
    stock INT DEFAULT 0,
    category VARCHAR(20) DEFAULT 'Unisex', 
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_name VARCHAR(255),
    email VARCHAR(255),
    phone VARCHAR(50),
    city VARCHAR(100),
    country VARCHAR(100),
    postal VARCHAR(20),
    address TEXT,
    items TEXT,
    total FLOAT,
    payment_method VARCHAR(50),
    sale_status VARCHAR(20) DEFAULT 'Inactive',   -- 🔥 Added column
    status VARCHAR(50) DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_name VARCHAR(100) NOT NULL,
    discount_percent INT NOT NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    status ENUM('active','inactive') DEFAULT 'inactive',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL
);