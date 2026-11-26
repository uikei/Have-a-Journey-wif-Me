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

## Publishing on GitHub Pages

GitHub Pages can serve this `index.html` frontend, but it does not execute PHP or host the MySQL database or uploaded videos. The frontend and PHP API must therefore be hosted separately for the gallery and uploads to work.

1. Deploy `backend/api.php`, `backend/database.php`, the MySQL database, and `backend/uploads/` on a PHP/MySQL host.
2. Set `JOURNEY_ALLOWED_ORIGIN` on that host to `https://uikei.github.io` so the PHP API allows browser requests from the GitHub Pages site.
3. Change the `journey-api-url` meta tag in `index.html` to the full API URL provided by that host, for example `https://your-host.example/backend/api.php`.
4. Deploy the updated frontend to GitHub Pages. Test `https://your-host.example/backend/api.php?action=list` directly; it must return JSON with the `application/json` content type.

Until a PHP API host is configured, the page displays a clear backend-unavailable message rather than trying to parse PHP source as JSON. Never configure a PHP endpoint to return its source code. The local XAMPP API path only works on the XAMPP site; keep the API URL and host CORS origin in sync when publishing.

MP4 and WebM videos can be up to 150 MB. The browser creates a cover image from the video unless a JPEG, PNG, or WebP cover is selected. Covers are limited to 5 MB. To allow the maximum upload size, set `upload_max_filesize = 150M` and `post_max_size = 156M` in PHP's `php.ini`, then restart Apache. Hosting providers may enforce lower limits.

Delete keys are kept in the uploading browser's local storage; no login or account is created. Clearing that browser's saved site data removes its ability to delete its uploads. Titles, descriptions, names, videos, and covers are public. Since uploads are open to everyone, use this on a trusted host and monitor storage and bandwidth.
