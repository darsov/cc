(() => {
    'use strict';

    const fileInput = document.getElementById('refund-xlsx');
    const submitButton = document.getElementById('refund-submit');
    if (!(fileInput instanceof HTMLInputElement) || !(submitButton instanceof HTMLButtonElement)) {
        return;
    }

    const refreshState = () => {
        submitButton.disabled = !fileInput.files || fileInput.files.length === 0;
    };

    fileInput.addEventListener('change', refreshState);
    refreshState();
})();
