export const validators = {

    required(field, config) {

        const value = field.value.trim();

        if (value === "") {
            return {
                valid: false,
                message: config.message ?? "Dieses Feld ist erforderlich."
            };
        }

        return { valid: true };
    },

    regex(field, rule) {

        const value = field.value.trim();

        if (value === "") {
            return { valid: true };
        }

        const regex = new RegExp(rule.pattern);

        return {
            valid: regex.test(value),
            message: rule.message
        };
    },

    email(field, rule) {

        const value = field.value.trim();

        if (value === "") {
            return { valid: true };
        }

        const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

        return {
            valid: regex.test(value),
            message: rule.message
        };
    },

    length(field, rule) {

        const value = field.value.trim();

        if (value === "") {
            return { valid: true };
        }

        return {
            valid: value.length === rule.value,
            message: rule.message
        };
    },

    minLength(field, rule) {

        const value = field.value.trim();

        // Optionales Feld -> keine Prüfung
        if (value === "") {
            return { valid: true };
        }

        if (value.length < rule.value) {
            return {
                valid: false,
                message: rule.message
            };
        }

        return {
            valid: true
        };
    },

    maxLength(field, rule) {

        const value = field.value.trim();

        if (value === "") {
            return { valid: true };
        }

        return {
            valid: value.length <= rule.value,
            message: rule.message
        };
    },

    number(field, rule) {
        const value = field.value.trim();
        if (value === "") {
            return { valid: true };
        }
        return {
            valid: !isNaN(Number(value)),
            message: rule.message
        };

    },

    integer(field, rule) {

        const value = field.value.trim();

        if (value === "") {
            return { valid: true };
        }

        return {
            valid: Number.isInteger(Number(value)),
            message: rule.message
        };

    },

    min(field, rule) {

        const value = field.value.trim();

        if (value === "") {
            return { valid: true };
        }

        return {
            valid: Number(value) >= rule.value,
            message: rule.message
        };
    },

    max(field, rule) {

        const value = field.value.trim();

        if (value === "") {
            return { valid: true };
        }

        return {
            valid: Number(value) <= rule.value,
            message: rule.message
        };
    },

    range(field, rule) {

        const value = field.value.trim();

        if (value === "") {
            return { valid: true };
        }

        const number = Number(value);

        return {
            valid: number >= rule.min && number <= rule.max,
            message: rule.message
        };

    },

    url(field, rule) {

        const value = field.value.trim();

        if (value === "") {
            return { valid: true };
        }

        try {

            new URL(value);

            return { valid: true };

        } catch {

            return {
                valid: false,
                message: rule.message
            };

        }

    },

    date(field, rule) {

        const value = field.value.trim();

        if (value === "") {
            return { valid: true };
        }

        return {
            valid: !isNaN(Date.parse(value)),
            message: rule.message
        };

    },

    afterDate(field, rule) {
        const value = field.value;
        if (value === "") {
            return { valid: true };
        }
        return {
            valid: value >= rule.value,
            message: rule.message
        };
    },

    beforeDate(field, rule) {

        const value = field.value.trim();

        if (value === "") {
            return { valid: true };
        }

        return {
            valid: new Date(value) < new Date(rule.value),
            message: rule.message
        };

    },

    equals(field, rule) {

        const other = document.querySelector(`[name="${rule.field}"]`);

        if (!other) {
            return { valid: true };
        }

        return {
            valid: field.value === other.value,
            message: rule.message
        };

    },

    fileSize(field, rule) {

        if (field.type !== "file") {
            return { valid: true };
        }

        if (!field.files || field.files.length === 0) {
            return { valid: true };
        }

        return {
            valid: field.files[0].size <= rule.value,
            message: rule.message
        };

    },

    fileExtension(field, rule) {

        if (field.type !== "file") {
            return { valid: true };
        }

        if (!field.files || field.files.length === 0) {
            return { valid: true };
        }

        const extension = field.files[0].name
            .split(".")
            .pop()
            .toLowerCase();

        return {
            valid: rule.extensions.includes(extension),
            message: rule.message
        };

    },
};