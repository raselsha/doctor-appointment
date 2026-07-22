document.addEventListener('DOMContentLoaded', function() {
    var app = document.getElementById('mdbk-queue-app');
    if (!app) return;

    var body = document.getElementById('mdbk-queue-body');
    var doctorSelect = document.getElementById('mdbk-queue-doctor-select');
    var pollTimer = null;
    var busy = false;

    function currentDoctorId() {
        return doctorSelect ? doctorSelect.value : app.getAttribute('data-doctor');
    }

    function post(action, extra) {
        var formData = new FormData();
        formData.append('action', action);
        formData.append('nonce', mdbk_queue_obj.nonce);
        formData.append('doctor_id', currentDoctorId());
        if (extra) {
            Object.keys(extra).forEach(function(key) {
                formData.append(key, extra[key]);
            });
        }
        return fetch(mdbk_queue_obj.ajax_url, { method: 'POST', body: formData }).then(function(r) { return r.json(); });
    }

    function renderFragment(data) {
        if (data && data.success && data.data && typeof data.data.fragment === 'string') {
            body.innerHTML = data.data.fragment;
        }
    }

    function refresh() {
        if (busy) return;
        post('mdbk_get_queue_state').then(renderFragment).catch(function() {});
    }

    function startPolling() {
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(refresh, 12000);
    }

    // Delegate clicks for all queue action buttons (re-rendered fragments
    // don't need re-binding since we listen on the stable container).
    app.addEventListener('click', function(e) {
        var callNextBtn = e.target.closest('.mdbk-queue-call-next');
        var actionBtn = e.target.closest('.mdbk-queue-action');

        if (!callNextBtn && !actionBtn) return;
        if (busy) return;
        busy = true;

        var request;
        if (callNextBtn) {
            request = post('mdbk_queue_call_next');
        } else {
            request = post('mdbk_queue_set_status', {
                appointment_id: actionBtn.getAttribute('data-appointment-id'),
                status: actionBtn.getAttribute('data-status')
            });
        }

        request.then(function(data) {
            if (data && !data.success && data.data) {
                window.alert(data.data);
            }
            renderFragment(data);
        }).catch(function() {}).finally(function() {
            busy = false;
        });
    });

    if (doctorSelect) {
        doctorSelect.addEventListener('change', function() {
            app.setAttribute('data-doctor', doctorSelect.value);
            refresh();
        });
    }

    // Check-In box — a USB/Bluetooth QR scanner just types the decoded
    // token into whatever input has focus, then sends Enter, exactly like
    // a keyboard; manual paste + click works the same way.
    var checkinInput = document.getElementById('mdbk-checkin-input');
    var checkinBtn = document.getElementById('mdbk-checkin-verify-btn');
    var checkinResult = document.getElementById('mdbk-checkin-result');

    function verifyCheckin() {
        var token = checkinInput ? checkinInput.value.trim() : '';
        if (!token || busy) return;
        busy = true;
        if (checkinResult) {
            checkinResult.className = '';
            checkinResult.textContent = '';
        }

        post('mdbk_verify_checkin', { token: token }).then(function(data) {
            if (checkinResult) {
                if (data && data.success) {
                    checkinResult.className = 'mdbk-checkin-success';
                    checkinResult.textContent = '✓ Checked in: ' + data.data.patient_name +
                        ' — ' + data.data.ticket + ' — ' + data.data.doctor_name +
                        (data.data.slot_time ? ' — ' + data.data.slot_time : '');
                    if (checkinInput) checkinInput.value = '';
                } else {
                    checkinResult.className = 'mdbk-checkin-error';
                    checkinResult.textContent = data && data.data ? data.data : 'Something went wrong.';
                }
            }
            renderFragment(data);
        }).catch(function() {
            if (checkinResult) {
                checkinResult.className = 'mdbk-checkin-error';
                checkinResult.textContent = 'Something went wrong.';
            }
        }).finally(function() {
            busy = false;
        });
    }

    if (checkinBtn) {
        checkinBtn.addEventListener('click', verifyCheckin);
    }
    if (checkinInput) {
        checkinInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                verifyCheckin();
            }
        });
    }

    startPolling();
});
