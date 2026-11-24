(function () {
	'use strict';

	function buildUrl(base, path, query) {
		var cleanBase = base.replace(/\/+$/, '');
		var url = cleanBase + '/' + path.replace(/^\/+/, '');
		if (query) {
			url += (url.indexOf('?') === -1 ? '?' : '&') + query;
		}
		return url;
	}

	function initWidget(root) {
		var restBase = root.getAttribute('data-rest-url') || '';
		if (!restBase) {
			return;
		}

		var serviceSelect = root.querySelector('.gratis-booking-service');
		var dateInput = root.querySelector('.gratis-booking-date');
		var slotsWrap = root.querySelector('.gratis-booking-slots');
		var timeInput = root.querySelector('.gratis-booking-time');
		var form = root.querySelector('.gratis-booking-form');
		var statusEl = root.querySelector('.gratis-booking-status');

		if (!serviceSelect || !dateInput || !slotsWrap || !timeInput || !form || !statusEl) {
			return;
		}

		function setStatus(msg, isError) {
			statusEl.textContent = msg;
			statusEl.className = 'gratis-booking-status ' + (isError ? 'is-error' : 'is-success');
		}

		function renderSlots(slots) {
			slotsWrap.innerHTML = '';
			timeInput.value = '';
			if (!Array.isArray(slots) || slots.length === 0) {
				var empty = document.createElement('p');
				empty.className = 'gratis-booking-empty';
				empty.textContent = 'No available slots on this date.';
				slotsWrap.appendChild(empty);
				return;
			}
			slots.forEach(function (slot, idx) {
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'gratis-booking-slot';
				btn.textContent = slot;
				btn.setAttribute('aria-pressed', 'false');
				btn.addEventListener('click', function () {
					slotsWrap.querySelectorAll('.gratis-booking-slot').forEach(function (el) {
						el.classList.remove('is-selected');
						el.setAttribute('aria-pressed', 'false');
					});
					btn.classList.add('is-selected');
					btn.setAttribute('aria-pressed', 'true');
					timeInput.value = slot;
				});
				if (idx === 0) {
					btn.classList.add('is-selected');
					btn.setAttribute('aria-pressed', 'true');
					timeInput.value = slot;
				}
				slotsWrap.appendChild(btn);
			});
		}

		function loadSlots() {
			var serviceId = serviceSelect.value;
			var date = dateInput.value;
			if (!serviceId || !date) {
				return;
			}
			slotsWrap.textContent = 'Loading slots…';
			var url = buildUrl(restBase, 'slots/' + encodeURIComponent(serviceId), 'date=' + encodeURIComponent(date));
			fetch(url)
				.then(function (r) { return r.ok ? r.json() : []; })
				.then(renderSlots)
				.catch(function () { renderSlots([]); });
		}

		serviceSelect.addEventListener('change', loadSlots);
		dateInput.addEventListener('change', loadSlots);

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var nameVal = (form.querySelector('[name="name"]') || {}).value || '';
			var emailVal = (form.querySelector('[name="email"]') || {}).value || '';
			if (!timeInput.value) {
				setStatus('Please select a time slot.', true);
				return;
			}
			setStatus('Submitting booking…', false);
			var url = buildUrl(restBase, 'book', '');
			fetch(url, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({
					service_id: parseInt(serviceSelect.value, 10) || 0,
					date: dateInput.value,
					time: timeInput.value,
					name: nameVal.trim(),
					email: emailVal.trim()
				})
			})
				.then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
				.then(function (res) {
					if (!res.ok || !res.data || !res.data.success) {
						setStatus((res.data && res.data.error) || 'Could not complete booking.', true);
						return;
					}
					setStatus('Booking confirmed for ' + dateInput.value + ' at ' + timeInput.value + '.', false);
					form.reset();
					loadSlots();
				})
				.catch(function () {
					setStatus('Network error. Please try again.', true);
				});
		});

		loadSlots();
	}

	function boot() {
		document.querySelectorAll('.gratis-booking-widget').forEach(initWidget);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
