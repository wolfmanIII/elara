// assets/controllers/chat_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['form', 'input', 'submitButton', 'submitLabel', 'spinner'];
    static values = {
        endpoint: String,
        engineStatusUrl: String,
    };

    connect() {
        this.chatLog = this.element.querySelector('#chat-log');

        // Controllo stato motore all’avvio
        if (this.hasEngineStatusUrlValue) {
            this.checkEngineStatus();
        }

        // Gestione Invio / Shift+Invio
        if (this.hasInputTarget) {
            this.inputTarget.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    this.formTarget.requestSubmit();
                }
            });
        }
    }

    async checkEngineStatus() {
        const badge = document.getElementById('engine-status-badge');
        const sub = document.getElementById('engine-status-sub');
        const text = document.getElementById('engine-status-text');

        try {
            const resp = await fetch(this.engineStatusUrlValue, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            const data = await resp.json();

            if (data.ok) {
                if (badge) {
                    badge.classList.remove('badge-outline');
                    badge.classList.add('badge-success');
                    badge.textContent = 'Online';
                }
                if (sub) {
                    sub.textContent = data.model
                        ? `Modello: ${data.model}`
                        : 'Motore pronto';
                }
                if (text) {
                    text.textContent = data.source
                        ? `Backend: ${data.source}`
                        : 'Motore attivo e raggiungibile.';
                }
            } else {
                this.setEngineError('Motore non disponibile.');
            }
        } catch (e) {
            this.setEngineError('Errore nel controllo dello stato motore.');
        }
    }

    setEngineError(message) {
        const badge = document.getElementById('engine-status-badge');
        const sub = document.getElementById('engine-status-sub');
        const text = document.getElementById('engine-status-text');

        if (badge) {
            badge.classList.remove('badge-outline');
            badge.classList.add('badge-error');
            badge.textContent = 'Offline';
        }
        if (sub) {
            sub.textContent = 'Controlla il backend';
        }
        if (text && message) {
            text.textContent = message;
        }
    }

    async submit(event) {
        event.preventDefault();

        const question = this.inputTarget.value.trim();
        if (!question) {
            return;
        }

        // Bolla utente
        this.appendUserMessage(question);

        // Pulisco textarea
        this.inputTarget.value = '';

        // Loading
        this.toggleLoading(true);

        try {
            const resp = await fetch(this.endpointValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ question }),
            });

            const data = await resp.json();

            if (!resp.ok || data.error) {
                this.appendSystemMessage(data.error || 'Errore dal server.');
            } else {
                this.appendAssistantMessage(data.answer);
            }
        } catch (e) {
            this.appendSystemMessage('Errore di rete, riprova più tardi.');
        } finally {
            this.toggleLoading(false);
            this.scrollToBottom();
        }
    }

    appendUserMessage(text) {
        const wrapper = document.createElement('div');
        wrapper.className = 'chat chat-end';

        wrapper.innerHTML = `
            <div class="chat-image avatar">
                <div class="w-8 rounded-full bg-base-300 flex items-center justify-center text-xs font-bold">
                    <span>Tu</span>
                </div>
            </div>
            <div class="chat-header">
                Utente
            </div>
            <div class="chat-bubble chat-bubble-secondary">
                ${this.escapeHtml(text)}
            </div>
        `;

        this.chatLog.appendChild(wrapper);
        this.scrollToBottom();
    }

    appendAssistantMessage(text) {
        const wrapper = document.createElement('div');
        wrapper.className = 'chat chat-start';

        wrapper.innerHTML = `
            <div class="chat-image avatar">
                <div class="w-8 rounded-full ring ring-primary ring-offset-base-100 ring-offset-2 flex items-center justify-center text-xs font-bold">
                    <span>AI</span>
                </div>
            </div>
            <div class="chat-header">
                ELARA
            </div>
            <div class="chat-bubble chat-bubble-primary">
                ${this.escapeHtml(text).replace(/\n/g, "<br>")}<br>
            </div>
        `;

        this.chatLog.appendChild(wrapper);
        this.scrollToBottom();
    }

    appendSystemMessage(text) {
        const wrapper = document.createElement('div');
        wrapper.className = 'chat chat-start';

        wrapper.innerHTML = `
            <div class="chat-header text-xs opacity-70">
                Sistema
            </div>
            <div class="chat-bubble chat-bubble-error whitespace-pre-wrap">
                ${this.escapeHtml(text)}
            </div>
        `;

        this.chatLog.appendChild(wrapper);
        this.scrollToBottom();
    }

    toggleLoading(isLoading) {
        if (!this.hasSubmitButtonTarget) return;

        this.submitButtonTarget.disabled = isLoading;
        if (this.hasSpinnerTarget) {
            this.spinnerTarget.classList.toggle('hidden', !isLoading);
        }
        if (this.hasSubmitLabelTarget) {
            this.submitLabelTarget.textContent = isLoading ? 'Invio…' : 'Invia';
        }
    }

    scrollToBottom() {
        if (!this.chatLog) return;
        this.chatLog.scrollTop = this.chatLog.scrollHeight;
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}
