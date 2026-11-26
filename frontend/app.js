const API_URL = "backend/api.php";
const MAX_FILE_SIZE = 150 * 1024 * 1024;
const MAX_COVER_SIZE = 5 * 1024 * 1024;
const DELETE_TOKEN_STORAGE_KEY = "have-a-journey-video-delete-keys";

const videoGrid = document.querySelector("#video-grid");
const searchInput = document.querySelector("#video-search");
const uploadForm = document.querySelector("#upload-form");
const fileInput = document.querySelector("#video-file");
const coverInput = document.querySelector("#cover-file");
const fileName = document.querySelector("#file-name");
const coverName = document.querySelector("#cover-name");
const fileDrop = document.querySelector("#file-drop");
const uploadMessage = document.querySelector("#upload-message");
const submitButton = document.querySelector("#submit-upload");
const uploadProgress = document.querySelector("#upload-progress");
const progressBar = document.querySelector("#progress-bar");
const progressLabel = document.querySelector("#progress-label");
const videoStatus = document.querySelector("#video-status");
const storageWarning = document.querySelector("#storage-warning");
const videoDialog = document.querySelector("#video-dialog");
const dialogPlayer = document.querySelector("#dialog-player");
const deleteDialog = document.querySelector("#delete-dialog");
const deleteDialogMessage = document.querySelector("#delete-dialog-message");
const cancelDeleteButton = document.querySelector("#cancel-delete");
const confirmDeleteButton = document.querySelector("#confirm-delete");

let videos = [];
let pendingDelete = null;

function showVideoMessage(message, className) {
    const element = document.createElement("p");
    element.className = className;
    element.textContent = message;
    videoGrid.replaceChildren(element);
}

function showStorageWarning(message) {
    storageWarning.textContent = message;
    storageWarning.hidden = false;
}

function readDeleteTokens() {
    let serialized;
    try {
        serialized = localStorage.getItem(DELETE_TOKEN_STORAGE_KEY);
    } catch {
        throw new Error("This browser can't read its private delete keys. Enable browser storage to manage your uploads.");
    }

    if (!serialized) return {};

    try {
        const tokens = JSON.parse(serialized);
        if (!tokens || typeof tokens !== "object" || Array.isArray(tokens)) {
            throw new Error("Saved delete keys have an invalid format.");
        }
        return tokens;
    } catch {
        throw new Error("Saved delete keys couldn't be read. Avoid clearing this site's browser data if you want to delete your uploads.");
    }
}

function checkDeleteTokenStorage() {
    try {
        const tokens = readDeleteTokens();
        localStorage.setItem(DELETE_TOKEN_STORAGE_KEY, JSON.stringify(tokens));
    } catch {
        throw new Error("This browser can't save private delete keys. Enable browser storage before uploading.");
    }
}

function saveDeleteToken(id, token) {
    const tokens = readDeleteTokens();
    tokens[id] = token;
    localStorage.setItem(DELETE_TOKEN_STORAGE_KEY, JSON.stringify(tokens));
}

function removeDeleteToken(id) {
    const tokens = readDeleteTokens();
    delete tokens[id];
    localStorage.setItem(DELETE_TOKEN_STORAGE_KEY, JSON.stringify(tokens));
}

function makeThumbnail(video) {
    const frame = document.createElement("div");
    frame.className = "video-thumbnail";

    const artwork = document.createElement("div");
    artwork.className = "thumbnail-art";
    artwork.setAttribute("aria-hidden", "true");
    artwork.innerHTML = '<span class="thumbnail-sun"></span><span class="thumbnail-hill"></span><span class="thumbnail-flower">✿</span><span class="thumbnail-caption">a little memory</span>';
    frame.append(artwork);

    if (video.has_cover) {
        const image = document.createElement("img");
        image.className = "video-cover";
        image.src = `${API_URL}?action=thumbnail&id=${encodeURIComponent(video.id)}`;
        image.alt = "";
        image.loading = "lazy";
        image.addEventListener("error", () => image.remove(), { once: true });
        frame.append(image);
    }

    const playButton = document.createElement("button");
    playButton.className = "video-open";
    playButton.type = "button";
    playButton.setAttribute("aria-label", `Watch ${video.title}`);
    const playIcon = document.createElement("span");
    playIcon.className = "thumbnail-play";
    playIcon.setAttribute("aria-hidden", "true");
    playIcon.textContent = "▶";
    const watchLabel = document.createElement("span");
    watchLabel.className = "watch-label";
    watchLabel.textContent = "WATCH VIDEO";
    playButton.append(playIcon, watchLabel);
    playButton.addEventListener("click", () => openVideo(video));
    frame.append(playButton);

    return frame;
}

