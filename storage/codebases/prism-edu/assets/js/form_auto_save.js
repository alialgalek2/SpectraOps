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

    // Preserve drafts by default. A request can fail after submit (expired
    // session, validation error, network loss), so clearing here loses work.
    // Pages that have a verified success flow may opt in explicitly.
    document.querySelectorAll("form").forEach(form => {
        form.addEventListener("submit", function () {
            if (!form.hasAttribute('data-clear-draft-on-submit')) return;
            textareas.forEach(input => {
                const key = input.name || input.id;
                if (!key) return;
                const storageKey = "draft_" + window.location.pathname + "_" + key;
                localStorage.removeItem(storageKey);
            });
        });
    });
});

