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

                // "Doctor is visiting" pulse dot — lives in the heading,
                // outside .mdbk-queue-list-columns/-count above, so it was
                // never being refreshed on poll at all (stayed frozen at
                // whatever it was on initial page load, e.g. still
                // pulsing well after the doctor had already been marked as
                // having finished with that patient). Synced explicitly here.
                var dotEl = bodyEl.querySelector('.mdbk-live-pulse-dot');
                var newDotEl = tmp.querySelector('.mdbk-live-pulse-dot');
                if (dotEl && newDotEl) {
                    dotEl.classList.toggle('mdbk-live-pulse-active', newDotEl.classList.contains('mdbk-live-pulse-active'));
                }

                var updatedEl = bodyEl.querySelector('.mdbk-queue-updated');
                var newUpdatedEl = tmp.querySelector('.mdbk-queue-updated');
                if (updatedEl && newUpdatedEl) updatedEl.textContent = newUpdatedEl.textContent;

                // "On break" notice — the poll no longer decides whether
                // this is showing; syncBreakNotices() below owns that, so
                // the notice lands on the exact second the break window
                // opens and closes instead of up to a whole poll interval
                // late. All the poll has to do is keep the card's own
                // break list current, in case the doctor's breaks were
                // edited while this screen sat open.
                var newCardEl = tmp.querySelector('.mdbk-queue-list-card');
                var cardEl = bodyEl.querySelector('.mdbk-queue-list-card') ||
                    (bodyEl.classList && bodyEl.classList.contains('mdbk-queue-list-card') ? bodyEl : null);
                if (newCardEl && cardEl && newCardEl.dataset.breaks !== cardEl.dataset.breaks) {
                    cardEl.dataset.breaks = newCardEl.dataset.breaks;
                    delete cardEl._mdbkBreaks;
                }
                syncBreakNotices();

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

    // ---- "On break" notice ----
    //
    // Driven off each card's own data-breaks and the server's clock
    // rather than off the 12-second poll. The break window is a fixed
    // fact known at render time, so there is nothing to ask the server
    // about: ticking it here puts the notice up and takes it down on
    // the exact second, and costs no extra requests — raising the poll
    // rate instead would have multiplied the load from every
    // waiting-room screen and phone on the page and still been late.
    //
    // The one thing the client can't know by itself is whether the
    // doctor is mid-visit right now, which would contradict an "on
    // break" notice — that's read off the pulse dot the poll already
    // keeps in sync, so it clears within a poll of the doctor resuming.
    // That one is a human action rather than a clock boundary, so a few
    // seconds either way doesn't read as wrong.
    var CLOCK_SVG = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>';
    var serverBase = null;

    function initServerBase() {
        var stamp = parseFloat(mdbk_queue_view_obj.now);
        if (isNaN(stamp)) return;
        // Anchored to when the response's first byte arrived, not to
        // whenever this script got to run — the gap between the two is
        // page parse + asset load, and counting it as zero elapsed time
        // would leave the notice permanently that far behind.
        var anchor = Date.now();
        try {
            var nav = performance.getEntriesByType('navigation')[0];
            if (nav && nav.responseStart > 0 && typeof performance.timeOrigin === 'number') {
                anchor = performance.timeOrigin + nav.responseStart;
            }
        } catch (e) {}
        serverBase = { server: stamp, at: anchor };
    }

    function nowSecondsOfDay() {
        if (!serverBase) return -1;
        return (serverBase.server + (Date.now() - serverBase.at) / 1000) % 86400;
    }

    function toSeconds(hm) {
        var parts = String(hm).split(':');
        return parseInt(parts[0], 10) * 3600 + parseInt(parts[1], 10) * 60;
    }

    function syncBreakNotices() {
        var now = nowSecondsOfDay();
        if (now < 0) return;
        document.querySelectorAll('.mdbk-queue-list-card').forEach(function(card) {
            var breaks = card._mdbkBreaks;
            if (!breaks) {
                try { breaks = JSON.parse(card.dataset.breaks || '[]'); } catch (e) { breaks = []; }
                card._mdbkBreaks = breaks;
            }
            var dot = card.querySelector('.mdbk-live-pulse-dot');
            var visiting = dot && dot.classList.contains('mdbk-live-pulse-active');
            var active = null;
            if (!visiting) {
                breaks.forEach(function(b) {
                    var from = toSeconds(b.from);
                    var to = toSeconds(b.to);
                    // Overlapping windows shouldn't happen, but if two
                    // are somehow in range the later-starting one is the
                    // more current fact.
                    if (now >= from && now < to && (!active || from > toSeconds(active.from))) active = b;
                });
            }

            var noticeEl = card.querySelector('.mdbk-queue-break-notice');
            if (!active) {
                if (noticeEl) noticeEl.remove();
                return;
            }
            var text = String(mdbk_queue_view_obj.on_break || 'On break — %s.').replace('%s', active.name);
            if (!noticeEl) {
                var heading = card.querySelector('.mdbk-queue-list-heading');
                if (!heading) return;
                noticeEl = document.createElement('div');
                noticeEl.className = 'mdbk-queue-break-notice';
                noticeEl.insertAdjacentHTML('beforeend', CLOCK_SVG);
                noticeEl.appendChild(document.createElement('span'));
                heading.insertAdjacentElement('afterend', noticeEl);
            }
            // textContent, never innerHTML — the break name is free text
            // the doctor typed into their own Edit form.
            var span = noticeEl.querySelector('span');
            if (span && span.textContent !== text) span.textContent = text;
        });
    }

    // Re-aimed at the next whole second of SERVER time each pass rather
    // than a fixed setInterval, which would start wherever the page
    // happened to finish loading and stay offset from the real boundary
    // for good.
    initServerBase();
    syncBreakNotices();
    (function schedule() {
        var now = nowSecondsOfDay();
        var delay = now < 0 ? 1000 : Math.max(50, Math.round((Math.floor(now) + 1 - now) * 1000) + 20);
        setTimeout(function() { syncBreakNotices(); schedule(); }, delay);
    })();
});
