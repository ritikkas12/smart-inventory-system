# 📦 Smart Inventory & Auto-Replenishment System

A full-stack, enterprise-grade inventory management web application with **statistical demand forecasting** and **automated purchase order replenishment**.

---

## 🛠️ Tech Stack

- **Backend:** PHP 7.4+ / 8.x (Native PDO, Prepared Statements, Secure Sessions, `password_hash`)
- **Database:** MySQL 5.7+ / 8.0+ (InnoDB, Foreign Keys, Row-Level Locking, ACID Transactions)
- **Frontend:** Semantic HTML5, CSS3 Custom Properties (Modern Flat Business UI), Vanilla JS + [Chart.js](https://www.chartjs.org/) via CDN
- **Forecasting Engine:** Python 3.8+ (`pandas`, `numpy`, `statsmodels`, `pymysql`), cron-ready

---

## 🚀 Key Features

1. **Authentication & Session Security:**
   - Role-based session management (`admin`, `staff`).
   - Secure passwords via `password_hash()` and `password_verify()` with bcrypt.
   - Session fixation defense (`session_regenerate_id()`).

2. **Products & Suppliers CRUD:**
   - Search by SKU/name, category filtering, supplier assignment.
   - Dynamic stock health indicators (Healthy, Low, Critical, Out of Stock).
   - Full input sanitization, CSRF token validation on all mutating forms, XSS escaping via `e()`.

3. **Transaction-Safe Stock In/Out:**
   - Real-time stock receipts (IN) and sales/shipment dispatches (OUT).
   - **ACID Transaction Protection with Row-Level Locking** (`SELECT ... FOR UPDATE`): Prevents race conditions and strictly forbids negative stock.

4. **Live Dashboard:**
   - Key operational KPIs (Total SKUs, Inventory Valuation, Low Stock Alerts, Pending POs).
   - Urgent stock replenishment warnings table.
   - **Interactive Demand Forecast Chart:** Dynamically loads 30-day historical usage alongside 14-day future predictions via Chart.js and asynchronous JSON API.

5. **Python Demand Forecasting Engine (`forecast.py`):**
   - Ingests past 90 days of daily OUT movements per SKU.
   - Computes dual models: **7-Day Simple Moving Average** and **Exponential Smoothing** (`statsmodels.tsa.holtwinters`).
   - Evaluates in-sample Root Mean Squared Error (RMSE) and selects the best-fit model.
   - Projects 14 days forward and persists predictions into MySQL `forecasts` table.

6. **Statistical Auto-Replenishment Engine:**
   - **Safety Stock Formula:**
     $$\text{Safety Stock} = Z \times \sigma(\text{daily usage}) \times \sqrt{\text{Lead Time}}$$
     *(where $Z = 1.65$ represents a 95% cycle service level)*
   - **Reorder Point (ROP):**
     $$\text{Reorder Point} = (\text{Avg Daily Usage} \times \text{Lead Time}) + \text{Safety Stock}$$
   - When $\text{Current Stock} \le \text{Reorder Point}$, automatically drafts a **Suggested Purchase Order**:
     $$\text{Order Qty} = \max\left(1, \lceil \text{Projected Demand over (Lead Time + Review Period)} - \text{Current Stock} \rceil\right)$$

7. **Purchase Order Lifecycle Workflow:**
   - State Machine: `Suggested` &rarr; `Approved` &rarr; `Sent` (supplier email trigger) &rarr; `Received` (automatically increments product stock and logs an IN movement inside a database transaction).
   - Allows cancellation of open orders.

8. **Comprehensive Reports & Analytics:**
   - **Dead Stock Analysis:** Tracks items with 0 or low (< 5) sales in the last 60 days, showing total capital tied up.
   - **ABC Inventory Analysis (80/20 Pareto):** Classifies products into Class A (top 80% revenue), Class B (next 15%), and Class C (bottom 5%) with interactive Donut chart.
   - **Forecast Accuracy Benchmarking (MAPE):** Evaluates past predictions against actual sales to calculate Mean Absolute Percentage Error with accuracy grading badges and bar chart.

---

## 📁 Directory Structure

```text
smart-inventory-system/
├── public/                       # Web Document Root
│   ├── index.php                 # Live Dashboard & Forecasting charts
│   ├── login.php                 # Authentication login
│   ├── logout.php                # Session termination
│   ├── products.php              # Products listing, search, deletion
│   ├── product_form.php          # Product create & edit
│   ├── suppliers.php             # Suppliers directory
│   ├── supplier_form.php         # Supplier create & edit
│   ├── stock_movement.php        # Stock In/Out + transaction audit log
│   ├── purchase_orders.php       # PO workflow & replenishment engine
│   ├── reports.php               # Dead Stock, ABC analysis, MAPE report
│   ├── api/
│   │   └── forecast_data.php     # JSON API endpoint for Chart.js
│   └── assets/
│       ├── css/
│       │   └── style.css         # Modern responsive application styles
│       └── js/
│           └── app.js            # Frontend interactivity & chart loaders
├── includes/                     # Reusable PHP Libraries
│   ├── config.php                # Database credentials & app constants
│   ├── db.php                    # PDO Singleton connection helper
│   ├── auth.php                  # Authentication & session controls
│   ├── csrf.php                  # Cryptographic CSRF token generation & validation
│   ├── helpers.php               # Formatting, pagination, XSS escape (e)
│   ├── header.php                # Top navigation & flash alert renderer
│   └── footer.php                # Closing markup & scripts
├── forecast/                     # Python Machine Learning Subsystem
│   ├── forecast.py               # Time-series forecasting script
│   └── requirements.txt          # Python dependencies
├── schema.sql                    # Complete relational database DDL
├── seed.sql                      # Demo users, suppliers, products, 90d sales
└── README.md                     # Documentation
```

---

## ⚙️ Installation & Setup

### 1. Database Setup
Ensure MySQL is running, then create the database and import schema + seed data:

```bash
# Log into MySQL and create database
mysql -u root -p -e "CREATE DATABASE smart_inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Import schema (tables, foreign keys, indexes)
mysql -u root -p smart_inventory < schema.sql

# Import seed data (sample suppliers, products, 90 days of sales movements, forecasts)
mysql -u root -p smart_inventory < seed.sql
```

### 2. Configure PHP Application
Check or edit `includes/config.php` to set your MySQL credentials (or pass via environment variables):
- `DB_HOST`: default `127.0.0.1`
- `DB_PORT`: default `3306`
- `DB_NAME`: default `smart_inventory`
- `DB_USER`: default `root`
- `DB_PASS`: default `""` (empty)

### 3. Install Python Dependencies for Forecasting
```bash
cd forecast
pip install -r requirements.txt
```

### 4. Run the Forecasting Engine
Generate initial forecasts across all products:
```bash
python3 forecast/forecast.py
```

### 5. Launch the Web Application
Using PHP's built-in development server pointing to `/public`:
```bash
php -S localhost:8080 -t public
```
Now navigate to: **[http://localhost:8080](http://localhost:8080)**

---

## 🔑 Default Login Credentials

| Username | Password   | Role   |
| :---     | :---       | :---   |
| `admin`  | `password` | Admin  |
| `staff`  | `password` | Staff  |

---

## ⏰ Automated Cron Job Setup

To run the demand forecast engine automatically every 6 hours and update predictions in MySQL, add this entry to your system crontab (`crontab -e`):

```bash
0 */6 * * * cd "/Users/ritik/smart inventory system" && /usr/bin/python3 forecast/forecast.py >> /tmp/inventory_forecast.log 2>&1
```

---

## 🛡️ Security Implementation Summary

- **SQL Injection Prevention:** Every query with dynamic parameters exclusively utilizes PDO prepared statements with parameter binding.
- **Cross-Site Scripting (XSS):** Every dynamic output rendered in templates is filtered through the helper function `e($str)` utilizing `htmlspecialchars($str, ENT_QUOTES, 'UTF-8')`.
- **Cross-Site Request Forgery (CSRF):** Mutating requests (POST) require a 64-character cryptographic random token checked using `hash_equals()`.
- **Negative Stock & Race Condition Protection:** The stock movement and PO receiving engines execute within isolated transactions with row locking (`SELECT stock FROM products WHERE id = ? FOR UPDATE`).
