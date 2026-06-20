# Build Prompt: Cow Management & Diagnosis System

Use this prompt as-is with a developer or an AI coding assistant to build the system.

---

## PROMPT START

Build a full-stack **Cow Management & Diagnosis System** web application for a dairy/cattle farm. The system must be deployable on standard shared/VPS hosting (Hostinger) using only:

- **Frontend:** HTML5, CSS3, vanilla JavaScript (no frontend framework)
- **Backend:** PHP (procedural or simple OOP, no framework like Laravel)
- **Database:** PostgreSQL
- **Architecture:** Server-rendered PHP pages + small AJAX/fetch calls to PHP endpoints returning JSON where dynamic updates are needed (charts, live tables). No Node.js, no build step, no SPA framework.

### 1. Project Goals
A farm management system with role-based dashboards for tracking cows, milk production, sales, workers, feed/medicine, equipment, maintenance, and finances, with admin-configurable module visibility.

### 2. Roles & Permissions (implement as a `roles` table + middleware check on every page/endpoint)

| Role | Permissions |
|---|---|
| Admin | Full access to everything; can enable/disable modules; manage all data and users |
| Cowboy/Worker | Create-only access to: feeding logs, milking logs, medicine-given logs, cleaning logs, daily weight entries, equipment-use logs. No delete, no financial access |
| Accountant | View financial summary; record expenses; update cow sales, meat sales, milk revenue; generate reports. No cow management, no treatments |
| Veterinarian | Update cow health records, treatments, vaccinations, medicine administered. No sales/financial access |
| Reception/Scheduler | Create/edit schedules for sales, breeding, vet visits, milking/treatment appointments. No financial or worker-salary access |

Use PHP sessions for auth. Store password hashes with PHP's `password_hash()` (bcrypt). Implement a `requireRole(['admin','vet'])` style guard function included at the top of every protected page and API endpoint.

### 3. Database Schema (PostgreSQL)

Design normalized tables covering at minimum:
- `users` (id, name, email, password_hash, role, status, created_at)
- `cows` (id, tag_number, breed, birth_date, purchase_price, purchase_date, current_weight, health_status, is_pregnant, status [active/pregnant/lactating/dry/sick/quarantine/ready_for_sale/sold/deceased], photo_url, created_at)
- `cow_weight_logs` (id, cow_id, weight, recorded_at, recorded_by)
- `milk_records` (id, cow_id, liters, fat_percentage, contamination_flag, recorded_at, recorded_by)
- `milk_price_history` (id, price_per_liter, effective_date)
- `cow_sales` (id, cow_id, buyer_name, sale_price, sale_date, profit_loss, approved_by)
- `meat_sales` (id, cow_id, kg_sold, price_per_kg, total_revenue, event_type [regular/eid/gift], sale_date)
- `workers` (id, user_id, salary, hire_date, termination_date, status)
- `worker_tasks` (id, worker_id, task_type, assigned_date, completed_at, status)
- `feed_inventory` (id, item_name, quantity, unit, reorder_threshold, last_updated)
- `medicine_inventory` (id, item_name, quantity, unit, expiry_date, reorder_threshold)
- `treatments` (id, cow_id, medicine_id, administered_by, dosage, cost, treatment_date, notes)
- `equipment` (id, name, purchase_date, status [operational/maintenance/damaged], lifespan_months, last_maintenance_date)
- `maintenance_logs` (id, equipment_id OR area_id, description, cost, scheduled_date, completed_date)
- `farm_areas` (id, name, type [barn/storage/etc], capacity)
- `area_purchases` (id, area_id, item, cost, purchase_date)
- `finance_transactions` (id, type [income/expense], category, amount, related_module, reference_id, transaction_date, recorded_by, approved_by)
- `alerts` (id, type, severity, message, related_table, related_id, is_read, created_at)
- `module_settings` (id, module_name, is_enabled, updated_by)
- `audit_log` (id, user_id, action, table_name, record_id, old_value, new_value, created_at)
- `cow_symptoms` (id, cow_id, symptom, severity, recorded_by, recorded_at)
- `diagnosis_records` (id, cow_id, diagnosis, confidence_level, recommended_action, veterinarian_id, created_at)
- `breeding_records` (id, cow_id, heat_cycle_date, insemination_date, breeding_date, expected_calving_date, actual_calving_date, status, recorded_by)
- `calf_records` (id, breeding_record_id, mother_cow_id, calf_tag_number, birth_date, birth_weight, gender, status)

Use foreign keys, `CHECK` constraints (e.g., quantities ≥ 0), and indexes on frequently filtered columns (cow status, dates).

### 4. Folder Structure
```
/public_html
  /assets (css, js, images)
  /includes (db.php, auth.php, functions.php, role-guard.php)
  /api (ajax endpoints: get_milk_stats.php, get_alerts.php, etc — return JSON)
  /uploads (cows, treatments, equipment subfolders — configure so PHP scripts can't execute from here)
  /modules
    /cows
    /milk
    /sales
    /workers
    /feed-medicine
    /equipment
    /maintenance
    /finance
  /reports (PDF/Excel export scripts)
  index.php (login)
  dashboard.php
```

