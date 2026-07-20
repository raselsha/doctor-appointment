document.addEventListener('DOMContentLoaded', function() {
    
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

    function setDoctorPhotoPreview(url) {
        const preview = document.getElementById('mdbk-doc-photo-preview');
        const removeBtn = document.getElementById('mdbk-doc-photo-remove');
        if (!preview) return;
        preview.innerHTML = url ? '<img src="' + url + '" alt="">' : DOCTOR_PHOTO_PLACEHOLDER;
        if (removeBtn) removeBtn.style.display = url ? '' : 'none';
    }

    // Generic custom-dropdown controller: a real (hidden) <select> stays the
    // actual form control everything else reads/writes and submits; this just
    // keeps a styleable button+panel visually in sync with it. Returns null
    // if the wrapper isn't on the page, so callers can no-op safely.
    function initCustomSelect(wrapperId) {
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
            panel.querySelectorAll('.mdbk-custom-select-option').forEach(function(o) {
                o.classList.toggle('selected', String(o.dataset.value) === String(value));
            });
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
            '<details class="mdbk-modal-schedule" open><summary class="mdbk-modal-schedule-summary">Weekly Availability</summary>' +
                '<div class="mdbk-view-schedule-list">' +
                    '<div class="mdbk-view-day-row mdbk-view-day-header"><span>Day</span><span>Hours</span></div>' +
                    scheduleRows +
                '</div>' +
            '</details>';
    });

    initModal('mdbk-patient-modal', '.mdbk-add-patient, .mdbk-edit-patient', 'mdbk-patient-form', 'mdbk-edit-patient', (id, btn) => {
        document.getElementById('mdbk-patient-id').value = id;
        const row = btn.closest('tr');
        if (row) {
            document.getElementById('mdbk-patient-name').value = row.dataset.name;
            document.getElementById('mdbk-patient-phone').value = row.dataset.phone;
            document.getElementById('mdbk-patient-email').value = row.dataset.email;
            document.getElementById('mdbk-patient-address').value = row.dataset.address;
        }
    });

    const appDoctorSelect = initCustomSelect('mdbk-app-doctor-select');
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
            document.getElementById('mdbk-app-slot-time').value = row.dataset.slotTime || '';
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

    initModal('mdbk-specialty-modal', '.mdbk-add-specialty, .mdbk-edit-specialty', 'mdbk-specialty-form', 'mdbk-edit-specialty', (id, btn) => {
        document.getElementById('mdbk-spec-id').value = id;
        const row = btn.closest('tr');
        if (row) {
            document.getElementById('mdbk-spec-name').value = row.dataset.name;
        }
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
