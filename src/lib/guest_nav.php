<?php
/**
 * Shared parts of the public guest pages: toast and the guest identity
 * (device id + name in localStorage) used by the drink API.
 */
function renderGuestCommon(string $eventId): void
{
?>
<style>
    #guest-toast {
        position: fixed;
        left: 50%;
        bottom: 90px;
        transform: translateX(-50%);
        width: max-content;
        max-width: calc(100% - 40px);
        box-sizing: border-box;
        background: #1d1d1d;
        border: 1px solid var(--success);
        color: var(--success);
        padding: 12px 20px;
        border-radius: 30px;
        z-index: 3000;
        font-weight: bold;
        text-align: center;
        box-shadow: 0 6px 24px rgba(0, 0, 0, 0.6);
    }

    #guest-toast.error {
        border-color: #ff4444;
        color: #ff6666;
    }
</style>

<div id="guest-toast" class="hidden" role="status" aria-live="polite"></div>

<script>
    const Guest = {
        eventId: <?php echo json_encode($eventId); ?>,
        deviceId() {
            let uuid = localStorage.getItem('fotobox_device_uuid');
            if (!uuid) {
                uuid = 'dev_' + Date.now().toString(36) + Math.random().toString(36).substr(2);
                localStorage.setItem('fotobox_device_uuid', uuid);
            }
            return uuid;
        },
        name() {
            return (localStorage.getItem('party_user') || '').trim();
        },
        setName(name) {
            localStorage.setItem('party_user', name);
        },
        /** POST to the drink API; returns the parsed JSON or throws with the server message. */
        async api(action, fields = {}, formData = null) {
            const data = formData || new FormData();
            data.append('device_uuid', Guest.deviceId());
            if (!data.has('name')) data.append('name', Guest.name());
            for (const [key, value] of Object.entries(fields)) {
                if (Array.isArray(value)) value.forEach((v) => data.append(key + '[]', v));
                else data.append(key, value);
            }
            const res = await fetch('drink_api.php?action=' + action + '&event=' + encodeURIComponent(Guest.eventId), {
                method: 'POST',
                body: data
            });
            let body = null;
            try {
                body = await res.json();
            } catch (e) {}
            if (!res.ok || !body) throw new Error((body && body.error) || 'Keine Verbindung – bitte erneut versuchen.');
            return body;
        },
        toast(text, isError = false) {
            const el = document.getElementById('guest-toast');
            el.textContent = text;
            el.className = isError ? 'error' : '';
            clearTimeout(Guest.toastTimer);
            Guest.toastTimer = setTimeout(() => el.classList.add('hidden'), 3500);
        },
        formatBac(value) {
            return (value || 0).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ‰';
        },
        formatTime(unix) {
            return new Date(unix * 1000).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
        }
    };
</script>
<?php
}