function makeVideoCard(video, deleteTokens) {
    const card = document.createElement("article");
    card.className = "video-card";
    card.append(makeThumbnail(video));

    const details = document.createElement("div");
    details.className = "video-details";
    const title = document.createElement("h3");
    title.textContent = video.title;
    details.append(title);

    if (video.description) {
        const note = document.createElement("p");
        note.className = "video-note";
        note.textContent = video.description;
        details.append(note);
    }

    const footer = document.createElement("div");
    footer.className = "video-meta";
    const creator = document.createElement("span");
    creator.className = "video-author";
    creator.textContent = `♡ ${video.creator}`;
    footer.append(creator);
    details.append(footer);

    if (typeof deleteTokens[video.id] === "string") {
        const removeButton = document.createElement("button");
        removeButton.className = "video-delete";
        removeButton.type = "button";
        removeButton.textContent = "Remove video";
        removeButton.setAttribute("aria-label", `Remove ${video.title}`);
        removeButton.addEventListener("click", () => requestVideoDeletion(video, deleteTokens[video.id], removeButton));
        details.append(removeButton);
    }

    card.append(details);
    return card;
}

function renderVideos() {
    const query = searchInput.value.trim().toLocaleLowerCase();
    const matches = videos.filter((video) =>
        `${video.title} ${video.creator} ${video.description}`.toLocaleLowerCase().includes(query)
    );

    let deleteTokens = {};
    try {
        deleteTokens = readDeleteTokens();
        storageWarning.hidden = true;
    } catch (error) {
        showStorageWarning(error.message);
    }

    if (matches.length === 0) {
        const empty = document.createElement("div");
        empty.className = "empty-state";
        const heading = document.createElement("strong");
        const note = document.createElement("span");

        if (videos.length === 0) {
            heading.textContent = "The memory lane is waiting for its first story.";
            note.textContent = "Be the first to share a video with everyone!";
        } else {
            heading.textContent = "No little moments found.";
            note.textContent = "Try another search, or ";
            const link = document.createElement("a");
            link.href = "#upload";
            link.textContent = "share a video";
            note.append(link, ".");
        }

        empty.append(heading, note);
        videoGrid.replaceChildren(empty);
        return;
    }

    const groups = new Map();
    for (const video of matches) {
        const date = new Date(video.created_at);
        const isValidDate = !Number.isNaN(date.getTime());
        const key = isValidDate
            ? `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`
            : "unknown";
        if (!groups.has(key)) {
            groups.set(key, {
                label: isValidDate
                    ? date.toLocaleDateString(undefined, { year: "numeric", month: "long", day: "numeric" })
                    : "Date unavailable",
                dateTime: isValidDate ? key : "",
                videos: [],
            });
        }
        groups.get(key).videos.push(video);
    }

    const dateGroups = [];
    for (const group of groups.values()) {
        const section = document.createElement("section");
        section.className = "video-date-group";
        const heading = document.createElement("h3");
        heading.className = "video-date-heading";
        const date = document.createElement("time");
        date.dateTime = group.dateTime;
        date.textContent = group.label;
        heading.append(date);

        const cards = document.createElement("div");
        cards.className = "video-group-cards";
        cards.append(...group.videos.map((video) => makeVideoCard(video, deleteTokens)));
        section.append(heading, cards);
        dateGroups.push(section);
    }

    videoGrid.replaceChildren(...dateGroups);
}

async function loadVideos() {
    showVideoMessage("Gathering the good memories...", "loading-message");
    try {
        const response = await fetch(`${API_URL}?action=list`, { headers: { Accept: "application/json" } });
        const result = await response.json();
        if (!response.ok || !result.success || !Array.isArray(result.videos)) {
            throw new Error(result.error || "Couldn't load the videos right now.");
        }
        videos = result.videos;
        renderVideos();
    } catch (error) {
        showVideoMessage(error.message || "Couldn't load the videos right now.", "error-state");
        const retry = document.createElement("button");
        retry.type = "button";
        retry.textContent = " Try again";
        retry.addEventListener("click", loadVideos);
        videoGrid.firstElementChild.append(retry);
    }
}

