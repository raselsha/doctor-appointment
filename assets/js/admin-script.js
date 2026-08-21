document.addEventListener('DOMContentLoaded', function() {

    // Mobile off-canvas sidebar (see admin-style.css's matching
    // @media (max-width: 782px) block) — every .mdbk-menu-item link is a
    // real navigation (onclick="window.location.href=..."), so the drawer
    // never needs to be closed programmatically on menu selection, only
    // on the backdrop tap.
    (function() {
        const toggle = document.getElementById('mdbk-mobile-menu-toggle');
        const sidebar = document.getElementById('mdbk-sidebar');
        const backdrop = document.getElementById('mdbk-sidebar-backdrop');
        if (!toggle || !sidebar || !backdrop) return;
        function closeSidebar() {
            sidebar.classList.remove('is-open');
            backdrop.classList.remove('is-open');
            toggle.classList.remove('is-open');
        }
        toggle.addEventListener('click', function() {
            const isOpen = sidebar.classList.toggle('is-open');
            backdrop.classList.toggle('is-open', isOpen);
            toggle.classList.toggle('is-open', isOpen);
        });
        backdrop.addEventListener('click', closeSidebar);
    })();

    // Booking page's filters bar (date nav/search/doctor+status filters) —
    // a plain <details>/<summary> (same collapse mechanism as each doctor
    // group below it) instead of the earlier scroll-direction auto-hide
    // attempt, which reopened every time the user scrolled up even a
    // little and gave no way to just leave it collapsed. Persisted per
    // browser (not per page load) via localStorage, same pattern as the
    // Doctors page's own grid/list view toggle, so a staff member who
    // collapses it once doesn't have to redo it on every visit.
    (function() {
        const bar = document.getElementById('mdbk-filters-bar');
        if (!bar) return;
        const collapsed = localStorage.getItem('mdbk_filters_bar_collapsed') === '1';
        if (collapsed) bar.removeAttribute('open');
        bar.addEventListener('toggle', function() {
            localStorage.setItem('mdbk_filters_bar_collapsed', bar.open ? '0' : '1');
        });

        // Tablet/desktop (see admin-style.css's @media (min-width: 783px))
        // makes the bar sticky again instead of collapsible, which means
        // .mdbk-schedule-queue-scroll-wrap's own per-section sticky
        // headers (.mdbk-card-header) need to stick just BELOW it rather
        // than at the same top:0 — otherwise the two sticky elements
        // would paint on top of each other. Measures the bar's own actual
        // rendered height (0 below the breakpoint, where it isn't sticky
        // at all) rather than assuming a fixed number, since the filter
        // form's own row can wrap to more than one line depending on
        // exactly how much width is available.
        const scrollWrap = document.querySelector('.mdbk-schedule-queue-scroll-wrap');
        if (scrollWrap) {
            let resizeTimer;
            function updateFiltersBarHeight() {
                const isDesktop = window.innerWidth >= 783;
                const height = isDesktop ? bar.getBoundingClientRect().height : 0;
                scrollWrap.style.setProperty('--mdbk-filters-bar-height', height + 'px');
            }
            updateFiltersBarHeight();
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(updateFiltersBarHeight, 120);
            });
        }
    })();

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

    function mdbkAdminEscHtml(str) {
        var div = document.createElement('div');
        div.textContent = String(str || '');
        return div.innerHTML;
    }

    function mdbkFormatInvoiceAmount(amount) {
        var n = parseFloat(amount);
        return isNaN(n) ? mdbkAdminEscHtml(amount) : n.toFixed(2);
    }

    /**
     * A proper single-page A4 portrait invoice — two-column letterhead
     * (clinic left, INVOICE/number/date/status right), a Bill To block,
     * a real line-item table with a Total row, and a footer — instead of
     * the earlier centered receipt-card look, which read fine for a small
     * QR booking confirmation but not for something meant to be filed or
     * handed to a patient as a corporate document. Same standalone-
     * print-window technique form-script.js's own mdbkPrintBookingCard()
     * uses (a fresh, empty document instead of window.print() on the live
     * page, which kept the modal's own centered-overlay vertical offset
     * baked into the printout there too). mdbk_admin_obj already carries
     * the same clinic_name/logo/contact/address fields as the frontend's
     * mdbk_form_obj (see clinic_branding_data() in doctor-appointment.php).
     */
    function mdbkPrintInvoice(details) {
        var obj = typeof mdbk_admin_obj !== 'undefined' ? mdbk_admin_obj : {};
        var isPaid = details.status === 'paid';
        var statusLabel = isPaid ? 'PAID' : 'UNPAID';
        var statusColor = isPaid ? '#15803d' : '#b91c1c';
        var statusBg = isPaid ? '#dcfce7' : '#fee2e2';

        var letterheadLeft =
            '<div class="mdbk-inv-clinic">' +
            (obj.clinic_logo ? '<img class="mdbk-inv-logo" src="' + obj.clinic_logo + '" alt="">' : '') +
            (obj.clinic_name ? '<div class="mdbk-inv-clinic-name">' + mdbkAdminEscHtml(obj.clinic_name) + '</div>' : '') +
            (obj.clinic_contact ? '<div class="mdbk-inv-clinic-meta">' + mdbkAdminEscHtml(obj.clinic_contact) + '</div>' : '') +
            (obj.clinic_address ? '<div class="mdbk-inv-clinic-meta">' + mdbkAdminEscHtml(obj.clinic_address) + '</div>' : '') +
            '</div>';

        var letterheadRight =
            '<div class="mdbk-inv-title-block">' +
            '<div class="mdbk-inv-title">INVOICE</div>' +
            '<div class="mdbk-inv-meta-row"><span>Invoice No.</span><strong>' + mdbkAdminEscHtml(details.invoice_number) + '</strong></div>' +
            '<div class="mdbk-inv-meta-row"><span>Date</span><strong>' + mdbkAdminEscHtml(details.date) + '</strong></div>' +
            '<div class="mdbk-inv-status" style="color:' + statusColor + ';background:' + statusBg + ';">' + statusLabel + '</div>' +
            '</div>';

        var billTo =
            '<div class="mdbk-inv-bill-to">' +
            '<div class="mdbk-inv-label">Bill To</div>' +
            '<div class="mdbk-inv-patient-name">' + mdbkAdminEscHtml(details.patient_name) + '</div>' +
            (details.patient_phone ? '<div class="mdbk-inv-clinic-meta">' + mdbkAdminEscHtml(details.patient_phone) + '</div>' : '') +
            '</div>';

        var amountFormatted = mdbkFormatInvoiceAmount(details.amount);
        var table =
            '<table class="mdbk-inv-table">' +
            '<thead><tr><th>Description</th><th class="mdbk-inv-amount-col">Amount</th></tr></thead>' +
            '<tbody><tr><td>Consultation Fee — ' + mdbkAdminEscHtml(details.doctor_name) + '</td><td class="mdbk-inv-amount-col">৳' + amountFormatted + '</td></tr></tbody>' +
            '<tfoot><tr><td>Total</td><td class="mdbk-inv-amount-col">৳' + amountFormatted + '</td></tr></tfoot>' +
            '</table>';

        var footer =
            '<div class="mdbk-inv-footer">' +
            (obj.clinic_name ? '<p>Thank you for choosing ' + mdbkAdminEscHtml(obj.clinic_name) + '.</p>' : '') +
            '<p class="mdbk-inv-footer-note">This is a computer-generated invoice and does not require a signature.</p>' +
            '</div>';

        var win = window.open('', '_blank', 'width=720,height=920');
        if (!win) return;
        win.document.write(
            '<html><head><title>Invoice ' + mdbkAdminEscHtml(details.invoice_number) + '</title><style>' +
            '@page{size:A4 portrait;margin:18mm;}' +
            '*{box-sizing:border-box;}' +
            'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;margin:0;padding:32px;color:#1e293b;}' +
            '.mdbk-inv-letterhead{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;padding-bottom:24px;border-bottom:2px solid #1e293b;}' +
            '.mdbk-inv-logo{max-width:56px;max-height:56px;display:block;margin-bottom:8px;}' +
            '.mdbk-inv-clinic-name{font-size:18px;font-weight:800;margin-bottom:4px;}' +
            '.mdbk-inv-clinic-meta{font-size:12px;color:#64748b;line-height:1.6;}' +
            '.mdbk-inv-title-block{text-align:right;}' +
            '.mdbk-inv-title{font-size:24px;font-weight:800;letter-spacing:2px;color:#1e293b;margin-bottom:10px;}' +
            '.mdbk-inv-meta-row{font-size:13px;margin-bottom:4px;}' +
            '.mdbk-inv-meta-row span{color:#64748b;margin-right:10px;}' +
            '.mdbk-inv-meta-row strong{color:#1e293b;}' +
            '.mdbk-inv-status{display:inline-block;margin-top:8px;padding:4px 14px;border-radius:4px;font-size:12px;font-weight:800;letter-spacing:1px;}' +
            '.mdbk-inv-bill-to{margin:28px 0;}' +
            '.mdbk-inv-label{font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#94a3b8;margin-bottom:6px;}' +
            '.mdbk-inv-patient-name{font-size:16px;font-weight:700;margin-bottom:2px;}' +
            '.mdbk-inv-table{width:100%;border-collapse:collapse;margin-top:8px;}' +
            '.mdbk-inv-table thead th{text-align:left;font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:#64748b;padding:0 0 10px;border-bottom:1.5px solid #1e293b;}' +
            '.mdbk-inv-table tbody td{padding:16px 0;font-size:14px;border-bottom:1px solid #e2e8f0;}' +
            '.mdbk-inv-table tfoot td{padding:14px 0 0;font-size:15px;font-weight:800;}' +
            '.mdbk-inv-amount-col{text-align:right;white-space:nowrap;}' +
            '.mdbk-inv-footer{margin-top:56px;padding-top:16px;border-top:1px solid #e2e8f0;text-align:center;}' +
            '.mdbk-inv-footer p{margin:0 0 4px;font-size:12px;color:#64748b;}' +
            '.mdbk-inv-footer-note{font-style:italic;}' +
            '</style></head><body>' +
            '<div class="mdbk-inv-letterhead">' + letterheadLeft + letterheadRight + '</div>' +
            billTo +
            table +
            footer +
            '</body></html>'
        );
        win.document.close();
        win.focus();
        win.print();
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
    initReorderModal('mdbk-open-specialty-reorder', 'mdbk-reorder-modal-specialty', 'mdbk_save_specialty_order');

    // +/- stepper buttons around a number input (.mdbk-stepper, e.g. the
    // Doctor modal's Consultation Fee) — reads data-step (falls back to the
    // input's own native step, then 1) so the click increment can be a
    // friendlier round number than whatever `step` the input needs for
    // native decimal validation. Delegated at the document level since
    // .mdbk-stepper can live inside any modal, present or future.
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.mdbk-stepper-minus, .mdbk-stepper-plus');
        if (!btn) return;
        e.preventDefault();
        const input = btn.parentElement.querySelector('input[type="number"]');
        if (!input || input.disabled) return;
        const step = parseFloat(input.dataset.step || input.step || '1') || 1;
        const min = input.min !== '' ? parseFloat(input.min) : -Infinity;
        const max = input.max !== '' ? parseFloat(input.max) : Infinity;
        let val = parseFloat(input.value) || 0;
        val = btn.classList.contains('mdbk-stepper-plus') ? val + step : val - step;
        val = Math.min(max, Math.max(min, val));
        // Rounds away floating-point noise (0.1 + 0.2 = 0.30000000000000004).
        input.value = Math.round(val * 100) / 100;
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });

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
        const perPageSelect = document.getElementById('mdbk-patients-per-page');
        let debounceTimer;
        let currentPage = 1;

        function runSearch() {
            const body = new URLSearchParams();
            body.set('action', 'mdbk_search_patients');
            body.set('nonce', mdbk_admin_obj.nonce);
            body.set('s', searchInput.value);
            body.set('filter_gender', genderSelect ? genderSelect.value : '');
            body.set('paged', currentPage);
            body.set('per_page', perPageSelect ? perPageSelect.value : 25);
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
            currentPage = 1;
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(runSearch, 300);
        });
        if (genderSelect) genderSelect.addEventListener('change', function() {
            currentPage = 1;
            clearTimeout(debounceTimer);
            runSearch();
        });
        if (perPageSelect) perPageSelect.addEventListener('change', function() {
            currentPage = 1;
            clearTimeout(debounceTimer);
            runSearch();
        });
        if (clearBtn) clearBtn.addEventListener('click', function(e) {
            e.preventDefault();
            searchInput.value = '';
            if (genderSelect) genderSelect.value = '';
            currentPage = 1;
            clearTimeout(debounceTimer);
            runSearch();
        });

        // Pagination buttons live inside #mdbk-patients-results, which this
        // same runSearch() wholesale-replaces on every keystroke/filter —
        // delegated on document (not bound once per button) so a page
        // rendered by an earlier AJAX response still responds to clicks.
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.mdbk-patients-page-btn');
            if (!btn || btn.disabled) return;
            e.preventDefault();
            const page = parseInt(btn.dataset.page, 10);
            if (!page || page < 1) return;
            currentPage = page;
            runSearch();
            if (resultsEl) resultsEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
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
        const analyticsEl = document.getElementById('mdbk-schedule-analytics');
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
                    // Only present for the Today view (analytics don't exist
                    // on the other 3 branches) — leaving a stale grid up on
                    // those isn't a concern since #mdbk-schedule-analytics
                    // itself only ever renders inside the Today view's markup.
                    if (analyticsEl && res.data.analytics_html !== undefined) analyticsEl.innerHTML = res.data.analytics_html;
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

    // Booking page's own date-filter nav — same modern popover calendar as
    // the Add/Edit Booking modal's Date field, replacing the browser's
    // native date input. No doctor-availability graying here (unlike the
    // booking modal) since this is for browsing to ANY date's bookings,
    // past ones included — not picking a slot to book. Selecting a day
    // just submits the existing GET filters form, carrying forward
    // whatever search/doctor/status filters are already set, same as the
    // native input's own onchange="this.form.submit()" it replaces.
    (function() {
        const selectEl = document.getElementById('mdbk-schedule-date-select');
        const trigger = document.getElementById('mdbk-schedule-date-trigger');
        const panel = document.getElementById('mdbk-schedule-date-panel');
        const hiddenInput = document.getElementById('mdbk-schedule-date-hidden');
        if (!selectEl || !trigger || !panel || !hiddenInput || typeof mdbk_admin_obj === 'undefined') return;

        const today = parseServerDate(mdbk_admin_obj.today);
        let selectedDateStr = hiddenInput.value || '';
        let viewYear = today.getFullYear();
        let viewMonth = today.getMonth();
        if (selectedDateStr) {
            const parts = selectedDateStr.split('-').map(Number);
            viewYear = parts[0];
            viewMonth = parts[1] - 1;
        }

        function pad2(n) { return String(n).padStart(2, '0'); }
        function todayStr() { return today.getFullYear() + '-' + pad2(today.getMonth() + 1) + '-' + pad2(today.getDate()); }

        function render() {
            const firstDay = new Date(viewYear, viewMonth, 1).getDay();
            const days = new Date(viewYear, viewMonth + 1, 0).getDate();
            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
            const tStr = todayStr();
            let html = '<div class="mdbk-mini-cal-nav">' +
                '<button type="button" class="mdbk-mini-cal-nav-btn" data-action="prev">&lsaquo;</button>' +
                '<span class="mdbk-mini-cal-title">' + monthNames[viewMonth] + ' ' + viewYear + '</span>' +
                '<button type="button" class="mdbk-mini-cal-nav-btn" data-action="next">&rsaquo;</button>' +
                '</div><div class="mdbk-mini-cal-grid">';
            ['S', 'M', 'T', 'W', 'T', 'F', 'S'].forEach(function(l) { html += '<span class="mdbk-mini-cal-day-header">' + l + '</span>'; });
            for (let i = 0; i < firstDay; i++) html += '<span class="mdbk-mini-cal-day empty"></span>';
            for (let d = 1; d <= days; d++) {
                const dateStr = viewYear + '-' + pad2(viewMonth + 1) + '-' + pad2(d);
                let classes = 'mdbk-mini-cal-day';
                if (dateStr === tStr) classes += ' today';
                if (dateStr === selectedDateStr) classes += ' selected';
                html += '<span class="' + classes + '" data-date="' + dateStr + '">' + d + '</span>';
            }
            html += '</div>';
            panel.innerHTML = html;
        }

        // Same fixed-position + flip-up-if-no-room approach as the booking
        // modal's calendar (initAppDateCalendar()) — see its comment for why
        // plain absolute positioning isn't reliable inside a scrolling
        // ancestor (here, .mdbk-schedule-queue-scroll-wrap on the Today view).
        function reposition() {
            const rect = trigger.getBoundingClientRect();
            const panelHeight = panel.offsetHeight;
            const spaceBelow = window.innerHeight - rect.bottom;
            if (spaceBelow < panelHeight + 10 && rect.top > panelHeight + 10) {
                panel.style.top = (rect.top - panelHeight - 6) + 'px';
            } else {
                panel.style.top = (rect.bottom + 6) + 'px';
            }
            panel.style.left = rect.left + 'px';
        }
        function open() {
            panel.style.position = 'fixed';
            panel.style.display = 'block';
            reposition();
            selectEl.classList.add('open');
        }
        function close() { selectEl.classList.remove('open'); panel.style.display = 'none'; }

        trigger.addEventListener('click', function(e) {
            e.preventDefault();
            selectEl.classList.contains('open') ? close() : open();
        });

        panel.addEventListener('click', function(e) {
            // See initAppDateCalendar()'s identical stopPropagation() note —
            // render() below replaces panel.innerHTML, which would otherwise
            // detach the clicked Prev/Next button before this click finishes
            // bubbling to the document-level "close on outside click" below.
            e.stopPropagation();
            const navBtn = e.target.closest('.mdbk-mini-cal-nav-btn');
            if (navBtn) {
                if (navBtn.dataset.action === 'prev') { viewMonth--; if (viewMonth < 0) { viewMonth = 11; viewYear--; } }
                else { viewMonth++; if (viewMonth > 11) { viewMonth = 0; viewYear++; } }
                render();
                return;
            }
            const dayEl = e.target.closest('.mdbk-mini-cal-day');
            if (!dayEl || dayEl.classList.contains('empty')) return;
            hiddenInput.value = dayEl.getAttribute('data-date');
            hiddenInput.form.submit();
        });

        document.addEventListener('click', function(e) { if (!selectEl.contains(e.target)) close(); });
        document.addEventListener('keydown', function(e) { if (e.key === 'Escape') close(); });
        const scrollParent = selectEl.closest('.mdbk-schedule-queue-scroll-wrap');
        if (scrollParent) scrollParent.addEventListener('scroll', function() {
            if (selectEl.classList.contains('open')) reposition();
        });

        render();
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
            // Always opens on today's month, whether or not this doctor
            // already has extra/off dates saved — jumping to the first
            // existing date's month instead (the old behavior) meant a
            // doctor whose only saved date was months back opened the
            // editor on an all-past month with nothing left to click,
            // hiding the actual current month the admin almost always
            // wants (to add a new date). Existing dates still show up via
            // the .selected class the moment their own month is viewed.
            setSelected: function(dates) {
                selected = Array.isArray(dates) ? dates.slice() : [];
                viewYear = today.getFullYear();
                viewMonth = today.getMonth();
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
            var docFee = document.getElementById('mdbk-doc-fee');
            if (docFee) docFee.value = row.dataset.fee || '';
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
            var addDocFee = document.getElementById('mdbk-doc-fee');
            if (addDocFee) addDocFee.value = '';
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

    // Read-only "View Patient" popup (contact details + full visit
    // history) — content is fetched fresh on every open via
    // ajax_view_patient(), not a form, so this doesn't go through
    // initModal()'s add/edit/reset handling above; same
    // fetch-then-swap-innerHTML shape the public booking form's own
    // "Today's Patients" popup already uses.
    (function() {
        var viewModal = document.getElementById('mdbk-patient-view-modal');
        var viewTitle = document.getElementById('mdbk-patient-view-modal-title');
        var viewBody = document.getElementById('mdbk-patient-view-modal-body');
        if (!viewModal || !viewBody || typeof mdbk_admin_obj === 'undefined') return;

        function closeViewModal() { viewModal.style.display = 'none'; }
        var viewCloseBtn = viewModal.querySelector('.mdbk-modal-close');
        if (viewCloseBtn) viewCloseBtn.addEventListener('click', closeViewModal);
        window.addEventListener('click', function(e) { if (e.target === viewModal) closeViewModal(); });

        document.addEventListener('click', function(e) {
            var trigger = e.target.closest('.mdbk-view-patient');
            if (!trigger) return;
            e.preventDefault();

            if (viewTitle) viewTitle.textContent = 'Visit History';
            viewBody.innerHTML = '<p style="text-align:center; opacity:.6; padding:30px 0;">Loading...</p>';
            viewModal.style.display = 'flex';

            var body = new URLSearchParams();
            body.set('action', 'mdbk_view_patient');
            body.set('nonce', mdbk_admin_obj.nonce);
            body.set('patient_id', trigger.dataset.id);
            fetch(mdbk_admin_obj.ajax_url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (!res || !res.success) {
                        viewBody.innerHTML = '<p style="text-align:center; opacity:.6; padding:30px 0;">Could not load this patient.</p>';
                        return;
                    }
                    if (viewTitle) viewTitle.textContent = res.data.title;
                    viewBody.innerHTML = res.data.body_html;
                })
                .catch(function() {
                    viewBody.innerHTML = '<p style="text-align:center; opacity:.6; padding:30px 0;">Something went wrong.</p>';
                });
        });
    })();

    // Invoice popup — .mdbk-open-invoice trigger on the Booking page's
    // per-appointment rows. Loads current amount/status (or the doctor's
    // default fee, for an appointment with no invoice saved yet) via AJAX
    // on open; Save persists it, Print always prints whatever is currently
    // in the form (mdbkPrintInvoice(), defined above) whether or not it's
    // been saved.
    (function() {
        var invModal = document.getElementById('mdbk-invoice-modal');
        if (!invModal || typeof mdbk_admin_obj === 'undefined') return;

        var invNumber = document.getElementById('mdbk-invoice-number');
        var invDate = document.getElementById('mdbk-invoice-date');
        var invPatient = document.getElementById('mdbk-invoice-patient');
        var invDoctor = document.getElementById('mdbk-invoice-doctor');
        var invAmount = document.getElementById('mdbk-invoice-amount');
        var invStatusBtns = invModal.querySelectorAll('.mdbk-invoice-status-btn');
        var invSaveMsg = document.getElementById('mdbk-invoice-save-msg');
        var invSaveBtn = document.getElementById('mdbk-invoice-save');
        var invPrintBtn = document.getElementById('mdbk-invoice-print');
        var currentAppointmentId = null;
        var currentPatientPhone = '';

        function closeInvModal() { invModal.style.display = 'none'; }
        var invCloseBtn = invModal.querySelector('.mdbk-modal-close');
        if (invCloseBtn) invCloseBtn.addEventListener('click', closeInvModal);
        window.addEventListener('click', function(e) { if (e.target === invModal) closeInvModal(); });

        function setInvoiceStatus(status) {
            invStatusBtns.forEach(function(btn) {
                btn.classList.toggle('is-active', btn.dataset.status === status);
            });
        }
        function currentInvoiceStatus() {
            var active = invModal.querySelector('.mdbk-invoice-status-btn.is-active');
            return active ? active.dataset.status : 'unpaid';
        }
        invStatusBtns.forEach(function(btn) {
            btn.addEventListener('click', function() { setInvoiceStatus(btn.dataset.status); });
        });

        document.addEventListener('click', function(e) {
            var trigger = e.target.closest('.mdbk-open-invoice');
            if (!trigger) return;
            e.preventDefault();
            currentAppointmentId = trigger.dataset.id;

            invSaveMsg.style.display = 'none';
            invNumber.textContent = '…';
            invDate.textContent = invPatient.textContent = invDoctor.textContent = '—';
            invAmount.value = '';
            setInvoiceStatus('unpaid');
            invModal.style.display = 'flex';

            var body = new URLSearchParams();
            body.set('action', 'mdbk_get_invoice');
            body.set('nonce', mdbk_admin_obj.nonce);
            body.set('appointment_id', currentAppointmentId);
            fetch(mdbk_admin_obj.ajax_url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (!res || !res.success) { invNumber.textContent = '—'; return; }
                    invNumber.textContent = res.data.invoice_number;
                    invDate.textContent = res.data.date_display;
                    invPatient.textContent = res.data.patient_name;
                    invDoctor.textContent = res.data.doctor_name;
                    invAmount.value = res.data.amount;
                    currentPatientPhone = res.data.patient_phone || '';
                    setInvoiceStatus(res.data.status);
                })
                .catch(function() { invNumber.textContent = '—'; });
        });

        if (invSaveBtn) invSaveBtn.addEventListener('click', function() {
            if (!currentAppointmentId) return;
            var body = new URLSearchParams();
            body.set('action', 'mdbk_save_invoice');
            body.set('nonce', mdbk_admin_obj.nonce);
            body.set('appointment_id', currentAppointmentId);
            body.set('amount', invAmount.value);
            body.set('status', currentInvoiceStatus());
            invSaveBtn.disabled = true;
            fetch(mdbk_admin_obj.ajax_url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    invSaveBtn.disabled = false;
                    invSaveMsg.style.display = 'block';
                    invSaveMsg.style.color = (res && res.success) ? '#16a34a' : '#dc2626';
                    invSaveMsg.textContent = (res && res.success) ? 'Invoice saved.' : 'Could not save invoice.';
                })
                .catch(function() {
                    invSaveBtn.disabled = false;
                    invSaveMsg.style.display = 'block';
                    invSaveMsg.style.color = '#dc2626';
                    invSaveMsg.textContent = 'Could not save invoice.';
                });
        });

        if (invPrintBtn) invPrintBtn.addEventListener('click', function() {
            mdbkPrintInvoice({
                invoice_number: invNumber.textContent,
                date: invDate.textContent,
                patient_name: invPatient.textContent,
                patient_phone: currentPatientPhone,
                doctor_name: invDoctor.textContent,
                amount: invAmount.value,
                status: currentInvoiceStatus()
            });
        });
    })();

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
    // by the server) — the slot picker means nothing for them, so it's
    // hidden and a hint takes its place, mirroring the Doctor modal's own
    // Slot Duration field.
    function updateAppSlotTimeAvailability(selectedOpt) {
        const picker = document.getElementById('mdbk-app-slot-picker');
        const hint = document.getElementById('mdbk-app-slot-hint');
        const valueEl = document.getElementById('mdbk-app-slot-time');
        if (!picker) return;
        const slotEnabled = !selectedOpt || selectedOpt.dataset.slotEnabled !== 'no';
        picker.style.display = slotEnabled ? '' : 'none';
        if (hint) hint.style.display = slotEnabled ? 'none' : '';
        if (!slotEnabled && valueEl) valueEl.value = '';
    }

    // Fetches and renders the Add/Edit Booking modal's clickable slot
    // grid — the admin-side equivalent of form-script.js's loadSlotsInto()
    // for the public booking widget, reusing the same mdbk_get_doctor_slots
    // endpoint/nonce and the same .mdbk-slot-btn markup/classes so both
    // pickers look and behave identically. preselectTime/excludeId are
    // Edit-mode only: excludeId (this appointment's own ID) keeps its
    // current slot out of the "already booked" count, and preselectTime
    // marks that same slot as chosen instead of auto-picking the first
    // open one like a fresh "Add Booking" does.
    let appSlotToken = 0;
    function loadAppSlots(doctorId, dateStr, preselectTime, excludeId) {
        const picker = document.getElementById('mdbk-app-slot-picker');
        const valueEl = document.getElementById('mdbk-app-slot-time');
        if (!picker || !valueEl) return;
        valueEl.value = '';

        if (!doctorId || !dateStr) {
            picker.classList.add('mdbk-slot-picker-disabled');
            picker.innerHTML = '<p class="mdbk-time-placeholder">Select a date first</p>';
            return;
        }

        picker.classList.remove('mdbk-slot-picker-disabled');
        picker.innerHTML = '<div class="mdbk-slot-loading">Loading times...</div>';

        const token = ++appSlotToken;
        const body = new URLSearchParams();
        body.set('action', 'mdbk_get_doctor_slots');
        body.set('doctor_id', doctorId);
        body.set('date', dateStr);
        body.set('nonce', mdbk_admin_obj.form_nonce);
        if (excludeId) body.set('exclude_id', excludeId);

        fetch(mdbk_admin_obj.ajax_url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (token !== appSlotToken) return;
                const slots = (res && res.success && res.data) ? res.data : [];
                if (!slots.length) {
                    picker.innerHTML = '<div class="mdbk-no-slots">No time slots available on this date.</div>';
                    return;
                }

                function selectSlot(btn, time) {
                    const prev = picker.querySelector('.mdbk-slot-btn.selected');
                    if (prev) prev.classList.remove('selected');
                    btn.classList.add('selected');
                    valueEl.value = time;
                }

                picker.innerHTML = '';
                let autoBtn = null, autoTime = null;
                slots.forEach(function(slot) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'mdbk-slot-btn' + (slot.available ? '' : ' mdbk-slot-taken');
                    btn.textContent = mdbkFormatTimeDisplay(slot.time);
                    if (!slot.available) {
                        btn.disabled = true;
                    } else {
                        btn.addEventListener('click', function() { selectSlot(btn, slot.time); });
                        if (preselectTime && slot.time === preselectTime) {
                            selectSlot(btn, slot.time);
                        } else if (!autoBtn) {
                            autoBtn = btn;
                            autoTime = slot.time;
                        }
                    }
                    picker.appendChild(btn);
                });
                // Nothing matched preselectTime (fresh "Add Booking", or an
                // edit whose date was changed away from its original one) —
                // auto-assign the earliest open slot, same as the public
                // form does, so Save never gets submitted with an empty time.
                if (!valueEl.value && autoBtn) {
                    autoBtn.classList.add('selected');
                    valueEl.value = autoTime;
                }
            })
            .catch(function() {
                if (token !== appSlotToken) return;
                picker.innerHTML = '<div class="mdbk-no-slots">Error loading time slots.</div>';
            });
    }

    /**
     * Add/Edit Booking modal's Date field — a hand-built calendar (same
     * approach as the public booking form's own #mdbk-calendar) rendered
     * permanently inline instead of behind a click-to-open trigger, so a
     * date always gets picked and the slot grid next to it always has
     * something to load. A day the selected doctor doesn't work shows as
     * unavailable instead of only being caught after clicking Save.
     */
    function initAppDateCalendar() {
        const wrap = document.getElementById('mdbk-app-date-wrap');
        const panel = document.getElementById('mdbk-app-calendar');
        const hiddenInput = document.getElementById('mdbk-app-date');
        if (!wrap || !panel || !hiddenInput) return null;

        const today = parseServerDate(typeof mdbk_admin_obj !== 'undefined' ? mdbk_admin_obj.today : null);
        let viewYear = today.getFullYear();
        let viewMonth = today.getMonth();
        let selectedDateStr = '';
        let disabledWeekdays = [];
        let extraDates = [];
        let offDates = [];

        function pad2(n) { return String(n).padStart(2, '0'); }
        function todayStr() { return today.getFullYear() + '-' + pad2(today.getMonth() + 1) + '-' + pad2(today.getDate()); }
        function isUnavailable(dateStr, dayOfWeek) {
            if (offDates.indexOf(dateStr) !== -1) return true;
            const weekdayOff = disabledWeekdays.indexOf(dayOfWeek) !== -1;
            return weekdayOff && extraDates.indexOf(dateStr) === -1;
        }

        function render() {
            const firstDay = new Date(viewYear, viewMonth, 1).getDay();
            const days = new Date(viewYear, viewMonth + 1, 0).getDate();
            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
            const tStr = todayStr();
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
                if (dateStr === tStr) classes += ' today';
                if (dateStr < tStr) classes += ' past';
                if (dateStr === selectedDateStr) classes += ' selected';
                else if (isUnavailable(dateStr, dayOfWeek)) classes += ' unavailable';
                html += '<span class="' + classes + '" data-date="' + dateStr + '">' + d + '</span>';
            }
            html += '</div>';
            panel.innerHTML = html;
        }

        function selectDate(dateStr) {
            selectedDateStr = dateStr;
            hiddenInput.value = dateStr;
            wrap.classList.remove('mdbk-field-error');
            render();
            // Picking a date is exactly when the slot grid needs to reload
            // for whichever doctor is currently selected — excludeId is
            // this appointment's own id (empty on a fresh "Add Booking"),
            // so re-picking THIS appointment's original date while editing
            // still shows its own slot as open, not falsely taken.
            const doctorEl = document.getElementById('mdbk-app-doctor');
            const appIdEl = document.getElementById('mdbk-app-id');
            loadAppSlots(doctorEl ? doctorEl.value : '', dateStr, '', appIdEl ? appIdEl.value : '');
        }

        panel.addEventListener('click', function(e) {
            const navBtn = e.target.closest('.mdbk-mini-cal-nav-btn');
            if (navBtn) {
                if (navBtn.dataset.action === 'prev') { viewMonth--; if (viewMonth < 0) { viewMonth = 11; viewYear--; } }
                else { viewMonth++; if (viewMonth > 11) { viewMonth = 0; viewYear++; } }
                render();
                return;
            }
            const dayEl = e.target.closest('.mdbk-mini-cal-day');
            if (!dayEl || dayEl.classList.contains('empty') || dayEl.classList.contains('past') || dayEl.classList.contains('unavailable')) return;
            selectDate(dayEl.getAttribute('data-date'));
        });

        render();

        return {
            // allowClear=false lets the Edit-populate flow apply an
            // appointment's OWN already-booked date and fetch its doctor's
            // schedule for month-grid rendering, without that fetch's
            // resolution wiping the date right back out just because the
            // doctor's schedule may have since changed since that date was
            // originally booked.
            setAvailability: function(offDaysArr, extraDatesArr, offDatesArr, allowClear) {
                disabledWeekdays = offDaysArr || [];
                extraDates = extraDatesArr || [];
                offDates = offDatesArr || [];
                if (allowClear !== false && selectedDateStr) {
                    const parts = selectedDateStr.split('-').map(Number);
                    const dow = new Date(parts[0], parts[1] - 1, parts[2]).getDay();
                    if (isUnavailable(selectedDateStr, dow)) {
                        selectedDateStr = '';
                        hiddenInput.value = '';
                    }
                }
                render();
            },
            setSelected: function(dateStr) {
                if (!dateStr) {
                    selectedDateStr = '';
                    hiddenInput.value = '';
                    viewYear = today.getFullYear();
                    viewMonth = today.getMonth();
                    render();
                    return;
                }
                selectedDateStr = dateStr;
                hiddenInput.value = dateStr;
                const parts = dateStr.split('-').map(Number);
                viewYear = parts[0];
                viewMonth = parts[1] - 1;
                wrap.classList.remove('mdbk-field-error');
                render();
            },
            reset: function() {
                selectedDateStr = '';
                hiddenInput.value = '';
                disabledWeekdays = [];
                extraDates = [];
                offDates = [];
                viewYear = today.getFullYear();
                viewMonth = today.getMonth();
                wrap.classList.remove('mdbk-field-error');
                render();
                loadAppSlots('', '');
            }
        };
    }
    const appDateCalendar = initAppDateCalendar();
    // Set true only while the Edit-populate callback below is synchronously
    // setting specialty/doctor/date together — captured (not re-read) at the
    // moment each doctor-schedule fetch fires, so it can't be affected by
    // that flag having already been reset back to false by the time the
    // fetch's response actually arrives.
    let suppressDateAutoClear = false;
    // Edit-populate below sets the doctor TWICE in a row (filterDoctorsBySpecialty()'s
    // own default-to-first-visible-doctor, immediately followed by the
    // appointment's actual doctor) — each firing its own async schedule
    // fetch. Since network responses aren't guaranteed to resolve in the
    // order they were sent, without this token guard the (wrong,
    // throwaway) first fetch could resolve AFTER the second, silently
    // leaving the calendar showing some OTHER doctor's availability.
    let dateAvailabilityToken = 0;
    function updateAppDateAvailability(doctorId) {
        if (!appDateCalendar) return;
        const allowClear = !suppressDateAutoClear;
        const token = ++dateAvailabilityToken;
        if (!doctorId || typeof mdbk_admin_obj === 'undefined') {
            appDateCalendar.setAvailability([], [], [], allowClear);
            return;
        }
        const body = new URLSearchParams();
        body.set('action', 'mdbk_get_doctor_schedule');
        body.set('doctor_id', doctorId);
        body.set('nonce', mdbk_admin_obj.form_nonce);
        fetch(mdbk_admin_obj.ajax_url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (token !== dateAvailabilityToken) return;
                const payload = (res && res.success && res.data) ? res.data : {};
                appDateCalendar.setAvailability(payload.off_days || [], payload.extra_dates || [], payload.off_dates || [], allowClear);
            })
            .catch(function() {
                if (token !== dateAvailabilityToken) return;
                appDateCalendar.setAvailability([], [], [], allowClear);
            });
    }

    const appDoctorSelect = initCustomSelect('mdbk-app-doctor-select', function(selectedOpt, value) {
        updateAppSlotTimeAvailability(selectedOpt);
        updateAppDateAvailability(value);
        // Whatever slot was picked belonged to the PREVIOUS doctor's
        // schedule, not this one — reload for the currently-selected date
        // (if any) against the new doctor. During Edit-populate this fires
        // before the target date/slot are set below and is superseded by
        // that flow's own final loadAppSlots() call (appSlotToken guards
        // against this throwaway one resolving later and clobbering it).
        const dateEl = document.getElementById('mdbk-app-date');
        loadAppSlots(value, dateEl ? dateEl.value : '');
        // Remembered so the NEXT "+ New Booking" (openAddAppointmentModal()
        // below) can restore it instead of always defaulting back to the
        // first doctor — a receptionist booking several patients in a row
        // for the same doctor shouldn't have to reselect it every time.
        if (value) localStorage.setItem('mdbk_last_doctor_id', value);
    });
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
            if (!opt) return;
            filterDoctorsBySpecialty(opt.dataset.value);
            localStorage.setItem('mdbk_last_specialty_id', opt.dataset.value || '');
        });
    }

    // Add-path only — initModal()'s own generic form.reset() (fired for
    // any non-edit openSelector match) would otherwise run AFTER this and
    // silently revert the hidden <select>s this restores back to their
    // page-load defaults, so the ADD flow is handled entirely outside
    // initModal() below instead (openAddAppointmentModal()); only Edit
    // still goes through it.
    initModal('mdbk-appointment-modal', '.mdbk-edit-appointment', 'mdbk-appointment-form', 'mdbk-edit-appointment', (id, btn) => {
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
            // Suppressed for this whole block: setting specialty/doctor
            // below each kicks off an async doctor-schedule fetch (for the
            // calendar's unavailable-day styling), and without this its
            // resolution could wipe the date being restored just below,
            // purely because the doctor's schedule may have changed since
            // this appointment was originally booked on it.
            suppressDateAutoClear = true;
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
            if (appDateCalendar) appDateCalendar.setSelected(row.dataset.date);
            suppressDateAutoClear = false;
            // One explicit, fully-informed call now that doctor/date are
            // both set — supersedes the throwaway ones the doctor setValue()
            // above already fired (appSlotToken discards those once this
            // one's response lands). excludeId keeps this SAME appointment's
            // own slot out of its own "already booked" count; preselectTime
            // marks it chosen instead of auto-picking the first open slot.
            // updateAppSlotTimeAvailability() (via the doctor setValue()
            // above) already hid the whole picker if this doctor is
            // slot-disabled, so this is a harmless no-op fetch for them.
            loadAppSlots(row.dataset.doctor, row.dataset.date, row.dataset.slotTime, id);
            if (appStatusSelect && row.dataset.status) {
                const opt = appStatusSelect.panel.querySelector('.mdbk-custom-select-option[data-value="' + row.dataset.status + '"]');
                if (opt) appStatusSelect.setValue(opt.dataset.value, opt.textContent);
            }
        }
    });

    // Restores whichever Specialty/Doctor were last used for a new booking
    // (saved by appDoctorSelect/appSpecSelect above) instead of always
    // resetting to "All Specialties" / the first doctor. Falls back to
    // those old defaults if nothing's stored yet, or if a stored id no
    // longer matches any option (e.g. it was deleted, or filtered out by
    // the specialty just restored).
    function applyLastUsedDoctorSpecialty() {
        const lastSpecId = localStorage.getItem('mdbk_last_specialty_id') || '';
        const lastDoctorId = localStorage.getItem('mdbk_last_doctor_id') || '';
        if (appSpecSelect) {
            let specOpt = lastSpecId ? appSpecSelect.panel.querySelector('.mdbk-custom-select-option[data-value="' + lastSpecId + '"]') : null;
            if (!specOpt) specOpt = appSpecSelect.panel.querySelector('.mdbk-custom-select-option[data-value=""]');
            if (specOpt) {
                appSpecSelect.setValue(specOpt.dataset.value, specOpt.textContent);
                filterDoctorsBySpecialty(specOpt.dataset.value);
            }
        }
        if (appDoctorSelect) {
            let doctorOpt = lastDoctorId ? appDoctorSelect.panel.querySelector('.mdbk-custom-select-option[data-value="' + lastDoctorId + '"]:not([style*="display: none"])') : null;
            if (!doctorOpt) doctorOpt = appDoctorSelect.panel.querySelector('.mdbk-custom-select-option:not([style*="display: none"])');
            if (doctorOpt) appDoctorSelect.setValue(doctorOpt.dataset.value, doctorOpt.textContent);
        }
    }

    // Everything "+ New Booking" needs done, in the one order that's safe:
    // form.reset() FIRST (restores the hidden <select>s to their page-load
    // defaults), THEN the custom-select restores/overrides — the other way
    // round, form.reset() would silently revert what those just set. Used
    // directly by both the real "+ New Booking" buttons below and the
    // Patient Directory's "Book" button, which has no "+ New Booking"
    // button of its own on that page to click through.
    function openAddAppointmentModal() {
        const modal = document.getElementById('mdbk-appointment-modal');
        const form = document.getElementById('mdbk-appointment-form');
        if (!modal || !form) return;
        form.reset();
        modal.style.display = 'flex';
        const title = document.getElementById('mdbk-appointment-modal-title');
        if (title) title.textContent = 'Add Booking';
        applyLastUsedDoctorSpecialty();
        // Reset gender back to its default (Male) — form.reset() alone
        // only restores the hidden <select>'s value, not the visible
        // custom-select trigger label, so without this an earlier edit
        // (e.g. a booking whose gender was "Female") would leave a stale
        // label showing on the next "+ New Booking".
        if (appGenderSelect) {
            const defaultOpt = appGenderSelect.panel.querySelector('.mdbk-custom-select-option[data-value="Male"]');
            if (defaultOpt) appGenderSelect.setValue(defaultOpt.dataset.value, defaultOpt.textContent);
        }
        // Wipe any date left selected from a previous Add/Edit — the
        // applyLastUsedDoctorSpecialty() call above already kicks off a
        // fresh availability fetch for whichever doctor this reset lands on.
        if (appDateCalendar) appDateCalendar.reset();
    }

    document.querySelectorAll('.mdbk-add-appointment').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            openAddAppointmentModal();
        });
    });

    // Patient Directory's "Book" button — opens this SAME modal in place
    // (no page navigation to the Booking page), prefilled from the
    // clicked row's own data-name/phone/email/age/gender (already on
    // .mdbk-patient-row-directory for the View/Edit flows). Doctor/
    // Specialty are deliberately left at whatever openAddAppointmentModal()
    // just restored (the last-used ones) rather than reset per-patient — a
    // patient isn't tied to one doctor, only Date/Time need picking fresh.
    document.querySelectorAll('.mdbk-book-appointment').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const row = btn.closest('.mdbk-patient-row-directory');
            if (!row) return;
            openAddAppointmentModal();
            const nameInput = document.getElementById('mdbk-app-patient');
            const phoneInput = document.getElementById('mdbk-app-phone');
            const emailInput = document.getElementById('mdbk-app-email');
            const ageInput = document.getElementById('mdbk-app-age');
            if (nameInput) nameInput.value = row.dataset.name || '';
            if (phoneInput) phoneInput.value = row.dataset.phone || '';
            if (emailInput) emailInput.value = row.dataset.email || '';
            if (ageInput) ageInput.value = row.dataset.age || '';
            if (appGenderSelect && row.dataset.gender) {
                const genderOpt = appGenderSelect.panel.querySelector('.mdbk-custom-select-option[data-value="' + row.dataset.gender + '"]');
                if (genderOpt) appGenderSelect.setValue(genderOpt.dataset.value, genderOpt.textContent);
            }
        });
    });

    // The Date field is now a hidden <input>, not a native
    // <input type="date" required"> — hidden inputs are barred from HTML5
    // constraint validation, so an empty date has to be caught explicitly
    // here instead of relying on the browser's own required-field popup.
    const appointmentForm = document.getElementById('mdbk-appointment-form');
    if (appointmentForm) {
        appointmentForm.addEventListener('submit', function(e) {
            const dateInput = document.getElementById('mdbk-app-date');
            const dateWrap = document.getElementById('mdbk-app-date-wrap');
            if (dateInput && !dateInput.value) {
                e.preventDefault();
                if (dateWrap) dateWrap.classList.add('mdbk-field-error');
            }
        });
    }

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
    // this standalone page/canvas has no matching CSS for. Delegated on
    // document (not bound once per button at load) — the search/filter
    // inputs above and the group's own Refresh button (further down) both
    // replace #mdbk-schedule-results / a single group's markup, which
    // would silently detach any per-element listener a plain
    // querySelectorAll().forEach() bound here instead.
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.mdbk-print-group');
        if (!btn) return;
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
    // Delegated for the same reason as .mdbk-print-group above.
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.mdbk-download-group-image');
        if (!btn) return;
        const group = btn.closest('.mdbk-doctor-group');
        if (!group) return;
        const name = group.querySelector('.mdbk-doctor-group-name');
        const table = group.querySelector('.mdbk-doctor-group-print-table');
        if (!table) return;
        const titleText = name ? name.textContent : 'Patients';
        mdbkDownloadHtmlAsImage(titleText, table.innerHTML, titleText.replace(/[^a-z0-9]+/gi, '-').toLowerCase() + '-patients.png');
    });

    // Per-doctor-group manual Refresh — replaces the earlier
    // setInterval(runSearch, 12000) whole-page auto-refresh (see the
    // schedule search IIFE above), which wholesale-replaced
    // #mdbk-schedule-results on every tick and, in doing so, reset every
    // <details>' open/closed state back to the server's default (always
    // open) and could jump the page's scroll position — a staff member
    // who'd deliberately collapsed one doctor's queue to focus on
    // another's would find it sprung back open again 12 seconds later.
    // This instead touches only the ONE group that was clicked: its own
    // <details> element is never replaced (so its open/closed state is
    // untouched), and every OTHER group on the page — including their
    // scroll position — is left completely alone.
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.mdbk-refresh-group');
        if (!btn || typeof mdbk_admin_obj === 'undefined') return;
        const group = btn.closest('.mdbk-doctor-group');
        if (!group || btn.disabled) return;
        const icon = btn.querySelector('svg');
        btn.disabled = true;
        if (icon) icon.classList.add('mdbk-spin');

        const searchInput = document.getElementById('mdbk-schedule-search');
        const statusSelect = document.getElementById('mdbk-schedule-filter-status');
        const body = new URLSearchParams();
        body.set('action', 'mdbk_refresh_doctor_group');
        body.set('nonce', mdbk_admin_obj.nonce);
        body.set('doctor_id', group.dataset.doctorId || '0');
        body.set('is_today', group.dataset.isToday || '0');
        body.set('s', searchInput ? searchInput.value : '');
        body.set('filter_status', statusSelect ? statusSelect.value : '');

        fetch(mdbk_admin_obj.ajax_url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res || !res.success) return;
                const countEl = group.querySelector('.mdbk-doctor-group-count');
                const listEl = group.querySelector('.mdbk-doctor-group-list');
                const tableEl = group.querySelector('.mdbk-doctor-group-print-table');
                const dotEl = group.querySelector('.mdbk-live-pulse-dot');
                if (countEl) countEl.textContent = res.data.count_label;
                if (listEl) listEl.innerHTML = res.data.list_html;
                if (tableEl) tableEl.innerHTML = res.data.print_table_html;
                if (dotEl) dotEl.classList.toggle('mdbk-live-pulse-active', !!res.data.is_visiting);
            })
            .finally(function() {
                btn.disabled = false;
                if (icon) icon.classList.remove('mdbk-spin');
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

    // Global Settings' "Reset to Default Colors" — puts each color input
    // back to its own data-default (rendered from
    // MDBK_Admin_Dashboard::DEFAULT_COLOR_PRIMARY/SECONDARY) so a clinic
    // that's tried a few colors always has a way back, without needing to
    // remember or retype the original hex values. Only resets the two
    // fields on the page — still requires "Save Settings" to actually
    // persist it, same as changing them by hand would.
    const colorsReset = document.getElementById('mdbk-colors-reset');
    if (colorsReset) {
        colorsReset.addEventListener('click', function() {
            const primary = document.getElementById('mdbk-color-primary');
            const secondary = document.getElementById('mdbk-color-secondary');
            if (primary && primary.dataset.default) primary.value = primary.dataset.default;
            if (secondary && secondary.dataset.default) secondary.value = secondary.dataset.default;
        });
    }

    // License card (Global Settings) — Activate/Deactivate/Refresh, each a
    // direct admin-ajax call with its own button-disable-while-in-flight
    // guard. No page reload needed; the two panels (inactive/activated) are
    // just toggled in place from the AJAX response.
    (function() {
        const activateBtn = document.getElementById('mdbk-license-activate');
        const deactivateBtn = document.getElementById('mdbk-license-deactivate');
        const refreshBtn = document.getElementById('mdbk-license-refresh');
        const messageEl = document.getElementById('mdbk-license-message');
        if ((!activateBtn && !deactivateBtn && !refreshBtn) || typeof mdbk_admin_obj === 'undefined') return;

        function setMessage(text, isError) {
            if (!messageEl) return;
            messageEl.textContent = text || '';
            messageEl.style.color = isError ? '#dc2626' : '#16a34a';
        }

        function callLicenseAction(action, extraParams) {
            const body = new URLSearchParams();
            body.set('action', action);
            body.set('nonce', mdbk_admin_obj.nonce);
            Object.keys(extraParams || {}).forEach((key) => body.set(key, extraParams[key]));
            return fetch(mdbk_admin_obj.ajax_url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                .then((r) => r.json());
        }

        if (activateBtn) {
            activateBtn.addEventListener('click', function() {
                const input = document.getElementById('mdbk-license-key-input');
                const key = input ? input.value.trim() : '';
                if (!key) { setMessage('Please enter a license key.', true); return; }
                activateBtn.disabled = true;
                callLicenseAction('mdbk_license_activate', { license_key: key })
                    .then((res) => {
                        activateBtn.disabled = false;
                        if (res && res.success) {
                            window.location.reload();
                        } else {
                            setMessage((res && res.data && res.data.message) || 'Could not activate this license key.', true);
                        }
                    })
                    .catch(() => { activateBtn.disabled = false; setMessage('Something went wrong, please try again.', true); });
            });
        }

        if (deactivateBtn) {
            deactivateBtn.addEventListener('click', function() {
                if (!confirm('Deactivate this license on this site?')) return;
                deactivateBtn.disabled = true;
                callLicenseAction('mdbk_license_deactivate')
                    .then(() => { window.location.reload(); })
                    .catch(() => { deactivateBtn.disabled = false; setMessage('Something went wrong, please try again.', true); });
            });
        }

        if (refreshBtn) {
            refreshBtn.addEventListener('click', function() {
                refreshBtn.disabled = true;
                callLicenseAction('mdbk_license_refresh')
                    .then((res) => {
                        refreshBtn.disabled = false;
                        if (res && res.success) {
                            window.location.reload();
                        } else {
                            setMessage((res && res.data && res.data.message) || 'Could not refresh license status.', true);
                        }
                    })
                    .catch(() => { refreshBtn.disabled = false; setMessage('Something went wrong, please try again.', true); });
            });
        }
    })();

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
        const dragHint = document.getElementById('mdbk-doctor-drag-hint');
        const PAGE_SIZE = 9;
        let currentPage = 1;

        function allCards() {
            return Array.from(doctorGrid.querySelectorAll('.mdbk-admin-doctor-card'));
        }

        function isUnfiltered() {
            return !(searchInput.value || '').trim() && !specialtyFilter.value;
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

        // Drag-to-reorder (see doctorSortable below) needs every doctor on
        // screen together — the position it writes back is a plain index
        // in the visible DOM order, which is only ever the TRUE global order
        // when nothing is hidden by a filter or a pagination page boundary.
        // So the unfiltered view skips PAGE_SIZE slicing entirely (shows
        // every card, hides Prev/Next) instead of trying to make dragging
        // work across page boundaries it can't see past.
        function refreshGrid() {
            const cards = allCards();
            const matches = matchingCards();
            const unfiltered = isUnfiltered();

            if (doctorSortable) doctorSortable.option('disabled', !unfiltered);
            doctorGrid.classList.toggle('mdbk-drag-inactive', !unfiltered);
            if (dragHint) dragHint.textContent = unfiltered
                ? 'Drag cards to reorder'
                : 'Clear search/filter to drag and reorder';

            if (unfiltered) {
                cards.forEach((c) => c.classList.remove('is-hidden'));
                if (noMatch) noMatch.style.display = cards.length === 0 ? '' : 'none';
                if (pagination) pagination.style.display = 'none';
                if (countBadge) countBadge.textContent = 'Showing ' + cards.length + ' Doctors of ' + cards.length + ' Total';
                return;
            }

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

        // SortableJS (window.Sortable, vendored — see doctor-appointment.php's
        // admin_enqueue_scripts()) drags the real .mdbk-admin-doctor-card DOM
        // node during a drag, which the CSS Grid just reflows around — jQuery
        // UI Sortable's absolute-positioning math doesn't understand Grid
        // tracks and would intermittently miscalculate the drop target.
        // Starts disabled; refreshGrid() (called once below, and again on
        // every search/filter change) turns it on only in the unfiltered view.
        let doctorSortable = null;
        if (typeof mdbk_admin_obj !== 'undefined' && window.Sortable) {
            doctorSortable = window.Sortable.create(doctorGrid, {
                animation: 150,
                handle: '.mdbk-doctor-drag-handle',
                draggable: '.mdbk-admin-doctor-card',
                ghostClass: 'mdbk-drag-ghost',
                chosenClass: 'mdbk-drag-chosen',
                disabled: true,
                // Touch only (mouse stays instant): a short press-and-hold
                // before the drag actually engages, so a finger really
                // trying to scroll the page (and moving a few px during
                // that hold) cancels the drag instead of yanking a card.
                delay: 150,
                delayOnTouchOnly: true,
                touchStartThreshold: 5,
                onEnd: function(evt) {
                    const ids = Array.from(doctorGrid.querySelectorAll('.mdbk-admin-doctor-card')).map((c) => c.dataset.id);
                    if (!ids.length) return;
                    const body = new URLSearchParams();
                    body.set('action', 'mdbk_save_doctor_order');
                    body.set('nonce', mdbk_admin_obj.nonce);
                    ids.forEach((id) => body.append('order[]', id));
                    fetch(mdbk_admin_obj.ajax_url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                        .then((r) => r.json())
                        .then((res) => {
                            if (!res || !res.success) alert((res && res.data && res.data.message) || 'Something went wrong, please try again.');
                        })
                        .catch(() => alert('Something went wrong, please try again.'));
                }
            });
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
