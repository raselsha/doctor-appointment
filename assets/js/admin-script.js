document.addEventListener('DOMContentLoaded', function() {

    // "Today" for the scheduling calendars below comes from the server
    // (mdbk_admin_obj.today, set via current_time('Y-m-d') — WP's
    // configured site timezone), not the admin's own browser clock, so the
    // past/bookable-date cutoff always matches the clinic's actual today
    // regardless of which timezone the admin happens to be browsing from.
    function parseServerDate(str) {
        if (str) {
            var parts = str.split('-');
            return new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
        }
        return new Date();
    }

    /**
     * "09:00" -> "9:00 AM" (or left as "09:00" if the site's WordPress
     * Settings > General > Time Format is a 24-hour one — see
     * mdbk_admin_obj.time_format_24h, doctor-appointment.php's
     * clinic_branding_data()) — used everywhere this admin JS renders a
     * doctor's schedule hours (the "View" popup's client-rendered
     * version), so it never disagrees with the server-rendered one.
     */
    function mdbkFormatTimeDisplay(time24) {
        if (!time24) return '';
        if (typeof mdbk_admin_obj !== 'undefined' && mdbk_admin_obj.time_format_24h) return time24;
        var parts = time24.split(':');
        var hour = parseInt(parts[0], 10);
        var minute = parts[1];
        var suffix = hour >= 12 ? 'PM' : 'AM';
        var hour12 = hour % 12;
        if (hour12 === 0) hour12 = 12;
        return hour12 + ':' + minute + ' ' + suffix;
    }

    function initModal(modalId, openSelector, formId, editClass, populateFn) {
        const modal = document.getElementById(modalId);
        if (!modal) return;
        const closeBtn = modal.querySelector('.mdbk-modal-close');
        const form = document.getElementById(formId) || modal.querySelector('form');

        // Delegated on document (not bound once per matched button at load)
        // since several of these open-triggers/edit-rows live inside
        // fragments the Patients/Booking pages' own live search replaces
        // via AJAX after load — a per-element binding here would go dead
        // on every row those searches re-render.
        document.addEventListener('click', function(e) {
            const btn = e.target.closest(openSelector);
            if (!btn) return;
            e.preventDefault();
            modal.style.display = 'flex';
            if (btn.classList.contains(editClass)) {
                populateFn(btn.dataset.id, btn);
            } else if (form) {
                // form.reset() alone already correctly restores every
                // field to its original page-load value — including
                // the ID hidden field (back to empty, since it has no
                // `value` attribute) AND the nonce field (back to its
                // valid freshly-rendered value). A prior version of
                // this also did `form.querySelector('input[type="hidden"]').value = ''`
                // to explicitly blank the ID field, but wp_nonce_field()
                // always renders its OWN hidden inputs (_wpnonce,
                // _wp_http_referer) before the ID field in every one of
                // this plugin's modals — so that querySelector matched
                // the NONCE field instead (the first hidden input in
                // DOM order) and blanked it, silently breaking every
                // "Add New X" submission with a "link expired" nonce
                // failure. Removed rather than fixed-to-target-the-
                // right-field, since form.reset() already made it
                // redundant regardless.
                form.reset();
            }
        });
        closeBtn.addEventListener('click', () => modal.style.display = 'none');
        window.addEventListener('click', (e) => { if (e.target == modal) modal.style.display = 'none'; });
    }

    /**
     * Doctors/Specialties "Reorder" modal — a plain open/close wrapper (no
     * form, no add-vs-edit distinction like initModal() above handles) plus
     * drag-and-drop reordering and the AJAX save. triggerBtn/modal/ajaxAction
     * are per-type (doctor vs specialty); the drag mechanics themselves are
     * identical either way.
     */
    function initReorderModal(triggerId, modalId, ajaxAction) {
        const trigger = document.getElementById(triggerId);
        const modal = document.getElementById(modalId);
        if (!trigger || !modal || typeof mdbk_admin_obj === 'undefined') return;

        const list = modal.querySelector('.mdbk-reorder-list');
        const closeBtn = modal.querySelector('.mdbk-modal-close');
        const cancelBtn = modal.querySelector('.mdbk-modal-cancel');
        const saveBtn = modal.querySelector('.mdbk-save-reorder');
        const sortAzBtn = modal.querySelector('.mdbk-sort-az');
        const sortZaBtn = modal.querySelector('.mdbk-sort-za');

        trigger.addEventListener('click', () => { modal.style.display = 'flex'; });
        function close() { modal.style.display = 'none'; }
        if (closeBtn) closeBtn.addEventListener('click', close);
        if (cancelBtn) cancelBtn.addEventListener('click', close);
        window.addEventListener('click', (e) => { if (e.target === modal) close(); });

        // A→Z / Z→A — an instant full re-sort by name, on top of (not
        // instead of) manual dragging: sort once to get close, then
        // fine-tune a specific item's position by hand if needed. Reuses
        // the exact same list.appendChild() re-parenting the drag's own
        // onUp() commits with, so both paths leave the list in an
        // identical, equally "real" DOM order — the Save button doesn't
        // need to know or care which one produced it.
        function sortByName(direction) {
            if (!list) return;
            const items = Array.from(list.querySelectorAll('.mdbk-reorder-item'));
            items.sort((a, b) => {
                const nameA = a.querySelector('.mdbk-reorder-name').textContent.trim();
                const nameB = b.querySelector('.mdbk-reorder-name').textContent.trim();
                return direction === 'desc' ? nameB.localeCompare(nameA) : nameA.localeCompare(nameB);
            });
            items.forEach((el) => list.appendChild(el));
        }
        if (sortAzBtn) sortAzBtn.addEventListener('click', () => sortByName('asc'));
        if (sortZaBtn) sortZaBtn.addEventListener('click', () => sortByName('desc'));

        // Pointer Events (not the HTML5 Drag and Drop API, which has no
        // touch support at all) — one code path handles mouse AND touch,
        // "hold and move" either way.
        //
        // The dragged item tracks the pointer directly via a CSS
        // transform (no transition — see .is-dragging in admin-style.css
        // — so it follows with zero lag), while every OTHER item slides
        // smoothly into a "make room" gap via its own transform + the
        // transition every .mdbk-reorder-item already has. The DOM itself
        // is only actually reordered ONCE, on drop — during the drag it's
        // all just visual transforms, which is what makes this smooth
        // instead of the earlier version's instant insertBefore() on
        // every single pointermove (the "abnormal", flickering jump the
        // reorder modal used to show mid-drag).
        if (list) {
            list.querySelectorAll('.mdbk-reorder-handle').forEach((handle) => {
                handle.addEventListener('pointerdown', (e) => {
                    const item = handle.closest('.mdbk-reorder-item');
                    if (!item) return;
                    e.preventDefault();
                    handle.setPointerCapture(e.pointerId);

                    const allItems = Array.from(list.querySelectorAll('.mdbk-reorder-item'));
                    const startIndex = allItems.indexOf(item);
                    const others = allItems.filter((el) => el !== item);
                    // Snapshot each OTHER item's resting position ONCE —
                    // used as the stable reference for every "has the
                    // pointer crossed this row's midpoint" check for the
                    // rest of this drag, so a sibling's own temporary
                    // "make room" shift never feeds back into where we
                    // think the pointer currently is (which is what would
                    // cause the item to oscillate back and forth).
                    const otherRects = others.map((el) => el.getBoundingClientRect());
                    const step = item.getBoundingClientRect().height + 6; // 6px matches .mdbk-reorder-list's own gap

                    const startY = e.clientY;
                    let currentIndex = startIndex; // target slot among `others`

                    item.classList.add('is-dragging');

                    function onMove(ev) {
                        item.style.transform = 'translateY(' + (ev.clientY - startY) + 'px)';

                        let newIndex = 0;
                        otherRects.forEach((rect) => {
                            if (ev.clientY > rect.top + rect.height / 2) newIndex++;
                        });

                        if (newIndex !== currentIndex) {
                            const lo = Math.min(currentIndex, newIndex);
                            const hi = Math.max(currentIndex, newIndex);
                            others.forEach((el, idx) => {
                                if (idx < lo || idx >= hi) {
                                    el.style.transform = '';
                                } else {
                                    el.style.transform = 'translateY(' + (currentIndex < newIndex ? -step : step) + 'px)';
                                }
                            });
                            currentIndex = newIndex;
                        }
                    }

                    function onUp(ev) {
                        handle.removeEventListener('pointermove', onMove);
                        handle.removeEventListener('pointerup', onUp);
                        handle.releasePointerCapture(ev.pointerId);

                        item.classList.remove('is-dragging');
                        item.style.transform = '';
                        others.forEach((el) => { el.style.transform = ''; });

                        if (currentIndex !== startIndex) {
                            const finalOrder = others.slice();
                            finalOrder.splice(currentIndex, 0, item);
                            finalOrder.forEach((el) => list.appendChild(el));
                        }
                    }

                    handle.addEventListener('pointermove', onMove);
                    handle.addEventListener('pointerup', onUp);
                });
            });
        }

        if (saveBtn) {
            saveBtn.addEventListener('click', () => {
                const ids = list ? Array.from(list.querySelectorAll('.mdbk-reorder-item')).map((el) => el.dataset.id) : [];
                const body = new URLSearchParams();
                body.set('action', ajaxAction);
                body.set('nonce', mdbk_admin_obj.nonce);
                ids.forEach((id) => body.append('order[]', id));
                saveBtn.disabled = true;
                fetch(mdbk_admin_obj.ajax_url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                    .then((r) => r.json())
                    .then((res) => {
                        saveBtn.disabled = false;
                        if (res && res.success) {
                            window.location.reload();
                        } else {
                            alert((res && res.data && res.data.message) || 'Something went wrong, please try again.');
                        }
                    })
                    .catch(() => {
                        saveBtn.disabled = false;
                        alert('Something went wrong, please try again.');
                    });
            });
        }
    }
    initReorderModal('mdbk-open-doctor-reorder', 'mdbk-reorder-modal-doctor', 'mdbk_save_doctor_order');
    initReorderModal('mdbk-open-specialty-reorder', 'mdbk-reorder-modal-specialty', 'mdbk_save_specialty_order');

    // Patient Directory's live search — debounced 300ms on every
    // keystroke (same feel as the tailor-manager project's own live
    // customer search), immediate on the gender <select> since a dropdown
    // change doesn't need debouncing. ajax_search_patients() (admin-
    // dashboard.php) returns the exact same count sentence + results
    // markup render_patients_page() itself uses, so the two can never
    // drift apart. The <form>'s own GET submit/page-reload path stays as
    // a plain fallback (e.g. no-JS), untouched by any of this.
    (function() {
        const searchInput = document.getElementById('mdbk-patients-search');
        const genderSelect = document.getElementById('mdbk-patients-filter-gender');
        if (!searchInput || typeof mdbk_admin_obj === 'undefined') return;
        const countEl = document.getElementById('mdbk-patients-count');
        const resultsEl = document.getElementById('mdbk-patients-results');
        const clearBtn = document.getElementById('mdbk-patients-clear-filters');
        let debounceTimer;

        function runSearch() {
            const body = new URLSearchParams();
            body.set('action', 'mdbk_search_patients');
            body.set('nonce', mdbk_admin_obj.nonce);
            body.set('s', searchInput.value);
            body.set('filter_gender', genderSelect ? genderSelect.value : '');
            fetch(mdbk_admin_obj.ajax_url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                .then((r) => r.json())
                .then((res) => {
                    if (!res || !res.success) return;
                    if (countEl) countEl.textContent = res.data.count_html;
                    if (resultsEl) resultsEl.innerHTML = res.data.results_html;
                    if (clearBtn) clearBtn.style.display = (searchInput.value || (genderSelect && genderSelect.value)) ? '' : 'none';
                })
                .catch(() => {});
        }

        searchInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(runSearch, 300);
        });
        if (genderSelect) genderSelect.addEventListener('change', function() {
            clearTimeout(debounceTimer);
            runSearch();
        });
        if (clearBtn) clearBtn.addEventListener('click', function(e) {
            e.preventDefault();
            searchInput.value = '';
            if (genderSelect) genderSelect.value = '';
            clearTimeout(debounceTimer);
            runSearch();
        });
    })();

    // Booking page's live search — same debounced-AJAX shape as the
    // Patient Directory search above, so staff can check whether someone
    // already has an appointment without a full page reload.
    // ajax_search_schedule() reuses render_schedule_results_html(), the
    // exact same private method render_schedule_page() itself calls, so
    // the AJAX fragment and a hard page reload can never show different
    // markup for the same filters. Date navigation (prev/today/next/date
    // picker) deliberately stays a full <form> submit — the resulting
    // view differs enough (today's rich queue vs. a flat other-date list)
    // that keeping it a real page load is simpler and avoids a query-string
    // getting out of sync with what's on screen.
    (function() {
        const searchInput = document.getElementById('mdbk-schedule-search');
        if (!searchInput || typeof mdbk_admin_obj === 'undefined') return;
        const doctorSelect = document.getElementById('mdbk-schedule-filter-doctor');
        const statusSelect = document.getElementById('mdbk-schedule-filter-status');
        const countEl = document.getElementById('mdbk-schedule-count');
        const resultsEl = document.getElementById('mdbk-schedule-results');
        const clearBtn = document.getElementById('mdbk-schedule-clear-filters');
        const dateInput = document.querySelector('.mdbk-date-nav-input');
        let debounceTimer;

        function runSearch() {
            const body = new URLSearchParams();
            body.set('action', 'mdbk_search_schedule');
            body.set('nonce', mdbk_admin_obj.nonce);
            body.set('s', searchInput.value);
            body.set('filter_doctor', doctorSelect ? doctorSelect.value : '');
            body.set('filter_status', statusSelect ? statusSelect.value : '');
            body.set('filter_date', dateInput ? dateInput.value : '');
            fetch(mdbk_admin_obj.ajax_url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                .then((r) => r.json())
                .then((res) => {
                    if (!res || !res.success) return;
                    if (countEl) countEl.innerHTML = res.data.count_html;
                    if (resultsEl) resultsEl.innerHTML = res.data.results_html;
                    const hasFilters = searchInput.value || (doctorSelect && doctorSelect.value) || (statusSelect && statusSelect.value);
                    if (clearBtn) clearBtn.style.display = hasFilters ? '' : 'none';
                })
                .catch(() => {});
        }

        searchInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(runSearch, 300);
        });
        if (doctorSelect) doctorSelect.addEventListener('change', function() {
            clearTimeout(debounceTimer);
            runSearch();
        });
        if (statusSelect) statusSelect.addEventListener('change', function() {
            clearTimeout(debounceTimer);
            runSearch();
        });
        if (clearBtn) clearBtn.addEventListener('click', function(e) {
            e.preventDefault();
            searchInput.value = '';
            if (doctorSelect) doctorSelect.value = '';
            if (statusSelect) statusSelect.value = '';
            clearTimeout(debounceTimer);
            runSearch();
        });
    })();

    // Matches the PHP-rendered Weekly Availability form's own day order
    // (Settings > General > "Week Starts On" — see get_week_day_order() in
    // appointment-manager.php), passed through mdbk_admin_obj so there's
    // one source of truth instead of a second hardcoded list. Both actual
    // uses below look each day up by name (order-independent), so this
    // fallback only matters if mdbk_admin_obj somehow isn't loaded.
    const DOCTOR_DAYS = (typeof mdbk_admin_obj !== 'undefined' && Array.isArray(mdbk_admin_obj.week_days))
        ? mdbk_admin_obj.week_days
        : ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    const DOCTOR_PHOTO_PLACEHOLDER = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>';
    // Availability section header icons — same markup as the ones rendered
    // server-side for the Edit modal, so the View modal's read-only copy
    // matches it exactly.
    const CALENDAR_ICON = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="3"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>';
    const CALENDAR_MONTH_ICON = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="3"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line><circle cx="12" cy="15" r="2"></circle></svg>';

    function setDoctorPhotoPreview(url) {
        const preview = document.getElementById('mdbk-doc-photo-preview');
        const removeBtn = document.getElementById('mdbk-doc-photo-remove');
        if (!preview) return;
        preview.innerHTML = url ? '<img src="' + url + '" alt="">' : DOCTOR_PHOTO_PLACEHOLDER;
        if (removeBtn) removeBtn.style.display = url ? '' : 'none';
    }

    // Slot Duration only means anything when Time Slot Booking is on —
    // dim it (purely visual; the value still submits either way, but it's
    // ignored server-side for a slot-disabled doctor) so it doesn't look
    // like an active, required field while off.
    function updateSlotDurationVisibility() {
        var toggle = document.getElementById('mdbk-doc-slot-enabled');
        var group = document.getElementById('mdbk-doc-slot-duration-group');
        if (toggle && group) group.classList.toggle('mdbk-field-disabled', !toggle.checked);
    }
    var slotEnabledToggle = document.getElementById('mdbk-doc-slot-enabled');
    if (slotEnabledToggle) slotEnabledToggle.addEventListener('change', updateSlotDurationVisibility);

    // Monthly Availability's two calendars (extra working dates / off
    // dates) — a hand-built month grid, click a day to toggle it into that
    // calendar's own date set. Mirrors the frontend booking form's own
    // calendar (plain <span> day cells, Prev/Next month nav) rather than a
    // native date input, for the same reason: nothing here for the theme's
    // button/input reset CSS to snag on.
    function createMiniCalendar(containerId, hiddenInputId, getRegularWeekdays) {
        const container = document.getElementById(containerId);
        const hiddenInput = document.getElementById(hiddenInputId);
        if (!container || !hiddenInput) return null;

        const today = parseServerDate(typeof mdbk_admin_obj !== 'undefined' ? mdbk_admin_obj.today : null);
        let viewYear = today.getFullYear();
        let viewMonth = today.getMonth();
        let selected = [];

        function pad2(n) { return String(n).padStart(2, '0'); }
        function todayStr() { return today.getFullYear() + '-' + pad2(today.getMonth() + 1) + '-' + pad2(today.getDate()); }

        function sync() { hiddenInput.value = JSON.stringify(selected); }

        function render() {
            const firstDay = new Date(viewYear, viewMonth, 1).getDay();
            const days = new Date(viewYear, viewMonth + 1, 0).getDate();
            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
            const tStr = todayStr();
            // Read live off the Weekly Availability checkboxes on every
            // render (not a one-time snapshot) so toggling a day there
            // immediately updates which dates read as "regular" here —
            // that's the whole point: see the normal pattern before
            // deciding where an extra/off date makes sense against it.
            const regularWeekdays = getRegularWeekdays ? getRegularWeekdays() : [];

            let html = '<div class="mdbk-mini-cal-nav">' +
                '<button type="button" class="mdbk-mini-cal-nav-btn" data-action="prev">&lsaquo;</button>' +
                '<span class="mdbk-mini-cal-title">' + monthNames[viewMonth] + ' ' + viewYear + '</span>' +
                '<button type="button" class="mdbk-mini-cal-nav-btn" data-action="next">&rsaquo;</button>' +
                '</div><div class="mdbk-mini-cal-grid">';
            ['S', 'M', 'T', 'W', 'T', 'F', 'S'].forEach(function(l) { html += '<span class="mdbk-mini-cal-day-header">' + l + '</span>'; });
            for (let i = 0; i < firstDay; i++) html += '<span class="mdbk-mini-cal-day empty"></span>';
            for (let d = 1; d <= days; d++) {
                const dateStr = viewYear + '-' + pad2(viewMonth + 1) + '-' + pad2(d);
                const dayOfWeek = new Date(viewYear, viewMonth, d).getDay();
                let classes = 'mdbk-mini-cal-day';
                if (dateStr < tStr) classes += ' past';
                const isSelected = selected.indexOf(dateStr) !== -1;
                if (isSelected) classes += ' selected';
                else if (regularWeekdays.indexOf(dayOfWeek) !== -1) classes += ' regular';
                html += '<span class="' + classes + '" data-date="' + dateStr + '">' + d + '</span>';
            }
            html += '</div>';
            container.innerHTML = html;
        }

        container.addEventListener('click', function(e) {
            const navBtn = e.target.closest('.mdbk-mini-cal-nav-btn');
            if (navBtn) {
                if (navBtn.dataset.action === 'prev') { viewMonth--; if (viewMonth < 0) { viewMonth = 11; viewYear--; } }
                else { viewMonth++; if (viewMonth > 11) { viewMonth = 0; viewYear++; } }
                render();
                return;
            }
            const dayEl = e.target.closest('.mdbk-mini-cal-day');
            if (!dayEl || dayEl.classList.contains('empty') || dayEl.classList.contains('past')) return;
            const dateStr = dayEl.getAttribute('data-date');
            const idx = selected.indexOf(dateStr);
            if (idx === -1) selected.push(dateStr); else selected.splice(idx, 1);
            sync();
            render();
        });

        render();

        return {
            setSelected: function(dates) {
                selected = Array.isArray(dates) ? dates.slice() : [];
                if (selected.length) {
                    const parts = selected[0].split('-').map(Number);
                    viewYear = parts[0];
                    viewMonth = parts[1] - 1;
                } else {
                    viewYear = today.getFullYear();
                    viewMonth = today.getMonth();
                }
                sync();
                render();
            },
            reset: function() {
                selected = [];
                viewYear = today.getFullYear();
                viewMonth = today.getMonth();
                sync();
                render();
            },
            rerender: render
        };
    }
    // Day-of-week indices (JS Date.getDay(): 0=Sunday..6=Saturday) currently
    // checked on in Weekly Availability — read fresh each time so the mini
    // calendars' "regular day" highlight always matches what's on screen.
    const DAY_NAME_TO_INDEX = { Sunday: 0, Monday: 1, Tuesday: 2, Wednesday: 3, Thursday: 4, Friday: 5, Saturday: 6 };
    function getRegularActiveWeekdays() {
        const result = [];
        DOCTOR_DAYS.forEach(function(day) {
            const check = document.querySelector('input[name="schedule[' + day + '][active]"]');
            if (check && check.checked) result.push(DAY_NAME_TO_INDEX[day]);
        });
        return result;
    }
    const docExtraCal = createMiniCalendar('mdbk-doc-extra-cal', 'mdbk-doc-extra-dates-input', getRegularActiveWeekdays);
    const docOffCal = createMiniCalendar('mdbk-doc-off-cal', 'mdbk-doc-off-dates-input', getRegularActiveWeekdays);
    // Enabling a day with no hours set yet defaults it to a normal
    // 9-to-5 rather than leaving the from/to time inputs blank (which
    // get_available_slots() would just treat as "closed that day" —
    // see appointment-manager.php); disabling clears them back to
    // empty, so re-enabling always lands on that same clean default
    // instead of silently keeping a stale time from before.
    document.querySelectorAll('.mdbk-day-check').forEach(function(cb) {
        cb.addEventListener('change', function() {
            const row = cb.closest('.mdbk-day-row');
            const fromInput = row ? row.querySelector('.mdbk-day-times input[name$="[from]"]') : null;
            const toInput = row ? row.querySelector('.mdbk-day-times input[name$="[to]"]') : null;
            if (cb.checked) {
                if (fromInput && !fromInput.value) fromInput.value = '09:00';
                if (toInput && !toInput.value) toInput.value = '17:00';
            } else {
                if (fromInput) fromInput.value = '';
                if (toInput) toInput.value = '';
            }
            if (docExtraCal) docExtraCal.rerender();
            if (docOffCal) docOffCal.rerender();
        });
    });

    // Generic custom-dropdown controller: a real (hidden) <select> stays the
    // actual form control everything else reads/writes and submits; this just
    // keeps a styleable button+panel visually in sync with it. Returns null
    // if the wrapper isn't on the page, so callers can no-op safely.
    function initCustomSelect(wrapperId, onChange) {
        const wrapper = document.getElementById(wrapperId);
        if (!wrapper) return null;
        const trigger = wrapper.querySelector('.mdbk-custom-select-trigger');
        const valueEl = wrapper.querySelector('.mdbk-custom-select-value');
        const panel = wrapper.querySelector('.mdbk-custom-select-panel');
        const hiddenSelect = wrapper.querySelector('select');

        function close() { wrapper.classList.remove('open'); panel.style.display = 'none'; }
        function open() { wrapper.classList.add('open'); panel.style.display = 'block'; }

        function setValue(value, label) {
            hiddenSelect.value = value;
            if (valueEl) valueEl.textContent = label;
            let selectedOpt = null;
            panel.querySelectorAll('.mdbk-custom-select-option').forEach(function(o) {
                const isMatch = String(o.dataset.value) === String(value);
                o.classList.toggle('selected', isMatch);
                if (isMatch) selectedOpt = o;
            });
            if (onChange) onChange(selectedOpt, value);
        }

        trigger.addEventListener('click', function(e) {
            e.preventDefault();
            wrapper.classList.contains('open') ? close() : open();
        });
        panel.addEventListener('click', function(e) {
            const opt = e.target.closest('.mdbk-custom-select-option');
            if (!opt) return;
            setValue(opt.dataset.value, opt.textContent);
            close();
        });
        document.addEventListener('click', function(e) { if (!wrapper.contains(e.target)) close(); });
        document.addEventListener('keydown', function(e) { if (e.key === 'Escape') close(); });

        return { setValue: setValue, wrapper: wrapper, panel: panel };
    }

    const doctorSpecSelect = initCustomSelect('mdbk-doc-spec-select');
    const patientGenderSelect = initCustomSelect('mdbk-patient-gender-select');
    const staffRoleSelect = initCustomSelect('mdbk-staff-role-select');

    initModal('mdbk-doctor-modal', '.mdbk-add-doctor, .mdbk-edit-doctor', 'mdbk-doctor-form', 'mdbk-edit-doctor', (id, btn) => {
        document.getElementById('mdbk-doctor-id').value = id;
        // .mdbk-profile-view: the doctor's own "Profile" page (reached
        // from the sidebar) — a real page, not a grid card, but carries
        // the same data-* attributes purely so this populate step works
        // unchanged.
        const row = btn.closest('tr, .mdbk-admin-doctor-card, .mdbk-profile-view');
        const title = document.getElementById('mdbk-doctor-modal-title');
        if (title) title.textContent = 'Edit Doctor';
        if (row) {
            document.getElementById('mdbk-doc-name').value = row.dataset.name;
            document.getElementById('mdbk-doc-email').value = row.dataset.email;
            document.getElementById('mdbk-doc-phone').value = row.dataset.phone;
            (function() {
                var el = document.getElementById('mdbk-doc-bio');
                if (el) {
                    var val = row.getAttribute('data-bio') || '';
                    el.value = val;
                }
            })();
            var showPhone = document.getElementById('mdbk-show-phone');
            if (showPhone) showPhone.checked = row.dataset.showPhone !== 'no';
            var showEmail = document.getElementById('mdbk-show-email');
            if (showEmail) showEmail.checked = row.dataset.showEmail !== 'no';
            var slotDuration = document.getElementById('mdbk-doc-slot-duration');
            if (slotDuration) slotDuration.value = row.dataset.slotDuration || 20;
            var slotEnabled = document.getElementById('mdbk-doc-slot-enabled');
            if (slotEnabled) { slotEnabled.checked = row.dataset.slotEnabled !== 'no'; updateSlotDurationVisibility(); }
            if (doctorSpecSelect && row.dataset.specialty) {
                const opt = doctorSpecSelect.panel.querySelector('.mdbk-custom-select-option[data-value="' + row.dataset.specialty + '"]');
                if (opt) doctorSpecSelect.setValue(opt.dataset.value, opt.textContent);
            }
            var photoId = document.getElementById('mdbk-doc-photo-id');
            if (photoId) photoId.value = row.dataset.thumbnailId || 0;
            setDoctorPhotoPreview(row.dataset.thumbnail || '');

            // Populate Day-wise Schedule — visits every day, not just the ones
            // present in this doctor's schedule, so a previously-edited
            // doctor's checked/filled days can't leak into this one (the
            // modal form isn't reset between two consecutive Edit clicks).
            var schedule = {};
            try { schedule = JSON.parse(row.dataset.schedule) || {}; } catch(e) { console.error("Error parsing schedule JSON", e); }
            DOCTOR_DAYS.forEach(function(day) {
                const activeCheck = document.querySelector(`input[name="schedule[${day}][active]"]`);
                const fromInput = document.querySelector(`input[name="schedule[${day}][from]"]`);
                const toInput = document.querySelector(`input[name="schedule[${day}][to]"]`);
                const d = schedule[day];
                const isActive = !!(d && d.active);
                if (activeCheck) {
                    activeCheck.checked = isActive;
                    const dayRow = activeCheck.closest('.mdbk-day-row');
                    if (dayRow) dayRow.classList.toggle('is-off', !isActive);
                }
                if (fromInput) fromInput.value = isActive ? (d.from || '') : '';
                if (toInput) toInput.value = isActive ? (d.to || '') : '';
            });

            if (docExtraCal) { try { docExtraCal.setSelected(JSON.parse(row.dataset.extraDates) || []); } catch(e) { docExtraCal.reset(); } }
            if (docOffCal) { try { docOffCal.setSelected(JSON.parse(row.dataset.offDates) || []); } catch(e) { docOffCal.reset(); } }
        }
    });

    document.querySelectorAll('.mdbk-add-doctor').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const title = document.getElementById('mdbk-doctor-modal-title');
            if (title) title.textContent = 'Add Doctor';
            setDoctorPhotoPreview('');
            document.querySelectorAll('#mdbk-doctor-form .mdbk-day-row').forEach(function(row) {
                row.classList.add('is-off');
            });
            if (slotEnabledToggle) { slotEnabledToggle.checked = true; updateSlotDurationVisibility(); }
            if (docExtraCal) docExtraCal.reset();
            if (docOffCal) docOffCal.reset();
            if (doctorSpecSelect) {
                const firstOpt = doctorSpecSelect.panel.querySelector('.mdbk-custom-select-option');
                if (firstOpt) doctorSpecSelect.setValue(firstOpt.dataset.value, firstOpt.textContent);
            }
        });
    });

    let doctorPhotoFrame;
    const doctorPhotoUpload = document.getElementById('mdbk-doc-photo-upload');
    if (doctorPhotoUpload) {
        doctorPhotoUpload.addEventListener('click', function(e) {
            e.preventDefault();
            if (typeof wp === 'undefined' || !wp.media) return;
            if (doctorPhotoFrame) { doctorPhotoFrame.open(); return; }
            doctorPhotoFrame = wp.media({
                title: 'Select Doctor Photo',
                button: { text: 'Use this photo' },
                multiple: false,
                library: { type: 'image' }
            });
            doctorPhotoFrame.on('select', function() {
                const attachment = doctorPhotoFrame.state().get('selection').first().toJSON();
                const url = (attachment.sizes && attachment.sizes.thumbnail) ? attachment.sizes.thumbnail.url : attachment.url;
                document.getElementById('mdbk-doc-photo-id').value = attachment.id;
                setDoctorPhotoPreview(url);
            });
            doctorPhotoFrame.open();
        });
    }
    const doctorPhotoRemove = document.getElementById('mdbk-doc-photo-remove');
    if (doctorPhotoRemove) {
        doctorPhotoRemove.addEventListener('click', function() {
            document.getElementById('mdbk-doc-photo-id').value = 0;
            setDoctorPhotoPreview('');
        });
    }

    const doctorModalCancel = document.querySelector('#mdbk-doctor-modal .mdbk-modal-cancel');
    if (doctorModalCancel) {
        doctorModalCancel.addEventListener('click', function() {
            document.getElementById('mdbk-doctor-modal').style.display = 'none';
        });
    }

    function escHtml(str) {
        const div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    // Read-only "View" popup — built entirely from the clicked card's own
    // markup/data-* attributes rather than a second server round-trip, so it
    // always reflects whatever is currently on screen (e.g. a just-flipped
    // Active/Inactive toggle).
    initModal('mdbk-doctor-view-modal', '.mdbk-view-doctor', '', 'mdbk-view-doctor', (id, btn) => {
        const card = btn.closest('.mdbk-admin-doctor-card');
        const body = document.getElementById('mdbk-doctor-view-body');
        if (!card || !body) return;

        const avatarHtml = card.querySelector('.mdbk-admin-doctor-card-avatar').innerHTML;
        const specialtyHtml = card.querySelector('.mdbk-admin-doctor-card-specialty').outerHTML;
        const isActive = !card.classList.contains('is-inactive');

        let schedule = {};
        try { schedule = JSON.parse(card.dataset.schedule) || {}; } catch (e) {}
        const days = DOCTOR_DAYS;
        const dayLabels = (typeof mdbk_admin_obj !== 'undefined' && mdbk_admin_obj.day_labels) || {};
        const offLabel = (typeof mdbk_admin_obj !== 'undefined' && mdbk_admin_obj.off_label) || 'Off';
        let scheduleRows = '';
        days.forEach((day) => {
            const d = schedule[day];
            const working = d && d.active;
            const hours = working
                ? (escHtml(mdbkFormatTimeDisplay(d.from)) || '—') + ' – ' + (escHtml(mdbkFormatTimeDisplay(d.to)) || '—')
                : '<span class="mdbk-view-day-off">' + escHtml(offLabel) + '</span>';
            scheduleRows += '<div class="mdbk-view-day-row' + (working ? '' : ' is-off') + '"><span class="mdbk-view-day-name">' + escHtml(dayLabels[day] || day) + '</span><span class="mdbk-view-day-hours">' + hours + '</span></div>';
        });

        function formatDateStr(dateStr) {
            const parts = dateStr.split('-').map(Number);
            const d = new Date(parts[0], parts[1] - 1, parts[2]);
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
        }
        let extraDates = [];
        let offDates = [];
        try { extraDates = JSON.parse(card.dataset.extraDates) || []; } catch (e) {}
        try { offDates = JSON.parse(card.dataset.offDates) || []; } catch (e) {}
        // Only shown when this doctor actually has an override — most
        // doctors won't, and an empty "Monthly Availability" section would
        // just be noise.
        let monthlyHtml = '';
        if (extraDates.length || offDates.length) {
            monthlyHtml = '<details class="mdbk-availability-section"><summary class="mdbk-availability-header">' + CALENDAR_MONTH_ICON + '<h4>Monthly Availability</h4><span class="mdbk-availability-chevron"></span></summary>' +
                '<div class="mdbk-view-schedule-list">' +
                    (extraDates.length ? '<div class="mdbk-view-day-row"><span class="mdbk-view-day-name">Extra Working Dates</span><span class="mdbk-view-day-hours">' + escHtml(extraDates.slice().sort().map(formatDateStr).join(', ')) + '</span></div>' : '') +
                    (offDates.length ? '<div class="mdbk-view-day-row"><span class="mdbk-view-day-name">Off Dates</span><span class="mdbk-view-day-hours">' + escHtml(offDates.slice().sort().map(formatDateStr).join(', ')) + '</span></div>' : '') +
                '</div>' +
            '</details>';
        }

        body.innerHTML =
            '<div class="mdbk-view-top-row">' +
                '<div class="mdbk-view-hero">' +
                    '<div class="mdbk-view-avatar">' + avatarHtml + '</div>' +
                    '<div class="mdbk-view-hero-info"><h3>' + escHtml(card.dataset.name) + '</h3>' + specialtyHtml + '</div>' +
                '</div>' +
                '<div class="mdbk-view-col">' +
                    '<div class="mdbk-view-field"><label>Email</label><span>' + escHtml(card.dataset.email || '—') + '</span></div>' +
                    '<div class="mdbk-view-field"><label>Phone</label><span>' + escHtml(card.dataset.phone || '—') + '</span></div>' +
                    '<div class="mdbk-view-field"><label>Slot Duration</label><span>' + escHtml(card.dataset.slotDuration || 20) + ' min</span></div>' +
                '</div>' +
            '</div>' +
            '<div class="mdbk-view-field mdbk-view-field-full"><label>Bio</label><span>' + escHtml(card.dataset.bio || '—') + '</span></div>' +
            '<details class="mdbk-availability-section" open><summary class="mdbk-availability-header">' + CALENDAR_ICON + '<h4>Weekly Availability</h4><span class="mdbk-availability-chevron"></span></summary>' +
                '<div class="mdbk-view-schedule-list">' +
                    '<div class="mdbk-view-day-row mdbk-view-day-header"><span>Day</span><span>Hours</span></div>' +
                    scheduleRows +
                '</div>' +
            '</details>' +
            monthlyHtml;
    });

    initModal('mdbk-patient-modal', '.mdbk-add-patient, .mdbk-edit-patient', 'mdbk-patient-form', 'mdbk-edit-patient', (id, btn) => {
        document.getElementById('mdbk-patient-id').value = id;
        const title = document.getElementById('mdbk-patient-modal-title');
        if (title) title.textContent = 'Edit Patient';
        const row = btn.closest('tr, .mdbk-patient-row');
        if (row) {
            document.getElementById('mdbk-patient-name').value = row.dataset.name;
            document.getElementById('mdbk-patient-phone').value = row.dataset.phone;
            document.getElementById('mdbk-patient-email').value = row.dataset.email;
            document.getElementById('mdbk-patient-address').value = row.dataset.address;
            var patientAge = document.getElementById('mdbk-patient-age');
            if (patientAge) patientAge.value = row.dataset.age || '';
            if (patientGenderSelect && row.dataset.gender) {
                const opt = patientGenderSelect.panel.querySelector('.mdbk-custom-select-option[data-value="' + row.dataset.gender + '"]');
                if (opt) patientGenderSelect.setValue(opt.dataset.value, opt.textContent);
            }
        }
    });

    document.querySelectorAll('.mdbk-add-patient').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const title = document.getElementById('mdbk-patient-modal-title');
            if (title) title.textContent = 'Add Patient';
            if (patientGenderSelect) {
                const firstOpt = patientGenderSelect.panel.querySelector('.mdbk-custom-select-option');
                if (firstOpt) patientGenderSelect.setValue(firstOpt.dataset.value, firstOpt.textContent);
            }
        });
    });
    const patientModalCancel = document.querySelector('#mdbk-patient-modal .mdbk-modal-cancel');
    if (patientModalCancel) {
        patientModalCancel.addEventListener('click', function() {
            document.getElementById('mdbk-patient-modal').style.display = 'none';
        });
    }

    // Staff (front-desk) accounts — a WP user, not a post, but the same
    // Add/Edit modal pattern as Patient/Doctor above.
    initModal('mdbk-staff-modal', '.mdbk-add-staff, .mdbk-edit-staff', 'mdbk-staff-form', 'mdbk-edit-staff', (id, btn) => {
        document.getElementById('mdbk-staff-id').value = id;
        const title = document.getElementById('mdbk-staff-modal-title');
        if (title) title.textContent = 'Edit Staff';
        const row = btn.closest('.mdbk-staff-row');
        if (row) {
            document.getElementById('mdbk-staff-name').value = row.dataset.name;
            document.getElementById('mdbk-staff-email').value = row.dataset.email;
            document.getElementById('mdbk-staff-phone').value = row.dataset.phone;
            if (staffRoleSelect && row.dataset.role) {
                const opt = staffRoleSelect.panel.querySelector('.mdbk-custom-select-option[data-value="' + row.dataset.role + '"]');
                if (opt) staffRoleSelect.setValue(opt.dataset.value, opt.textContent);
            }
        }
    });
    document.querySelectorAll('.mdbk-add-staff').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const title = document.getElementById('mdbk-staff-modal-title');
            if (title) title.textContent = 'Add Staff';
            if (staffRoleSelect) {
                const firstOpt = staffRoleSelect.panel.querySelector('.mdbk-custom-select-option');
                if (firstOpt) staffRoleSelect.setValue(firstOpt.dataset.value, firstOpt.textContent);
            }
        });
    });
    const staffModalCancel = document.querySelector('#mdbk-staff-modal .mdbk-modal-cancel');
    if (staffModalCancel) {
        staffModalCancel.addEventListener('click', function() {
            document.getElementById('mdbk-staff-modal').style.display = 'none';
        });
    }

    // A slot-disabled doctor is booked serially (queue number auto-assigned
    // by the server) — the Slot Time field means nothing for them, so it's
    // dimmed/disabled and a hint takes its place, mirroring the Doctor
    // modal's own Slot Duration field.
    function updateAppSlotTimeAvailability(selectedOpt) {
        const slotInput = document.getElementById('mdbk-app-slot-time');
        const hint = document.getElementById('mdbk-app-slot-hint');
        if (!slotInput) return;
        const slotEnabled = !selectedOpt || selectedOpt.dataset.slotEnabled !== 'no';
        slotInput.disabled = !slotEnabled;
        if (!slotEnabled) slotInput.value = '';
        if (hint) hint.style.display = slotEnabled ? 'none' : '';
    }
    const appDoctorSelect = initCustomSelect('mdbk-app-doctor-select', updateAppSlotTimeAvailability);
    const appStatusSelect = initCustomSelect('mdbk-app-status-select');
    const appSpecSelect = initCustomSelect('mdbk-app-spec-select');
    const appGenderSelect = initCustomSelect('mdbk-app-gender-select');

    function filterDoctorsBySpecialty(specId) {
        if (!appDoctorSelect) return;
        let firstVisible = null;
        appDoctorSelect.panel.querySelectorAll('.mdbk-custom-select-option').forEach(function(opt) {
            const match = !specId || opt.dataset.specialty === specId;
            opt.style.display = match ? '' : 'none';
            if (match && !firstVisible) firstVisible = opt;
        });
        // Reset to first visible doctor
        if (firstVisible) {
            appDoctorSelect.setValue(firstVisible.dataset.value, firstVisible.textContent);
        } else {
            appDoctorSelect.setValue('', '');
        }
    }

    if (appSpecSelect) {
        appSpecSelect.panel.addEventListener('click', function(e) {
            const opt = e.target.closest('.mdbk-custom-select-option');
            if (opt) filterDoctorsBySpecialty(opt.dataset.value);
        });
    }

    initModal('mdbk-appointment-modal', '.mdbk-add-appointment, .mdbk-edit-appointment', 'mdbk-appointment-form', 'mdbk-edit-appointment', (id, btn) => {
        document.getElementById('mdbk-app-id').value = id;
        const title = document.getElementById('mdbk-appointment-modal-title');
        if (title) title.textContent = 'Edit Booking';
        const row = btn.closest('tr, .mdbk-patient-row');
        if (row) {
            document.getElementById('mdbk-app-patient').value = row.dataset.patient;
            document.getElementById('mdbk-app-phone').value = row.dataset.phone;
            document.getElementById('mdbk-app-email').value = row.dataset.email || '';
            document.getElementById('mdbk-app-age').value = row.dataset.age || '';
            if (appGenderSelect && row.dataset.gender) {
                const genderOpt = appGenderSelect.panel.querySelector('.mdbk-custom-select-option[data-value="' + row.dataset.gender + '"]');
                if (genderOpt) appGenderSelect.setValue(genderOpt.dataset.value, genderOpt.textContent);
            }
            // Set specialty first, then doctor
            if (appSpecSelect && row.dataset.specialty) {
                const specOpt = appSpecSelect.panel.querySelector('.mdbk-custom-select-option[data-value="' + row.dataset.specialty + '"]');
                if (specOpt) {
                    appSpecSelect.setValue(specOpt.dataset.value, specOpt.textContent);
                    filterDoctorsBySpecialty(row.dataset.specialty);
                }
            }
            if (appDoctorSelect && row.dataset.doctor) {
                const opt = appDoctorSelect.panel.querySelector('.mdbk-custom-select-option[data-value="' + row.dataset.doctor + '"]');
                if (opt) appDoctorSelect.setValue(opt.dataset.value, opt.textContent);
            }
            document.getElementById('mdbk-app-date').value = row.dataset.date;
            // updateAppSlotTimeAvailability() already ran (via the doctor
            // setValue() above) and disabled this field if the doctor is
            // slot-disabled — don't restore a value into a field that's
            // about to be dropped from the submit anyway.
            const appSlotInput = document.getElementById('mdbk-app-slot-time');
            if (!appSlotInput.disabled) appSlotInput.value = row.dataset.slotTime || '';
            if (appStatusSelect && row.dataset.status) {
                const opt = appStatusSelect.panel.querySelector('.mdbk-custom-select-option[data-value="' + row.dataset.status + '"]');
                if (opt) appStatusSelect.setValue(opt.dataset.value, opt.textContent);
            }
        }
    });

    document.querySelectorAll('.mdbk-add-appointment').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const title = document.getElementById('mdbk-appointment-modal-title');
            if (title) title.textContent = 'Add Booking';
            // Reset specialty to All Specialties
            if (appSpecSelect) {
                const allOpt = appSpecSelect.panel.querySelector('.mdbk-custom-select-option[data-value=""]');
                if (allOpt) {
                    appSpecSelect.setValue(allOpt.dataset.value, allOpt.textContent);
                    filterDoctorsBySpecialty('');
                }
            }
            if (appDoctorSelect) {
                const firstOpt = appDoctorSelect.panel.querySelector('.mdbk-custom-select-option:not([style*="display: none"])');
                if (firstOpt) appDoctorSelect.setValue(firstOpt.dataset.value, firstOpt.textContent);
            }
            // Reset gender back to its default (Male) — form.reset() alone
            // only restores the hidden <select>'s value, not the visible
            // custom-select trigger label, so without this an earlier edit
            // (e.g. a booking whose gender was "Female") would leave a stale
            // label showing on the next "+ New Booking".
            if (appGenderSelect) {
                const defaultOpt = appGenderSelect.panel.querySelector('.mdbk-custom-select-option[data-value="Male"]');
                if (defaultOpt) appGenderSelect.setValue(defaultOpt.dataset.value, defaultOpt.textContent);
            }
        });
    });

    const appointmentModalCancel = document.querySelector('#mdbk-appointment-modal .mdbk-modal-cancel');
    if (appointmentModalCancel) {
        appointmentModalCancel.addEventListener('click', function() {
            document.getElementById('mdbk-appointment-modal').style.display = 'none';
        });
    }

    // New Booking modal's phone-number live search — debounced lookup of
    // existing mdbk_patient records by (partial) phone match, so staff
    // don't have to re-type a patient's details or accidentally create a
    // duplicate record find_or_create_patient() would have matched anyway
    // on submit. ajax_search_patient_phone() returns pre-escaped HTML
    // (data-* attributes carry the field values), so this never builds
    // HTML from raw strings itself. Only runs for a NEW booking (#mdbk-app-id
    // empty) — editing an existing appointment already has its own linked
    // patient, so searching there would just be noise.
    (function() {
        const phoneInput = document.getElementById('mdbk-app-phone');
        const suggestBox = document.getElementById('mdbk-app-phone-suggest');
        if (!phoneInput || !suggestBox || typeof mdbk_admin_obj === 'undefined') return;
        const appIdInput = document.getElementById('mdbk-app-id');
        const nameInput = document.getElementById('mdbk-app-patient');
        const emailInput = document.getElementById('mdbk-app-email');
        const ageInput = document.getElementById('mdbk-app-age');
        let debounceTimer;
        let requestToken = 0;

        function hideSuggestions() {
            suggestBox.style.display = 'none';
            suggestBox.innerHTML = '';
        }

        function runSearch() {
            const phone = phoneInput.value.trim();
            if ((appIdInput && appIdInput.value) || phone.length < 3) { hideSuggestions(); return; }
            const token = ++requestToken;
            const body = new URLSearchParams();
            body.set('action', 'mdbk_search_patient_phone');
            body.set('nonce', mdbk_admin_obj.nonce);
            body.set('phone', phone);
            fetch(mdbk_admin_obj.ajax_url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                .then((r) => r.json())
                .then((res) => {
                    if (token !== requestToken) return; // a newer keystroke's response already landed
                    if (!res || !res.success || !res.data.results_html) { hideSuggestions(); return; }
                    suggestBox.innerHTML = res.data.results_html;
                    suggestBox.style.display = 'block';
                })
                .catch(() => {});
        }

        phoneInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(runSearch, 300);
        });

        suggestBox.addEventListener('click', function(e) {
            const item = e.target.closest('.mdbk-patient-suggest-item');
            if (!item) return;
            if (nameInput) nameInput.value = item.dataset.name || '';
            phoneInput.value = item.dataset.phone || '';
            if (emailInput) emailInput.value = item.dataset.email || '';
            if (ageInput) ageInput.value = item.dataset.age || '';
            if (appGenderSelect && item.dataset.gender) {
                const genderOpt = appGenderSelect.panel.querySelector('.mdbk-custom-select-option[data-value="' + item.dataset.gender + '"]');
                if (genderOpt) appGenderSelect.setValue(genderOpt.dataset.value, genderOpt.textContent);
            }
            hideSuggestions();
        });

        document.addEventListener('click', function(e) {
            if (e.target !== phoneInput && !suggestBox.contains(e.target)) hideSuggestions();
        });
        // Clears any dropdown left open from a previous visit to this modal
        // before it opens again (form.reset() itself doesn't touch this,
        // since it isn't a form field).
        document.addEventListener('click', function(e) {
            if (e.target.closest('.mdbk-add-appointment, .mdbk-edit-appointment')) hideSuggestions();
        });
    })();

    // Per-doctor card "View All" — scoped to one doctor's popup
    // (one modal per doctor card is pre-rendered server-side; this just
    // opens/closes whichever one a given link points at). On the Bookings
    // page this link sits inside a <summary> (the collapsible card
    // header), so stopPropagation keeps a click here from also toggling
    // that card open/closed. Delegated on document (not queried once at
    // load) because the Booking page's live search (#mdbk-schedule-results)
    // can replace this whole markup, including these modals, after load.
    document.addEventListener('click', function(e) {
        const link = e.target.closest('[data-doctor-modal]');
        if (link) {
            e.preventDefault();
            e.stopPropagation();
            const modal = document.getElementById(link.dataset.doctorModal);
            if (modal) modal.style.display = 'flex';
            return;
        }
        if (e.target.closest('.mdbk-doctor-popup .mdbk-modal-close')) {
            const modal = e.target.closest('.mdbk-doctor-popup');
            if (modal) modal.style.display = 'none';
            return;
        }
        if (e.target.classList.contains('mdbk-doctor-popup')) {
            e.target.style.display = 'none';
        }
    });

    // Shared by the modal print button below and the doctor-group Print/
    // Download-Image buttons on the Booking page — one clean, dependency-
    // free table style used everywhere a patient list gets exported as a
    // standalone document/image, rather than trying to drag the admin
    // screen's own CSS along.
    const MDBK_PRINT_STYLES = 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;padding:24px;color:#1e293b;background:#fff;margin:0;text-align:center;}' +
        '.mdbk-print-logo{max-width:64px;max-height:64px;margin:0 auto 8px;display:block;}' +
        '.mdbk-print-clinic-name{margin:0 0 3px;font-size:15px;font-weight:700;}' +
        '.mdbk-print-clinic-meta{margin:0 0 3px;font-size:11px;color:#64748b;}' +
        'h2{margin:14px 0 16px;font-size:18px;text-align:left;}' +
        'table{width:100%;border-collapse:collapse;font-size:13px;text-align:left;}' +
        'th,td{padding:8px 10px;border-bottom:1px solid #94a3b8;text-align:left;}' +
        'th{background:#f8fafc;text-transform:uppercase;font-size:11px;color:#64748b;}';
    // Logo/name/contact/address from Global Settings — shown above every
    // print/download-image output on this page so a printed patient list
    // is identifiable as belonging to this clinic. Empty fields just
    // don't render their line.
    function mdbkBuildClinicHeaderHtml() {
        const obj = typeof mdbk_admin_obj !== 'undefined' ? mdbk_admin_obj : {};
        let html = '';
        if (obj.clinic_logo) html += '<img class="mdbk-print-logo" src="' + obj.clinic_logo + '" alt="">';
        if (obj.clinic_name) html += '<p class="mdbk-print-clinic-name">' + obj.clinic_name + '</p>';
        if (obj.clinic_contact) html += '<p class="mdbk-print-clinic-meta">' + obj.clinic_contact + '</p>';
        if (obj.clinic_address) html += '<p class="mdbk-print-clinic-meta">' + obj.clinic_address + '</p>';
        return html;
    }
    function mdbkBuildPrintBody(titleText, bodyHtml) {
        return mdbkBuildClinicHeaderHtml() + '<h2>' + titleText + '</h2>' + bodyHtml;
    }

    // Print just this modal's table — window.print() on the main page would
    // try to print the whole admin screen behind the overlay, so this opens
    // a small standalone print window with only the modal's own title +
    // table markup instead. Delegated (see the doctor-popup handler above)
    // since these modals can be re-rendered by the Booking page's live search.
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.mdbk-print-modal');
        if (!btn) return;
        const modal = btn.closest('.mdbk-modal');
        if (!modal) return;
        const title = modal.querySelector('.mdbk-modal-head h2');
        const body = modal.querySelector('.mdbk-modal-body');
        if (!body) return;
        const win = window.open('', '_blank', 'width=900,height=700');
        if (!win) return;
        win.document.write(
            '<html><head><title>' + (title ? title.textContent : 'Print') + '</title><style>' + MDBK_PRINT_STYLES + '</style></head><body>' +
            mdbkBuildPrintBody(title ? title.textContent : '', body.innerHTML) +
            '</body></html>'
        );
        win.document.close();
        win.focus();
        win.print();
    });

    // Per-doctor Print/Download Image on the Booking page's collapsible
    // group headers — both read from the group's own hidden
    // .mdbk-doctor-group-print-table (a plain <table>, server-rendered by
    // render_today_queue_table()) rather than the visible
    // .mdbk-doctor-group-list, since that's flex/grid patient-row markup
    // this standalone page/canvas has no matching CSS for.
    document.querySelectorAll('.mdbk-print-group').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const group = btn.closest('.mdbk-doctor-group');
            if (!group) return;
            const name = group.querySelector('.mdbk-doctor-group-name');
            const table = group.querySelector('.mdbk-doctor-group-print-table');
            if (!table) return;
            const titleText = name ? name.textContent : 'Print';
            const win = window.open('', '_blank', 'width=900,height=700');
            if (!win) return;
            win.document.write(
                '<html><head><title>' + titleText + '</title><style>' + MDBK_PRINT_STYLES + '</style></head><body>' +
                mdbkBuildPrintBody(titleText, table.innerHTML) +
                '</body></html>'
            );
            win.document.close();
            win.focus();
            win.print();
        });
    });

    // Renders the same title+table markup onto an off-screen element, then
    // rasterizes it (via an SVG <foreignObject>, the one dependency-free
    // way to turn arbitrary HTML into a bitmap) into a downloadable PNG.
    // No charting/screenshot library is vendored in this plugin, so this
    // stays plain browser APIs rather than pulling one in for a single button.
    function mdbkDownloadHtmlAsImage(titleText, bodyHtml, filename) {
        const width = 640;
        const innerHtml = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;padding:24px;color:#1e293b;background:#fff;box-sizing:border-box;width:' + width + 'px;"><style>' + MDBK_PRINT_STYLES + '</style>' + mdbkBuildPrintBody(titleText, bodyHtml) + '</div>';
        const container = document.createElement('div');
        container.style.cssText = 'position:fixed;left:-9999px;top:0;width:' + width + 'px;';
        container.innerHTML = innerHtml;
        document.body.appendChild(container);
        requestAnimationFrame(function() {
            const height = Math.max(container.scrollHeight, 60);
            document.body.removeChild(container);
            const svgMarkup = '<svg xmlns="http://www.w3.org/2000/svg" width="' + width + '" height="' + height + '">' +
                '<foreignObject width="100%" height="100%"><div xmlns="http://www.w3.org/1999/xhtml">' + innerHtml + '</div></foreignObject>' +
                '</svg>';
            const svgUrl = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svgMarkup);
            const img = new Image();
            img.onload = function() {
                const scale = 2;
                const canvas = document.createElement('canvas');
                canvas.width = width * scale;
                canvas.height = height * scale;
                const ctx = canvas.getContext('2d');
                ctx.scale(scale, scale);
                ctx.fillStyle = '#fff';
                ctx.fillRect(0, 0, width, height);
                ctx.drawImage(img, 0, 0, width, height);
                canvas.toBlob(function(blob) {
                    if (!blob) { alert('Could not generate image. Try Print instead.'); return; }
                    const link = document.createElement('a');
                    link.download = filename;
                    link.href = URL.createObjectURL(blob);
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                });
            };
            img.onerror = function() {
                alert('Could not generate image. Try Print instead.');
            };
            img.src = svgUrl;
        });
    }
    document.querySelectorAll('.mdbk-download-group-image').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const group = btn.closest('.mdbk-doctor-group');
            if (!group) return;
            const name = group.querySelector('.mdbk-doctor-group-name');
            const table = group.querySelector('.mdbk-doctor-group-print-table');
            if (!table) return;
            const titleText = name ? name.textContent : 'Patients';
            mdbkDownloadHtmlAsImage(titleText, table.innerHTML, titleText.replace(/[^a-z0-9]+/gi, '-').toLowerCase() + '-patients.png');
        });
    });

    function setSpecialtyIconPreview(url) {
        const preview = document.getElementById('mdbk-spec-icon-preview');
        const removeBtn = document.getElementById('mdbk-spec-icon-remove');
        if (!preview) return;
        preview.innerHTML = url ? '<img src="' + url + '" alt="">' : '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41L13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg>';
        if (removeBtn) removeBtn.style.display = url ? '' : 'none';
    }

    initModal('mdbk-specialty-modal', '.mdbk-add-specialty, .mdbk-edit-specialty', 'mdbk-specialty-form', 'mdbk-edit-specialty', (id, btn) => {
        document.getElementById('mdbk-spec-id').value = id;
        const card = btn.closest('.mdbk-specialty-card');
        document.getElementById('mdbk-specialty-modal-title').textContent = 'Edit Specialty';
        if (card) {
            document.getElementById('mdbk-spec-name').value = card.dataset.name;
            document.getElementById('mdbk-spec-icon-id').value = card.dataset.iconId || 0;
            setSpecialtyIconPreview(card.dataset.iconUrl || '');
            document.getElementById('mdbk-spec-status').checked = !card.classList.contains('is-inactive');
        }
    });
    document.querySelectorAll('.mdbk-add-specialty').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.getElementById('mdbk-specialty-modal-title').textContent = 'Add Specialty';
            document.getElementById('mdbk-spec-icon-id').value = 0;
            setSpecialtyIconPreview('');
            document.getElementById('mdbk-spec-status').checked = true;
        });
    });

    let specialtyIconFrame;
    const specialtyIconUpload = document.getElementById('mdbk-spec-icon-upload');
    if (specialtyIconUpload) {
        specialtyIconUpload.addEventListener('click', function(e) {
            e.preventDefault();
            if (typeof wp === 'undefined' || !wp.media) return;
            if (specialtyIconFrame) { specialtyIconFrame.open(); return; }
            specialtyIconFrame = wp.media({
                title: 'Select Specialty Icon',
                button: { text: 'Use this image' },
                multiple: false,
                library: { type: 'image' }
            });
            specialtyIconFrame.on('select', function() {
                const attachment = specialtyIconFrame.state().get('selection').first().toJSON();
                const url = (attachment.sizes && attachment.sizes.thumbnail) ? attachment.sizes.thumbnail.url : attachment.url;
                document.getElementById('mdbk-spec-icon-id').value = attachment.id;
                setSpecialtyIconPreview(url);
            });
            specialtyIconFrame.open();
        });
    }
    const specialtyIconRemove = document.getElementById('mdbk-spec-icon-remove');
    if (specialtyIconRemove) {
        specialtyIconRemove.addEventListener('click', function() {
            document.getElementById('mdbk-spec-icon-id').value = 0;
            setSpecialtyIconPreview('');
        });
    }

    function setClinicLogoPreview(url) {
        const preview = document.getElementById('mdbk-clinic-logo-preview');
        const removeBtn = document.getElementById('mdbk-clinic-logo-remove');
        if (!preview) return;
        preview.innerHTML = url ? '<img src="' + url + '" alt="">' : '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41L13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg>';
        if (removeBtn) removeBtn.style.display = url ? '' : 'none';
    }
    let clinicLogoFrame;
    const clinicLogoUpload = document.getElementById('mdbk-clinic-logo-upload');
    if (clinicLogoUpload) {
        clinicLogoUpload.addEventListener('click', function(e) {
            e.preventDefault();
            if (typeof wp === 'undefined' || !wp.media) return;
            if (clinicLogoFrame) { clinicLogoFrame.open(); return; }
            clinicLogoFrame = wp.media({
                title: 'Select Clinic Logo',
                button: { text: 'Use this image' },
                multiple: false,
                library: { type: 'image' }
            });
            clinicLogoFrame.on('select', function() {
                const attachment = clinicLogoFrame.state().get('selection').first().toJSON();
                const url = (attachment.sizes && attachment.sizes.thumbnail) ? attachment.sizes.thumbnail.url : attachment.url;
                document.getElementById('mdbk-clinic-logo-id').value = attachment.id;
                setClinicLogoPreview(url);
            });
            clinicLogoFrame.open();
        });
    }
    const clinicLogoRemove = document.getElementById('mdbk-clinic-logo-remove');
    if (clinicLogoRemove) {
        clinicLogoRemove.addEventListener('click', function() {
            document.getElementById('mdbk-clinic-logo-id').value = 0;
            setClinicLogoPreview('');
        });
    }

    // Active/Inactive toggle on each specialty card footer — same
    // optimistic-update-then-revert-on-failure pattern as the doctor
    // grid's own active toggle.
    document.addEventListener('change', (e) => {
        const input = e.target.closest('.mdbk-specialty-toggle');
        if (!input || typeof mdbk_admin_obj === 'undefined') return;
        const card = input.closest('.mdbk-specialty-card');
        const termId = card.dataset.id;
        const wasChecked = !input.checked;
        const body = new URLSearchParams();
        body.set('action', 'mdbk_toggle_specialty_active');
        body.set('nonce', mdbk_admin_obj.nonce);
        body.set('term_id', termId);
        fetch(mdbk_admin_obj.ajax_url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
            .then((r) => r.json())
            .then((res) => {
                if (res && res.success) {
                    card.classList.toggle('is-inactive', !res.data.active);
                } else {
                    input.checked = wasChecked;
                    alert((res && res.data && res.data.message) || 'Something went wrong, please try again.');
                }
            })
            .catch(() => {
                input.checked = wasChecked;
                alert('Something went wrong, please try again.');
            });
    });

    // Per-doctor Live Queue on/off toggle on the Today's Queue page's group
    // header — same optimistic-update-then-revert-on-failure pattern as the
    // specialty card's Active toggle above. The toggle sits inside a
    // <summary> element (doctor group is a <details>), so its own onclick
    // already stops propagation to avoid opening/closing the group.
    document.addEventListener('change', (e) => {
        const input = e.target.closest('.mdbk-doctor-live-queue-checkbox');
        if (!input || typeof mdbk_admin_obj === 'undefined') return;
        const doctorId = input.dataset.doctorId;
        const wasChecked = !input.checked;
        const body = new URLSearchParams();
        body.set('action', 'mdbk_toggle_doctor_live_queue');
        body.set('nonce', mdbk_admin_obj.nonce);
        body.set('doctor_id', doctorId);
        fetch(mdbk_admin_obj.ajax_url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
            .then((r) => r.json())
            .then((res) => {
                if (!res || !res.success) {
                    input.checked = wasChecked;
                    alert((res && res.data && res.data.message) || 'Something went wrong, please try again.');
                }
            })
            .catch(() => {
                input.checked = wasChecked;
                alert('Something went wrong, please try again.');
            });
    });

    // "Mark as Visited" on the Booking page's "Today" view —
    // delegated on document since the button is inside a fragment that
    // gets replaced wholesale on success. Swaps the WHOLE
    // #mdbk-today-queue-list, not just the clicked row — same one
    // consistent refresh path every action on this page uses (see
    // ajax_mark_visited()'s comment in admin-dashboard.php).
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.mdbk-mark-visited');
        if (!btn || typeof mdbk_admin_obj === 'undefined') return;
        const list = document.getElementById('mdbk-today-queue-list');
        const appointmentId = btn.dataset.id;
        btn.disabled = true;
        const body = new URLSearchParams();
        body.set('action', 'mdbk_mark_visited');
        body.set('nonce', mdbk_admin_obj.nonce);
        body.set('appointment_id', appointmentId);
        // Tells the server which list this page is showing (one doctor,
        // or every doctor combined on front-desk staff's view) — see
        // data-view-doctor-id on #mdbk-today-queue-list
        // (render_schedule_today_view()) and resolve_queue_view_scope()
        // (admin-dashboard.php) for why the response needs to know this.
        if (list && list.dataset.viewDoctorId !== undefined) {
            body.set('view_doctor_id', list.dataset.viewDoctorId);
        }
        fetch(mdbk_admin_obj.ajax_url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
            .then((r) => r.json())
            .then((res) => {
                if (res && res.success && list) {
                    list.innerHTML = res.data.fragment;
                } else {
                    btn.disabled = false;
                    alert((res && res.data && res.data.message) || 'Something went wrong, please try again.');
                }
            })
            .catch(() => {
                btn.disabled = false;
                alert('Something went wrong, please try again.');
            });
    });

    // "Check In" — on the Bookings page (mdbk-schedule) AND now on the
    // "Patients" page's Today's Queue too. Delegated on document since the
    // button is inside a fragment that gets replaced wholesale on
    // success, same pattern as "Mark as Visited" above. The two contexts
    // need different swaps: the Patients page's #mdbk-today-queue-list
    // needs the WHOLE list replaced (check-in can auto-promote a
    // different row to "serving" too — see ajax_admin_checkin()'s
    // comment), while the Bookings page swaps just the clicked row.
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.mdbk-admin-checkin-btn');
        if (!btn || typeof mdbk_admin_obj === 'undefined') return;
        const list = btn.closest('#mdbk-today-queue-list');
        const row = btn.closest('.mdbk-patient-row');
        const appointmentId = btn.dataset.id;
        btn.disabled = true;
        const body = new URLSearchParams();
        body.set('action', 'mdbk_admin_checkin');
        body.set('nonce', mdbk_admin_obj.nonce);
        body.set('appointment_id', appointmentId);
        if (list) {
            body.set('view_doctor_id', list.dataset.viewDoctorId);
        } else {
            body.set('show_doctor', row && row.classList.contains('mdbk-patient-row-has-doctor') ? '1' : '0');
        }
        fetch(mdbk_admin_obj.ajax_url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
            .then((r) => r.json())
            .then((res) => {
                if (res && res.success && res.data.mode === 'list' && list) {
                    list.innerHTML = res.data.fragment;
                } else if (res && res.success && row) {
                    const tmp = document.createElement('div');
                    tmp.innerHTML = res.data.fragment;
                    row.replaceWith(tmp.firstElementChild);
                } else {
                    btn.disabled = false;
                    alert((res && res.data && res.data.message) || 'Something went wrong, please try again.');
                }
            })
            .catch(() => {
                btn.disabled = false;
                alert('Something went wrong, please try again.');
            });
    });

    // "Skip"/"Recall" toggle on the Booking page's "Today" view —
    // same delegated-on-document pattern as "Mark as Visited" above, same
    // whole-list swap (see that handler's comment for why).
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.mdbk-toggle-skip');
        if (!btn || typeof mdbk_admin_obj === 'undefined') return;
        const list = document.getElementById('mdbk-today-queue-list');
        const appointmentId = btn.dataset.id;
        btn.disabled = true;
        const body = new URLSearchParams();
        body.set('action', 'mdbk_toggle_skip');
        body.set('nonce', mdbk_admin_obj.nonce);
        body.set('appointment_id', appointmentId);
        if (list && list.dataset.viewDoctorId !== undefined) {
            body.set('view_doctor_id', list.dataset.viewDoctorId);
        }
        fetch(mdbk_admin_obj.ajax_url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
            .then((r) => r.json())
            .then((res) => {
                if (res && res.success && list) {
                    list.innerHTML = res.data.fragment;
                } else {
                    btn.disabled = false;
                    alert((res && res.data && res.data.message) || 'Something went wrong, please try again.');
                }
            })
            .catch(() => {
                btn.disabled = false;
                alert('Something went wrong, please try again.');
            });
    });

    // "Start Visiting" — promotes a checked-in waiting patient to
    // "serving", same delegated/whole-list-swap pattern as "Skip"/"Mark
    // as Visited" above (see ajax_start_visiting()'s comment for why this
    // exists).
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.mdbk-start-visiting');
        if (!btn || typeof mdbk_admin_obj === 'undefined') return;
        const list = document.getElementById('mdbk-today-queue-list');
        const appointmentId = btn.dataset.id;
        btn.disabled = true;
        const body = new URLSearchParams();
        body.set('action', 'mdbk_start_visiting');
        body.set('nonce', mdbk_admin_obj.nonce);
        body.set('appointment_id', appointmentId);
        if (list && list.dataset.viewDoctorId !== undefined) {
            body.set('view_doctor_id', list.dataset.viewDoctorId);
        }
        fetch(mdbk_admin_obj.ajax_url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
            .then((r) => r.json())
            .then((res) => {
                if (res && res.success && list) {
                    list.innerHTML = res.data.fragment;
                } else {
                    btn.disabled = false;
                    alert((res && res.data && res.data.message) || 'Something went wrong, please try again.');
                }
            })
            .catch(() => {
                btn.disabled = false;
                alert('Something went wrong, please try again.');
            });
    });

    // "Expand All" / "Collapse All" — front-desk staff's all-doctors
    // "Patients" view groups rows under a <details> per doctor (see
    // render_patient_list_html() in admin-dashboard.php); these two
    // buttons toggle every group in the SAME card only (Today's Queue and
    // Patient List are independent sections, each with their own button
    // pair), not the whole page at once.
    document.addEventListener('click', (e) => {
        const expandBtn = e.target.closest('.mdbk-expand-all');
        const collapseBtn = e.target.closest('.mdbk-collapse-all');
        const btn = expandBtn || collapseBtn;
        if (!btn) return;
        const card = btn.closest('.mdbk-card');
        if (!card) return;
        card.querySelectorAll('.mdbk-doctor-group').forEach((group) => {
            group.open = !!expandBtn;
        });
    });

    // ---- Doctors grid: search, specialty filter, pagination, grid/list view ----
    const doctorGrid = document.getElementById('mdbk-admin-doctor-grid');
    if (doctorGrid) {
        const searchInput = document.getElementById('mdbk-doctor-search');
        const specialtyFilter = document.getElementById('mdbk-doctor-filter-specialty');
        const noMatch = document.getElementById('mdbk-doctor-no-match');
        const countBadge = document.getElementById('mdbk-doctor-count-badge');
        const pagination = document.getElementById('mdbk-doctor-pagination');
        const pageNumbers = document.getElementById('mdbk-doctor-page-numbers');
        const prevBtn = document.getElementById('mdbk-doctor-prev');
        const nextBtn = document.getElementById('mdbk-doctor-next');
        const PAGE_SIZE = 9;
        let currentPage = 1;

        function allCards() {
            return Array.from(doctorGrid.querySelectorAll('.mdbk-admin-doctor-card'));
        }

        function matchingCards() {
            const q = (searchInput.value || '').trim().toLowerCase();
            const specF = specialtyFilter.value;
            return allCards().filter((card) => {
                if (specF && card.dataset.specialty !== specF) return false;
                if (q) {
                    const hay = (card.dataset.name + ' ' + card.dataset.email + ' ' + card.dataset.phone).toLowerCase();
                    if (hay.indexOf(q) === -1) return false;
                }
                return true;
            });
        }

        function refreshGrid() {
            const cards = allCards();
            const matches = matchingCards();
            const totalPages = Math.max(1, Math.ceil(matches.length / PAGE_SIZE));
            if (currentPage > totalPages) currentPage = totalPages;

            cards.forEach((c) => c.classList.add('is-hidden'));
            const start = (currentPage - 1) * PAGE_SIZE;
            const pageMatches = matches.slice(start, start + PAGE_SIZE);
            pageMatches.forEach((c) => c.classList.remove('is-hidden'));

            if (noMatch) noMatch.style.display = (cards.length > 0 && matches.length === 0) ? '' : 'none';
            if (pagination) pagination.style.display = matches.length > PAGE_SIZE ? 'flex' : 'none';
            if (prevBtn) prevBtn.disabled = currentPage <= 1;
            if (nextBtn) nextBtn.disabled = currentPage >= totalPages;
            if (countBadge) countBadge.textContent = 'Showing ' + pageMatches.length + ' Doctors of ' + cards.length + ' Total';

            if (pageNumbers) {
                pageNumbers.innerHTML = '';
                for (let p = 1; p <= totalPages; p++) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'mdbk-page-btn' + (p === currentPage ? ' is-active' : '');
                    btn.dataset.page = p;
                    btn.textContent = p;
                    pageNumbers.appendChild(btn);
                }
            }
        }

        if (searchInput) searchInput.addEventListener('input', () => { currentPage = 1; refreshGrid(); });
        if (specialtyFilter) specialtyFilter.addEventListener('change', () => { currentPage = 1; refreshGrid(); });
        if (prevBtn) prevBtn.addEventListener('click', () => { if (currentPage > 1) { currentPage--; refreshGrid(); } });
        if (nextBtn) nextBtn.addEventListener('click', () => { currentPage++; refreshGrid(); });
        if (pageNumbers) pageNumbers.addEventListener('click', (e) => {
            const btn = e.target.closest('.mdbk-page-btn');
            if (!btn) return;
            currentPage = parseInt(btn.dataset.page, 10) || 1;
            refreshGrid();
        });

        // Grid/list view toggle, persisted like the reference plugin's own view switch.
        function applyView(view) {
            doctorGrid.classList.toggle('is-list', view === 'list');
            document.querySelectorAll('.mdbk-view-btn').forEach((b) => b.classList.toggle('is-active', b.dataset.view === view));
        }
        applyView(localStorage.getItem('mdbk_doctor_view') || 'grid');
        document.querySelectorAll('.mdbk-view-btn').forEach((b) => {
            b.addEventListener('click', () => {
                localStorage.setItem('mdbk_doctor_view', b.dataset.view);
                applyView(b.dataset.view);
            });
        });

        // Active/Inactive toggle on each card footer.
        document.addEventListener('change', (e) => {
            const input = e.target.closest('.mdbk-admin-doctor-active-toggle input');
            if (!input || typeof mdbk_admin_obj === 'undefined') return;
            const card = input.closest('.mdbk-admin-doctor-card');
            const doctorId = card.dataset.id;
            const textEl = card.querySelector('.mdbk-admin-doctor-active-text');
            const wasChecked = !input.checked;
            const body = new URLSearchParams();
            body.set('action', 'mdbk_toggle_doctor_active');
            body.set('nonce', mdbk_admin_obj.nonce);
            body.set('doctor_id', doctorId);
            fetch(mdbk_admin_obj.ajax_url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                .then((r) => r.json())
                .then((res) => {
                    if (res && res.success) {
                        card.classList.toggle('is-inactive', !res.data.active);
                        if (textEl) textEl.textContent = res.data.active ? 'Active' : 'Inactive';
                    } else {
                        input.checked = wasChecked;
                        alert((res && res.data && res.data.message) || 'Something went wrong, please try again.');
                    }
                })
                .catch(() => {
                    input.checked = wasChecked;
                    alert('Something went wrong, please try again.');
                });
        });

        refreshGrid();
    }
});
