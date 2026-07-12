document.addEventListener('DOMContentLoaded', function() {
    var dateValue = document.getElementById('mdbk-date-value');

    /**
     * Fetch a doctor's available time slots for a date and render them as
     * clickable buttons inside `pickerEl`, storing the chosen slot in `valueEl`.
     */
    function loadSlotsInto(pickerEl, valueEl, doctorId, dateStr) {
        if (!pickerEl || !valueEl) return;
        valueEl.value = '';

        if (!doctorId || !dateStr) {
            pickerEl.innerHTML = '';
            return;
        }

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
            pickerEl.innerHTML = '';
            slots.forEach(function(slot) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'mdbk-slot-btn' + (slot.available ? '' : ' mdbk-slot-taken');
                btn.textContent = slot.time;
                if (!slot.available) {
                    btn.disabled = true;
                } else {
                    btn.addEventListener('click', function() {
                        var prev = pickerEl.querySelector('.mdbk-slot-btn.selected');
                        if (prev) prev.classList.remove('selected');
                        btn.classList.add('selected');
                        valueEl.value = slot.time;
                    });
                }
                pickerEl.appendChild(btn);
            });
        })
        .catch(function() {
            pickerEl.innerHTML = '<div class="mdbk-no-slots">Error loading time slots.</div>';
        });
    }

    var formDatepicker = document.getElementById('mdbk-date-picker');
    var formSlotPicker = document.getElementById('mdbk-slot-picker');
    var formSlotValue = document.getElementById('mdbk-slot-value');
    var formDoctorSelect = document.getElementById('mdbk-doctor-select');

    function loadFormSlots(dateStr) {
        var doctorId = formDoctorSelect ? formDoctorSelect.value : '';
        loadSlotsInto(formSlotPicker, formSlotValue, doctorId, dateStr);
    }

    if (formDatepicker && typeof flatpickr !== 'undefined') {
        flatpickr(formDatepicker, {
            minDate: 'today',
            dateFormat: 'Y-m-d',
            inline: true,
            onChange: function(selectedDates, dateStr) {
                if (dateValue) dateValue.value = dateStr;
                loadFormSlots(dateStr);
            }
        });
    }

    if (formDoctorSelect) {
        formDoctorSelect.addEventListener('change', function() {
            if (dateValue && dateValue.value) loadFormSlots(dateValue.value);
        });
    }

    var modal = document.getElementById('mdbk-booking-modal');
    if (!modal) return;

    var triggers = document.querySelectorAll('.mdbk-book-trigger');
    var closeBtn = modal.querySelector('.mdbk-modal-close');

    var step1 = document.getElementById('mdbk-step-1');
    var step2 = document.getElementById('mdbk-step-2');
    var step3 = document.getElementById('mdbk-step-3');
    var doctorList = document.getElementById('mdbk-doctor-list');
    var doctorIdInput = document.getElementById('mdbk-doctor-id');
    var selectedDoctorEl = document.getElementById('mdbk-selected-doctor');
    var specialtyRadios = document.querySelectorAll('.mdbk-specialty-radio');
    var dots = document.querySelectorAll('.mdbk-step-dot');
    var lines = document.querySelectorAll('.mdbk-step-line');
    var modalTitle = document.getElementById('mdbk-modal-title');

    var modalDateInput = document.getElementById('mdbk-modal-date-picker');
    var modalSlotPicker = document.getElementById('mdbk-modal-slot-picker');
    var modalSlotValue = document.getElementById('mdbk-modal-slot-value');
    var modalForm = document.getElementById('mdbk-modal-form');
    var msgBox = modal.querySelector('.mdbk-modal-message');

    var modalDatepicker = null;

    function loadModalSlots(dateStr) {
        loadSlotsInto(modalSlotPicker, modalSlotValue, doctorIdInput.value, dateStr);
    }

    function initModalDatepicker() {
        if (modalDateInput && typeof flatpickr !== 'undefined' && !modalDatepicker) {
            modalDatepicker = flatpickr(modalDateInput, {
                minDate: 'today',
                dateFormat: 'Y-m-d',
                disable: [],
                inline: true,
                onChange: function(selectedDates, dateStr) {
                    if (dateValue) dateValue.value = dateStr;
                    loadModalSlots(dateStr);
                }
            });
        }
    }

    function updateDisabledDays(doctorId) {
        if (!modalDatepicker || !doctorId) return;
        var formData = new FormData();
        formData.append('action', 'mdbk_get_doctor_schedule');
        formData.append('doctor_id', doctorId);
        formData.append('nonce', mdbk_form_obj.nonce);

        fetch(mdbk_form_obj.ajax_url, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.data.length) {
                modalDatepicker.set('disable', [
                    function(date) { return data.data.indexOf(date.getDay()) !== -1; }
                ]);
                if (modalDatepicker.selectedDates.length) {
                    var d = modalDatepicker.selectedDates[0];
                    if (data.data.indexOf(d.getDay()) !== -1) modalDatepicker.clear();
                }
            } else {
                modalDatepicker.set('disable', []);
            }
        })
        .catch(function() { modalDatepicker.set('disable', []); });
    }

    function goToStep(num) {
        step1.style.display = num === 1 ? '' : 'none';
        step2.style.display = num === 2 ? '' : 'none';
        step3.style.display = num === 3 ? '' : 'none';
        dots.forEach(function(d) {
            var s = parseInt(d.getAttribute('data-step'), 10);
            d.classList.toggle('active', s <= num);
        });
        lines.forEach(function(l, idx) {
            l.classList.toggle('active', idx < num - 1);
        });
        if (num === 3) {
            modalTitle.textContent = 'Complete Your Booking';
            setTimeout(function() {
                if (modalDatepicker) modalDatepicker.redraw();
            }, 50);
        } else if (num === 2) {
            modalTitle.textContent = 'Choose a Doctor';
        } else {
            modalTitle.textContent = 'Book Appointment';
        }
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
                '<div class="mdbk-doc-check"><span class="mdbk-doc-check-icon">&#10003;</span></div>' +
                '<div class="mdbk-doc-info">' +
                    '<div class="mdbk-doc-name">' + doc.name + '</div>' +
                    '<div class="mdbk-doc-meta">' + specsHtml + daysHtml + '</div>' +
                '</div>';

            card.addEventListener('click', function() {
                var prev = doctorList.querySelector('.mdbk-doctor-item.selected');
                if (prev) prev.classList.remove('selected');
                card.classList.add('selected');
                doctorIdInput.value = doc.id;
                selectedDoctorEl.innerHTML = '<div class="mdbk-selected-doc-badge">' + doc.name + '</div>';
                goToStep(3);
                initModalDatepicker();
                updateDisabledDays(doc.id);
            });

            doctorList.appendChild(card);
        });
    }

    function loadDoctors(specId) {
        doctorList.innerHTML = '<div class="mdbk-doc-loading">Loading doctors...</div>';
        goToStep(2);

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

    function selectDoctorAndProceed(doctorId) {
        doctorList.innerHTML = '<div class="mdbk-doc-loading">Loading...</div>';
        goToStep(2);

        var formData = new FormData();
        formData.append('action', 'mdbk_get_doctors_by_specialty');
        formData.append('specialty_id', 0);
        formData.append('nonce', mdbk_form_obj.nonce);

        fetch(mdbk_form_obj.ajax_url, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                renderDoctors(data.data);
                var target = doctorList.querySelector('[data-doctor-id="' + doctorId + '"]');
                if (target) {
                    target.click();
                } else {
                    goToStep(2);
                }
            } else {
                doctorList.innerHTML = '<div class="mdbk-no-doctors-modal">No doctors available.</div>';
            }
        })
        .catch(function() {
            doctorList.innerHTML = '<div class="mdbk-no-doctors-modal">Error loading doctors.</div>';
        });
    }

    function loadDefaultSpecialty() {
        var checked = document.querySelector('.mdbk-specialty-radio:checked');
        if (checked) loadDoctors(checked.value);
    }

    if (triggers.length) {
        triggers.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                var doctorId = this.getAttribute('data-mdbk-doctor-id');
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
                if (doctorId) {
                    selectDoctorAndProceed(doctorId);
                } else {
                    goToStep(1);
                    loadDefaultSpecialty();
                }
            });
        });
    }

    function resetModal() {
        modal.style.display = 'none';
        document.body.style.overflow = '';
        goToStep(1);
        doctorIdInput.value = '';
        selectedDoctorEl.innerHTML = '';
        if (modalDatepicker) modalDatepicker.clear();
        if (dateValue) dateValue.value = '';
        if (modalSlotPicker) modalSlotPicker.innerHTML = '';
        if (modalSlotValue) modalSlotValue.value = '';
        if (msgBox) {
            msgBox.innerHTML = '';
            msgBox.className = 'mdbk-modal-message';
        }
        doctorList.innerHTML = '';
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', resetModal);
    }

    modal.addEventListener('click', function(e) {
        if (e.target === modal) resetModal();
    });

    if (specialtyRadios.length) {
        specialtyRadios.forEach(function(radio) {
            radio.addEventListener('change', function() {
                loadDoctors(this.value);
            });
        });
    }

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
                        if (modalDatepicker) modalDatepicker.clear();
                        if (dateValue) dateValue.value = '';
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
