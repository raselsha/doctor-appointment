/**
 * Polling-only counterpart to queue-script.js, for the public read-only
 * "Live Queue" view (mdbk_queue_list). A page can have one instance
 * (single-doctor mode) or several stacked at once (all-doctors mode), so
 * every instance polls independently rather than assuming a single
 * `#mdbk-queue-app`. Deliberately has no click delegation for action
 * buttons and no Check-In box wiring — those elements never render on this
 * view, so the code that could even construct a mutating request is
 * physically absent from this bundle.
 *
 * Rows are reconciled in place (matched by data-appointment-id) rather than
 * replaced with a blunt innerHTML swap, so a status change (not-present ->
 * present -> serving) animates via the CSS transitions on
 * .mdbk-queue-list-row instead of just snapping to the new state, and a
 * patient leaving the list (doctor marked them completed/no-show) fades out
 * instead of vanishing instantly.
 */
document.addEventListener('DOMContentLoaded', function() {
    var instances = document.querySelectorAll('.mdbk-queue-app-instance');
    if (!instances.length) return;

    function reconcileRows(columnsEl, newRows) {
        var newIds = newRows.map(function(el) { return el.getAttribute('data-appointment-id'); });
        var existingRows = {};
        columnsEl.querySelectorAll('.mdbk-queue-list-row').forEach(function(el) {
            existingRows[el.getAttribute('data-appointment-id')] = el;
        });

        // Patients no longer in the queue (completed/no-show) fade out,
        // then get removed once the transition finishes.
        Object.keys(existingRows).forEach(function(id) {
            if (newIds.indexOf(id) !== -1) return;
            var el = existingRows[id];
            el.classList.add('mdbk-row-exit');
            el.addEventListener('transitionend', function() { el.remove(); }, { once: true });
            setTimeout(function() { if (el.parentNode) el.remove(); }, 500);
        });

        // Add new rows (fade+slide in) or update/reposition existing ones —
        // appendChild on an already-attached node moves it rather than
        // recreating it, so an in-progress transition on that row isn't
        // interrupted when the doctor's queue reorders.
        newRows.forEach(function(newRowEl) {
            var id = newRowEl.getAttribute('data-appointment-id');
            var existing = existingRows[id];
            if (existing) {
                if (existing.className !== newRowEl.className) {
                    existing.className = newRowEl.className;
                }
                if (existing.innerHTML !== newRowEl.innerHTML) {
                    existing.innerHTML = newRowEl.innerHTML;
                }
                columnsEl.appendChild(existing);
            } else {
                newRowEl.classList.add('mdbk-row-enter');
                columnsEl.appendChild(newRowEl);
                void newRowEl.offsetWidth; // force layout so the enter->normal transition plays
                requestAnimationFrame(function() {
                    newRowEl.classList.remove('mdbk-row-enter');
                });
            }
        });
    }

    function refreshInstance(instance) {
        var bodyEl = instance.querySelector('.mdbk-queue-body-instance');
        if (!bodyEl) return;

        var formData = new FormData();
        formData.append('action', 'mdbk_get_queue_state');
        formData.append('nonce', mdbk_queue_view_obj.nonce);
        formData.append('doctor_id', instance.getAttribute('data-doctor'));

        fetch(mdbk_queue_view_obj.ajax_url, { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data || !data.success || !data.data || typeof data.data.fragment !== 'string') return;

                var tmp = document.createElement('div');
                tmp.innerHTML = data.data.fragment;

                var columnsEl = bodyEl.querySelector('.mdbk-queue-list-columns');
                var newColumnsEl = tmp.querySelector('.mdbk-queue-list-columns');
                var newRows = newColumnsEl ? Array.prototype.slice.call(newColumnsEl.querySelectorAll('.mdbk-queue-list-row')) : [];

                if (columnsEl && newColumnsEl) {
                    if (newRows.length) {
                        var emptyMsg = columnsEl.querySelector('.mdbk-no-doctors');
                        if (emptyMsg) emptyMsg.remove();
                        reconcileRows(columnsEl, newRows);

                        // The list is capped to ~5 visible rows and scrolls
                        // for the rest (see .mdbk-queue-list-columns) — keep
                        // whoever's currently being served scrolled into
                        // view, so completing them (and promoting the next)
                        // is never hidden below the fold.
                        var servingRow = columnsEl.querySelector('.mdbk-serving');
                        if (servingRow) {
                            servingRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                        }
                    } else {
                        // No rows left either way (queue fully cleared, or
                        // still empty) — a rare edge case, not worth animating.
                        columnsEl.innerHTML = newColumnsEl.innerHTML;
                    }
                }

                var countEl = bodyEl.querySelector('.mdbk-queue-list-count');
                var newCountEl = tmp.querySelector('.mdbk-queue-list-count');
                if (countEl && newCountEl) countEl.textContent = newCountEl.textContent;

                var updatedEl = bodyEl.querySelector('.mdbk-queue-updated');
                var newUpdatedEl = tmp.querySelector('.mdbk-queue-updated');
                if (updatedEl && newUpdatedEl) updatedEl.textContent = newUpdatedEl.textContent;

                // In all-doctors grid mode, a doctor's card is hidden while
                // their count is 0 (see the [data-patient-count="0"] CSS
                // rule) — updating this attribute on every poll is what
                // makes the card reappear on its own once a new booking
                // lands for them, with no page reload.
                if (typeof data.data.count !== 'undefined') {
                    instance.setAttribute('data-patient-count', data.data.count);
                }
            })
            .catch(function() {});
    }

    function refreshAll() {
        instances.forEach(refreshInstance);
    }

    setInterval(refreshAll, 12000);
});
