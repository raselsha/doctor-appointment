/**
 * Draws the booking summary + QR onto an offscreen <canvas> so it can be
 * saved as a single image. Defined at top level (not inside the
 * DOMContentLoaded wrapper below) so it's already available the moment
 * this script tag finishes loading — the footer "view my booking" status
 * view (shortcode.php render_status_view()) is printed right after this
 * script and calls this synchronously, before DOMContentLoaded fires.
 */
function mdbkBuildBookingCardImage(details, qrImgSrc, callback) {
    function roundRect(ctx, x, y, w, h, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + w, y, x + w, y + h, r);
        ctx.arcTo(x + w, y + h, x, y + h, r);
        ctx.arcTo(x, y + h, x, y, r);
        ctx.arcTo(x, y, x + w, y, r);
        ctx.closePath();
    }

    var W = 400;
    var rows = [
        ['Ticket', details.ticket],
        ['Patient', details.patient_name],
        ['Doctor', details.doctor_name],
        ['Date', details.date]
    ];
    if (details.slot_time) rows.push(['Time', details.slot_time]);

    var rowH = 34;
    var boxTop = 140;
    var boxPad = 16;
    var boxH = rows.length * rowH + boxPad * 2;
    var qrSize = 200;
    var qrY = boxTop + boxH + 24;
    var H = qrY + qrSize + 60;

    var canvas = document.createElement('canvas');
    canvas.width = W;
    canvas.height = H;
    var ctx = canvas.getContext('2d');

    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, W, H);
    ctx.strokeStyle = '#e2e8f0';
    ctx.lineWidth = 2;
    ctx.strokeRect(1, 1, W - 2, H - 2);

    ctx.beginPath();
    ctx.fillStyle = '#e6f7ed';
    ctx.arc(W / 2, 60, 28, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = '#1a7f45';
    ctx.font = 'bold 26px sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText('✓', W / 2, 61);

    ctx.fillStyle = '#1e293b';
    ctx.font = 'bold 20px sans-serif';
    ctx.textBaseline = 'alphabetic';
    ctx.fillText(details.title || 'Booking Confirmed', W / 2, 112);

    roundRect(ctx, 24, boxTop, W - 48, boxH, 12);
    ctx.fillStyle = '#f8fafc';
    ctx.fill();
    ctx.strokeStyle = '#e2e8f0';
    ctx.lineWidth = 1;
    ctx.stroke();

    rows.forEach(function(row, i) {
        var y = boxTop + boxPad + i * rowH + 20;
        ctx.font = '14px sans-serif';
        ctx.textAlign = 'left';
        ctx.fillStyle = '#64748b';
        ctx.fillText(row[0], 24 + 16, y);
        ctx.font = 'bold 14px sans-serif';
        ctx.textAlign = 'right';
        ctx.fillStyle = '#1e293b';
        ctx.fillText(String(row[1] || ''), W - 24 - 16, y);
    });

    if (!qrImgSrc) {
        callback(canvas);
        return;
    }

    var qrImg = new Image();
    qrImg.onload = function() {
        ctx.drawImage(qrImg, (W - qrSize) / 2, qrY, qrSize, qrSize);
        ctx.font = '12px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillStyle = '#94a3b8';
        ctx.fillText('Show this QR code at check-in.', W / 2, qrY + qrSize + 24);
        callback(canvas);
    };
    qrImg.onerror = function() { callback(canvas); };
    qrImg.src = qrImgSrc;
}

/**
 * Triggers a PNG download of the built canvas — shared by both the
 * post-booking confirmation panel and the footer status view.
 */
