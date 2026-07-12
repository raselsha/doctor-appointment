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

    startPolling();
});
