# Have a Journey With Me

A cozy video diary where anyone can browse and upload videos without creating an account. Video cards show cover images; selecting a card opens a separate video player. Only the browser that uploaded a video can remove it.

## Files and folders

- **Frontend — [index.php](./index.php):** page content, upload form, and video-player dialog.
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

MP4 and WebM videos can be up to 150 MB. The browser creates a cover image from the video unless a JPEG, PNG, or WebP cover is selected. Covers are limited to 5 MB. To allow the maximum upload size, set `upload_max_filesize = 150M` and `post_max_size = 156M` in PHP's `php.ini`, then restart Apache. Hosting providers may enforce lower limits.

Delete keys are kept in the uploading browser's local storage; no login or account is created. Clearing that browser's saved site data removes its ability to delete its uploads. Titles, descriptions, names, videos, and covers are public. Since uploads are open to everyone, use this on a trusted host and monitor storage and bandwidth.