function mdbkDownloadBookingCard(details, qrImgSrc) {
    mdbkBuildBookingCardImage(details, qrImgSrc, function(canvas) {
        var link = document.createElement('a');
        link.download = 'booking-' + (details.ticket || 'confirmation').replace(/[^a-z0-9-]/gi, '') + '.png';
        link.href = canvas.toDataURL('image/png');
        document.body.appendChild(link);
        link.click();
        link.remove();
    });
}

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

    function formatDisplayDate(dateStr) {
        var parts = dateStr.split('-');
        var d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
        var monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        return monthNames[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
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
            // Only sets the hidden value without triggering onSlotChosen —
            // the calendar+time picker stays visible until the patient
            // explicitly taps a slot.
            if (firstAvailableBtn) {
                firstAvailableBtn.classList.add('selected');
                valueEl.value = firstAvailableTime;
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
    var bookingColumns = document.querySelector('.mdbk-booking-columns');
    var datetimeSelected = document.getElementById('mdbk-datetime-selected');
    var datetimeValue = document.getElementById('mdbk-datetime-value');
    var datetimeChange = document.getElementById('mdbk-datetime-change');
    var modalForm = document.getElementById('mdbk-modal-form');
    var msgBox = container.querySelector('.mdbk-modal-message');
    var confirmationEl = document.getElementById('mdbk-booking-confirmation');
    var confQrEl = document.getElementById('mdbk-confirmation-qr');
    var confCloseBtn = document.getElementById('mdbk-confirmation-close');
    var confDownloadBtn = document.getElementById('mdbk-confirmation-download');
    var confPrintBtn = document.getElementById('mdbk-confirmation-print');
    var currentBooking = null;

    function showBookingConfirmation(booking) {
        if (!confirmationEl) return;
        currentBooking = booking;

        document.getElementById('mdbk-conf-ticket').textContent = booking.ticket || '';
        document.getElementById('mdbk-conf-patient').textContent = booking.patient_name || '';
        document.getElementById('mdbk-conf-doctor').textContent = booking.doctor_name || '';
        document.getElementById('mdbk-conf-date').textContent = booking.date || '';

        var timeRow = document.getElementById('mdbk-conf-time-row');
        if (booking.slot_time) {
            document.getElementById('mdbk-conf-time').textContent = booking.slot_time;
            timeRow.style.display = '';
        } else if (timeRow) {
            timeRow.style.display = 'none';
        }

        if (confQrEl) {
            confQrEl.innerHTML = '';
            if (booking.checkin_url && typeof qrcode === 'function') {
                var qr = qrcode(0, 'M');
                qr.addData(booking.checkin_url);
                qr.make();
                confQrEl.innerHTML = qr.createImgTag(5, 4);
            }
        }

        if (modalForm) modalForm.style.display = 'none';
        confirmationEl.style.display = 'block';
    }

    if (confDownloadBtn) {
        confDownloadBtn.addEventListener('click', function() {
            if (!currentBooking) return;
            var qrImg = confQrEl ? confQrEl.querySelector('img') : null;
            mdbkDownloadBookingCard(currentBooking, qrImg ? qrImg.src : '');
        });
    }
    if (confPrintBtn) {
        confPrintBtn.addEventListener('click', function() {
            window.print();
        });
    }

    if (confCloseBtn) {
        confCloseBtn.addEventListener('click', resetModal);
    }

    // ===== Hand-built calendar (no third-party date picker) =====
    // A theme's global button/select/input[type=number] resets kept
    // colliding with flatpickr's own controls (month <select>, year
    // <input>, nav buttons) every time this modal was restyled. Rendering
    // day cells as plain <span>s sidesteps that whole class of conflict —
    // there's nothing here for a form-control reset to target.
    //
    // "Today" comes from the server (mdbk_form_obj.today, set via
    // current_time('Y-m-d') — WP's configured site timezone), not the
    // visitor's own browser clock. A patient booking from a different
    // timezone than the clinic must see the clinic's actual today as the
    // past/bookable-date cutoff, not their own device's.
    function parseServerDate(str) {
        if (str) {
            var parts = str.split('-');
            return new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
        }
        return new Date();
    }
    var today = parseServerDate(typeof mdbk_form_obj !== 'undefined' ? mdbk_form_obj.today : null);
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

    function showDatetimeSummary() {
        if (!calendarCol || !timeCol || !datetimeSelected) return;
        var dateStr = dateValue ? dateValue.value : '';
        var timeStr = modalSlotValue ? modalSlotValue.value : '';
        if (!dateStr) return;
        var formatted = formatDisplayDate(dateStr);
        if (timeStr && currentDoctorSlotEnabled) {
            formatted += ' at ' + formatTime12h(timeStr);
        }
        datetimeValue.textContent = formatted;
        calendarCol.style.display = 'none';
        timeCol.style.display = 'none';
        datetimeSelected.style.display = 'flex';
    }

    function showDatetimePicker() {
        if (calendarCol) calendarCol.style.display = '';
        if (timeCol) timeCol.style.display = '';
        if (datetimeSelected) datetimeSelected.style.display = 'none';
    }

    function showDetails() {
        detailsSection.style.display = '';
    }

    function loadModalSlots(dateStr) {
        // Only on explicit slot click (not auto-select) do we hide the
        // calendar+time picker and show the date/time summary — the
        // auto-assigned first slot just pre-fills the hidden input.
        loadSlotsInto(modalSlotPicker, modalSlotValue, doctorIdInput.value, dateStr, function() {
            showDetails();
            showDatetimeSummary();
        });
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
        showDatetimePicker();
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
        if (confirmationEl) confirmationEl.style.display = 'none';
        if (confQrEl) confQrEl.innerHTML = '';
        if (modalForm) modalForm.style.display = '';
        currentBooking = null;
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
        if (e.target.closest('.mdbk-datetime-change')) {
            showDatetimePicker();
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
                if (data.success) {
                    modalForm.reset();
                    showBookingConfirmation(data.data);
                } else if (msgBox) {
                    msgBox.className = 'mdbk-modal-message mdbk-error';
                    msgBox.textContent = data.data;
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
