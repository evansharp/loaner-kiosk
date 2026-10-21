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

/**
 * Turns the "Checkout To" field into a combobox that suggests people from the synced
 * Google directory (people.php). Choosing a suggestion fills the hidden user_id field,
 * which links the checkout to that person.
 *
 * @param {boolean} allowTyped Offer "Use what I typed" for names not in the directory
 */
function setupNameAutocomplete(allowTyped) {
    const input = document.getElementById('user_name');
    const userIdInput = document.getElementById('user_id');
    const list = document.getElementById('userSuggestions');
    const icon = document.getElementById('userIcon');

    let items = [];          // [{id, name, email}], plus {typed: true, name} for "use what I typed"
    let activeIndex = -1;
    let debounceTimer = null;
    let requestSeq = 0;      // Ignore responses to older keystrokes
    let pending = false;     // Suggestions for the current text haven't arrived yet

    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', 'userSuggestions');
    input.setAttribute('aria-expanded', 'false');

    function markLinked(linked) {
        icon.className = linked ? 'bi bi-person-check-fill text-success' : 'bi bi-person';
    }

    function close() {
        items = [];
        activeIndex = -1;
        list.classList.remove('show');
        list.replaceChildren();
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
    }

    function render() {
        list.replaceChildren();
        items.forEach((item, i) => {
            const option = document.createElement('li');
            option.id = 'userSuggestion' + i;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', i === activeIndex ? 'true' : 'false');

            const row = document.createElement('div');
            row.className = 'dropdown-item' + (i === activeIndex ? ' active' : '');
            if (item.typed) {
                row.classList.add('fst-italic', 'border-top');
                row.textContent = `Use “${item.name}” (not in the directory)`;
            } else {
                const name = document.createElement('div');
                name.className = 'fw-semibold';
                name.textContent = item.name;
                const email = document.createElement('div');
                email.className = 'small text-muted';
                email.textContent = item.email;
                row.append(name, email);
            }
            option.appendChild(row);

            // mousedown (not click) so the input doesn't lose focus and close the list first
            option.addEventListener('mousedown', e => {
                e.preventDefault();
                choose(i);
            });
            list.appendChild(option);
        });

        const open = items.length > 0;
        list.classList.toggle('show', open);
        input.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (activeIndex >= 0) {
            input.setAttribute('aria-activedescendant', 'userSuggestion' + activeIndex);
            document.getElementById('userSuggestion' + activeIndex).scrollIntoView({ block: 'nearest' });
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    }

    function choose(i) {
        const item = items[i];
        input.value = item.name;
        userIdInput.value = item.typed ? '' : item.id;
        markLinked(!item.typed);
        close();
    }

    async function fetchSuggestions(query) {
        const seq = ++requestSeq;
        let people = [];
        try {
            const response = await fetch('people.php?q=' + encodeURIComponent(query));
            people = await response.json();
        } catch (e) {
            console.warn('Name lookup failed:', e);
        }
        if (seq !== requestSeq) return; // A newer keystroke has its own request

        pending = false;
        items = people.slice();
        const exactMatch = people.some(p => p.name.toLowerCase() === query.toLowerCase());
        if (allowTyped && people.length > 0 && !exactMatch) {
            items.push({ typed: true, name: query });
        }
        activeIndex = items.length > 0 ? 0 : -1; // Enter picks the top match
        render();
    }

    input.addEventListener('input', () => {
        // Edited text no longer matches a chosen person
        userIdInput.value = '';
        markLinked(false);

        clearTimeout(debounceTimer);
        const query = input.value.trim();
        if (query.length < 2) {
            requestSeq++;
            pending = false;
            close();
            return;
        }
        pending = true;
        debounceTimer = setTimeout(() => fetchSuggestions(query), 150);
    });

    input.addEventListener('keydown', e => {
        if (e.key === 'Enter' && pending) {
            // Don't submit a half-typed name before its suggestions have arrived
            e.preventDefault();
            clearTimeout(debounceTimer);
            fetchSuggestions(input.value.trim());
            return;
        }
        if (!list.classList.contains('show')) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIndex = (activeIndex + 1) % items.length;
            render();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIndex = (activeIndex - 1 + items.length) % items.length;
            render();
        } else if (e.key === 'Enter' && activeIndex >= 0) {
            e.preventDefault();
            choose(activeIndex);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            close();
        }
    });

    input.addEventListener('blur', close);
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
            document.getElementById('user_name').dispatchEvent(new Event('input')); // Also clears a chosen directory person

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

    // --- Directory Name Autocomplete ---
    if (config.directory && config.directory !== 'off') {
        setupNameAutocomplete(config.directory === 'allow-typed');
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
                if (userInput) {
                    userInput.value = '';
                    userInput.dispatchEvent(new Event('input')); // Also clears a chosen directory person
                }
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
