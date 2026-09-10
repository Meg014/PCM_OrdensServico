(() => {
    'use strict';

    const root = document.querySelector('[data-pcm-presentation]');
    const payloadNode = document.querySelector('[data-presentation-payload]');
    if (!root || !payloadNode || root.dataset.initialized === 'true') {
        return;
    }

    root.dataset.initialized = 'true';
    document.body.classList.add('pcm-presentation-mode');
    let payload = JSON.parse(payloadNode.textContent);
    let currentIndex = 0;
    let remaining = 15;
    let stopped = false;
    const nodes = {
        title: root.querySelector('[data-presentation-title]'),
        updated: root.querySelector('[data-presentation-updated]'),
        open: root.querySelector('[data-presentation-open]'),
        completed: root.querySelector('[data-presentation-completed]'),
        preventive: root.querySelector('[data-presentation-preventive]'),
        corrective: root.querySelector('[data-presentation-corrective]'),
        improvement: root.querySelector('[data-presentation-improvement]'),
        emergency: root.querySelector('[data-presentation-emergency]'),
        scheduled: root.querySelector('[data-presentation-scheduled]'),
        offseason: root.querySelector('[data-presentation-offseason]'),
        position: root.querySelector('[data-presentation-position]'),
        countdown: root.querySelector('[data-presentation-countdown]'),
    };
    const number = new Intl.NumberFormat('pt-BR');

    const updatedLabel = (isoDate) => {
        if (!isoDate) return 'Dados atualizados em: indisponível';
        const formatted = new Intl.DateTimeFormat('pt-BR', {
            day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
        }).format(new Date(isoDate));
        return `Dados atualizados em: ${formatted.replace(', ', ' às ')}`;
    };

    const render = () => {
        const screens = payload.screens || [];
        if (!screens.length) return;
        currentIndex %= screens.length;
        const screen = screens[currentIndex];
        nodes.title.textContent = screen.title;
        nodes.open.textContent = number.format(screen.open);
        nodes.completed.textContent = number.format(screen.completed);
        nodes.preventive.textContent = number.format(screen.preventive);
        nodes.corrective.textContent = number.format(screen.corrective);
        nodes.improvement.textContent = number.format(screen.improvement);
        for (const key of ['emergency', 'scheduled', 'offseason']) {
            nodes[key].textContent = number.format(screen[key] ?? 0);
        }
        nodes.position.textContent = `Tela ${currentIndex + 1} de ${screens.length}`;
        nodes.countdown.textContent = String(remaining);
        nodes.updated.textContent = updatedLabel(payload.updated_at);
    };

    const rotateTimer = window.setInterval(() => {
        if (stopped) return;
        remaining -= 1;
        if (remaining <= 0) {
            currentIndex = (currentIndex + 1) % payload.screens.length;
            remaining = 15;
            render();
            return;
        }
        nodes.countdown.textContent = String(remaining);
    }, 1000);

    const refreshTimer = window.setInterval(async () => {
        if (stopped) return;
        try {
            const response = await fetch(root.dataset.presentationUrl, {
                cache: 'no-store', headers: {'Accept': 'application/json'},
            });
            if (!response.ok) return;
            const nextPayload = await response.json();
            if (!nextPayload || !Array.isArray(nextPayload.screens)) return;
            const currentKey = payload.screens[currentIndex]?.key;
            payload = nextPayload;
            const preservedIndex = payload.screens.findIndex((screen) => screen.key === currentKey);
            currentIndex = preservedIndex >= 0 ? preservedIndex : 0;
            render();
        } catch (_error) {
            // A falha temporária será verificada novamente sem interromper a apresentação.
        }
    }, 30_000);

    const exit = async () => {
        if (stopped) return;
        stopped = true;
        window.clearInterval(rotateTimer);
        window.clearInterval(refreshTimer);
        try {
            if (document.fullscreenElement) await document.exitFullscreen();
        } catch (_error) {
            // A navegação normal continua mesmo se o navegador bloquear a API.
        }
        window.location.href = root.dataset.exitUrl;
    };

    root.querySelector('[data-presentation-exit]')?.addEventListener('click', exit);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') exit();
    });
    try {
        document.documentElement.requestFullscreen?.().catch(() => {});
    } catch (_error) {
        // Fullscreen é opcional; a apresentação permanece funcional.
    }
    render();
})();