### 5. Core Pages/Modules to Build
1. **Login & session handling** with role-based redirect
2. **Admin dashboard**: module on/off toggles, KPI summary cards, alerts panel
3. **Cow management**: list (searchable/filterable/paginated), add/edit form, detail view with weight history chart, health status, pregnancy flag, mark-for-sale action
4. **Milk module**: daily entry form, weekly trend chart (Chart.js via CDN), revenue calculator, top-producing cows view, quality flags
5. **Sales & meat**: sale entry forms with auto profit/loss calculation, meat sale entry (kg × price), ceremonial event logging that auto-posts to finance
6. **Worker management**: CRUD, salary tracking, task assignment/completion tracking
7. **Feed & medicine**: inventory CRUD with low-stock alerts, treatment logging tied to cows
8. **Equipment**: CRUD, status pie chart, maintenance schedule table
9. **Farm maintenance**: area/infrastructure purchase logging, maintenance alerts
10. **Financial summary**: income/expense breakdown, auto net profit/loss, month-over-month and year-over-year comparison charts
11. **Alerts panel**: aggregated from low stock, sick cows, maintenance due, overdue tasks — color-coded by severity
12. **Reports**: PDF export (use a lightweight library like FPDF or TCPDF) and Excel export (PhpSpreadsheet or CSV) for each module
13. **Cow diagnosis**: vet-only symptom entry (temperature, heart rate, appetite, stool condition, milk abnormalities), diagnosis record creation with confidence level and recommended action, full diagnosis history timeline per cow
14. **Breeding management**: heat cycle log, insemination/breeding date entry, expected calving countdown, calf record creation upon calving, breeding reminders that feed into the alerts panel

### 6. Diagnosis Module
Build a dedicated diagnosis system for veterinarians, separate from general treatment logging:
- Record symptoms (free text or selectable list), with severity rating
- Record vital signs: temperature, heart rate, appetite status, stool condition
- Record milk abnormalities (color, consistency, blood presence)
- Combine symptom entries into a `diagnosis_records` entry with a diagnosis, a confidence level, and a recommended action
- Display full diagnosis history per cow as a chronological timeline, accessible from the cow detail page
- Only the Veterinarian and Admin roles can create or edit diagnosis records

### 7. Breeding Module
Track the full breeding lifecycle per cow:
- Heat cycle dates
- Insemination date
- Breeding date
- Expected calving date (auto-calculated from breeding date using standard gestation length, editable)
- Actual calving date
- Calf records (tag number, birth weight, gender, status) linked back to the breeding record and mother cow
- Auto-generate breeding/calving reminders that appear in the alerts panel as the expected calving date approaches

### 8. Image Management
Allow image uploads in these areas:
- Cow photos (profile image on the cow detail page)
- Treatment/diagnosis evidence photos
- Equipment photos
- Maintenance/repair photos

Store uploaded files under `/uploads/cows`, `/uploads/treatments`, `/uploads/equipment` respectively. Validate file type (jpg/png/webp only) and file size (e.g., max 5MB) server-side before accepting an upload, and rename files to avoid collisions/overwrites. Serve images through a controlled path, not by exposing the raw uploads directory listing.

### 9. Dashboard KPI Cards
The main admin dashboard must display these KPI cards explicitly (not just generic "summary" widgets):
- Total Cows
- Healthy Cows
- Sick Cows
- Pregnant Cows
- Today's Milk Production
- Monthly Milk Revenue
- Feed Stock Alerts
- Medicine Stock Alerts
- Equipment Under Maintenance
- Net Profit This Month

Each card should be clickable, linking through to the relevant module's filtered view (e.g., clicking "Sick Cows" opens the cow list pre-filtered to `status = sick`).

### 10. Cross-Cutting Requirements
- All forms validated both client-side (JS) and server-side (PHP), with clear inline error messages
- All SQL via **prepared statements** (PDO with PostgreSQL driver `pgsql`) — no raw string concatenation, to prevent SQL injection
- CSRF token on every form submission
- Every write action that affects sensitive data (cow records, finance, treatments, diagnoses) logged to `audit_log`
- Responsive layout (mobile-friendly, since workers may log entries from phones in the field)
- Charts via Chart.js (loaded from CDN, no build tooling)
- Use semantic HTML and a clean, farm-appropriate visual style — not a generic admin template look

### 11. Deployment Notes (Hostinger)
- Confirm whether the Hostinger plan is shared or VPS: **PostgreSQL is only available on Hostinger VPS or shared plans**, requiring manual installation; shared hosting plans do not support it. If on shared hosting, port the schema to MySQL/MariaDB instead.
- Use environment-based config (a `.env`-style PHP config file outside the web root, or constants in a non-public `config.php`) for DB credentials.
- Ensure PHP version compatibility (check Hostinger's available PHP version in hPanel) and enable the `pdo_pgsql` extension if using PostgreSQL on VPS.

## PROMPT END
