# VK-KPI

VK-KPI is a Laravel-based business operations system for CRM, sales, procurement, inventory, task/SLA, alerts, audit logs, and KPI dashboards.

The implementation follows `README_VK_KPI_DEV.md` and starts from a SaaS-ready foundation:

- Laravel 12 / PHP 8.2
- SQLite for local development
- MySQL target for production
- JWT-style bearer API authentication
- Tenant-aware users, departments, roles, and permissions
- Audit log and business event tables for workflow automation
- Task, SLA, alert, and notification foundation tables

## Local Setup

```bash
composer install
php artisan migrate:fresh --seed
php artisan serve
```

The local API will run at:

```text
http://127.0.0.1:8000
```

## Demo Accounts

All seeded demo users use password:

```text
Admin@123
```

Accounts:

```text
admin@vk-kpi.local
director@vk-kpi.local
sales@vk-kpi.local
warehouse@vk-kpi.local
procurement@vk-kpi.local
marketing@vk-kpi.local
```

## API Foundation

```http
POST /api/v1/auth/login
POST /api/v1/auth/logout
GET  /api/v1/me
```

Login body:

```json
{
  "email": "admin@vk-kpi.local",
  "password": "Admin@123"
}
```

Use the returned token as:

```text
Authorization: Bearer <access_token>
```

## Verification

```bash
php artisan test
```
