// --- Web Audio API Chimes ---
function playChime(type) {
    try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        const ctx = new AudioCtx();

        if (type === 'success') {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(587.33, ctx.currentTime);
            osc.frequency.setValueAtTime(880, ctx.currentTime + 0.1);
            gain.gain.setValueAtTime(0.25, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.35);
        } else if (type === 'warning') {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(440, ctx.currentTime);
            gain.gain.setValueAtTime(0.25, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.25);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.25);
        } else if (type === 'error') {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(160, ctx.currentTime);
            gain.gain.setValueAtTime(0.3, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.3);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.3);
        }
    } catch (e) {
        console.warn("Audio playback issue:", e);
    }
}

// Initial setup on load
document.addEventListener("DOMContentLoaded", () => {
    // Server-side state is passed via data attributes on <body>
    const config = document.body.dataset;

    const focusTarget = document.getElementById(config.focusTarget);
    if (focusTarget) {
        focusTarget.focus();
        focusTarget.select();
    }

    if (config.sound) {
        playChime(config.sound);
    }

    if (config.autoReset === 'true') {
        setTimeout(() => {
            const alertEl = document.getElementById('statusAlert');
            if (alertEl) {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alertEl);
                bsAlert.close();
            }

            document.getElementById('asset_number').value = '';
            document.getElementById('user_name').value = '';

            const activeTag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
            if (activeTag !== 'input' && activeTag !== 'select' && activeTag !== 'textarea') {
                const assetInput = document.getElementById('asset_number');
                if (assetInput) {
                    assetInput.focus();
                }
            }
        }, 4000);
    }

    // --- Client-side Live Filtering ---
    const filterQueryInput = document.getElementById('filter_query');
    const filterStatusSelect = document.getElementById('filter_status');
    const historyTable = document.getElementById('historyTable');

    if (filterQueryInput && historyTable) {
        function performLiveFilter() {
            const query = filterQueryInput.value.toLowerCase().trim();
            const statusFilter = filterStatusSelect ? filterStatusSelect.value : 'all';
            const rows = historyTable.querySelectorAll('tbody tr[data-asset]');
            const noResultsRow = document.getElementById('noResultsRow');
            let visibleCount = 0;

            rows.forEach(row => {
                const asset = row.getAttribute('data-asset') || '';
                const name = row.getAttribute('data-name') || '';
                const status = row.getAttribute('data-status') || '';

                const matchesQuery = query === '' || asset.includes(query) || name.includes(query);
                const matchesStatus = statusFilter === 'all' || status === statusFilter;

                if (matchesQuery && matchesStatus) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            if (noResultsRow) {
                noResultsRow.style.display = (visibleCount === 0 && rows.length > 0) ? '' : 'none';
            }
        }

        filterQueryInput.addEventListener('keyup', performLiveFilter);
        filterQueryInput.addEventListener('input', performLiveFilter);
        if (filterStatusSelect) {
            filterStatusSelect.addEventListener('change', performLiveFilter);
        }
    }

    // --- Inactivity Reset Timer (5 Seconds) ---
    let inactivityTimer;
    function resetInactivityTimer() {
        clearTimeout(inactivityTimer);
        inactivityTimer = setTimeout(() => {
            const assetInput = document.getElementById('asset_number');
            const userInput = document.getElementById('user_name');
            const filterInput = document.getElementById('filter_query');
            const statusSelect = document.getElementById('filter_status');
            const alertEl = document.getElementById('statusAlert');

            let hasValues = false;
            if (assetInput && assetInput.value !== '') hasValues = true;
            if (userInput && userInput.value !== '') hasValues = true;
            if (filterInput && filterInput.value !== '') hasValues = true;
            if (statusSelect && statusSelect.value !== 'all') hasValues = true;

            if (hasValues) {
                if (assetInput) assetInput.value = '';
                if (userInput) userInput.value = '';
                if (filterInput) {
                    filterInput.value = '';
                    filterInput.dispatchEvent(new Event('input'));
                }
                if (statusSelect) {
                    statusSelect.value = 'all';
                    statusSelect.dispatchEvent(new Event('change'));
                }
                if (alertEl) {
                    const bsAlert = bootstrap.Alert.getOrCreateInstance(alertEl);
                    bsAlert.close();
                }
            }

            if (assetInput) {
                assetInput.focus();
                assetInput.select();
            }
        }, 5000);
    }

    // Reset timer on user activity events
    ['mousemove', 'mousedown', 'keypress', 'touchstart', 'scroll', 'input'].forEach(event => {
        document.addEventListener(event, resetInactivityTimer, true);
    });

    // Initialize timer on load
    resetInactivityTimer();

    // --- Camera Handling & Photo Capture ---
    const kioskForm = document.getElementById('kioskForm');
    const cameraPreview = document.getElementById('cameraPreview');
    const photoCanvas = document.getElementById('photoCanvas');
    const photoUuidInput = document.getElementById('photo_uuid');
    const photoBlobInput = document.getElementById('photo_blob');
    let stream = null;

    async function startCamera() {
        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } });
            cameraPreview.srcObject = stream;
        } catch (err) {
            console.error("Camera access denied or unavailable:", err);
        }
    }

    // Start camera immediately
    startCamera();

    kioskForm.addEventListener('submit', async (e) => {
        // Only capture photo if user_name is being submitted (check-out)
        const userName = document.getElementById('user_name').value.trim();
        const assetNum = document.getElementById('asset_number').value.trim();

        // We only want to capture if both are present (Checkout flow)
        if (userName !== '' && assetNum !== '') {
            // Prevent submission briefly to capture photo
            e.preventDefault();

            if (stream) {
                const context = photoCanvas.getContext('2d');
                photoCanvas.width = cameraPreview.videoWidth;
                photoCanvas.height = cameraPreview.videoHeight;
                context.drawImage(cameraPreview, 0, 0, photoCanvas.width, photoCanvas.height);

                // Generate simple UUID
                const uuid = crypto.randomUUID();
                photoUuidInput.value = uuid;
                photoBlobInput.value = photoCanvas.toDataURL('image/jpeg', 0.8);
            }

            // Now submit the form
            kioskForm.submit();
        }
    });
});
