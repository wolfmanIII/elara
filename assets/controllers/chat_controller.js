// assets/controllers/chat_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['form', 'input', 'submitButton', 'submitLabel', 'spinner'];
    static values = {
        endpoint: String,
        streamEndpoint: String,
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
                        ? `Backend: ${data.source}
                           Test mode: ${data.test_mode}
                           Offline fallback: ${data.offline_fallback}`
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

        this.appendUserMessage(question);
        this.inputTarget.value = '';

        if (this.hasStreamEndpointValue) {
            await this.submitStream(question);
        } else {
            await this.submitClassic(question);
        }
    }

    async submitClassic(question) {
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

    async submitStream(question) {
        this.toggleLoading(true);

        const assistantBubble = this.startAssistantMessage();

        try {
            const resp = await fetch(this.streamEndpointValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ question }),
            });

            if (!resp.ok || !resp.body) {
                const data = await resp.json().catch(() => ({}));
                this.updateAssistantMessage(
                    assistantBubble,
                    data.error || data.answer || 'Impossibile ottenere una risposta.'
                );
                this.finishAssistantMessage();
                return;
            }

            const reader = resp.body.getReader();
            const decoder = new TextDecoder('utf-8');
            let buffer = '';

            while (true) {
                const { value, done } = await reader.read();
                if (done) {
                    break;
                }
                buffer += decoder.decode(value, { stream: true });
                let delimiterIndex;
                while ((delimiterIndex = buffer.indexOf('\n\n')) !== -1) {
                    const event = buffer.slice(0, delimiterIndex);
                    buffer = buffer.slice(delimiterIndex + 2);

                    const dataLine = event
                        .split('\n')
                        .filter((line) => line.startsWith('data:'))
                        .map((line) => line.slice(5).trim())
                        .join('\n');

                    if (!dataLine) {
                        continue;
                    }

                    let payload;
                    try {
                        payload = JSON.parse(dataLine);
                    } catch {
                        continue;
                    }

                    if (payload.chunk) {
                        this.updateAssistantMessage(assistantBubble, payload.chunk);
                    }

                    if (payload.error) {
                        this.updateAssistantMessage(assistantBubble, payload.error);
                        this.finishAssistantMessage();
                        this.toggleLoading(false);
                        this.scrollToBottom();
                        return;
                    }

                    if (payload.done) {
                        this.finishAssistantMessage();
                        this.toggleLoading(false);
                        this.scrollToBottom();
                        return;
                    }
                }
            }

            this.finishAssistantMessage();
        } catch (e) {
            this.updateAssistantMessage(assistantBubble, 'Errore di rete, riprova più tardi.');
            this.finishAssistantMessage();
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
                <code><b><em>Utente</em></b></code>
            </div>
            <div class="chat-bubble chat-bubble-secondary">
                ${this.escapeHtml(text)}
            </div>
        `;

        this.chatLog.appendChild(wrapper);
        this.scrollToBottom();
    }

    appendAssistantMessage(text) {
        const bubble = this.startAssistantMessage();
        this.updateAssistantMessage(bubble, text);
        this.finishAssistantMessage();
    }

    startAssistantMessage() {
        const wrapper = document.createElement('div');
        wrapper.className = 'chat chat-start';

        wrapper.innerHTML = `
            <div class="chat-image avatar">
                <div class="w-8 rounded-full ring ring-primary ring-offset-base-100 ring-offset-2 flex items-center justify-center text-xs font-bold">
                    <span>Al</span>
                </div>
            </div>
            <div class="chat-header">
                <code><b><em>Almeno</em></b></code>
            </div>
            <div class="chat-bubble chat-bubble-primary"></div>
        `;

        this.chatLog.appendChild(wrapper);
        const bubble = wrapper.querySelector('.chat-bubble');
        return bubble;
    }

    updateAssistantMessage(bubble, text) {
        if (!bubble || !text) {
            return;
        }
        bubble.innerHTML += `${this.escapeHtml(text).replace(/\n/g, '<br>')}`;
        this.scrollToBottom();
    }

    finishAssistantMessage() {
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
