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

This repo supports a local `.env` file for the PHP backend and a frontend `.env` file for Vite.

### Backend local environment
Create a root `.env` file next to `db.php` with values like:

```text
DB_HOST=127.0.0.1
DB_PORT=3307
DB_USER=root
DB_PASSWORD=
DB_NAME=fertilizer_shop
VITE_API_BASE_URL=
```

The PHP app will automatically load `.env` values if the file exists.

### Frontend local environment
Create `greengrow-frontend/.env` with:

```text
VITE_API_BASE_URL=
```

Leave it blank for local development because Vite proxies `.php` requests to `http://localhost:8000`.

If you are deploying the React frontend separately (for example, on Render), set the frontend environment variable `VITE_API_BASE_URL` to the URL of your PHP backend. This ensures the React app can call `api_login.php` and other PHP endpoints correctly in production.

### GitHub Actions / Render environment setup
The existing CI workflow now reads these secrets if they are configured in GitHub:
- `VITE_API_BASE_URL`
- `RENDER_API_KEY`
- `RENDER_SERVICE_ID`

If you set `RENDER_API_KEY` and `RENDER_SERVICE_ID`, the workflow will deploy the frontend service automatically after a successful build.

### Render deployment checklist

- Set `VITE_API_BASE_URL=https://<your-backend-service>.onrender.com` in the Render frontend service environment variables.
- Rebuild/redeploy the frontend service after changing the variable.
- Verify the backend service is live and reachable from the deployed frontend.
- Do not rely on the local Vite proxy in production; it only works during local development.

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