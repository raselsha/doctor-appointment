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

    initModal('mdbk-doctor-modal', '.mdbk-add-doctor, .mdbk-edit-doctor', 'mdbk-doctor-form', 'mdbk-edit-doctor', (id, btn) => {
        document.getElementById('mdbk-doctor-id').value = id;
        const row = btn.closest('tr');
        if (row) {
            document.getElementById('mdbk-doc-name').value = row.dataset.name;
            document.getElementById('mdbk-doc-email').value = row.dataset.email;
            document.getElementById('mdbk-doc-phone').value = row.dataset.phone;
            
            // Populate Day-wise Schedule
            try {
                const schedule = JSON.parse(row.dataset.schedule);
                Object.keys(schedule).forEach(day => {
                    const activeCheck = document.querySelector(`input[name="schedule[${day}][active]"]`);
                    const fromInput = document.querySelector(`input[name="schedule[${day}][from]"]`);
                    const toInput = document.querySelector(`input[name="schedule[${day}][to]"]`);
                    
                    if (activeCheck && schedule[day].active) {
                        activeCheck.checked = true;
                        if (fromInput) fromInput.value = schedule[day].from;
                        if (toInput) toInput.value = schedule[day].to;
                    }
                });
            } catch(e) { console.error("Error parsing schedule JSON", e); }
        }
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

    initModal('mdbk-appointment-modal', '.mdbk-add-appointment, .mdbk-edit-appointment', 'mdbk-appointment-form', 'mdbk-edit-appointment', (id, btn) => {
        document.getElementById('mdbk-app-id').value = id;
        const row = btn.closest('tr');
        if (row) {
            document.getElementById('mdbk-app-patient').value = row.dataset.patient;
            document.getElementById('mdbk-app-phone').value = row.dataset.phone;
            document.getElementById('mdbk-app-doctor').value = row.dataset.doctor;
            document.getElementById('mdbk-app-date').value = row.dataset.date;
            document.getElementById('mdbk-app-status').value = row.dataset.status;
        }
    });

    initModal('mdbk-specialty-modal', '.mdbk-add-specialty, .mdbk-edit-specialty', 'mdbk-specialty-form', 'mdbk-edit-specialty', (id, btn) => {
        document.getElementById('mdbk-spec-id').value = id;
        const row = btn.closest('tr');
        if (row) {
            document.getElementById('mdbk-spec-name').value = row.dataset.name;
        }
    });
});
