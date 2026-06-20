-- Production Database Schema for PostgreSQL

CREATE TABLE IF NOT EXISTS admin (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50),
    password VARCHAR(100)
);

CREATE TABLE IF NOT EXISTS fertilizers (
    id SERIAL PRIMARY KEY,
    barcode VARCHAR(100) DEFAULT '',
    fertilizer_name VARCHAR(100),
    company_name VARCHAR(100),
    quantity INT,
    price DECIMAL(10,2)
);

CREATE TABLE IF NOT EXISTS customers (
    id SERIAL PRIMARY KEY,
    customer_name VARCHAR(100),
    mobile VARCHAR(15),
    address TEXT,
    password VARCHAR(100) DEFAULT '',
    gstin VARCHAR(30) DEFAULT '',
    points INT DEFAULT 0
);

CREATE TABLE IF NOT EXISTS suppliers (
    id SERIAL PRIMARY KEY,
    supplier_name VARCHAR(100),
    mobile VARCHAR(15),
    address TEXT
);

CREATE TABLE IF NOT EXISTS sales (
    id SERIAL PRIMARY KEY,
    customer_name VARCHAR(100),
    fertilizer_name VARCHAR(100),
    quantity INT,
    total_price DECIMAL(10,2),
    sale_date DATE
);

CREATE TABLE IF NOT EXISTS purchases (
    id SERIAL PRIMARY KEY,
    supplier_name VARCHAR(100),
    fertilizer_name VARCHAR(100),
    quantity INT,
    cost DECIMAL(10,2),
    purchase_date DATE
);

CREATE TABLE IF NOT EXISTS orders (
    id SERIAL PRIMARY KEY,
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
VALUES (1, 'admin', 'admin123') 
ON CONFLICT (id) DO NOTHING;

INSERT INTO fertilizers (id, fertilizer_name, company_name, quantity, price) 
VALUES 
(1, 'Urea', 'ABC Company', 100, 50.00),
(2, 'DAP', 'XYZ Ltd', 50, 80.00),
(3, 'Potash', 'Fertilizer Corp', 75, 60.00),
(4, 'Organic Compost', 'Green Farms', 20, 30.00)
ON CONFLICT (id) DO NOTHING;

INSERT INTO customers (id, customer_name, mobile, address) 
VALUES
(1, 'John Doe', '1234567890', '123 Main St'),
(2, 'Jane Smith', '0987654321', '456 Oak Ave')
ON CONFLICT (id) DO NOTHING;

INSERT INTO suppliers (id, supplier_name, mobile, address) 
VALUES
(1, 'Supplier A', '1111111111', '789 Pine Rd'),
(2, 'Supplier B', '2222222222', '321 Elm St')
ON CONFLICT (id) DO NOTHING;

-- Reset SERIAL sequences to prevent duplicate key errors on auto-increment insert
SELECT setval(pg_get_serial_sequence('admin', 'id'), COALESCE(max(id), 1)) FROM admin;
SELECT setval(pg_get_serial_sequence('fertilizers', 'id'), COALESCE(max(id), 1)) FROM fertilizers;
SELECT setval(pg_get_serial_sequence('customers', 'id'), COALESCE(max(id), 1)) FROM customers;
SELECT setval(pg_get_serial_sequence('suppliers', 'id'), COALESCE(max(id), 1)) FROM suppliers;
