CREATE TABLE admin (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50),
    password VARCHAR(100)
);

CREATE TABLE fertilizers (
    id SERIAL PRIMARY KEY,
    fertilizer_name VARCHAR(100),
    company_name VARCHAR(100),
    quantity INT,
    price DECIMAL(10,2)
);

CREATE TABLE customers (
    id SERIAL PRIMARY KEY,
    customer_name VARCHAR(100),
    mobile VARCHAR(15),
    address TEXT,
    password VARCHAR(100) DEFAULT '',
    gstin VARCHAR(30) DEFAULT ''
);

CREATE TABLE suppliers (
    id SERIAL PRIMARY KEY,
    supplier_name VARCHAR(100),
    mobile VARCHAR(15),
    address TEXT
);

CREATE TABLE sales (
    id SERIAL PRIMARY KEY,
    customer_name VARCHAR(100),
    fertilizer_name VARCHAR(100),
    quantity INT,
    total_price DECIMAL(10,2),
    sale_date DATE
);

CREATE TABLE purchases (
    id SERIAL PRIMARY KEY,
    supplier_name VARCHAR(100),
    fertilizer_name VARCHAR(100),
    quantity INT,
    cost DECIMAL(10,2),
    purchase_date DATE
);

CREATE TABLE orders (
    id SERIAL PRIMARY KEY,
    customer_id INT,
    fertilizer_id INT,
    quantity INT,
    total_price DECIMAL(10,2),
    status VARCHAR(50) DEFAULT 'Pending',
    order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    points_earned INT DEFAULT 0
);

INSERT INTO admin (username, password) VALUES ('admin', '$2a$10$9X2aG0J10F8KqL93H3/FmOrhLd/Q5u02iQ9kK6vO1yZ92H.xQ5Ofe'); -- admin123 hashed to match production standard, or we can keep standard admin123 plaintext since migrations.js auto-hashes it on direct match. Let's use plaintext 'admin123' as per original file seed.
UPDATE admin SET password = 'admin123' WHERE username = 'admin';

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