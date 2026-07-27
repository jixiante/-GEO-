const page = document.querySelector('[data-ai-exposure-page]');

if (page) {
    const modal = page.querySelector('[data-monitor-modal]');
    const form = modal?.querySelector('form');
    const articleSelect = form?.querySelector('[name="article_id"]');
    const queryInput = form?.querySelector('[name="query"]');
    const frequencySelect = form?.querySelector('[name="frequency"]');
    const methodInput = form?.querySelector('[name="_method"]');
    const title = modal?.querySelector('[data-monitor-modal-title]');
    const submitLabel = modal?.querySelector('[data-monitor-submit-label]');

    const metricText = (key, value) => {
        const number = Number(value);
        if (!Number.isFinite(number)) {
            return '0';
        }

        return key === 'citation_rate' ? `${number.toFixed(1)}%` : String(Math.trunc(number));
    };

    const updateMetrics = (container, stats, selector) => {
        if (!stats || typeof stats !== 'object') {
            return;
        }

        container.querySelectorAll(selector).forEach((element) => {
            const key = element.dataset.aiExposureMetric
                ?? element.dataset.aiExposurePlatformMetric
                ?? element.dataset.aiExposureMonitorMetric;
            if (key && Object.hasOwn(stats, key)) {
                element.textContent = metricText(key, stats[key]);
            }
        });
    };

    const updateRealtimeOverview = (overview) => {
        updateMetrics(page, overview?.metrics, '[data-ai-exposure-metric]');

        page.querySelectorAll('[data-ai-exposure-platform]').forEach((platform) => {
            const stats = overview?.platforms?.[platform.dataset.aiExposurePlatform];
            updateMetrics(platform, stats, '[data-ai-exposure-platform-metric]');
        });

        page.querySelectorAll('[data-ai-exposure-monitor]').forEach((monitor) => {
            const stats = overview?.monitors?.[monitor.dataset.aiExposureMonitor];
            updateMetrics(monitor, stats, '[data-ai-exposure-monitor-metric]');
        });
    };

    const closeModal = () => {
        modal?.classList.add('hidden');
        modal?.setAttribute('aria-hidden', 'true');
    };

    const openModal = (button) => {
        if (!modal || !form || !articleSelect || !queryInput || !frequencySelect || !methodInput) {
            return;
        }

        const editing = button.dataset.monitorId !== undefined;
        form.action = editing
            ? page.dataset.updateUrl.replace('__MONITOR_ID__', button.dataset.monitorId)
            : page.dataset.storeUrl;
        methodInput.value = editing ? 'PUT' : 'POST';
        articleSelect.value = editing ? button.dataset.articleId : '';
        queryInput.value = editing ? button.dataset.query : '';
        frequencySelect.value = editing ? button.dataset.frequency : 'daily';
        title.textContent = editing ? page.dataset.editTitle : page.dataset.createTitle;
        submitLabel.textContent = editing ? page.dataset.saveLabel : page.dataset.createLabel;
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        (editing ? queryInput : articleSelect).focus();
    };

    page.querySelectorAll('[data-open-monitor-modal]').forEach((button) => {
        button.addEventListener('click', () => openModal(button));
    });
    modal?.querySelectorAll('[data-close-monitor-modal]').forEach((button) => {
        button.addEventListener('click', closeModal);
    });
    articleSelect?.addEventListener('change', () => {
        if (!queryInput || queryInput.value.trim() !== '') {
            return;
        }
        queryInput.value = articleSelect.selectedOptions[0]?.dataset.defaultQuery || '';
    });
    page.querySelectorAll('[data-confirm-submit]').forEach((button) => {
        button.addEventListener('click', () => {
            if (window.confirm(button.dataset.confirmSubmit)) {
                button.closest('form')?.requestSubmit();
            }
        });
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal?.classList.contains('hidden')) {
            closeModal();
        }
    });

    if (window.Echo) {
        window.Echo.private('admin.ai-exposure')
            .listen('.ai-exposure.overview.updated', updateRealtimeOverview);
    }
}
