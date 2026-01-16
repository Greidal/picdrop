<?php
require 'auth.php';
requireLogin();

$eventId = $_GET['event'] ?? die("Missing Event ID");
checkEventAccess($conn, $eventId);

$stmt = $conn->prepare("SELECT name, setting_show_badge, setting_show_uploader, setting_show_time, setting_show_event_name, setting_slide_duration FROM events WHERE uuid = ?");
$stmt->bind_param("s", $eventId);
$stmt->execute();
$eventData = $stmt->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($eventData['name']); ?> Slideshow</title>
    <style>
        body {
            background: #000;
            margin: 0;
            overflow: hidden;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            height: 100vh;
            width: 100vw;
        }

        #stage {
            width: 100%;
            height: 100%;
            object-fit: contain;
            position: absolute;
            top: 0;
            left: 0;
            transition: opacity 1s ease;
            opacity: 0;
        }

        .overlay {
            position: absolute;
            z-index: 10;
            color: white;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.9);
            transition: opacity 1s;
            pointer-events: none;
            opacity: 0;
        }

        #badge {
            top: 30px;
            right: 30px;
            background: #ff0055;
            padding: 10px 25px;
            border-radius: 8px;
            font-weight: bold;
            font-size: 2.5rem;
            box-shadow: 0 0 20px rgba(255, 0, 85, 0.6);
            transform: rotate(5deg);
        }

        #meta-box {
            bottom: 30px;
            left: 30px;
            background: rgba(0, 0, 0, 0.6);
            padding: 20px 30px;
            border-radius: 15px;
            border-left: 6px solid #ff0055;
            backdrop-filter: blur(5px);
            max-width: 40%;
        }

        #meta-label {
            font-size: 1.1rem;
            text-transform: uppercase;
            color: #ccc;
            display: block;
            margin-bottom: 5px;
            letter-spacing: 1px;
        }

        #meta-name {
            font-size: 2.2rem;
            font-weight: bold;
            line-height: 1.1;
        }

        #info-box {
            bottom: 30px;
            right: 30px;
            text-align: right;
        }

        #time-display {
            font-size: 2.5rem;
            font-weight: bold;
        }

        #event-display {
            font-size: 1.2rem;
            color: #aaa;
            margin-top: 5px;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        #reaction-layer {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 20;
            overflow: hidden;
        }

        .floater {
            position: absolute;
            bottom: -50px;
            font-size: 4rem;
            animation: floatUp 3s ease-out forwards;
            opacity: 0;
        }

        @keyframes floatUp {
            0% {
                transform: translateY(0) scale(0.5);
                opacity: 0;
            }

            10% {
                opacity: 1;
                transform: translateY(-5vh) scale(1.2);
            }

            100% {
                transform: translateY(-60vh) scale(1);
                opacity: 0;
            }
        }
    </style>
</head>

