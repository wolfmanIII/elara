import daisyui from "daisyui";

export default {
    content: [
        "./assets/**/*.js",
        "./templates/**/*.html.twig",
    ],

    plugins: {
        // ✅ Aggiungi daisyUI
        daisyui,
    },

    // (opzionale) configura i temi
    daisyui: {
        themes: ["light", "dark", "cupcake", "abyss"],
    },
};
