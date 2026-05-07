document.addEventListener('DOMContentLoaded', function() {
    const specialtyRadios = document.querySelectorAll('.mdbk-specialty-radio');
    const doctorSelect = document.getElementById('mdbk-doctor-select');

    if (specialtyRadios.length && doctorSelect) {
        specialtyRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                const specId = this.value;
                
                // Add loading state
                doctorSelect.disabled = true;
                doctorSelect.innerHTML = '<option value="">Loading doctors...</option>';

                const formData = new FormData();
                formData.append('action', 'mdbk_get_doctors_by_specialty');
                formData.append('specialty_id', specId);
                formData.append('nonce', mdbk_form_obj.nonce);

                fetch(mdbk_form_obj.ajax_url, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    doctorSelect.disabled = false;
                    if (data.success) {
                        doctorSelect.innerHTML = data.data;
                    } else {
                        doctorSelect.innerHTML = '<option value="">No doctors available</option>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    doctorSelect.disabled = false;
                    doctorSelect.innerHTML = '<option value="">Error loading doctors</option>';
                });
            });
        });

        // Trigger initial filter for checked radio
        const checkedRadio = document.querySelector('.mdbk-specialty-radio:checked');
        if (checkedRadio) {
            checkedRadio.dispatchEvent(new Event('change'));
        }
    }
});