<body>

    <img id="stage" src="" alt="Slideshow">

    <div id="reaction-layer"></div>

    <div id="badge" class="overlay">NEU!</div>

    <div id="meta-box" class="overlay">
        <span id="meta-label">Foto von</span>
        <div id="meta-name">Name</div>
    </div>

    <div id="info-box" class="overlay">
        <div id="time-display"></div>
        <div id="event-display"><?php echo htmlspecialchars($eventData['name']); ?></div>
    </div>

    <script>
        const CONFIG = {
            eventId: "<?php echo htmlspecialchars($eventId); ?>",
            duration: <?php echo $eventData['setting_slide_duration']; ?>,
            pollInterval: 5000,
            showBadge: <?php echo $eventData['setting_show_badge']; ?>,
            showUploader: <?php echo $eventData['setting_show_uploader']; ?>,
            showTime: <?php echo $eventData['setting_show_time']; ?>,
            showEventName: <?php echo $eventData['setting_show_event_name']; ?>
        };

        const els = {
            stage: document.getElementById('stage'),
            badge: document.getElementById('badge'),
            metaBox: document.getElementById('meta-box'),
            metaLabel: document.getElementById('meta-label'),
            metaName: document.getElementById('meta-name'),
            infoBox: document.getElementById('info-box'),
            timeDisplay: document.getElementById('time-display'),
            eventDisplay: document.getElementById('event-display')
        };

        if (!CONFIG.showEventName) els.eventDisplay.style.display = 'none';

        let state = {
            allImages: [],
            queue: [],
            seen: new Set(),
            firstLoad: true
        };

        async function fetchImages() {
            try {
                const res = await fetch(`get_images.php?event=${CONFIG.eventId}`);
                if (!res.ok) throw new Error("Network Error");

                const data = await res.json();

                data.forEach(img => {
                    if (!state.seen.has(img.file)) {
                        state.seen.add(img.file);
                        if (!state.firstLoad) {
                            state.queue.unshift({
                                ...img,
                                isNew: true
                            });
                        }
                    }
                });

                state.allImages = data;

                if (state.firstLoad && state.allImages.length > 0) {
                    state.firstLoad = false;
                    nextSlide();
                }
            } catch (e) {
                console.error(e);
            }
        }

        function nextSlide() {
            if (state.allImages.length === 0) return;

            if (state.queue.length === 0) {
                let shuffled = [...state.allImages];
                for (let i = shuffled.length - 1; i > 0; i--) {
                    const j = Math.floor(Math.random() * (i + 1));
                    [shuffled[i], shuffled[j]] = [shuffled[j], shuffled[i]];
                }
                state.queue = shuffled;
            }

            const img = state.queue.shift();

            els.stage.style.opacity = 0;
            els.badge.style.opacity = 0;
            els.metaBox.style.opacity = 0;
            els.infoBox.style.opacity = 0;

            setTimeout(() => {
                els.stage.src = `uploads/${CONFIG.eventId}/${img.file}`;

                els.stage.onload = () => {
                    if (CONFIG.showUploader) {
                        if (img.is_drink) {
                            els.metaLabel.innerText = "Trinkt gerade:";
                            const uploaderSuffix = img.uploader ? ` (${img.uploader})` : '';
                            els.metaName.innerText = `${img.drink_name}${uploaderSuffix}`;
                            els.metaBox.style.opacity = 1;
                        } else {
                            if (img.uploader) {
                                els.metaLabel.innerText = "Foto von:";
                                els.metaName.innerText = img.uploader;
                                els.metaBox.style.opacity = 1;
                            } else {
                                els.metaBox.style.opacity = 0;
                            }
                        }
                    }

                    if (CONFIG.showBadge && img.isNew) {
                        els.badge.style.opacity = 1;
                        img.isNew = false;
                    }

                    if (CONFIG.showTime) {
                        const date = new Date(img.timestamp * 1000);
                        const timeStr = date.toLocaleTimeString('de-DE', {
                            hour: '2-digit',
                            minute: '2-digit'
                        }) + " Uhr";
                        els.timeDisplay.innerText = timeStr;
                        els.infoBox.style.opacity = 1;
                    } else if (CONFIG.showEventName) {
                        els.infoBox.style.opacity = 1;
                    }

                    els.stage.style.opacity = 1;
                };
            }, 1000);
        }

        setInterval(fetchImages, CONFIG.pollInterval);
        setInterval(nextSlide, CONFIG.duration);
        fetchImages();

        let lastReactionId = 0;

        function pollReactions() {
            fetch(`reaction_api.php?action=poll&event=${CONFIG.eventId}&last_id=${lastReactionId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.length > 0) {
                        data.forEach(r => {
                            lastReactionId = Math.max(lastReactionId, r.id);
                            spawnEmoji(r.emoji);
                        });
                    }
                })
                .catch(e => console.error(e));
        }

        function spawnEmoji(char) {
            const layer = document.getElementById('reaction-layer');
            const el = document.createElement('div');
            el.innerText = char;
            el.className = 'floater';

            const randomLeft = Math.floor(Math.random() * 80) + 10;
            el.style.left = randomLeft + '%';

            const randomDur = (Math.random() * 1 + 2) + 's';
            el.style.animationDuration = randomDur;

            layer.appendChild(el);

            setTimeout(() => {
                el.remove();
            }, 3500);
        }

        setInterval(pollReactions, 1000);
    </script>
</body>

</html>