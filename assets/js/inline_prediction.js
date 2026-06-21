/*
 * Inline prediction saving + deadline countdowns.
 *
 * Shared by the predictions page and the home "today's matches" cards.
 * Uses event delegation on `document`, so it keeps working when the home
 * Turbo Frame reloads its content.
 */
(() => {
    const updateCountdowns = () => {
        document.querySelectorAll('.js-deadline-countdown').forEach((element) => {
            const rawDeadline = element.dataset.deadline;
            const deadline = new Date(rawDeadline);

            if (!rawDeadline || Number.isNaN(deadline.getTime())) {
                element.textContent = '';
                return;
            }

            const now = new Date();
            const diffMs = deadline.getTime() - now.getTime();
            if (diffMs <= 0) {
                element.textContent = 'Ventana cerrada';
                return;
            }

            const minutes = Math.floor(diffMs / 60000);
            const hours = Math.floor(minutes / 60);
            const remMinutes = minutes % 60;

            if (hours > 0) {
                element.textContent = `Cierra en ${hours}h ${remMinutes}m`;
                return;
            }

            element.textContent = `Cierra en ${minutes}m`;
        });
    };

    updateCountdowns();
    setInterval(updateCountdowns, 30000);
    document.addEventListener('turbo:load', updateCountdowns);
    // Render inmediato de los countdowns al cargar un Turbo Frame diferido (home).
    document.addEventListener('turbo:frame-load', updateCountdowns);

    async function saveSingleForm(form) {
        const btn = form.querySelector('button');
        const feedback = form.querySelector('.prediction-feedback');
        const prevText = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Guardando…';
        feedback.textContent = '';
        feedback.className = 'prediction-feedback pt-2';
        try {
            const res = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await res.json();
            if (data.success) {
                btn.textContent = 'Editar';
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-outline-primary');
                feedback.textContent = '✓';
                feedback.classList.add('text-success');
                setTimeout(() => { feedback.textContent = ''; feedback.className = 'prediction-feedback pt-2'; }, 3000);
                return true;
            } else {
                btn.textContent = prevText;
                feedback.textContent = data.error ?? 'Error';
                feedback.classList.add('text-danger');
                setTimeout(() => { feedback.textContent = ''; feedback.className = 'prediction-feedback pt-2'; }, 3000);
                return false;
            }
        } catch {
            btn.textContent = prevText;
            feedback.textContent = 'Error de red';
            feedback.classList.add('text-danger');
            setTimeout(() => { feedback.textContent = ''; feedback.className = 'prediction-feedback pt-2'; }, 3000);
            return false;
        } finally {
            btn.disabled = false;
        }
    }

    document.addEventListener('submit', async (e) => {
        if (!e.target.classList.contains('prediction-form')) return;
        e.preventDefault();
        await saveSingleForm(e.target);
    });

    document.addEventListener('click', async (e) => {
        if (e.target.id !== 'btn-save-all') return;
        const btnSaveAll = e.target;
        const forms = [...document.querySelectorAll('.prediction-form')].filter((form) => {
            const home = form.querySelector('input[name="home"]');
            const away = form.querySelector('input[name="away"]');
            // skip forms with natively disabled inputs (window closed)
            if (home && home.hasAttribute('disabled')) return false;
            return home && away && home.value !== '' && away.value !== '';
        });
        if (forms.length === 0) {
            const fb = document.getElementById('save-all-feedback');
            fb.textContent = 'Completa al menos un marcador abierto para guardar todo.';
            fb.className = 'text-warning fw-semibold';
            setTimeout(() => { fb.textContent = ''; fb.className = 'text-success fw-semibold'; }, 3000);
            return;
        }
        btnSaveAll.disabled = true;
        btnSaveAll.textContent = 'Guardando…';
        const results = await Promise.all(forms.map(saveSingleForm));
        const saved = results.filter(Boolean).length;
        const failed = results.length - saved;
        btnSaveAll.disabled = false;
        btnSaveAll.textContent = 'Guardar todo';
        const fb = document.getElementById('save-all-feedback');
        if (failed === 0) {
            fb.textContent = `✓ ${saved} predicción${saved !== 1 ? 'es' : ''} guardada${saved !== 1 ? 's' : ''}`;
            fb.className = 'text-success fw-semibold';
        } else {
            fb.textContent = `${saved} guardadas, ${failed} con error`;
            fb.className = 'text-warning fw-semibold';
        }
        setTimeout(() => { fb.textContent = ''; fb.className = 'text-success fw-semibold'; }, 4000);
    });
})();
