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
    // No ticket yet under check-in-order queue mode (assigned only once
    // this patient actually checks in) — Booking ID fills the row instead.
    var rows = [
        details.ticket ? ['Ticket', details.ticket] : ['Booking ID', details.booking_id],
        ['Patient', details.patient_name],
        ['Doctor', details.doctor_name],
        ['Date', details.date]
    ];
    if (details.slot_time) rows.push(['Time', details.slot_time]);

    var rowH = 34;
    var boxTop = 66;
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

    ctx.fillStyle = '#1e293b';
    ctx.font = 'bold 20px sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'alphabetic';
    ctx.fillText(details.title || 'Booking Confirmed', W / 2, 40);

    roundRect(ctx, 24, boxTop, W - 48, boxH, 12);
    ctx.fillStyle = '#f8fafc';
    ctx.fill();
    ctx.strokeStyle = '#cbd5e1';
    ctx.lineWidth = 1;
    ctx.stroke();

    // A divider between each row (not just the box's own outer border) —
    // matches the live on-screen card's .mdbk-confirmation-row + .mdbk-
    // confirmation-row border, and the print window's own version of it.
    rows.forEach(function(row, i) {
        var rowTop = boxTop + boxPad + i * rowH;
        var y = rowTop + 20;
        if (i > 0) {
            ctx.strokeStyle = '#94a3b8';
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.moveTo(24 + 16, rowTop);
            ctx.lineTo(W - 24 - 16, rowTop);
            ctx.stroke();
        }
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

function mdbkEscHtml(str) {
    var div = document.createElement('div');
    div.textContent = String(str || '');
    return div.innerHTML;
}

/**
 * Logo + name + contact + address block, shown above the confirmation
 * card in the print window — empty Global Settings fields just don't
 * render their line, so a clinic that's only filled in the name still
 * gets a clean header instead of empty rows.
 */
function mdbkBuildClinicHeaderHtml() {
    var obj = typeof mdbk_form_obj !== 'undefined' ? mdbk_form_obj : {};
    var html = '';
    if (obj.clinic_logo) html += '<img class="mdbk-print-logo" src="' + obj.clinic_logo + '" alt="">';
    if (obj.clinic_name) html += '<p class="mdbk-print-clinic-name">' + mdbkEscHtml(obj.clinic_name) + '</p>';
    if (obj.clinic_contact) html += '<p class="mdbk-print-clinic-meta">' + mdbkEscHtml(obj.clinic_contact) + '</p>';
    if (obj.clinic_address) html += '<p class="mdbk-print-clinic-meta">' + mdbkEscHtml(obj.clinic_address) + '</p>';
    return html;
}

/**
 * Opens a small standalone print window styled to match the live
 * on-screen confirmation card (.mdbk-booking-confirmation in
 * form-style.css) — bordered details box, same row layout (no checkmark
 * icon, dropped per feedback: not needed on a printed/downloaded card)
 * — instead of window.print() on the live page. The confirmation panel
 * sits deep inside a centered modal overlay
 * (position:fixed, flex-centered) — @media print rules trying to pull
 * just that one nested element up to the top of the page turned out
 * unreliable (the print output kept the overlay's centered vertical
 * offset baked in, pushing the card down the page and often onto a
 * second one). A fresh, empty document has no such ancestor to fight,
 * and it's also the natural place to put the clinic branding (Global
 * Settings) above the card, which the live on-screen version never
 * shows. Row dividers use a darker border (#94a3b8) than the screen
 * version's #e2e8f0 — the very pale screen color reliably printed as
 * invisible on a real physical printout.
 */
function mdbkPrintBookingCard(details, qrImgSrc) {
    var titleText = details.title || 'Booking Confirmed';
    var rows = [
        details.ticket ? ['Ticket', details.ticket] : ['Booking ID', details.booking_id],
        ['Patient', details.patient_name],
        ['Doctor', details.doctor_name],
        ['Date', details.date]
    ];
    if (details.slot_time) rows.push(['Time', details.slot_time]);

    var rowsHtml = rows.map(function(r) {
        return '<div class="mdbk-confirmation-row"><span>' + mdbkEscHtml(r[0]) + '</span><strong>' + mdbkEscHtml(r[1]) + '</strong></div>';
    }).join('');

    var win = window.open('', '_blank', 'width=480,height=700');
    if (!win) return;
    win.document.write(
        '<html><head><title>' + mdbkEscHtml(titleText) + '</title><style>' +
        '@page{margin:20px;}' +
        'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;margin:0;padding:24px;color:#1e293b;text-align:center;}' +
        '.mdbk-print-logo{max-width:64px;max-height:64px;margin:0 auto 8px;display:block;}' +
        '.mdbk-print-clinic-name{margin:0 0 3px;font-size:15px;font-weight:700;}' +
        '.mdbk-print-clinic-meta{margin:0 0 3px;font-size:11px;color:#64748b;}' +
        'h2{margin:16px 0 18px;font-size:19px;font-weight:700;}' +
        '.mdbk-confirmation-details{text-align:left;background:#f8fafc;border:1px solid #cbd5e1;border-radius:12px;padding:14px 16px;margin:0 auto 18px;max-width:340px;}' +
        '.mdbk-confirmation-row{display:flex;justify-content:space-between;padding:6px 0;font-size:14px;}' +
        '.mdbk-confirmation-row + .mdbk-confirmation-row{border-top:1px solid #94a3b8;}' +
        '.mdbk-confirmation-row span{color:#64748b;}' +
        '.mdbk-confirmation-row strong{color:#1e293b;font-weight:600;text-align:right;}' +
        '.mdbk-print-qr{max-width:180px;border:1px solid #cbd5e1;border-radius:8px;padding:8px;background:#fff;}' +
        '.mdbk-print-hint{font-size:12px;color:#94a3b8;margin-top:8px;}' +
        '</style></head><body>' +
        mdbkBuildClinicHeaderHtml() +
        '<h2>' + mdbkEscHtml(titleText) + '</h2>' +
        '<div class="mdbk-confirmation-details">' + rowsHtml + '</div>' +
        (qrImgSrc ? '<img class="mdbk-print-qr" src="' + qrImgSrc + '" alt="">' : '') +
        '<p class="mdbk-print-hint">Show this QR code at check-in.</p>' +
        '</body></html>'
    );
    win.document.close();
    win.focus();
    win.print();
}

document.addEventListener('DOMContentLoaded', function() {
    var dateValue = document.getElementById('mdbk-date-value');

    /**
     * "09:00" -> "9:00 AM" for display only — the underlying 24-hour value
     * (submitted, sorted, and compared everywhere else in the booking flow)
     * is left untouched. Left as-is when the site's WordPress
     * Settings > General > Time Format is a 24-hour one — see
     * mdbk_form_obj.time_format_24h, doctor-appointment.php's
     * clinic_branding_data() — so this respects the same whole-system
     * preference the doctor availability list and admin schedule views do.
     */
    function formatTime12h(time24) {
        if (typeof mdbk_form_obj !== 'undefined' && mdbk_form_obj.time_format_24h) return time24;
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
                // slot.break (get_available_slots() in appointment-manager.php)
                // — that break's own name string when this slot falls
                // inside one, else false. Distinct from a slot someone
                // else already booked, so a patient sees WHICH break it
                // is instead of it looking identical to "taken".
                btn.className = 'mdbk-slot-btn' + (slot.break ? ' mdbk-slot-break' : (slot.available ? '' : ' mdbk-slot-taken'));
                if (slot.break) {
                    btn.textContent = slot.break;
                    btn.title = formatTime12h(slot.time);
                } else {
                    btn.textContent = formatTime12h(slot.time);
                }
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
    function initCustomSelect(container, onChange) {
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

        /*
         * Panels drop downward by default. On a trigger sitting near the
         * bottom of whatever is showing it, the list ran straight off the
         * edge and the options below the fold were simply unreachable —
         * very easy to hit on District/Thana, which are the last row of
         * the form and hold the longest lists (Dhaka alone has 55).
         *
         * So: measure the real room on each side and flip above the
         * trigger when there is more of it, then cap the height to what
         * that side actually has, so the panel is always fully on screen
         * and scrollable rather than clipped. "Room" is bounded by the
         * nearest scrolling/clipping ancestor as well as the viewport —
         * inside a modal whose body scrolls, the window's edges are not
         * what cuts the panel off.
         */
        function availableRoom() {
            var top = 0;
            var bottom = window.innerHeight;
            var node = container.parentElement;
            while (node && node !== document.body) {
                var overflowY = window.getComputedStyle(node).overflowY;
                if (overflowY === 'auto' || overflowY === 'scroll' || overflowY === 'hidden') {
                    var box = node.getBoundingClientRect();
                    if (box.top > top) top = box.top;
                    if (box.bottom < bottom) bottom = box.bottom;
                }
                node = node.parentElement;
            }
            return { top: top, bottom: bottom };
        }

        function positionPanel() {
            container.classList.remove('mdbk-drop-up');
            panel.style.maxHeight = '';
            var rect = trigger.getBoundingClientRect();
            var bounds = availableRoom();
            // 6px matches the panel's CSS offset from the trigger; 12px
            // just keeps it off the very edge.
            var below = bounds.bottom - rect.bottom - 6 - 12;
            var above = rect.top - bounds.top - 6 - 12;
            var needed = panel.offsetHeight;
            var room = below;
            if (needed > below && above > below) {
                container.classList.add('mdbk-drop-up');
                room = above;
            }
            // The panel is border-box, so this cap is its real outer
            // height. The floor only bites when neither side can hold even
            // a couple of rows — there, overflowing slightly beats showing
            // a sliver.
            if (needed > room) panel.style.maxHeight = Math.max(96, room) + 'px';
        }

        function open() {
            container.classList.add('open');
            panel.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
            panel.scrollTop = 0;
            if (searchWrap && !searchWrap.hidden) {
                searchInput.value = '';
                applyFilter();
            }
            positionPanel();
            // Not on touch: focusing here would throw up the on-screen
            // keyboard over the very list the user came to look at.
            if (searchWrap && !searchWrap.hidden && !window.matchMedia('(pointer: coarse)').matches) {
                searchInput.focus();
            }
        }

        // An open panel has to keep up with whatever moves under it. The
        // panel's own scrolling is skipped: it doesn't move the trigger,
        // and recomputing on it would fight the user's scroll.
        window.addEventListener('resize', function() {
            if (container.classList.contains('open')) positionPanel();
        });
        window.addEventListener('scroll', function(e) {
            if (e.target === panel) return;
            if (container.classList.contains('open')) positionPanel();
        }, true);

        /*
         * Type-to-filter. Scrolling 64 districts (or Dhaka's 55 thanas)
         * to find one is the wrong way to use a list this long, so panels
         * past a handful of options grow a search box. Short ones
         * (Gender) don't — a filter over two items is only noise. The box
         * is built once and kept across rebuilds; syncSearch() re-decides
         * whether to show it after a list is replaced (Thana's list
         * changes size with every district).
         */
        var SEARCH_MIN_OPTIONS = 10;
        var searchWrap = null;
        var searchInput = null;
        var emptyNote = null;

        function optionEls() {
            return Array.prototype.slice.call(panel.querySelectorAll('.mdbk-custom-select-option'));
        }

        function applyFilter() {
            var term = (searchWrap && !searchWrap.hidden && searchInput) ? searchInput.value.trim().toLowerCase() : '';
            var shown = 0;
            optionEls().forEach(function(opt) {
                var hit = !term || opt.textContent.toLowerCase().indexOf(term) !== -1;
                opt.style.display = hit ? '' : 'none';
                if (hit) shown++;
            });
            if (emptyNote) emptyNote.hidden = !(term && shown === 0);
            // Filtering changes the panel's height, so where it fits has
            // to be worked out again.
            if (container.classList.contains('open')) positionPanel();
        }

        function buildSearch() {
            var obj = typeof mdbk_form_obj !== 'undefined' ? mdbk_form_obj : {};
            searchWrap = document.createElement('div');
            searchWrap.className = 'mdbk-select-search';
            searchInput = document.createElement('input');
            searchInput.type = 'text';
            searchInput.className = 'mdbk-select-search-input';
            searchInput.setAttribute('autocomplete', 'off');
            searchInput.placeholder = obj.i18n_search || 'Search…';
            searchWrap.appendChild(searchInput);
            emptyNote = document.createElement('div');
            emptyNote.className = 'mdbk-select-search-empty';
            emptyNote.hidden = true;
            emptyNote.textContent = obj.i18n_no_match || 'No match found';
            panel.insertBefore(searchWrap, panel.firstChild);
            panel.insertBefore(emptyNote, searchWrap.nextSibling);
            // Clicks in the search row are not option clicks, and must not
            // reach the document handler that closes the panel.
            searchWrap.addEventListener('click', function(e) { e.stopPropagation(); });
            searchInput.addEventListener('input', applyFilter);
            searchInput.addEventListener('keydown', function(e) {
                if (e.key !== 'Enter') return;
                e.preventDefault();
                // Enter takes the first match, so a unique search never
                // needs the mouse at all.
                var first = optionEls().filter(function(o) { return o.style.display !== 'none'; })[0];
                if (first) first.click();
            });
        }

        function syncSearch() {
            var many = optionEls().length >= SEARCH_MIN_OPTIONS;
            if (many && !searchWrap) buildSearch();
            if (!searchWrap) return;
            searchWrap.hidden = !many;
            searchInput.value = '';
            applyFilter();
        }

        /*
         * Clear (×), only on selects marked data-clearable — the optional
         * ones, where an accidental pick has to be undoable. Required
         * selects with a real default (Gender, Status) get no ×, since
         * there is no valid empty state to clear back to. Rendered as a
         * sibling of the trigger rather than inside it: the trigger is a
         * <button>, and a button cannot contain another one.
         */
        var clearBtn = null;
        var placeholderLabel = (valueSpan && valueSpan.classList.contains('mdbk-select-placeholder'))
            ? valueSpan.textContent : '';

        function setPlaceholder(text) {
            placeholderLabel = text;
            if (!nativeSelect || nativeSelect.value === '') setValue('', text);
        }

        function syncClear() {
            if (!clearBtn) return;
            var hasValue = !!(nativeSelect && nativeSelect.value !== '');
            container.classList.toggle('has-value', hasValue);
        }

        if (container.hasAttribute('data-clearable')) {
            clearBtn = document.createElement('span');
            clearBtn.className = 'mdbk-custom-select-clear';
            clearBtn.setAttribute('role', 'button');
            clearBtn.setAttribute('tabindex', '0');
            var obj = typeof mdbk_form_obj !== 'undefined' ? mdbk_form_obj : {};
            clearBtn.setAttribute('aria-label', obj.i18n_clear || 'Clear selection');
            clearBtn.setAttribute('title', obj.i18n_clear || 'Clear selection');
            clearBtn.textContent = '×';
            trigger.parentNode.insertBefore(clearBtn, trigger.nextSibling);
            clearBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                e.preventDefault();
                setValue('', placeholderLabel);
                close();
                // Cascades the same way a real pick does — clearing
                // District has to empty Thana too.
                if (typeof onChange === 'function') onChange('', placeholderLabel);
            });
            clearBtn.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); clearBtn.click(); }
            });
        }

        function setValue(value, label) {
            if (nativeSelect) nativeSelect.value = value;
            if (valueSpan) {
                valueSpan.textContent = label;
                // Only the clearable (optional) selects have a real empty
                // state; on the rest an empty data-value is a genuine
                // choice ("All ...") and must not be greyed out.
                if (clearBtn) valueSpan.classList.toggle('mdbk-select-placeholder', String(value) === '');
            }
            panel.querySelectorAll('.mdbk-custom-select-option').forEach(function(opt) {
                opt.classList.toggle('selected', opt.getAttribute('data-value') === String(value));
            });
            // Answering the field is what clears its "you missed this" ring.
            if (String(value) !== '') container.classList.remove('mdbk-field-error');
            syncClear();
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
            if (typeof onChange === 'function') onChange(opt.getAttribute('data-value'), opt.textContent);
        });

        syncSearch();
        syncClear();

        return {
            close: close, open: open, setValue: setValue, setPlaceholder: setPlaceholder,
            syncSearch: syncSearch, panel: panel, trigger: trigger, container: container
        };
    }

    // District -> Thana. The full map arrives with the page
    // (mdbk_form_obj.locations), so switching district refills the Thana
    // list with no request; a blank district leaves Thana disabled rather
    // than showing 493 thanas with no district to place them in.
    (function() {
        var districtBox = document.getElementById('mdbk-district-dropdown');
        var thanaBox = document.getElementById('mdbk-thana-dropdown');
        if (!districtBox || !thanaBox) return;
        var thanaSelect = document.getElementById('mdbk-thana-select');
        var thanaPanel = document.getElementById('mdbk-thana-panel');
        var thanaTrigger = document.getElementById('mdbk-thana-trigger');
        var formObj = typeof mdbk_form_obj !== 'undefined' ? mdbk_form_obj : {};
        var locations = formObj.locations || {};

        var thanaInst = initCustomSelect(thanaBox);

        function fillThanas(district) {
            var list = locations[district] || [];
            // Remove only the option rows: the panel also holds the search
            // box initCustomSelect() built into it, which must survive
            // every district change.
            Array.prototype.slice.call(thanaPanel.querySelectorAll('.mdbk-custom-select-option'))
                .forEach(function(opt) { opt.remove(); });
            thanaSelect.innerHTML = '<option value=""></option>';

            list.forEach(function(name) {
                var opt = document.createElement('div');
                opt.className = 'mdbk-custom-select-option';
                opt.setAttribute('role', 'option');
                opt.setAttribute('data-value', name);
                opt.textContent = name;
                thanaPanel.appendChild(opt);

                var native = document.createElement('option');
                native.value = name;
                native.textContent = name;
                thanaSelect.appendChild(native);
            });

            var enabled = list.length > 0;
            thanaBox.classList.toggle('is-disabled', !enabled);
            if (thanaTrigger) thanaTrigger.disabled = !enabled;
            if (thanaInst) {
                thanaInst.close();
                thanaInst.setPlaceholder(!district
                    ? (formObj.i18n_district_first || 'Select district first')
                    : (enabled ? (formObj.i18n_select_thana || 'Select thana')
                               : (formObj.i18n_no_thana || 'No thana found')));
                // The list just changed size — Dhaka wants a search box,
                // Jhalokati's four thanas don't.
                thanaInst.syncSearch();
            }
        }

        initCustomSelect(districtBox, fillThanas);
    })();

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
    var approxTimeNoticeEl = document.getElementById('mdbk-approx-time-notice');
    var approxTimeValueEl = document.getElementById('mdbk-approx-time-value');
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

        // No ticket yet under check-in-order queue mode (assigned only
        // once this patient actually checks in, see PHP's
        // queue_serial_mode()) — the row shows a Booking ID instead until
        // then.
        var ticketLabelEl = document.getElementById('mdbk-conf-ticket-label');
        if (booking.ticket) {
            if (ticketLabelEl) ticketLabelEl.textContent = 'Ticket';
            document.getElementById('mdbk-conf-ticket').textContent = booking.ticket;
        } else {
            if (ticketLabelEl) ticketLabelEl.textContent = 'Booking ID';
            document.getElementById('mdbk-conf-ticket').textContent = booking.booking_id || '';
        }
        document.getElementById('mdbk-conf-patient').textContent = booking.patient_name || '';
        document.getElementById('mdbk-conf-doctor').textContent = booking.doctor_name || '';
        document.getElementById('mdbk-conf-date').textContent = booking.date || '';

        // A hidden-picker doctor's assigned time was never chosen by the
        // patient (it's find_next_available_slot()'s pick, server-side) —
        // shown as the same "approximate" notice the pre-booking preview
        // used instead of the plain Time row, which would otherwise read
        // as a firm, patient-chosen appointment time.
        var timeRow = document.getElementById('mdbk-conf-time-row');
        var confApproxEl = document.getElementById('mdbk-conf-approx-time-notice');
        var confApproxValueEl = document.getElementById('mdbk-conf-approx-time-value');
        if (booking.slot_time && !currentDoctorSlotEnabled) {
            if (timeRow) timeRow.style.display = 'none';
            if (confApproxValueEl) confApproxValueEl.textContent = 'Approximate visiting time: ' + booking.slot_time;
            if (confApproxEl) confApproxEl.style.display = 'flex';
        } else {
            if (confApproxEl) confApproxEl.style.display = 'none';
            if (booking.slot_time) {
                document.getElementById('mdbk-conf-time').textContent = booking.slot_time;
                if (timeRow) timeRow.style.display = '';
            } else if (timeRow) {
                timeRow.style.display = 'none';
            }
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
            if (!currentBooking) return;
            var qrImg = confQrEl ? confQrEl.querySelector('img') : null;
            mdbkPrintBookingCard(currentBooking, qrImg ? qrImg.src : '');
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
                // Mirrors the slot-enabled branch above (loadModalSlots's own
                // callback calls both of these) — without collapsing the
                // calendar+time column into the compact "Selected: ..."
                // summary here too, they stayed fully expanded for a
                // slot-disabled doctor, pushing the patient details form far
                // down the page and making the whole modal scroll instead of
                // just the picker.
                showSerialBookingNotice();
                showApproxTimeNotice(doctorIdInput.value, selectedDateStr);
                showDetails();
                showDatetimeSummary();
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

    function hideApproxTimeNotice() {
        if (approxTimeNoticeEl) approxTimeNoticeEl.style.display = 'none';
        if (approxTimeValueEl) approxTimeValueEl.textContent = '';
    }

    /**
     * A hidden-picker doctor's patient never chooses an exact time — this
     * previews the time they'd likely be seen at (the same
     * find_next_available_slot() logic PHP uses to assign the real one on
     * submit), so they aren't left with zero time expectation until the
     * confirmation screen. Reuses the same mdbk_get_doctor_slots endpoint
     * loadSlotsInto() already calls for the visible-picker case — just
     * reads the first available entry instead of rendering a button grid.
     * Silently hides the notice (no error UI) if nothing comes back
     * available; the actual booking attempt is what surfaces that properly.
     */
    function showApproxTimeNotice(doctorId, dateStr) {
        if (!approxTimeNoticeEl || !approxTimeValueEl || !doctorId || !dateStr) {
            hideApproxTimeNotice();
            return;
        }
        var formData = new FormData();
        formData.append('action', 'mdbk_get_doctor_slots');
        formData.append('doctor_id', doctorId);
        formData.append('date', dateStr);
        formData.append('nonce', mdbk_form_obj.nonce);

        fetch(mdbk_form_obj.ajax_url, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var slots = (data.success && data.data) ? data.data : [];
            var next = slots.find(function(s) { return s.available; });
            if (!next) {
                hideApproxTimeNotice();
                return;
            }
            approxTimeValueEl.textContent = 'Approximate visiting time: ' + formatTime12h(next.time);
            approxTimeNoticeEl.style.display = 'flex';
        })
        .catch(hideApproxTimeNotice);
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
        hideApproxTimeNotice();
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

    /**
     * "Today's Patients" button on the doctor card (shortcode.php's
     * render_doctor_list()) — only rendered at all for a doctor working
     * today AND a logged-in staff/manager/admin/doctor viewer (see
     * $can_see_today_patients there), and the AJAX handler itself
     * (ajax_get_today_patient_summary() in appointment-manager.php) is
     * gated the same way server-side, not just hidden client-side. Opens
     * the one shared modal (render_today_patients_modal()) and populates
     * it with the doctor's full today's-patient list — names are fine
     * here since this is staff-only, not the public feature it started
     * as. Re-fetches fresh on every open rather than caching, so the list
     * never goes stale while browsing.
     */
    var todayPatientsModal = document.getElementById('mdbk-today-patients-modal');
    var todayPatientsList = document.getElementById('mdbk-today-patients-list');
    var todayPatientsTitle = document.getElementById('mdbk-today-patients-modal-title');

    function closeTodayPatientsModal() {
        if (todayPatientsModal) todayPatientsModal.style.display = 'none';
    }
    if (todayPatientsModal) {
        var todayPatientsCloseBtn = document.getElementById('mdbk-today-patients-modal-close');
        if (todayPatientsCloseBtn) todayPatientsCloseBtn.addEventListener('click', closeTodayPatientsModal);
        todayPatientsModal.addEventListener('click', function(e) {
            if (e.target === todayPatientsModal) closeTodayPatientsModal();
        });
    }

    document.addEventListener('click', function(e) {
        var trigger = e.target.closest('.mdbk-today-patients-trigger');
        if (!trigger || !todayPatientsModal || !todayPatientsList) return;
        e.preventDefault();
        var doctorId = trigger.getAttribute('data-mdbk-doctor-id');
        var doctorName = trigger.getAttribute('data-mdbk-doctor-name') || '';

        if (todayPatientsTitle) {
            todayPatientsTitle.textContent = doctorName ? (doctorName + ' — Today’s Patients') : 'Today’s Patients';
        }
        todayPatientsList.innerHTML = '<p class="mdbk-today-patients-loading">Loading...</p>';
        todayPatientsModal.style.display = 'flex';

        var formData = new FormData();
        formData.append('action', 'mdbk_get_today_patient_summary');
        formData.append('doctor_id', doctorId);
        formData.append('nonce', mdbk_form_obj.nonce);

        fetch(mdbk_form_obj.ajax_url, { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) {
                    todayPatientsList.innerHTML = '<p class="mdbk-today-patients-loading">Could not load today’s patients.</p>';
                    return;
                }
                var d = data.data;
                if (!d.patients.length) {
                    todayPatientsList.innerHTML = '<p class="mdbk-today-patients-loading">No patients today.</p>';
                    return;
                }
                var rows = d.patients.map(function(p) {
                    // No ticket yet under check-in-order queue mode (assigned
                    // only once this patient actually checks in) — the
                    // Booking ID fills the slot instead of a blank one.
                    var numberDisplay = p.ticket || p.booking_id || '';
                    return '<div class="mdbk-today-patient-row">' +
                        '<span class="mdbk-today-patient-ticket">' + mdbkEscHtml(numberDisplay) + '</span>' +
                        '<span class="mdbk-today-patient-name">' + mdbkEscHtml(p.patient_name) + '</span>' +
                        '<span class="mdbk-today-patient-time">' + mdbkEscHtml(p.time) + '</span>' +
                        '<span class="mdbk-today-patient-status mdbk-status-' + mdbkEscHtml(p.status_slug) + '">' + mdbkEscHtml(p.status_label) + '</span>' +
                        '</div>';
                }).join('');
                todayPatientsList.innerHTML =
                    '<div class="mdbk-today-patients-summary">' + d.total + (d.total === 1 ? ' patient today' : ' patients today') +
                    ' — ' + d.waiting + ' waiting, ' + d.serving + ' being seen, ' + d.completed + ' completed</div>' +
                    '<div class="mdbk-today-patients-rows">' + rows + '</div>';
            })
            .catch(function() {
                todayPatientsList.innerHTML = '<p class="mdbk-today-patients-loading">Could not load today’s patients.</p>';
            });
    });

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

            // District/Thana are only required when Global Settings >
            // Booking Form Fields says so (mdbk_form_obj.address_required —
            // see MDBK_Appointment_Manager::field_settings()); when the
            // field is hidden entirely, the elements below don't exist in
            // the DOM at all (render_booking_widget_fields()'s own PHP
            // conditional), so the null checks already skip this. Their
            // real controls are display:none <select>s either way — the
            // browser cannot focus those to report a native validation
            // message, so this is checked here instead (the server checks
            // it again regardless). Age/Email/Gender's required-ness is
            // instead expressed as a plain `required` attribute (present
            // only when their own setting says so) and never reach this
            // point unfilled.
            var missing = null;
            if (mdbk_form_obj.address_required) {
                var districtSelect = document.getElementById('mdbk-district-select');
                var thanaSelect = document.getElementById('mdbk-thana-select');
                if (districtSelect && !districtSelect.value) {
                    missing = document.getElementById('mdbk-district-dropdown');
                } else if (thanaSelect && !thanaSelect.value) {
                    missing = document.getElementById('mdbk-thana-dropdown');
                }
            }
            [document.getElementById('mdbk-district-dropdown'), document.getElementById('mdbk-thana-dropdown')]
                .forEach(function(box) { if (box) box.classList.remove('mdbk-field-error'); });
            if (missing) {
                missing.classList.add('mdbk-field-error');
                if (msgBox) {
                    msgBox.className = 'mdbk-modal-message mdbk-error';
                    msgBox.textContent = (mdbk_form_obj.i18n_location_required || 'Please choose a district and thana.');
                }
                missing.scrollIntoView({ block: 'center', behavior: 'smooth' });
                return;
            }

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