function openVideo(video) {
    dialogPlayer.src = `${API_URL}?action=video&id=${encodeURIComponent(video.id)}`;
    document.querySelector("#dialog-title").textContent = video.title;
    document.querySelector("#dialog-description").textContent = video.description;
    document.querySelector("#dialog-creator").textContent = `♡ ${video.creator}`;
    videoDialog.showModal();
}

videoDialog.addEventListener("close", () => {
    dialogPlayer.pause();
    dialogPlayer.removeAttribute("src");
    dialogPlayer.load();
});

videoDialog.addEventListener("click", (event) => {
    if (event.target === videoDialog) videoDialog.close();
});

function requestVideoDeletion(video, token, button) {
    pendingDelete = { video, token, button };
    deleteDialogMessage.textContent = `"${video.title}" and its cover will be permanently removed from the video page.`;
    deleteDialog.showModal();
    cancelDeleteButton.focus();
}

cancelDeleteButton.addEventListener("click", () => deleteDialog.close());
deleteDialog.addEventListener("close", () => {
    pendingDelete = null;
});

confirmDeleteButton.addEventListener("click", async () => {
    const selection = pendingDelete;
    pendingDelete = null;
    deleteDialog.close();
    if (selection) await deleteVideo(selection.video, selection.token, selection.button);
});

async function deleteVideo(video, token, button) {
    button.disabled = true;
    button.textContent = "Removing…";
    const formData = new FormData();
    formData.append("id", video.id);
    formData.append("delete_token", token);

    try {
        const response = await fetch(`${API_URL}?action=delete`, { method: "POST", body: formData });
        const result = await response.json();
        if (!response.ok || !result.success) {
            throw new Error(result.error || "This video couldn't be removed.");
        }
        removeDeleteToken(video.id);
        videos = videos.filter((item) => item.id !== video.id);
        videoStatus.textContent = "Your video was removed.";
        renderVideos();
    } catch (error) {
        button.disabled = false;
        button.textContent = "Remove video";
        showStorageWarning(error.message || "This video couldn't be removed. Please try again.");
    }
}

function createVideoThumbnail(file) {
    return new Promise((resolve) => {
        const video = document.createElement("video");
        const objectUrl = URL.createObjectURL(file);
        let settled = false;
        let capturing = false;
        const timeout = window.setTimeout(() => finish(null), 8000);

        function finish(image) {
            if (settled) return;
            settled = true;
            window.clearTimeout(timeout);
            video.removeAttribute("src");
            video.load();
            URL.revokeObjectURL(objectUrl);
            resolve(image);
        }

        function captureFrame() {
            if (settled || capturing || !video.videoWidth || !video.videoHeight) return;
            capturing = true;
            const canvas = document.createElement("canvas");
            const scale = Math.min(1, 640 / video.videoWidth);
            canvas.width = Math.round(video.videoWidth * scale);
            canvas.height = Math.round(video.videoHeight * scale);
            try {
                canvas.getContext("2d").drawImage(video, 0, 0, canvas.width, canvas.height);
                canvas.toBlob(finish, "image/jpeg", 0.84);
            } catch {
                finish(null);
            }
        }

        video.muted = true;
        video.preload = "metadata";
        video.addEventListener("error", () => finish(null), { once: true });
        video.addEventListener("loadeddata", captureFrame, { once: true });
        video.src = objectUrl;
    });
}

function isSupportedFile(file, allowedTypes, extensions) {
    return allowedTypes.includes(file.type) || extensions.some((extension) => file.name.toLowerCase().endsWith(extension));
}

fileInput.addEventListener("change", () => {
    const file = fileInput.files[0];
    fileName.textContent = file ? file.name : "";
    uploadMessage.textContent = "";
    uploadMessage.classList.remove("success");
});

coverInput.addEventListener("change", () => {
    coverName.textContent = coverInput.files[0]?.name || "Choose a cover photo";
});

