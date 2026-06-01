-- Production Database Schema
-- To prevent errors in free hosting like InfinityFree, the database creation script
-- should not contain hardcoded "CREATE DATABASE" or "USE" statements since the host generates
-- database names automatically (e.g. epiz_12345678_db).

CREATE TABLE IF NOT EXISTS admin (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50),
    password VARCHAR(100)
);

CREATE TABLE IF NOT EXISTS fertilizers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    barcode VARCHAR(100) DEFAULT '',
    fertilizer_name VARCHAR(100),
    company_name VARCHAR(100),
    quantity INT,
    price DECIMAL(10,2)
);

CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(100),
    mobile VARCHAR(15),
    address TEXT,
    password VARCHAR(100) DEFAULT '',
    gstin VARCHAR(30) DEFAULT '',
    points INT DEFAULT 0
);

CREATE TABLE IF NOT EXISTS suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(100),
    mobile VARCHAR(15),
    address TEXT
);

CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(100),
    fertilizer_name VARCHAR(100),
    quantity INT,
    total_price DECIMAL(10,2),
    sale_date DATE
);

CREATE TABLE IF NOT EXISTS purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(100),
    fertilizer_name VARCHAR(100),
    quantity INT,
    cost DECIMAL(10,2),
    purchase_date DATE
);

CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT,
    fertilizer_id INT,
    quantity INT,
    total_price DECIMAL(10,2),
    status VARCHAR(50) DEFAULT 'Pending',
    order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    points_earned INT DEFAULT 0
);

-- Seed Data (Only insert if not exists)
INSERT INTO admin (id, username, password) 
SELECT 1, 'admin', 'admin123' 
WHERE NOT EXISTS (SELECT 1 FROM admin WHERE id = 1);

INSERT INTO fertilizers (id, fertilizer_name, company_name, quantity, price) 
VALUES 
(1, 'Urea', 'ABC Company', 100, 50.00),
(2, 'DAP', 'XYZ Ltd', 50, 80.00),
(3, 'Potash', 'Fertilizer Corp', 75, 60.00),
(4, 'Organic Compost', 'Green Farms', 20, 30.00)
ON DUPLICATE KEY UPDATE id=id;

INSERT INTO customers (id, customer_name, mobile, address) 
VALUES
(1, 'John Doe', '1234567890', '123 Main St'),
(2, 'Jane Smith', '0987654321', '456 Oak Ave')
ON DUPLICATE KEY UPDATE id=id;

INSERT INTO suppliers (id, supplier_name, mobile, address) 
VALUES
(1, 'Supplier A', '1111111111', '789 Pine Rd'),
(2, 'Supplier B', '2222222222', '321 Elm St')
ON DUPLICATE KEY UPDATE id=id;
