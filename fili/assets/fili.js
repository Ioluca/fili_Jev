/* Fili admin. No dependencies. Text goes into the page through textContent only. */
(function () {
	'use strict';
	const $ = (id) => document.getElementById(id);
	const nf = new Intl.NumberFormat(document.documentElement.lang || 'it-IT');

	async function call(action, data) {
		const body = new URLSearchParams({ action: 'fili_' + action, nonce: FILI.nonce });
		Object.entries(data || {}).forEach(([k, v]) => {
			if (Array.isArray(v)) { v.forEach((x) => body.append(k + '[]', x)); } else { body.append(k, v); }
		});
		const r = await fetch(FILI.ajax, { method: 'POST', credentials: 'same-origin', body });
		const j = await r.json().catch(() => ({ success: false, data: { message: 'Risposta non leggibile dal server.' } }));
		if (!j.success) { throw new Error((j.data && j.data.message) || 'Errore.'); }
		return j.data;
	}

	/* ---- the run ---- */
	const PHASES = {
		idle: 'Fermo.', index: 'Leggo gli articoli e costruisco l’indice…', finalize: 'Peso le parole…',
		propose: 'Chiedo a Jev quali legami reggono…', pairs_find: 'Cerco gli articoli che si assomigliano troppo…',
		pairs_judge: 'Chiedo a Jev quali raccontano la stessa notizia…', done: 'Finito. Le proposte ti aspettano.'
	};
	let running = false;

	function paint(s) {
		if (!$('fili-indexed')) { return; }
		$('fili-indexed').textContent = nf.format(s.indexed || 0);
		$('fili-judged').textContent = nf.format(s.judged || 0);
		$('fili-decisions').textContent = nf.format(s.decisions || 0);
		$('fili-spend').textContent = '$' + (s.spend || 0).toFixed(4);
		$('fili-budget').textContent = 'spesi questo mese, tetto $' + (s.budget || 0).toFixed(2);
		const total = s.total || 0;
		let pct = 0;
		if (s.phase === 'index') { pct = total ? 25 * (s.indexed || 0) / total : 0; }
		else if (s.phase === 'finalize') { pct = 27; }
		else if (s.phase === 'propose') { pct = 28 + (total ? 52 * (s.judged || 0) / total : 0); }
		else if (s.phase === 'pairs_find') { pct = 84; }
		else if (s.phase === 'pairs_judge') { pct = 92; }
		else if (s.phase === 'done') { pct = 100; }
		$('fili-progress').style.width = pct.toFixed(1) + '%';
		$('fili-phase').textContent = PHASES[s.phase] || '';
		const err = $('fili-error');
		err.hidden = !s.error;
		err.textContent = s.error || '';
		err.className = 'fili-note fili-err';
		const active = !['idle', 'done'].includes(s.phase);
		$('fili-start').hidden = active;
		$('fili-stop').hidden = !active;
	}

	async function loop() {
		running = true;
		try {
			let s = FILI.state;
			while (running && !['idle', 'done'].includes(s.phase)) {
				s = await call('step');
				paint(s);
				if (s.busy) { // another worker holds the run: watch it instead of fighting it
					$('fili-phase').textContent += ' (un altro processo sta già lavorando: guardo e basta)';
					await new Promise((r) => setTimeout(r, 4000));
				}
			}
		} catch (e) {
			const err = $('fili-error'); err.hidden = false; err.textContent = e.message; err.className = 'fili-note fili-err';
		}
		running = false;
	}

	if ($('fili-start')) {
		paint(Object.assign({ spend: 0, budget: 0 }, FILI.state));
		$('fili-start').addEventListener('click', async () => {
			try { FILI.state = await call('start'); paint(FILI.state); loop(); }
			catch (e) { const err = $('fili-error'); err.hidden = false; err.textContent = e.message; err.className = 'fili-note fili-err'; }
		});
		$('fili-stop').addEventListener('click', async () => { running = false; paint(await call('stop')); });
		if (!['idle', 'done'].includes(FILI.state.phase)) { loop(); } // a run was interrupted: pick it up
	}

	/* ---- proposals ---- */
	const list = document.querySelector('.fili-list');
	if (list) {
		const fade = (ids) => ids.forEach((id) => { const li = list.querySelector('[data-id="' + id + '"]'); if (li) { li.classList.add('gone'); li.querySelectorAll('button,input').forEach((b) => { b.disabled = true; }); } });
		list.addEventListener('click', async (ev) => {
			const btn = ev.target.closest('button'); if (!btn) { return; }
			const id = btn.closest('.fili-row').dataset.id;
			try {
				if (btn.dataset.to) { await call('decide', { to: btn.dataset.to, ids: [id] }); }
				else if ('apply' in btn.dataset) { await call('apply', { id }); }
				else if ('undo' in btn.dataset) { await call('undo', { id }); }
				fade([id]);
			} catch (e) { window.alert(e.message); }
		});
		document.querySelectorAll('[data-bulk]').forEach((b) => b.addEventListener('click', async () => {
			const ids = [...list.querySelectorAll('.fili-pick:checked')].map((c) => c.value);
			if (!ids.length) { return; }
			try { await call('decide', { to: b.dataset.bulk, ids }); fade(ids); } catch (e) { window.alert(e.message); }
		}));
		const all = $('fili-all');
		if (all) { all.addEventListener('change', () => list.querySelectorAll('.fili-pick:not(:disabled)').forEach((c) => { c.checked = all.checked; })); }
	}
	const adopt = $('fili-adopt');
	if (adopt) { adopt.addEventListener('click', async () => { try { await call('threshold'); window.location.reload(); } catch (e) { window.alert(e.message); } }); }
})();
