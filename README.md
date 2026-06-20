# Fertilizer Shop Management System

A Node.js and Express-based ERP and Shop Management system for fertilizer sales, billing, inventory, and customer/supplier ledger management.

---

## 🚀 Quick Start (Docker Compose - Recommended)

The easiest way to start both the Node.js backend application and the MySQL database is using Docker Compose.

### Prerequisites
* [Docker Desktop](https://www.docker.com/products/docker-desktop/) installed and running.

### Setup & Run
1. Open your terminal in the `fertilizer-shop` directory.
2. Run the services in the background:
   ```bash
   docker compose up -d
   ```
3. Docker will automatically pull the MySQL image, build the Node.js backend, initialize the database using `database.sql`, and start both containers.
4. Open your browser and go to: `http://localhost:8000`
5. To stop the containers, run:
   ```bash
   docker compose down
   ```

---

## 🛠️ Manual Setup (Local Node.js & MySQL)

If you prefer to run the database and server locally on your machine without Docker:

### Prerequisites
* [Node.js](https://nodejs.org/) (v18 or higher recommended)
* A local MySQL server running (e.g., MySQL Installer, DBeaver, or another local MySQL service).

### Setup Steps
1. **Configure Environment Variables:**
   * Create a file named `.env` in the root folder of the project.
   * Copy the values from `.env.example` and customize them to fit your local MySQL configuration:
     ```env
     DB_HOST=127.0.0.1
     DB_PORT=3307
     DB_USER=root
     DB_PASSWORD=
     DB_NAME=fertilizer_shop
     ```

2. **Initialize MySQL Database:**
   * Create a MySQL database named `fertilizer_shop`.
   * Import the [database.sql](file:///c:/Users/ajayd/OneDrive/Desktop/shop%20mangement/fertilizer-shop/database.sql) file to set up the initial tables and seed data.

3. **Install Dependencies:**
   * Run the following command in the root folder:
     ```bash
     npm install
     ```

4. **Start the Backend Server:**
   * For development (starts server with reload watching):
     ```bash
     npm run dev
     ```
   * The server will start and output: `🚀 AgriBiz Express Server is running on http://localhost:8000`
   * Open your browser and go to `http://localhost:8000`.

---

## 💻 Frontend Development (Vite + React)

The frontend project is located in the [greengrow-frontend](file:///c:/Users/ajayd/OneDrive/Desktop/shop%20mangement/fertilizer-shop/greengrow-frontend) subdirectory.

1. Navigate to the frontend directory:
   ```bash
   cd greengrow-frontend
   ```
2. Install dependencies:
   ```bash
   npm install
   ```
3. Start the Vite development server:
   ```bash
   npm run dev
   ```
4. Access the frontend app in your browser at the address provided in your terminal (usually `http://localhost:5173`). Vite is configured to proxy API requests to the backend server running on `http://localhost:8000`.

---

## 🔑 Login Credentials

* **Username:** `admin`
* **Password:** `admin123`

You can also create customer accounts or register new administrators through the web interface.

---

## 📦 Features

* **Billing & Invoices**: Fast, print-ready checkout and receipt generation.
* **Fertilizer Inventory**: Manage products, stock quantities, HSN codes, and low stock thresholds.
* **Ledgers & Accounting**: Track customer and supplier credits, ledger balances, and payment records.
* **Audit Logging**: Secure background user logging of key operations.
* **Advisory & Dashboard**: Integration of live mandhi prices and weather alerts.

---

## ⚙️ Technologies Used

* **Backend**: Node.js, Express, EJS Templates, `mysql2` client
* **Frontend (optional API client)**: React, Vite, Tailwind CSS
* **Database**: MySQL 8.0
* **Containerization**: Docker, Docker Compose