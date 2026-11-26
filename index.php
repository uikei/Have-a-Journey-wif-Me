<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#fffaf4">
    <meta name="description" content="A cozy little corner for sharing videos, vlogs, and everyday adventures.">
    <title>Have a Journey With Me</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Nunito:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="frontend/styles.css?v=<?php echo filemtime(__DIR__ . '/frontend/styles.css'); ?>">
    <script src="frontend/app.js?v=<?php echo filemtime(__DIR__ . '/frontend/app.js'); ?>" defer></script>
</head>

<body>
    <header class="site-header">
        <a class="brand" href="#" aria-label="Have a Journey With Me home">
            <span class="brand-mark" aria-hidden="true">✿</span>
            <span>Have a Journey <span class="brand-light">with me</span></span>
        </a>
        <nav class="main-nav" aria-label="Main navigation">
            <a class="nav-link active" href="#videos">Videos</a>
            <a class="nav-link" href="#about">Our little corner</a>
            <a class="nav-upload" href="#upload"><span aria-hidden="true">＋</span> Share a video</a>
        </nav>
    </header>

    <main>
        <section class="hero" aria-labelledby="hero-title">
            <div class="hero-copy">
                <p class="eyebrow"><span class="eyebrow-dot"></span> A LITTLE VIDEO DIARY</p>
                <h1 id="hero-title">Welcome To<br><span>Yukie's Digital Diary🌼</span></h1>
                <p class="hero-description">Trips, tiny joys, and all the in-between moments. Pull up a cozy seat and
                    watch along.</p>
                <a class="button button-dark" href="#videos">Checkout 😜<span aria-hidden="true">↘</span></a>
                <div class="hero-note"><span class="note-avatars" aria-hidden="true">⭐</span> A happy place for
                    everyone's stories</div>
            </div>
            <div class="hero-art" aria-hidden="true">
                <div class="sun-doodle">✳</div>
                <div class="hero-photo">
                    <div class="photo-scene">
                        <span class="scene-sun"></span>
                        <span class="scene-hill hill-back"></span>
                        <span class="scene-hill hill-front"></span>
                        <span class="scene-flower flower-one">✿</span>
                        <span class="scene-flower flower-two">✿</span>
                        <span class="scene-caption">little moments, big memories</span>
                    </div>
                    <span class="play-bubble">▶</span>
                </div>
                <span class="art-sparkle sparkle-one">✦</span>
                <span class="art-sparkle sparkle-two">✿</span>
                <span class="art-caption">your next favorite memory ↓</span>
            </div>
        </section>

        <section class="video-section page-section" id="videos" aria-labelledby="videos-title">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">THE MEMORY LANE</p>
                    <h2 id="videos-title">Fresh from the camera roll <span class="heading-flower">✿</span></h2>
                    <p class="section-subtitle">A little bit of everywhere, shared by all of us.</p>
                </div>
                <label class="search-wrap">
                    <span class="search-icon" aria-hidden="true">⌕</span>
                    <input id="video-search" type="search" placeholder="Find a little moment..."
                        aria-label="Search videos">
                </label>
            </div>
            <div id="video-grid" class="video-grid" aria-live="polite">
                <p class="loading-message">Gathering the good memories...</p>
            </div>
            <p id="storage-warning" class="storage-warning" role="status" hidden></p>
            <p id="video-status" class="sr-only" aria-live="polite"></p>
        </section>

        <section class="share-banner" id="about">
            <span class="banner-flower" aria-hidden="true">✿</span>
            <div>
                <p class="eyebrow">NO ACCOUNT, NO FUSS</p>
                <h2>Your story belongs here, too.</h2>
                <p>Got a video that makes you smile? Share it with the journey crew.</p>
            </div>
            <a class="button button-light" href="#upload">Add your video <span aria-hidden="true">↗</span></a>
        </section>

        <section class="upload-section page-section" id="upload" aria-labelledby="upload-title">
            <div class="upload-intro">
                <p class="eyebrow">PASS THE CAMERA</p>
                <h2 id="upload-title">Share a little<br><span>piece of your day.</span></h2>
                <p>No sign-up or account needed. Pick a video, add a few details, and it's ready for everyone to enjoy.
                </p>
                <div class="upload-promise"><span aria-hidden="true">♡</span> Just good moments, shared kindly.</div>
            </div>
            <form id="upload-form" class="upload-card" enctype="multipart/form-data">
                <label class="field-label" for="video-file">Your video <span class="required-mark">*</span></label>
                <label class="file-drop" for="video-file" id="file-drop">
                    <span class="upload-icon" aria-hidden="true">↑</span>
                    <span class="file-prompt"><strong>Choose a video</strong> or drop it right here</span>
                    <span class="file-help">MP4 or WebM · up to 150 MB</span>
                    <span id="file-name" class="file-name"></span>
                    <input id="video-file" name="video" type="file" accept="video/mp4,video/webm,.mp4,.webm" required>
                </label>
                <div class="cover-picker-row">
                    <label class="cover-picker" for="cover-file">
                        <span aria-hidden="true">✿</span>
                        <span id="cover-name">Choose a cover photo</span>
                        <input id="cover-file" name="thumbnail" type="file"
                            accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp">
                    </label>
                    <span class="cover-picker-help">Leave blank to use a frame from your video.</span>
                </div>
                <div class="field-row">
                    <label class="field-label field-grow" for="video-title">Give it a title <span
                            class="required-mark">*</span>
                        <input id="video-title" name="title" type="text" maxlength="100"
                            placeholder="A day worth remembering" required>
                    </label>
                    <label class="field-label field-grow" for="video-creator">Your name <span
                            class="optional-mark">(optional)</span>
                        <input id="video-creator" name="creator" type="text" maxlength="40"
                            placeholder="A fellow traveler">
                    </label>
                </div>
                <label class="field-label" for="video-description">A little note <span
                        class="optional-mark">(optional)</span>
                    <textarea id="video-description" name="description" rows="3" maxlength="500"
                        placeholder="Where was it? What made you smile?"></textarea>
                </label>
                <div id="upload-progress" class="upload-progress" hidden>
                    <div class="progress-track"><span id="progress-bar"></span></div>
                    <span id="progress-label">Getting your video ready…</span>
                </div>
                <p id="upload-message" class="form-message" role="status" aria-live="polite"></p>
                <button id="submit-upload" class="button button-dark submit-button" type="submit">Share with everyone
                    <span aria-hidden="true">♡</span></button>
                <p class="privacy-note">Only this browser can remove videos uploaded here. Avoid clearing its saved
                    browser data.</p>
            </form>
        </section>
    </main>
    <dialog id="video-dialog" class="video-dialog" aria-labelledby="dialog-title">
        <form method="dialog" class="dialog-close-form">
            <button class="dialog-close" type="submit" aria-label="Close video">×</button>
        </form>
        <div class="dialog-player">
            <video id="dialog-player" controls playsinline preload="metadata"></video>
        </div>
        <div class="dialog-details">
            <h2 id="dialog-title"></h2>
            <p id="dialog-description"></p>
            <span id="dialog-creator"></span>
        </div>
    </dialog>
    <dialog id="delete-dialog" class="delete-dialog" aria-labelledby="delete-dialog-title"
        aria-describedby="delete-dialog-message">
        <div class="delete-dialog-icon" aria-hidden="true">×</div>
        <p class="eyebrow">ONE LAST CHECK</p>
        <h2 id="delete-dialog-title">Remove this video?</h2>
        <p id="delete-dialog-message"></p>
        <div class="delete-dialog-actions">
            <button id="cancel-delete" class="button button-light" type="button">Keep video</button>
            <button id="confirm-delete" class="button button-danger" type="button">Remove video</button>
        </div>
        <p class="delete-dialog-note">This can't be undone.</p>
    </dialog>
    <footer class="site-footer">
        <a class="brand footer-brand" href="#"><span class="brand-mark" aria-hidden="true">✿</span><span>Have a journey
                <span class="brand-light">with me</span></span></a>
        <p>Made for memories and the people who make them. <span aria-hidden="true">♡</span></p>
        <a href="#videos">Back to the good stuff ↑</a>
    </footer>
</body>

</html>