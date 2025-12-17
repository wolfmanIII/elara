import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['button'];

    connect() {
        this.submitting = false;
    }

    handleSubmit(event) {
        if (this.submitting) {
            event.preventDefault();
            return;
        }

        this.submitting = true;
        this.buttonTargets.forEach((button) => {
            button.disabled = true;
            button.classList.add('btn-disabled');
        });
    }
}
