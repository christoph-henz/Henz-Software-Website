import { validators } from "./validators.js";

export class FormValidator {

    constructor(form) {

        this.form = form;

    }

    renderErrors(field, errors) {

        const box = field.parentElement.querySelector(".validation-errors");

        if (!box) return;

        if (errors.length === 0) {

            field.classList.remove(
                "border-red-500",
                "focus:border-red-500"
            );

            box.innerHTML = "";

            return;
        }

        field.classList.add(
            "border-red-500",
            "focus:border-red-500"
        );

        box.innerHTML = errors
            .map(error =>
                `<div class="text-red-400 text-sm mt-1">${error}</div>`
            )
            .join("");

    }

    async validateForm() {

        let valid = true;

        const fields = this.form.querySelectorAll(
            "input,select,textarea"
        );

        for (const field of fields) {

            if (field.disabled)
                continue;

            if (field.closest(".hidden"))
                continue;

            if (!(await this.validateField(field)))
                valid = false;

        }

        return valid;

    }

    async validateField(field) {

        const errors = [];

        const rules = JSON.parse(
            field.dataset.validators || "[]"
        );

        for (const rule of rules) {

            const validator = validators[rule.rule];

            if (!validator)
                continue;

            const result = await validator(field, rule);

            if (!result.valid) {
                errors.push(result.message);
            }

        }

        this.renderErrors(field, errors);

        return errors.length === 0;

    }
}