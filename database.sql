CREATE DATABASE fertilizer_shop;
USE fertilizer_shop;

CREATE TABLE admin (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50),
    password VARCHAR(100)
);

CREATE TABLE fertilizers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fertilizer_name VARCHAR(100),
    company_name VARCHAR(100),
    quantity INT,
    price DECIMAL(10,2)
);

CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(100),
    mobile VARCHAR(15),
    address TEXT,
    password VARCHAR(100) DEFAULT '',
    gstin VARCHAR(30) DEFAULT ''
);

CREATE TABLE suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(100),
    mobile VARCHAR(15),
    address TEXT
);

CREATE TABLE sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(100),
    fertilizer_name VARCHAR(100),
    quantity INT,
    total_price DECIMAL(10,2),
    sale_date DATE
);

CREATE TABLE purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(100),
    fertilizer_name VARCHAR(100),
    quantity INT,
    cost DECIMAL(10,2),
    purchase_date DATE
);

INSERT INTO admin (username, password) VALUES ('admin', 'admin123');

INSERT INTO fertilizers (fertilizer_name, company_name, quantity, price) VALUES
('Urea', 'ABC Company', 100, 50.00),
('DAP', 'XYZ Ltd', 50, 80.00),
('Potash', 'Fertilizer Corp', 75, 60.00),
('Organic Compost', 'Green Farms', 20, 30.00);

INSERT INTO customers (customer_name, mobile, address) VALUES
('John Doe', '1234567890', '123 Main St'),
('Jane Smith', '0987654321', '456 Oak Ave');

INSERT INTO suppliers (supplier_name, mobile, address) VALUES
('Supplier A', '1111111111', '789 Pine Rd'),
('Supplier B', '2222222222', '321 Elm St');