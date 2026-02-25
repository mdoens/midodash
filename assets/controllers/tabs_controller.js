import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['tab', 'panel'];

    connect() {
        this.showTab(this.tabTargets[0]?.dataset.tab || 'overview');
    }

    switch(event) {
        event.preventDefault();
        this.showTab(event.currentTarget.dataset.tab);
    }

    showTab(tabId) {
        this.tabTargets.forEach(t => {
            t.classList.toggle('active', t.dataset.tab === tabId);
        });
        this.panelTargets.forEach(p => {
            p.classList.toggle('active', p.dataset.panel === tabId);
        });
    }
}
