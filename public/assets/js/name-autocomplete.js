/**
 * Turns a name field into a combobox that suggests people from the synced Google
 * directory (people.php). Choosing a suggestion fills the hidden user ID field, which
 * links the record to that person; editing the text afterwards unlinks it.
 * Used by the kiosk's "Checkout To" field and the admin page's edit form.
 *
 * @param {Object}           options
 * @param {HTMLInputElement} options.input           The visible name field
 * @param {HTMLInputElement} options.userIdInput     Hidden field submitted as user_id
 * @param {HTMLElement}      options.list            Empty <ul class="dropdown-menu"> for suggestions
 * @param {HTMLElement}      options.icon            <i> icon that shows whether a person is linked
 * @param {boolean}          options.allowTyped      Offer "Use what I typed" for names not in the directory
 * @param {boolean}          [options.includeInactive] Also suggest people no longer active (admins only)
 * @param {Function}         [options.onLinkChange]  Called with the chosen person, or null when unlinked
 * @returns {{markLinked: Function}} Call markLinked(bool) after setting the fields from code
 */
function setupNameAutocomplete(options) {
    const { input, userIdInput, list, icon, allowTyped } = options;
    const includeInactive = !!options.includeInactive;
    const onLinkChange = options.onLinkChange || (() => {});

    let items = [];          // [{id, name, email, active}], plus {typed: true, name} for "use what I typed"
    let activeIndex = -1;
    let debounceTimer = null;
    let requestSeq = 0;      // Ignore responses to older keystrokes
    let pending = false;     // Suggestions for the current text haven't arrived yet

    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', list.id);
    input.setAttribute('aria-expanded', 'false');

    function markLinked(linked) {
        icon.className = linked ? 'bi bi-person-check-fill text-success' : 'bi bi-person';
    }

    function optionId(i) {
        return list.id + 'Option' + i;
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
            option.id = optionId(i);
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
                if (!item.active) {
                    const former = document.createElement('span');
                    former.className = 'badge bg-secondary ms-2 fw-normal';
                    former.textContent = 'former';
                    name.appendChild(former);
                }
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
            input.setAttribute('aria-activedescendant', optionId(activeIndex));
            document.getElementById(optionId(activeIndex)).scrollIntoView({ block: 'nearest' });
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    }

    function choose(i) {
        const item = items[i];
        input.value = item.name;
        userIdInput.value = item.typed ? '' : item.id;
        markLinked(!item.typed);
        onLinkChange(item.typed ? null : item);
        close();
    }

    async function fetchSuggestions(query) {
        const seq = ++requestSeq;
        let people = [];
        try {
            const url = 'people.php?q=' + encodeURIComponent(query) + (includeInactive ? '&include_inactive=1' : '');
            const response = await fetch(url);
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
        if (userIdInput.value !== '') {
            userIdInput.value = '';
            onLinkChange(null);
        }
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
            e.stopPropagation(); // Close the list, not a surrounding modal
            close();
        }
    });

    input.addEventListener('blur', close);

    return { markLinked };
}
