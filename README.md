# Fertilizer Shop Management System

## Project Setup

1. Install XAMPP Server or another PHP + MySQL stack
2. Start Apache and MySQL services
3. Copy the `fertilizer-shop` folder to `C:\xampp\htdocs\` (or use PHP built-in server)
4. Open phpMyAdmin (http://localhost/phpmyadmin)
5. Create database `fertilizer_shop`
6. Import the `database.sql` file into the database
7. Open browser and go to `http://localhost/fertilizer-shop/`

Or run the project with the built-in PHP server:

    "C:\xampp\php\php.exe" -S localhost:8000

Then open `http://localhost:8000` in the browser.

If your MySQL root user has a password, update `db.php` with the correct credentials or set these environment variables before running the project:
- `DB_HOST`
- `DB_PORT`
- `DB_USER`
- `DB_PASSWORD`
- `DB_NAME`

If you have XAMPP installed and the default MySQL service is already in use, you can start XAMPP MySQL on a second port such as 3307 and then set `DB_PORT=3307`.

Example for XAMPP alternate MySQL port:

    "C:\xampp\mysql\bin\mysqld.exe" --defaults-file="C:\xampp\mysql\bin\my.ini" --port=3307 --socket="C:/xampp/mysql/mysql2.sock"

Then use:

- `DB_HOST=127.0.0.1`
- `DB_PORT=3307`
- `DB_USER=root`
- `DB_PASSWORD=` (blank)
- `DB_NAME=fertilizer_shop`

If PHP is not on your PATH, run the app with XAMPP PHP:

    "C:\xampp\php\php.exe" -S localhost:8000

## Login Credentials

- Username: admin
- Password: admin123

You can also create additional admin accounts by opening `register.php` in your browser after the project is running.

### Customer Login
Customers can create their own accounts using `customer_register.php`, login using `customer_login.php`, and buy products through `customer_shop.php`.

## Features

- **Secure Login**: Session-based authentication with logout
- **Dashboard**: Overview with total sales, stock, customers, and low stock alerts
- **Fertilizer Management**: Add, view, search, update, delete fertilizers
- **Stock Management**: Automatic stock updates on sales/purchases, low stock alerts
- **Billing System**: Generate bills with customer and fertilizer dropdowns, auto-calculate totals
- **Customer Management**: Add, view, update, delete customers
- **Supplier Management**: Add, view, update, delete suppliers, add purchases
- **Purchase Management**: Record purchases from suppliers, update stock
- **History Pages**: View sales history and purchase history
- **Reports**: Daily and monthly sales/purchase reports with totals

## Technologies Used

- PHP
- MySQL
- HTML
- CSS
- Sessions for authentication

## Database Tables

- admin: Admin login
- fertilizers: Fertilizer inventory
- customers: Customer details
- suppliers: Supplier details
- sales: Sales transactions
- purchases: Purchase transactions