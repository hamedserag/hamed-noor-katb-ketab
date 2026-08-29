document.querySelectorAll('form[data-confirm], form[data-confirm-permanent]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    const message = form.dataset.confirmPermanent || form.dataset.confirm || 'تأكيد تنفيذ الإجراء؟';
    if (!window.confirm(message)) {
      event.preventDefault();
      return;
    }
    form.querySelectorAll('button[type="submit"]').forEach((button) => {
      button.disabled = true;
    });
  });
});

document.querySelectorAll('form:not([data-confirm]):not([data-confirm-permanent])').forEach((form) => {
  form.addEventListener('submit', () => {
    form.querySelectorAll('button[type="submit"]').forEach((button) => {
      button.disabled = true;
    });
  });
});
