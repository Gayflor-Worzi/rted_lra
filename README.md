# RETD_LRA Laravel Project

## Overview
RETD_LRA - Real Property Tax Administration & Enforcement System  
Laravel backend with API-first architecture. Web (React/Vite) and mobile (React Native/Expo) clients consume the REST API.

## Quick Start - Get Running Fastest

### 1. Start Laragon
- Open Laragon
- Click "Start All" (Apache, MySQL, Redis)
- Verify MySQL is **green** (running)

### 2. Environment Configuration
```bash
cd C:\laragon\www\rted_lra
cp .env.example .env  # if .env doesn't exist
# The .env already has:
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=retd_lra
DB_USERNAME=root
DB_PASSWORD=
APP_URL=http://localhost:8000
```

### 3. Install Dependencies
```bash
composer install
npm install
npm run build
```

### 4. Run Migrations
```bash
php artisan migrate --force
```

### 5. Start the API Server
```bash
php artisan serve
```
**App available at:** `http://localhost:8000`  
**API base URL:** `http://localhost:8000/api/v1`

### 6. Test the API is Running
Open your browser to: `http://localhost:8000/api/v1/test`

You should see a JSON response like:
```json
{
  "success": true,
  "data": "Laravel API is running",
  "message": "API v1 is operational",
  "errors": null
}
```

## API Testing Tools

### Using cURL (Windows PowerShell):
```powershell
# Test API root
curl http://localhost:8000/api/v1/test

# Test with pretty JSON
curl -s http://localhost:8000/api/v1/test | ConvertFrom-Json
```

### Using PowerShell:
```powershell
Invoke-WebRequest -Uri 'http://localhost:8000/api/v1/test' -Method GET | Select-Object -ExpandProperty Content
```

### Using PHP built-in:
```bash
php -r 'echo json_encode(file_get_contents("http://localhost:8000/api/v1/test"), JSON_PRETTY_PRINT);'
```

## Available Scripts

```bash
# Start the development server
php artisan serve

# Run PHPUnit tests
vendor/bin/phpunit

# Check code style with Pint
vendor/bin/pint

# Run Pint to fix issues
vendor/bin/pint

# Clear cache
php artisan cache:clear

# Route list
php artisan route:list
```

## Default Database Tables (28 tables)

### Core Tables:
- `users`, `roles`, `permissions` - Auth & RBAC
- `properties`, `property_owners` - Property management
- `valuations`, `valuation_approvals` - Valuation workflow
- `tax_assessments`, `tax_bills`, `payments` - Billing system
- `enforcement_visits`, `enforcement_evidence` - Enforcement module
- `bill_delivery_log` - Delivery workflow tracking

### Additional Tables:
- `appeals`, `me_queries` - Dispute & query system
- `notifications`, `attachments` - Communication & files
- `bill_followup_tasks`, `staff_targets` - Performance tracking
- `registration_queue` - LITAS staging layer
- `audit_logs` - Full audit trail

## Troubleshooting

### "Connection refused" on localhost:8000
- Ensure `php artisan serve` is running
- Check that no other service is using port 8000
- Try port 8080: `php artisan serve --port=8080`

### Database connection errors
- Verify MySQL is running in Laragon
- Check `.env` has correct DB_DATABASE: `retd_lra`
- Run: `php artisan config:clear`

### CORS errors from React/Expo
- The CORS middleware allows: `localhost:5173`, `exp://localhost`, `exp://192.168.1.1`
- Ensure your dev server is on allowed origins

### "Class xxx not found" errors
- Run: `composer install`
- Run: `npm install && npm run build`

## Project Structure Highlights

```
app/
  Http/
    Middleware/       # ApiResponseEnvelope, CorsMiddleware
    Controllers/      # API controllers (auto-generated)
    Policies/         # RBAC policies
  Models/             # Eloquent models with relationships
  Observers/          # Auto-sync events (Prompt 7)
  Services/           # Business logic services

routes/
  api.php             # Versioned routes: prefix('v1')->name('api.v1.')

config/
  sanctum.php         # SPA + mobile token auth
```

## Next Steps (Prompt Sequence)

The project follows Prompt 1-19 from the playbook. Currently complete:
- ✅ Prompt 1: Project scaffolding (this file)
- ✅ Database: 28 tables created and migrated
- ✅ Auth: Sanctum configured
- ✅ API: Versioned responses, envelope format, CORS

**To continue:** Run Prompt 2 (RBAC & Auth) or start specific functionality.

## Need Help?
- Check `storage/logs/laravel.log` for errors
- Run `php artisan debugbar` if Laravel Debugbar is installed
- See `database/migrations/` for table schemas