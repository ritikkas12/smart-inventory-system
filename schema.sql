-- Smart Inventory Management System Schema
-- MySQL 5.7+ / 8.0+

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS purchase_orders;
DROP TABLE IF EXISTS forecasts;
DROP TABLE IF EXISTS stock_movements;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS suppliers;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

-- 1. Users Table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    role ENUM('admin', 'staff') DEFAULT 'staff',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Suppliers Table
CREATE TABLE suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    contact_email VARCHAR(100),
    phone VARCHAR(30),
    address TEXT,
    lead_time_days INT NOT NULL DEFAULT 7,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Products Table
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    category VARCHAR(100),
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    stock INT NOT NULL DEFAULT 0,
    reorder_point INT NOT NULL DEFAULT 0,
    safety_stock INT NOT NULL DEFAULT 0,
    supplier_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_products_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Stock Movements Table
CREATE TABLE stock_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    type ENUM('in', 'out') NOT NULL,
    quantity INT NOT NULL,
    reference VARCHAR(255),
    notes TEXT,
    moved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    user_id INT,
    CONSTRAINT fk_sm_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_sm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_product_type_date (product_id, type, moved_at),
    INDEX idx_moved_at (moved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Forecasts Table
CREATE TABLE forecasts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    forecast_date DATE NOT NULL,
    predicted_qty DECIMAL(10,2) NOT NULL,
    model_used VARCHAR(50) DEFAULT 'exponential_smoothing',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_forecasts_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY uq_product_forecast_date (product_id, forecast_date),
    INDEX idx_forecast_date (forecast_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Purchase Orders Table
CREATE TABLE purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    supplier_id INT,
    quantity INT NOT NULL,
    unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('suggested', 'approved', 'sent', 'received', 'cancelled') NOT NULL DEFAULT 'suggested',
    suggested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_at TIMESTAMP NULL DEFAULT NULL,
    sent_at TIMESTAMP NULL DEFAULT NULL,
    received_at TIMESTAMP NULL DEFAULT NULL,
    notes TEXT,
    CONSTRAINT fk_po_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    INDEX idx_po_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
