# Have a Journey With Me

A cozy video diary where anyone can browse and upload videos without creating an account. Video cards show cover images; selecting a card opens a separate video player. Only the browser that uploaded a video can remove it.

## Files and folders

- **Frontend — [index.html](./index.html):** page content, upload form, and video-player dialog.
- **Frontend — [frontend/styles.css](./frontend/styles.css):** responsive styling, thumbnail cards, and player dialog.
- **Frontend — [frontend/app.js](./frontend/app.js):** cover-frame generation, gallery search, upload progress, and uploader-only delete controls.
- **Backend — [backend/api.php](./backend/api.php):** PHP endpoints for listing, uploading, thumbnail/video streaming, and deleting videos.
- **Backend — [backend/database.php](./backend/database.php):** MySQL connection settings.
- **Database schema and sample data — [data/database.sql](./data/database.sql):** visible MySQL schema plus two inert example rows. Sample rows have no media files and are intentionally hidden from the public gallery.
- **Uploaded media — [backend/uploads/](./backend/uploads/):** protected video and cover files, created automatically.

## Run locally with XAMPP

1. Put the project folder inside `C:\xampp\htdocs`.
2. In XAMPP, start **Apache** and **MySQL**.
3. Open phpMyAdmin at `http://localhost/phpmyadmin`, choose **Import**, select `data/database.sql`, and run it. This creates the `journey_video_diary` database, its `videos` table, and the sample rows.
4. Make sure PHP has `pdo_mysql` and `fileinfo` enabled. XAMPP includes both in the typical setup.
5. Open `http://localhost/Have-a-Journey-wif-Me/`.

The local database connection defaults to host `127.0.0.1`, database `journey_video_diary`, user `root`, and an empty password, as in a default XAMPP installation. For another setup, configure `JOURNEY_DB_HOST`, `JOURNEY_DB_NAME`, `JOURNEY_DB_USER`, and `JOURNEY_DB_PASSWORD` in the PHP/Apache environment.

If you already have this site’s database and video rows, do **not** import the fresh-install SQL over it. Run `data/migrations/2026-11-26-r2-video-storage.sql` against the existing database to add the R2 columns and upload-session table without removing existing rows. Keep existing files in `backend/uploads/`.

## Large video storage with Cloudflare R2

R2 mode sends videos directly from the browser to a private Cloudflare R2 bucket in 64 MB multipart chunks. PHP handles upload authorization and video metadata; it does not receive the full video. The configured maximum is 20 GB per video. That is a file-size limit, not a guaranteed duration: actual vlog length depends on the video’s resolution, codec, and bitrate.

1. Create a private R2 bucket and an API token with read/write access limited to that bucket. Keep the token secret.
2. Deploy the PHP API to a PHP 8.2+ host with MySQL and the PHP `pdo_mysql`, `fileinfo`, and `openssl` extensions. Install the PHP dependency from the project directory with `composer install --no-dev --optimize-autoloader`; deploy `vendor/` with the PHP backend.
3. Configure the PHP host’s environment variables:
   - `JOURNEY_STORAGE_DRIVER=r2`
   - `R2_ACCOUNT_ID`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, and `R2_BUCKET_NAME`
   - `JOURNEY_DB_HOST`, `JOURNEY_DB_NAME`, `JOURNEY_DB_USER`, and `JOURNEY_DB_PASSWORD`
   - `JOURNEY_ALLOWED_ORIGIN=https://uikei.github.io` (or the exact origin of your custom frontend domain)
4. On an existing database, run `data/migrations/2026-11-26-r2-video-storage.sql` once. For a brand-new database, import `data/database.sql` instead.
5. Apply `data/r2-cors.json` in the R2 bucket’s CORS settings. Add the exact frontend origin if using a custom domain. The CORS rule must allow browser `PUT` uploads and expose `ETag`.
6. In `index.html`, set the `journey-api-url` meta tag to the full PHP API URL, such as `https://your-host.example/backend/api.php`, and publish the frontend to GitHub Pages.
7. Check `https://your-host.example/backend/api.php?action=list`. It must return JSON with an `application/json` content type and `"upload_mode":"r2"`. Then test a small upload and playback before uploading a long vlog.

Never put R2 credentials in `index.html`, JavaScript, or the GitHub repository. The public website can be hosted on GitHub Pages, but its PHP API, MySQL database, Composer dependencies, and R2 bucket must be configured on their respective providers before uploads work. Until then, the gallery shows a clear backend-unavailable message.

In local storage mode, MP4 and WebM videos are limited to 150 MB and pass through PHP; set `upload_max_filesize = 150M` and `post_max_size = 156M` in PHP’s `php.ini`, then restart Apache. R2 mode avoids the PHP upload-size limit for videos. Covers are limited to 5 MB in either mode.

Delete keys are kept in the uploading browser's local storage; no login or account is created. Clearing that browser's saved site data removes its ability to delete its uploads. Titles, descriptions, names, videos, and covers are public. Since uploads are open to everyone, use this on a trusted host and monitor storage and bandwidth.
