document.addEventListener("DOMContentLoaded", function () {
    const textareas = document.querySelectorAll("textarea, input[type='text']");
    
    textareas.forEach(input => {
        const key = input.name || input.id;
        if (!key) return;
        
        // Unique storage key based on page path and input identifier
        const storageKey = "draft_" + window.location.pathname + "_" + key;
        
        // Restore saved draft if available
        const savedDraft = localStorage.getItem(storageKey);
        if (savedDraft !== null && input.value === "") {
            input.value = savedDraft;
        }
        
        // Save draft as user types
        input.addEventListener("input", function () {
            localStorage.setItem(storageKey, input.value);
        });
    });

    // Clear drafts upon successful form submission
    document.querySelectorAll("form").forEach(form => {
        form.addEventListener("submit", function () {
            textareas.forEach(input => {
                const key = input.name || input.id;
                if (!key) return;
                const storageKey = "draft_" + window.location.pathname + "_" + key;
                localStorage.removeItem(storageKey);
            });
        });
    });
});