for (const eventName of ["dragenter", "dragover"]) {
    fileDrop.addEventListener(eventName, (event) => {
        event.preventDefault();
        fileDrop.classList.add("dragging");
    });
}
for (const eventName of ["dragleave", "drop"]) {
    fileDrop.addEventListener(eventName, (event) => {
        event.preventDefault();
        fileDrop.classList.remove("dragging");
    });
}
fileDrop.addEventListener("drop", (event) => {
    const [file] = event.dataTransfer.files;
    if (!file) return;
    const transfer = new DataTransfer();
    transfer.items.add(file);
    fileInput.files = transfer.files;
    fileInput.dispatchEvent(new Event("change", { bubbles: true }));
});

searchInput.addEventListener("input", renderVideos);

uploadForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    uploadMessage.textContent = "";
    uploadMessage.classList.remove("success");

    const file = fileInput.files[0];
    const customCover = coverInput.files[0];
    if (!file) {
        uploadMessage.textContent = "Choose a video to share first.";
        return;
    }
    if (file.size > MAX_FILE_SIZE) {
        uploadMessage.textContent = "That video is a little too big. Please choose one under 150 MB.";
        return;
    }
    if (!isSupportedFile(file, ["video/mp4", "video/webm"], [".mp4", ".webm"])) {
        uploadMessage.textContent = "Please choose an MP4 or WebM video.";
        return;
    }
    if (customCover && customCover.size > MAX_COVER_SIZE) {
        uploadMessage.textContent = "Choose a cover image smaller than 5 MB.";
        return;
    }
    if (customCover && !isSupportedFile(customCover, ["image/jpeg", "image/png", "image/webp"], [".jpg", ".jpeg", ".png", ".webp"])) {
        uploadMessage.textContent = "Choose a JPEG, PNG, or WebP cover image.";
        return;
    }

    try {
        checkDeleteTokenStorage();
    } catch (error) {
        uploadMessage.textContent = error.message;
        return;
    }

    submitButton.disabled = true;
    uploadProgress.hidden = false;
    progressBar.style.width = "0%";
    progressLabel.textContent = customCover ? "Getting your video ready…" : "Picking a cover frame…";

    const formData = new FormData(uploadForm);
    let generatedCoverFailed = false;
    try {
        if (!customCover) {
            const thumbnail = await createVideoThumbnail(file);
            if (thumbnail) {
                formData.append("thumbnail", thumbnail, "video-cover.jpg");
            } else {
                generatedCoverFailed = true;
            }
        }
    } catch {
        generatedCoverFailed = true;
    }

    const request = new XMLHttpRequest();
    request.open("POST", `${API_URL}?action=upload`);
    request.setRequestHeader("Accept", "application/json");
    progressLabel.textContent = "Uploading your video…";

    request.upload.addEventListener("progress", (progressEvent) => {
        if (!progressEvent.lengthComputable) return;
        const percent = Math.round((progressEvent.loaded / progressEvent.total) * 100);
        progressBar.style.width = `${percent}%`;
        progressLabel.textContent = percent >= 100 ? "Saving your video…" : `Uploading your video… ${percent}%`;
    });

    request.addEventListener("load", async () => {
        let result;
        try {
            result = JSON.parse(request.responseText);
        } catch {
            uploadMessage.textContent = "The server returned an unexpected response. Please try again.";
        }

        if (result && request.status >= 200 && request.status < 300 && result.success) {
            try {
                saveDeleteToken(result.id, result.delete_token);
                uploadMessage.textContent = generatedCoverFailed
                    ? "Your video is on the memory lane. We used a cozy placeholder cover."
                    : "Your video is on the memory lane. Thanks for sharing!";
                uploadMessage.classList.add("success");
            } catch {
                uploadMessage.textContent = `Your video uploaded, but this browser couldn't save its private delete key. Save this key before closing: ${result.delete_token}`;
            }
            uploadForm.reset();
            fileName.textContent = "";
            coverName.textContent = "Choose a cover photo";
            await loadVideos();
            document.querySelector("#videos").scrollIntoView({ behavior: "smooth" });
        } else if (result) {
            uploadMessage.textContent = result.error || "Couldn't upload your video. Please try again.";
        }

        submitButton.disabled = false;
        uploadProgress.hidden = true;
    });

    request.addEventListener("error", () => {
        uploadMessage.textContent = "Couldn't reach the server. Check your connection and try again.";
        submitButton.disabled = false;
        uploadProgress.hidden = true;
    });
    request.send(formData);
});

loadVideos();
