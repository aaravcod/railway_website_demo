# Raahi Railway Demo (PHP)

A responsive railway booking demo with a PHP backend. It requires PHP 8.1+ and no Composer packages or database server.

## Run locally

From this folder, run:

```sh
php -S localhost:8000 -t public router.php
```

Open <http://localhost:8000>. PHP writes demo users and bookings to `data.json` in this folder on the first API request. Delete `data.json` to reset it.

## Demo accounts

Both accounts use `RailDemo!2026`:

| Role | Email |
| --- | --- |
| Admin | `admin@raildemo.test` |
| Staff | `staff@raildemo.test` |
| Sample passenger | `passenger@raildemo.test` |

All three demo accounts use `RailDemo!2026`. New sign ups are always assigned the customer role. The admin demo can change roles in the People & permissions section.

## Included

- PHP session authentication with HTTP only, SameSite cookies and password hashing.
- Customer, staff and admin authorization enforced by the PHP API.
- Route search, seat selection, bookings, cancellations, and seat release after cancellation.
- Staff booking view and admin user and role management.
- Admin dashboard at `/admin.html`, with booking search, status filters, summary cards, all passenger records, and train services. The dashboard data API checks the admin role on every request.
- Sample passenger bookings seeded automatically, including confirmed and cancelled records.
- Exclusive file locking around JSON reads and writes to prevent duplicate seat bookings in this single server demo.

This is a demonstration, not production railway infrastructure. It has no payments, email verification, password reset, rate limiting, or production database. Use a transactional database and HTTPS for a deployed service.
