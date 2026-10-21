// --- D3.js Device Time Chart ---
document.addEventListener("DOMContentLoaded", () => {
    // --- Idle Logout ---
    // This page shares a browser with the student kiosk: after the server's session timeout
    // with no activity, log out and go back to the kiosk instead of leaving admin tools open.
    const idleTimeoutMs = (parseInt(document.body.dataset.idleTimeout, 10) || 600) * 1000;
    let idleTimer;
    function resetIdleTimer() {
        clearTimeout(idleTimer);
        idleTimer = setTimeout(() => {
            window.location.href = 'admin.php?logout=1';
        }, idleTimeoutMs);
    }
    ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'input'].forEach(event => {
        document.addEventListener(event, resetIdleTimer, true);
    });
    resetIdleTimer();

    // Device totals are passed from PHP via the chart container's data-chart attribute
    const chartData = JSON.parse(document.getElementById('deviceChart').dataset.chart || '[]');
    const rawData = chartData.map(d => ({ asset: d.asset, hours: +(d.seconds / 3600).toFixed(2) }));

    if (rawData.length > 0) {
        const container = d3.select("#deviceChart");
        const containerWidth = container.node().getBoundingClientRect().width || 700;
        const margin = { top: 20, right: 30, bottom: 60, left: 60 };
        const width = containerWidth - margin.left - margin.right;
        const height = 320 - margin.top - margin.bottom;

        const svg = container.append("svg")
            .attr("width", width + margin.left + margin.right)
            .attr("height", height + margin.top + margin.bottom)
            .append("g")
            .attr("transform", `translate(${margin.left},${margin.top})`);

        // X axis
        const x = d3.scaleBand()
            .range([0, width])
            .domain(rawData.map(d => d.asset))
            .padding(0.3);

        svg.append("g")
            .attr("transform", `translate(0,${height})`)
            .call(d3.axisBottom(x))
            .selectAll("text")
            .attr("transform", "translate(-10,0)rotate(-35)")
            .style("text-anchor", "end");

        // Y axis
        const y = d3.scaleLinear()
            .domain([0, d3.max(rawData, d => d.hours) * 1.1 || 10])
            .range([height, 0]);

        svg.append("g")
            .call(d3.axisLeft(y));

        // Y-axis Label
        svg.append("text")
            .attr("transform", "rotate(-90)")
            .attr("y", -45)
            .attr("x", -height / 2)
            .attr("fill", "#6c757d")
            .style("text-anchor", "middle")
            .style("font-size", "12px")
            .style("font-weight", "600")
            .text("Total Time Checked Out (Hours)");

        // Bars
        svg.selectAll(".bar")
            .data(rawData)
            .enter()
            .append("rect")
            .attr("class", "bar")
            .attr("x", d => x(d.asset))
            .attr("y", d => y(d.hours))
            .attr("width", x.bandwidth())
            .attr("height", d => height - y(d.hours))
            .append("title")
            .text(d => `${d.asset}: ${d.hours} hours`);
    } else {
        d3.select("#deviceChart").html("<p class='text-muted text-center py-5'>No chart data available.</p>");
    }

    // --- Advanced Table Filtering ---
    const filterDevice = document.getElementById('filterDevice');
    const filterUser = document.getElementById('filterUser');
    const filterDateOut = document.getElementById('filterDateOut');
    const filterDateIn = document.getElementById('filterDateIn');
    const resetFiltersBtn = document.getElementById('resetFilters');
    const adminTable = document.getElementById('adminTable');

    function applyAdminFilters() {
        const devQuery = filterDevice.value.toLowerCase().trim();
        const usrQuery = filterUser.value.toLowerCase().trim();
        const dateOutQuery = filterDateOut.value.trim();
        const dateInQuery = filterDateIn.value.trim();

        const rows = adminTable.querySelectorAll('tbody tr[data-asset]');
        const noResults = document.getElementById('adminNoResults');
        let visibleCount = 0;

        rows.forEach(row => {
            const asset = row.getAttribute('data-asset') || '';
            const user = row.getAttribute('data-user') || '';
            const dateOut = row.getAttribute('data-dateout') || '';
            const dateIn = row.getAttribute('data-datein') || '';

            const matchDev = devQuery === '' || asset.includes(devQuery);
            const matchUsr = usrQuery === '' || user.includes(usrQuery);
            const matchDateOut = dateOutQuery === '' || dateOut === dateOutQuery;
            const matchDateIn = dateInQuery === '' || dateIn.includes(dateInQuery);

            if (matchDev && matchUsr && matchDateOut && matchDateIn) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        if (noResults) {
            noResults.style.display = (visibleCount === 0 && rows.length > 0) ? '' : 'none';
        }
    }

    [filterDevice, filterUser, filterDateOut, filterDateIn].forEach(el => {
        el.addEventListener('input', applyAdminFilters);
        el.addEventListener('change', applyAdminFilters);
    });

    resetFiltersBtn.addEventListener('click', () => {
        filterDevice.value = '';
        filterUser.value = '';
        filterDateOut.value = '';
        filterDateIn.value = '';
        applyAdminFilters();
    });

    // --- Modal Populate for Editing ---
    const editModal = document.getElementById('editModal');
    if (editModal) {
        const editUserName = document.getElementById('editUserName');
        const editUserId = document.getElementById('editUserId');
        const editUserIcon = document.getElementById('editUserIcon');
        const editUserLink = document.getElementById('editUserLink');

        // Says which directory person (if any) the record will be saved as linked to
        function showLink(email) {
            editUserLink.replaceChildren();
            if (email) {
                const strong = document.createElement('strong');
                strong.textContent = email;
                editUserLink.append('Linked to ', strong, ' in the Google directory.');
            } else {
                editUserLink.textContent = document.body.dataset.directory === 'on'
                    ? 'Not linked. Start typing to choose someone from the directory, or enter any name.'
                    : 'Not linked to the Google directory.';
            }
        }

        let nameAutocomplete = null;
        if (document.body.dataset.directory === 'on') {
            nameAutocomplete = setupNameAutocomplete({
                input: editUserName,
                userIdInput: editUserId,
                list: document.getElementById('editUserSuggestions'),
                icon: editUserIcon,
                allowTyped: true,
                includeInactive: true, // Old records may belong to people who have since left
                onLinkChange: person => showLink(person ? person.email : null)
            });
        } else {
            // No suggestions, but a hand-edited name must still drop the old link
            editUserName.addEventListener('input', () => {
                if (editUserId.value !== '') {
                    editUserId.value = '';
                    editUserIcon.className = 'bi bi-person';
                    showLink(null);
                }
            });
        }

        editModal.addEventListener('show.bs.modal', event => {
            const button = event.relatedTarget;
            document.getElementById('editRecordId').value = button.getAttribute('data-id');
            document.getElementById('editAssetNumber').value = button.getAttribute('data-asset');
            editUserName.value = button.getAttribute('data-user');
            editUserId.value = button.getAttribute('data-user-id');
            document.getElementById('editCheckoutTime').value = button.getAttribute('data-checkout');
            document.getElementById('editCheckinTime').value = button.getAttribute('data-checkin');

            const linked = editUserId.value !== '';
            if (nameAutocomplete) {
                nameAutocomplete.markLinked(linked);
            } else {
                editUserIcon.className = linked ? 'bi bi-person-check-fill text-success' : 'bi bi-person';
            }
            showLink(linked ? button.getAttribute('data-user-email') : null);
        });
    }

    // --- Overdue Email Preview ---
    const emailPreviewModal = document.getElementById('emailPreviewModal');
    if (emailPreviewModal) {
        const frame = document.getElementById('emailPreviewFrame');
        emailPreviewModal.addEventListener('show.bs.modal', event => {
            const button = event.relatedTarget;
            document.getElementById('emailPreviewModalLabel').textContent = button.getAttribute('data-title');
            frame.src = 'admin.php?preview_email=' + encodeURIComponent(button.getAttribute('data-preview'));
        });
        emailPreviewModal.addEventListener('hidden.bs.modal', () => {
            frame.src = 'about:blank';
        });
    }

    // --- Modal Populate for Photo View ---
    const photoModal = document.getElementById('photoModal');
    if (photoModal) {
        photoModal.addEventListener('show.bs.modal', event => {
            const button = event.relatedTarget;
            const photoUrl = button.getAttribute('data-photo');
            const user = button.getAttribute('data-user');
            const asset = button.getAttribute('data-asset');

            document.getElementById('verificationImage').src = photoUrl;
            // Build with textContent so names/assets are never interpreted as HTML
            const userStrong = document.createElement('strong');
            userStrong.textContent = user;
            const assetStrong = document.createElement('strong');
            assetStrong.textContent = `#${asset}`;
            document.getElementById('photoMeta').replaceChildren('Assigned to: ', userStrong, ' • Chromebook: ', assetStrong);
        });
    }
});
