document.addEventListener('DOMContentLoaded', function() {
    var dateValue = document.getElementById('mdbk-date-value');

    /**
     * "09:00" -> "9:00 AM" for display only — the underlying 24-hour value
     * (submitted, sorted, and compared everywhere else in the booking flow)
     * is left untouched.
     */
    function formatTime12h(time24) {
        var parts = time24.split(':');
        var hour = parseInt(parts[0], 10);
        var minute = parts[1];
        var suffix = hour >= 12 ? 'PM' : 'AM';
        var hour12 = hour % 12;
        if (hour12 === 0) hour12 = 12;
        return hour12 + ':' + minute + ' ' + suffix;
    }

    /**
     * Fetch a doctor's available time slots for a date and render them as
     * clickable buttons inside `pickerEl`, storing the chosen slot in
     * `valueEl`. Toggles the "disabled/placeholder" look on `pickerEl`
     * until a date is actually picked, and fires `onSlotChosen` once a slot
     * is clicked (used to reveal the patient-details section).
     */
    function loadSlotsInto(pickerEl, valueEl, doctorId, dateStr, onSlotChosen) {
        if (!pickerEl || !valueEl) return;
        valueEl.value = '';

        if (!doctorId || !dateStr) {
            pickerEl.classList.add('mdbk-slot-picker-disabled');
            pickerEl.innerHTML = '<p class="mdbk-time-placeholder">Select a date first</p>';
            return;
        }

        pickerEl.classList.remove('mdbk-slot-picker-disabled');
        pickerEl.innerHTML = '<div class="mdbk-slot-loading">Loading times...</div>';

        var formData = new FormData();
        formData.append('action', 'mdbk_get_doctor_slots');
        formData.append('doctor_id', doctorId);
        formData.append('date', dateStr);
        formData.append('nonce', mdbk_form_obj.nonce);

        fetch(mdbk_form_obj.ajax_url, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var slots = (data.success && data.data) ? data.data : [];
            if (!slots.length) {
                pickerEl.innerHTML = '<div class="mdbk-no-slots">No time slots available on this date.</div>';
                return;
            }

            function selectSlot(btn, time) {
                var prev = pickerEl.querySelector('.mdbk-slot-btn.selected');
                if (prev) prev.classList.remove('selected');
                btn.classList.add('selected');
                valueEl.value = time;
                if (onSlotChosen) onSlotChosen();
            }

            pickerEl.innerHTML = '';
            var firstAvailableBtn = null;
            var firstAvailableTime = null;
            slots.forEach(function(slot) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'mdbk-slot-btn' + (slot.available ? '' : ' mdbk-slot-taken');
                btn.textContent = formatTime12h(slot.time);
                if (!slot.available) {
                    btn.disabled = true;
                } else {
                    btn.addEventListener('click', function() { selectSlot(btn, slot.time); });
                    if (!firstAvailableBtn) {
                        firstAvailableBtn = btn;
                        firstAvailableTime = slot.time;
                    }
                }
                pickerEl.appendChild(btn);
            });

            // Auto-assign the earliest open slot for this date so patients
            // don't have to hunt through the list — they can still tap a
            // different one below if the auto-assigned time doesn't suit.
            if (firstAvailableBtn) {
                selectSlot(firstAvailableBtn, firstAvailableTime);
            }
        })
        .catch(function() {
            pickerEl.innerHTML = '<div class="mdbk-no-slots">Error loading time slots.</div>';
        });
    }

    // Either a popup modal (default, everywhere) or an inline instance
    // (the [mdbk_appointment_form] shortcode) exists on a given page —
    // never both, since the PHP side skips the modal's own output when the
    // shortcode already rendered the same widget inline. Every other ID
    // lookup below (doctor list, calendar, form fields, ...) is unaffected
    // by which of the two this is, since only one of them is ever present.
    var modal = document.getElementById('mdbk-booking-modal');
    var inlineContainer = document.getElementById('mdbk-booking-inline');
    var container = modal || inlineContainer;
    if (!container) return;

    var closeBtn = modal ? modal.querySelector('.mdbk-modal-close') : null;

    var doctorList = document.getElementById('mdbk-doctor-list');
    var doctorIdInput = document.getElementById('mdbk-doctor-id');
    var selectedDoctorEl = document.getElementById('mdbk-selected-doctor');
    var specialtySelect = document.getElementById('mdbk-specialty-select');
    var specialtyDropdown = document.getElementById('mdbk-specialty-dropdown');
    var specialtyTrigger = document.getElementById('mdbk-specialty-trigger');
    var specialtyTriggerValue = specialtyTrigger ? specialtyTrigger.querySelector('.mdbk-custom-select-value') : null;
    var specialtyPanel = document.getElementById('mdbk-specialty-panel');
    var bookingSection = document.getElementById('mdbk-booking-section');
    var detailsSection = document.getElementById('mdbk-details-section');

    // ===== Custom dropdown (specialty, gender, etc.) =====
    // A native <select>'s open option panel can't be restyled in any
    // browser (no control over its radius, colors, or hover state), so the
    // hidden <select> below stays purely as the data model — this button +
    // panel is the entire visible/interactive surface.
    function initCustomSelect(container) {
        if (!container) return null;
        var trigger = container.querySelector('.mdbk-custom-select-trigger');
        var panel = container.querySelector('.mdbk-custom-select-panel');
        var valueSpan = trigger ? trigger.querySelector('.mdbk-custom-select-value') : null;
        var nativeSelect = container.querySelector('select');
        if (!trigger || !panel) return null;

        function close() {
            container.classList.remove('open');
            panel.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
        }

        function open() {
            container.classList.add('open');
            panel.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
        }

        function setValue(value, label) {
            if (nativeSelect) nativeSelect.value = value;
            if (valueSpan) valueSpan.textContent = label;
            panel.querySelectorAll('.mdbk-custom-select-option').forEach(function(opt) {
                opt.classList.toggle('selected', opt.getAttribute('data-value') === String(value));
            });
        }

        trigger.addEventListener('click', function(e) {
            e.stopPropagation();
            if (container.classList.contains('open')) {
                close();
            } else {
                open();
            }
        });

        panel.addEventListener('click', function(e) {
            var opt = e.target.closest('.mdbk-custom-select-option');
            if (!opt) return;
            setValue(opt.getAttribute('data-value'), opt.textContent);
            close();
        });

        return { close: close, open: open, setValue: setValue };
    }

    // Keep specialty-specific wrappers for backward compat (syncSpecialtySelect, loadDefaultSpecialty)
    var specialtyInst = specialtyDropdown ? initCustomSelect(specialtyDropdown) : null;
    function setSpecialtyValue(value, label) {
        if (specialtyInst) specialtyInst.setValue(value, label);
    }
    function closeSpecialtyDropdown() {
        if (specialtyInst) specialtyInst.close();
    }
    function openSpecialtyDropdown() {
        if (specialtyInst) specialtyInst.open();
    }

    if (specialtyInst && specialtyPanel) {
        // Reload doctors when specialty changes (extra step beyond generic handler)
        specialtyPanel.addEventListener('click', function(e) {
            var opt = e.target.closest('.mdbk-custom-select-option');
            if (opt) loadDoctors(opt.getAttribute('data-value'));
        });
    }

    // Init gender custom dropdown
    var genderContainer = document.querySelector('[data-custom-select="gender"]');
    initCustomSelect(genderContainer);

    // Close any open custom select when clicking outside
    document.addEventListener('click', function(e) {
        document.querySelectorAll('.mdbk-custom-select.open').forEach(function(el) {
            if (!el.contains(e.target)) {
                var p = el.querySelector('.mdbk-custom-select-panel');
                var t = el.querySelector('.mdbk-custom-select-trigger');
                el.classList.remove('open');
                if (p) p.hidden = true;
                if (t) t.setAttribute('aria-expanded', 'false');
            }
        });
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.mdbk-custom-select.open').forEach(function(el) {
                var p = el.querySelector('.mdbk-custom-select-panel');
                var t = el.querySelector('.mdbk-custom-select-trigger');
                el.classList.remove('open');
                if (p) p.hidden = true;
                if (t) t.setAttribute('aria-expanded', 'false');
            });
        }
    });

    var calendarEl = document.getElementById('mdbk-calendar');
    var calendarCol = document.querySelector('.mdbk-calendar-col');
    var timeCol = document.querySelector('.mdbk-time-col');
    var modalSlotPicker = document.getElementById('mdbk-modal-slot-picker');
    var modalSlotValue = document.getElementById('mdbk-modal-slot-value');
    var modalForm = document.getElementById('mdbk-modal-form');
    var msgBox = container.querySelector('.mdbk-modal-message');

    // ===== Hand-built calendar (no third-party date picker) =====
    // A theme's global button/select/input[type=number] resets kept
    // colliding with flatpickr's own controls (month <select>, year
    // <input>, nav buttons) every time this modal was restyled. Rendering
    // day cells as plain <span>s sidesteps that whole class of conflict —
    // there's nothing here for a form-control reset to target.
    var today = new Date();
    var calYear = today.getFullYear();
    var calMonth = today.getMonth();
    var selectedDateStr = '';
    var disabledWeekdays = [];
    // Date-level overrides on top of the weekday pattern above (set by
    // updateDisabledDays()): off dates close an otherwise-active weekday
    // for that one date; extra dates open an otherwise-inactive weekday.
    var extraDates = [];
    var offDates = [];
    // Slot-disabled doctors skip the time picker entirely — patients are
    // queued serially (ticket number assigned server-side on submit), so
    // picking a date is enough to reveal the patient-details section.
    var currentDoctorSlotEnabled = true;

    function pad2(n) { return String(n).padStart(2, '0'); }
    function daysInMonth(y, m) { return new Date(y, m + 1, 0).getDate(); }
    function firstDayOfMonth(y, m) { return new Date(y, m, 1).getDay(); }

    function renderCalendar() {
        if (!calendarEl) return;
        var firstDay = firstDayOfMonth(calYear, calMonth);
        var days = daysInMonth(calYear, calMonth);
        var monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        var todayStr = today.getFullYear() + '-' + pad2(today.getMonth() + 1) + '-' + pad2(today.getDate());

        var html = '<div class="mdbk-cal-nav">' +
            '<button type="button" class="mdbk-cal-nav-btn" data-action="prev">&lsaquo;</button>' +
            '<span class="mdbk-cal-title">' + monthNames[calMonth] + ' ' + calYear + '</span>' +
            '<button type="button" class="mdbk-cal-nav-btn" data-action="next">&rsaquo;</button>' +
            '</div>';

        html += '<div class="mdbk-cal-grid">';
        ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].forEach(function(label) {
            html += '<span class="mdbk-cal-day-header">' + label + '</span>';
        });

        for (var i = 0; i < firstDay; i++) {
            html += '<span class="mdbk-cal-day empty"></span>';
        }

        for (var d = 1; d <= days; d++) {
            var dateStr = calYear + '-' + pad2(calMonth + 1) + '-' + pad2(d);
            var dayOfWeek = new Date(calYear, calMonth, d).getDay();
            var classes = 'mdbk-cal-day';
            if (dateStr === todayStr) classes += ' today';
            if (dateStr < todayStr) classes += ' past';
            if (dateStr === selectedDateStr) classes += ' selected';
            // off_dates always wins (explicitly closed); otherwise fall
            // back to the weekday pattern unless extra_dates opts this
            // specific date back in.
            var weekdayOff = disabledWeekdays.indexOf(dayOfWeek) !== -1;
            var isUnavailable = offDates.indexOf(dateStr) !== -1 || (weekdayOff && extraDates.indexOf(dateStr) === -1);
            if (isUnavailable) classes += ' unavailable';
            html += '<span class="' + classes + '" data-date="' + dateStr + '">' + d + '</span>';
        }

        html += '</div>';
        calendarEl.innerHTML = html;

        // Match the time column's height to the calendar's — a 4-row month
        // and a 6-row month render at different heights, and the time list
        // (which can hold far more entries than fit) needs its own scroll
        // within whatever height the calendar actually took this render.
        if (calendarCol && timeCol) {
            timeCol.style.height = calendarCol.offsetHeight + 'px';
        }
    }

    if (calendarEl) {
        calendarEl.addEventListener('click', function(e) {
            var navBtn = e.target.closest('.mdbk-cal-nav-btn');
            if (navBtn) {
                if (navBtn.getAttribute('data-action') === 'prev') {
                    calMonth--;
                    if (calMonth < 0) { calMonth = 11; calYear--; }
                } else {
                    calMonth++;
                    if (calMonth > 11) { calMonth = 0; calYear++; }
                }
                renderCalendar();
                return;
            }

            var dayEl = e.target.closest('.mdbk-cal-day');
            if (!dayEl || dayEl.classList.contains('empty') || dayEl.classList.contains('past') || dayEl.classList.contains('unavailable')) {
                return;
            }

            selectedDateStr = dayEl.getAttribute('data-date');
            if (dateValue) dateValue.value = selectedDateStr;
            renderCalendar();
            detailsSection.style.display = 'none';
            if (currentDoctorSlotEnabled) {
                loadModalSlots(selectedDateStr);
            } else {
                showSerialBookingNotice();
                showDetails();
            }
        });
    }

    function showDetails() {
        detailsSection.style.display = '';
    }

    function loadModalSlots(dateStr) {
        loadSlotsInto(modalSlotPicker, modalSlotValue, doctorIdInput.value, dateStr, showDetails);
    }

    /**
     * Replaces the time-slot list with a short explanation for slot-
     * disabled doctors — there's nothing to pick, so an empty/disabled
     * picker would just look broken instead of intentional.
     */
    function showSerialBookingNotice() {
        if (!modalSlotPicker) return;
        modalSlotPicker.classList.remove('mdbk-slot-picker-disabled');
        modalSlotPicker.innerHTML = '<p class="mdbk-time-placeholder">No time slot needed — you\'ll be added to the queue automatically.</p>';
        if (modalSlotValue) modalSlotValue.value = '';
    }

    /**
     * Fetch the doctor's inactive weekdays and re-render the calendar with
     * those days marked unavailable. Clears the current date selection if
     * it now falls on a day the newly-selected doctor doesn't work.
     */
    function updateDisabledDays(doctorId) {
        if (!doctorId) {
            disabledWeekdays = [];
            extraDates = [];
            offDates = [];
            renderCalendar();
            return;
        }
        var formData = new FormData();
        formData.append('action', 'mdbk_get_doctor_schedule');
        formData.append('doctor_id', doctorId);
        formData.append('nonce', mdbk_form_obj.nonce);

        fetch(mdbk_form_obj.ajax_url, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var payload = (data.success && data.data) ? data.data : {};
            disabledWeekdays = payload.off_days || [];
            extraDates = payload.extra_dates || [];
            offDates = payload.off_dates || [];
            if (selectedDateStr) {
                var parts = selectedDateStr.split('-').map(Number);
                var dow = new Date(parts[0], parts[1] - 1, parts[2]).getDay();
                var weekdayOff = disabledWeekdays.indexOf(dow) !== -1;
                var nowUnavailable = offDates.indexOf(selectedDateStr) !== -1 || (weekdayOff && extraDates.indexOf(selectedDateStr) === -1);
                if (nowUnavailable) {
                    selectedDateStr = '';
                    resetSlotPicker();
                }
            }
            renderCalendar();
        })
        .catch(function() {
            disabledWeekdays = [];
            extraDates = [];
            offDates = [];
            renderCalendar();
        });
    }

    /**
     * Doctor avatar for both the doctor-list cards and the selected-doctor
     * card — falls back to an initial when there's no featured image.
     */
    function avatarHtml(doc) {
        if (doc.thumbnail) {
            return '<img class="mdbk-doc-avatar" src="' + doc.thumbnail + '" alt="">';
        }
        var initial = (doc.name || '?').trim().charAt(0).toUpperCase();
        return '<span class="mdbk-doc-avatar mdbk-doc-avatar-fallback">' + initial + '</span>';
    }

    function resetSlotPicker() {
        if (modalSlotPicker) {
            modalSlotPicker.classList.add('mdbk-slot-picker-disabled');
            modalSlotPicker.innerHTML = '<p class="mdbk-time-placeholder">Select a date first</p>';
        }
        if (modalSlotValue) modalSlotValue.value = '';
        if (dateValue) dateValue.value = '';
    }

    /**
     * Reflect the chosen doctor's own department in the specialty dropdown —
     * without this, a doctor preselected from the grid (or picked while a
     * different specialty happened to be selected) left an unrelated
     * specialty showing above the doctor that's actually shown. Updates the
     * hidden <select> and the custom dropdown's trigger/panel directly
     * (not via setSpecialtyValue's caller path) so it doesn't trigger a
     * doctor-list reload out from under the selection we're making.
     */
    function syncSpecialtySelect(doc) {
        if (!doc.department_ids || !doc.department_ids.length || !specialtySelect) return;
        var match = specialtySelect.querySelector('option[value="' + doc.department_ids[0] + '"]');
        if (match) setSpecialtyValue(doc.department_ids[0], match.textContent);
    }

    /**
     * Single source of truth for "a doctor has been chosen" — reached both
     * from clicking a card in the doctor list, and from preselectDoctor()
     * when arriving with a doctor already picked (e.g. from the doctor
     * grid). Renders the selected-doctor summary card (with a Change button
     * back to the full list) and reveals the date/time section.
     */
    function selectDoctor(doc) {
        doctorIdInput.value = doc.id;
        syncSpecialtySelect(doc);
        currentDoctorSlotEnabled = doc.slot_enabled !== false;

        var specsHtml = (doc.specialties && doc.specialties.length)
            ? '<span class="mdbk-doc-specs">' + doc.specialties.join(', ') + '</span>' : '';

        selectedDoctorEl.innerHTML =
            '<div class="mdbk-selected-doc-card">' +
                avatarHtml(doc) +
                '<div class="mdbk-selected-doc-info">' +
                    '<div class="mdbk-selected-doc-name">' + doc.name + '</div>' +
                    '<div class="mdbk-doc-meta">' + specsHtml + '</div>' +
                '</div>' +
                '<button type="button" class="mdbk-selected-doc-change">Change</button>' +
            '</div>';
        selectedDoctorEl.style.display = '';
        doctorList.style.display = 'none';

        resetSlotPicker();
        detailsSection.style.display = 'none';
        bookingSection.style.display = '';

        calYear = today.getFullYear();
        calMonth = today.getMonth();
        selectedDateStr = '';
        renderCalendar();
        updateDisabledDays(doc.id);
    }

    function renderDoctors(doctors) {
        doctorList.innerHTML = '';
        if (!doctors || !doctors.length) {
            doctorList.innerHTML = '<div class="mdbk-no-doctors-modal">No doctors available for this specialty.</div>';
            return;
        }
        doctors.forEach(function(doc) {
            var card = document.createElement('div');
            card.className = 'mdbk-doctor-item';
            card.setAttribute('data-doctor-id', doc.id);

            var daysHtml = '';
            if (doc.available_days && doc.available_days.length) {
                daysHtml = '<span class="mdbk-doc-days">' + doc.available_days.slice(0, 3).join(', ') + (doc.available_days.length > 3 ? ' & more' : '') + '</span>';
            }

            var specsHtml = '';
            if (doc.specialties && doc.specialties.length) {
                specsHtml = '<span class="mdbk-doc-specs">' + doc.specialties.join(', ') + '</span>';
            }

            card.innerHTML =
                avatarHtml(doc) +
                '<div class="mdbk-doc-info">' +
                    '<div class="mdbk-doc-name">' + doc.name + '</div>' +
                    '<div class="mdbk-doc-meta">' + specsHtml + daysHtml + '</div>' +
                '</div>';

            card.addEventListener('click', function() {
                selectDoctor(doc);
            });

            doctorList.appendChild(card);
        });
    }

    /**
     * Reveal the full doctor list for `specId` and hide the selected-doctor
     * card (and anything downstream of it) — used both for the specialty
     * switcher and the selected-doctor card's "Change" button.
     */
    function loadDoctors(specId) {
        selectedDoctorEl.style.display = 'none';
        selectedDoctorEl.innerHTML = '';
        doctorIdInput.value = '';
        doctorList.style.display = '';
        doctorList.innerHTML = '<div class="mdbk-doc-loading">Loading doctors...</div>';
        bookingSection.style.display = 'none';
        detailsSection.style.display = 'none';

        var formData = new FormData();
        formData.append('action', 'mdbk_get_doctors_by_specialty');
        formData.append('specialty_id', specId);
        formData.append('nonce', mdbk_form_obj.nonce);

        fetch(mdbk_form_obj.ajax_url, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                renderDoctors(data.data);
            } else {
                doctorList.innerHTML = '<div class="mdbk-no-doctors-modal">No doctors available for this specialty.</div>';
            }
        })
        .catch(function() {
            doctorList.innerHTML = '<div class="mdbk-no-doctors-modal">Error loading doctors.</div>';
        });
    }

    function loadDefaultSpecialty() {
        if (specialtySelect) loadDoctors(specialtySelect.value);
    }

    /**
     * Fetch a single doctor's info and go straight to the selected-doctor
     * card — no flash of the full doctor list first. Falls back to the
     * general specialty -> doctor flow if the doctor can't be loaded (e.g.
     * unpublished/deleted since the page was rendered).
     */
    function preselectDoctor(doctorId) {
        doctorList.style.display = '';
        selectedDoctorEl.style.display = 'none';
        doctorList.innerHTML = '<div class="mdbk-doc-loading">Loading...</div>';

        var formData = new FormData();
        formData.append('action', 'mdbk_get_doctor_info');
        formData.append('doctor_id', doctorId);
        formData.append('nonce', mdbk_form_obj.nonce);

        fetch(mdbk_form_obj.ajax_url, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                selectDoctor(data.data);
            } else {
                loadDefaultSpecialty();
            }
        })
        .catch(function() {
            loadDefaultSpecialty();
        });
    }

    function openBookingModal(doctorId) {
        if (modal) {
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
        if (doctorId) {
            preselectDoctor(doctorId);
        } else {
            loadDefaultSpecialty();
        }
    }

    /**
     * Public integration point: add class="mdbk-book-trigger" to *any*
     * button or link, anywhere on the site — theme templates, widgets,
     * Elementor content, a menu item — and it opens the popup modal. Add
     * data-mdbk-doctor-id="123" to preselect a doctor. Delegated on
     * `document` (not bound to a queried NodeList) so it also picks up
     * triggers added to the page after this script runs, and works
     * identically whether the element is a <button> or an <a href="...">.
     *
     * On a page where [mdbk_appointment_form] already rendered the widget
     * inline (no popup exists to open), a trigger scrolls to it and
     * preselects the doctor there instead.
     */
    document.addEventListener('click', function(e) {
        var trigger = e.target.closest('.mdbk-book-trigger');
        if (!trigger) return;
        e.preventDefault();
        var doctorId = trigger.getAttribute('data-mdbk-doctor-id');
        if (modal) {
            openBookingModal(doctorId);
        } else if (inlineContainer) {
            inlineContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
            if (doctorId) preselectDoctor(doctorId);
        }
    });

    // The inline form has no click-to-reveal step — it's ready immediately,
    // optionally preselected via [mdbk_appointment_form doctor="123"].
    if (inlineContainer) {
        openBookingModal(inlineContainer.getAttribute('data-mdbk-doctor-id'));
    }

    function resetModal() {
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
        doctorIdInput.value = '';
        selectedDoctorEl.innerHTML = '';
        selectedDoctorEl.style.display = 'none';
        doctorList.innerHTML = '';
        doctorList.style.display = '';
        bookingSection.style.display = 'none';
        detailsSection.style.display = 'none';

        calYear = today.getFullYear();
        calMonth = today.getMonth();
        selectedDateStr = '';
        disabledWeekdays = [];
        if (calendarEl) calendarEl.innerHTML = '';
        resetSlotPicker();

        if (msgBox) {
            msgBox.innerHTML = '';
            msgBox.className = 'mdbk-modal-message';
        }
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', resetModal);
    }

    // Bound to whichever of modal/inlineContainer actually exists. The
    // backdrop-close check below only ever matches in the true-modal case
    // (e.target can't equal a null `modal`), so this is safe for both.
    container.addEventListener('click', function(e) {
        if (e.target === modal) {
            resetModal();
            return;
        }
        if (e.target.closest('.mdbk-selected-doc-change')) {
            // Respect whichever specialty is currently selected (kept in sync
            // with the selected doctor's own department by syncSpecialtySelect())
            // rather than dumping back to the full unfiltered doctor list.
            loadDoctors(specialtySelect ? specialtySelect.value : 0);
        }
    });

    if (modalForm) {
        modalForm.addEventListener('submit', function(e) {
            e.preventDefault();

            var formData = new FormData(modalForm);
            formData.append('action', 'mdbk_submit_appointment');
            formData.append('nonce', mdbk_form_obj.nonce);

            var submitBtn = modalForm.querySelector('.mdbk-submit-btn');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Booking...';

            if (msgBox) {
                msgBox.innerHTML = '';
                msgBox.className = 'mdbk-modal-message';
            }

            fetch(mdbk_form_obj.ajax_url, { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Book Appointment';
                if (msgBox) {
                    if (data.success) {
                        msgBox.className = 'mdbk-modal-message mdbk-success';
                        msgBox.textContent = data.data;
                        modalForm.reset();
                        setTimeout(function() {
                            resetModal();
                        }, 2500);
                    } else {
                        msgBox.className = 'mdbk-modal-message mdbk-error';
                        msgBox.textContent = data.data;
                    }
                }
            })
            .catch(function() {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Book Appointment';
                if (msgBox) {
                    msgBox.className = 'mdbk-modal-message mdbk-error';
                    msgBox.textContent = 'Something went wrong. Please try again.';
                }
            });
        });
    }
});
