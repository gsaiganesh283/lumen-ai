# InterviewLens web portals (PHP + MySQL)

Two separate portals that control the InterviewLens desktop app:

| Folder | What it is | Local address |
|---|---|---|
| `user/` | **User portal**: sign up / Google sign-in, email verification, plans, payment, download, Device ID, activation keys, renewals, reminders, support | http://127.0.0.1:8080 |
| `user/api/` | API the desktop app calls (activate, check-in, events) | http://127.0.0.1:8080/api/ |
| `superadmin/` | **Superadmin portal**: everything is controlled from here (250+ features — see *All features* inside) | http://127.0.0.1:8081 |
| `shared/` | Common PHP code + `schema.sql` (never served to the web) | — |
| `data/` | Keys, backups, uploads, logs (never served to the web) | — |

## Run it locally (XAMPP)

1. Start **MySQL** in the XAMPP Control Panel.
2. Double-click `start_portals.bat` (starts both portals with PHP's built-in server).
3. The superadmin portal opens at `http://127.0.0.1:8081/setup.php`:
   - Step 1: database (XAMPP default: user `root`, empty password). Tables and the 3 plans are created automatically.
   - Step 2: create your superadmin account.
4. In **Superadmin → Settings**:
   - **Email**: Gmail address + an *App Password* (myaccount.google.com/apppasswords), then turn on "Send real emails" and send a test from *Emails → Test & tools*.
   - **Payments**: Razorpay key ID/secret (+ webhook secret) and/or your UPI ID.
   - **Accounts**: Google OAuth client ID/secret (redirect URI: `<user portal URL>/google.php`).
5. Upload the desktop EXE in **Superadmin → App releases**. The upload uses resumable 1.5 MB chunks and supports files up to 200 MB, even when PHP's upload limit is 2 MB.
6. Install the PDF dependency from the project root with `composer install` before publishing the manual.

## Put it online

Point two (sub)domains at the folders, e.g. `app.yourdomain.com → website/user` and `admin.yourdomain.com → website/superadmin` (Apache or nginx + PHP 8.1+), use HTTPS, then update **Settings → General** (portal URLs) and set `DEFAULT_PORTAL_URL` in `interview.py` to your user portal URL.
Add a scheduled task every 5 minutes: `php website/shared/cron.php` (expiry, reminders, email queue, backups).

## How the license works

1. User registers (email verification, or Google — no verification needed) → picks a plan (30 days ₹450 · 60 days ₹800 · 90 days ₹1200) → pays (Razorpay instantly, or UPI + admin approval).
2. User downloads the portable EXE. There is no installer; the user double-clicks the same EXE whenever the app is needed. The app creates a random **Device ID** (`HMD-XXXX-XXXX-XXXX-XXXX`) on first run.
3. User adds the Device ID in the portal and gets an **activation key** (`HM-XXXXX-XXXXX-XXXXX-XXXXX`) by email and on the Licenses page.
4. In the app, the 🔑 badge at the top left → paste key → Activate. The badge counts the days left; at 0 the app stops.
5. Reminders 7, 3 and 1 days before expiry (email + portal), and inside the app from 7 days.

The portal signs every license with an RSA key in `data/license_private.pem`. The app contains the matching public key (`LICENSE_PUBLIC_KEY_PEM` in `interview.py`). **Back up the `data/` folder.** If the keys are lost or recreated, paste the new public key (shown in *Settings → Desktop app*) into `interview.py`.
