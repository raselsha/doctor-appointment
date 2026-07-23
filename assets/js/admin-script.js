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

    function initModal(modalId, openSelector, formId, editClass, populateFn) {
        const modal = document.getElementById(modalId);
        if (!modal) return;
        const openBtns = document.querySelectorAll(openSelector);
        const closeBtn = modal.querySelector('.mdbk-modal-close');
        const form = document.getElementById(formId) || modal.querySelector('form');

        openBtns.forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                modal.style.display = 'flex';
                if (this.classList.contains(editClass)) {
                    populateFn(this.dataset.id, this);
                } else {
                    form.reset();
                    const idInput = form.querySelector('input[type="hidden"]');
                    if (idInput) idInput.value = '';
                }
            });
        });
        closeBtn.addEventListener('click', () => modal.style.display = 'none');
        window.addEventListener('click', (e) => { if (e.target == modal) modal.style.display = 'none'; });
    }

    const DOCTOR_DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
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
    document.querySelectorAll('.mdbk-day-check').forEach(function(cb) {
        cb.addEventListener('change', function() {
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

    initModal('mdbk-doctor-modal', '.mdbk-add-doctor, .mdbk-edit-doctor', 'mdbk-doctor-form', 'mdbk-edit-doctor', (id, btn) => {
        document.getElementById('mdbk-doctor-id').value = id;
        const row = btn.closest('tr, .mdbk-admin-doctor-card');
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
        const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        let scheduleRows = '';
        days.forEach((day) => {
            const d = schedule[day];
            const working = d && d.active;
            const hours = working
                ? (escHtml(d.from) || '—') + ' – ' + (escHtml(d.to) || '—')
                : '<span class="mdbk-view-day-off">Off</span>';
            scheduleRows += '<div class="mdbk-view-day-row' + (working ? '' : ' is-off') + '"><span class="mdbk-view-day-name">' + day + '</span><span class="mdbk-view-day-hours">' + hours + '</span></div>';
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
            if (row.dataset.gender) document.getElementById('mdbk-app-gender').value = row.dataset.gender;
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
        });
    });

    const appointmentModalCancel = document.querySelector('#mdbk-appointment-modal .mdbk-modal-cancel');
    if (appointmentModalCancel) {
        appointmentModalCancel.addEventListener('click', function() {
            document.getElementById('mdbk-appointment-modal').style.display = 'none';
        });
    }

    // Per-doctor card "View All" — scoped to one doctor's popup
    // (one modal per doctor card is pre-rendered server-side; this just
    // opens/closes whichever one a given link points at). On the Bookings
    // page this link sits inside a <summary> (the collapsible card
    // header), so stopPropagation keeps a click here from also toggling
    // that card open/closed.
    document.querySelectorAll('[data-doctor-modal]').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const modal = document.getElementById(this.dataset.doctorModal);
            if (modal) modal.style.display = 'flex';
        });
    });
    document.querySelectorAll('.mdbk-doctor-popup').forEach(function(modal) {
        const closeBtn = modal.querySelector('.mdbk-modal-close');
        if (closeBtn) closeBtn.addEventListener('click', () => modal.style.display = 'none');
        window.addEventListener('click', (e) => { if (e.target === modal) modal.style.display = 'none'; });
    });

    // Print just this modal's table — window.print() on the main page would
    // try to print the whole admin screen behind the overlay, so this opens
    // a small standalone print window with only the modal's own title +
    // table markup instead.
    document.querySelectorAll('.mdbk-print-modal').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const modal = btn.closest('.mdbk-modal');
            if (!modal) return;
            const title = modal.querySelector('.mdbk-modal-head h2');
            const body = modal.querySelector('.mdbk-modal-body');
            if (!body) return;
            const win = window.open('', '_blank', 'width=900,height=700');
            if (!win) return;
            win.document.write(
                '<html><head><title>' + (title ? title.textContent : 'Print') + '</title><style>' +
                'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;padding:24px;color:#1e293b;}' +
                'h2{margin:0 0 16px;font-size:18px;}' +
                'table{width:100%;border-collapse:collapse;font-size:13px;}' +
                'th,td{padding:8px 10px;border-bottom:1px solid #e2e8f0;text-align:left;}' +
                'th{background:#f8fafc;text-transform:uppercase;font-size:11px;color:#64748b;}' +
                '</style></head><body>' +
                '<h2>' + (title ? title.textContent : '') + '</h2>' +
                body.innerHTML +
                '</body></html>'
            );
            win.document.close();
            win.focus();
            win.print();
        });
    });

    initModal('mdbk-specialty-modal', '.mdbk-add-specialty, .mdbk-edit-specialty', 'mdbk-specialty-form', 'mdbk-edit-specialty', (id, btn) => {
        document.getElementById('mdbk-spec-id').value = id;
        const row = btn.closest('tr');
        if (row) {
            document.getElementById('mdbk-spec-name').value = row.dataset.name;
        }
    });

    // "Mark as Visited" on the doctor-restricted "My Queue" page
    // (mdbk-my-queue) — delegated on document since the button is inside
    // a fragment that gets replaced wholesale on success.
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.mdbk-mark-visited');
        if (!btn || typeof mdbk_admin_obj === 'undefined') return;
        const row = btn.closest('.mdbk-patient-row');
        const appointmentId = btn.dataset.id;
        btn.disabled = true;
        const body = new URLSearchParams();
        body.set('action', 'mdbk_mark_visited');
        body.set('nonce', mdbk_admin_obj.nonce);
        body.set('appointment_id', appointmentId);
        fetch(mdbk_admin_obj.ajax_url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
            .then((r) => r.json())
            .then((res) => {
                if (res && res.success && row) {
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
