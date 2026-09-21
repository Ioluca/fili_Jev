/* Fili admin. No dependencies. Text goes into the page through textContent only. */
(function () {
	'use strict';
	const $ = (id) => document.getElementById(id);
	const nf = new Intl.NumberFormat(FILI.locale || undefined);

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
	const T = (k) => (FILI.i18n && FILI.i18n[k]) || k;
	const PHASES = () => ({
		idle: T('phaseIdle'), index: T('phaseIndex'), finalize: T('phaseFinalize'),
		propose: T('phasePropose'), pairs_find: T('phasePairsFind'),
		pairs_judge: T('phasePairsJudge'), done: T('phaseDone')
	});
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
		$('fili-phase').textContent = PHASES()[s.phase] || '';
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
					$('fili-phase').textContent += ' ' + T('busyElsewhere');
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
		const bar = document.querySelector('.fili-toolbar');
		const view = bar ? bar.dataset.view : 'proposed';
		const undoBtn = $('fili-undo-last');
		let last = null; // the last decision, so a slip of the finger costs one click

		const bump = (key, by) => { const el = document.querySelector('[data-count="' + key + '"]'); if (el) { el.textContent = Math.max(0, (parseInt(el.textContent, 10) || 0) + by); } };

		// A decided proposal leaves the list: what stays on screen is what is left to do.
		function leave(ids, to) {
			ids.forEach((id) => { const li = list.querySelector('[data-id="' + id + '"]'); if (li) { li.remove(); } });
			bump(view, -ids.length);
			bump(to, ids.length);
			if (undoBtn && ['approved', 'rejected'].includes(to)) {
				last = { ids, from: view };
				undoBtn.hidden = false;
				undoBtn.textContent = T(to === 'approved' ? 'undoKept' : 'undoDropped').replace('%d', ids.length);
			}
			const bulkAll = document.querySelector('[data-bulk-all]');
			const left = list.querySelectorAll('.fili-row').length;
			if (bulkAll) { bulkAll.textContent = bulkAll.textContent.replace(/\d+/, left); }
			if (!left) { window.location.reload(); } // page done: bring in the next one
		}

		list.addEventListener('click', async (ev) => {
			const btn = ev.target.closest('button'); if (!btn) { return; }
			const id = btn.closest('.fili-row').dataset.id;
			btn.disabled = true;
			try {
				if (btn.dataset.to) { await call('decide', { to: btn.dataset.to, ids: [id] }); leave([id], btn.dataset.to); }
				else if ('apply' in btn.dataset) { await call('apply', { id }); leave([id], 'applied'); }
				else if ('undo' in btn.dataset) { await call('undo', { id }); leave([id], 'proposed'); }
			} catch (e) { btn.disabled = false; window.alert(e.message); }
		});

		async function bulk(to, ids) {
			if (!ids.length) { return; }
			try { await call('decide', { to, ids }); leave(ids, to); } catch (e) { window.alert(e.message); }
		}
		document.querySelectorAll('[data-bulk]').forEach((b) => b.addEventListener('click', () =>
			bulk(b.dataset.bulk, [...list.querySelectorAll('.fili-pick:checked')].map((c) => c.value))));
		document.querySelectorAll('[data-bulk-all]').forEach((b) => b.addEventListener('click', () =>
			bulk(b.dataset.bulkAll, [...list.querySelectorAll('.fili-row')].map((li) => li.dataset.id))));

		// every proposal of this view, not just the ones on screen
		document.querySelectorAll('[data-every]').forEach((b) => b.addEventListener('click', async () => {
			const n = b.dataset.n;
			const keep = b.dataset.every === 'approved';
			if (!window.confirm(T(keep ? 'confirmKeepAll' : 'confirmDropAll').replace('%d', n))) { return; }
			b.disabled = true;
			try { await call('decide_all', { to: b.dataset.every, from: b.dataset.from }); window.location.reload(); }
			catch (e) { b.disabled = false; window.alert(e.message); }
		}));

		if (undoBtn) {
			undoBtn.addEventListener('click', async () => {
				if (!last) { return; }
				try { await call('decide', { to: 'proposed', ids: last.ids }); window.location.reload(); } catch (e) { window.alert(e.message); }
			});
		}
	}
	/* ---- the gradual application ---- */
	const queueBox = $('fili-queue');
	if (queueBox) {
		const stateEl = $('fili-queue-state'), logEl = $('fili-queue-log');
		const go = $('fili-queue-go'), stop = $('fili-queue-stop');

		const durata = (s) => {
			if (s < 3600) { return Math.round(s / 60) + ' ' + T('minutes'); }
			const h = Math.round(s / 3600);
			return h < 48 ? h + ' ' + T('hours') : Math.round(h / 24) + ' ' + T('days');
		};
		const orario = (t) => new Date(t * 1000).toLocaleString(FILI.locale || undefined, { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' });

		function paintQueue(q) {
			if (!q.restano) {
				stateEl.textContent = T('queueEmpty');
			} else if (q.attiva) {
				stateEl.textContent = T('queueRunning').replace('%1$s', orario(q.prossima)).replace('%2$s', durata(q.eta));
			} else {
				stateEl.textContent = T('queuePaused').replace('%s', durata(q.eta));
			}
			if (go) { go.hidden = q.attiva || !q.restano; }
			if (stop) { stop.hidden = !q.attiva; }
			logEl.replaceChildren(...(q.log || []).map((r) => {
				const li = document.createElement('li');
				li.textContent = orario(r.t) + '  ' + r.m;
				return li;
			}));
		}

		paintQueue(FILI.queue);
		if (go) { go.addEventListener('click', async () => { try { paintQueue(await call('queue', { cosa: 'avvia' })); } catch (e) { window.alert(e.message); } }); }
		if (stop) { stop.addEventListener('click', async () => { paintQueue(await call('queue', { cosa: 'ferma' })); }); }
		// a wake-up can land while the page is open: check now and then, without insisting
		window.setInterval(async () => { try { paintQueue(await call('queue', { cosa: 'stato' })); } catch (e) { /* offline: riprova al prossimo giro */ } }, 60000);
	}

	/* ---- the key ---- */
	const keyChange = $('fili-key-change'), keyBox = $('fili-key-box');
	if (keyChange && keyBox) {
		keyChange.addEventListener('click', () => {
			keyBox.hidden = false;
			keyChange.hidden = true;
			const f = $('fili-key');
			if (f) { f.focus(); }
		});
	}
	const keyShow = $('fili-key-show'), keySnippet = $('fili-key-snippet'), keyDel = $('fili-key-del');
	if (keyShow) {
		keyShow.addEventListener('click', async () => {
			try {
				const d = await call('key_line');
				keySnippet.textContent = d.line;
				keySnippet.hidden = false;
				keyShow.hidden = true;
			} catch (e) { window.alert(e.message); }
		});
	}
	if (keyDel) {
		keyDel.addEventListener('click', async () => {
			if (!window.confirm(T('confirmKeyDelete'))) { return; }
			try { await call('key_delete'); window.location.reload(); } catch (e) { window.alert(e.message); }
		});
	}

	const adopt = $('fili-adopt');
	if (adopt) { adopt.addEventListener('click', async () => { try { await call('threshold'); window.location.reload(); } catch (e) { window.alert(e.message); } }); }
})();
