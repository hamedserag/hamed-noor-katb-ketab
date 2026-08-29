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

(function setupAlbumCommentEditor() {
  const items = Array.from(document.querySelectorAll('.album-admin-item'));
  if (!items.length) return;

  const csrf = document.querySelector('input[name="csrf"]')?.value || '';
  if (!csrf) {
    console.warn('Album comment editor: CSRF token was not found.');
    return;
  }

  const style = document.createElement('style');
  style.textContent = `
    .album-comment-tools {
      display: grid;
      gap: 8px;
      margin-top: 6px;
      padding-top: 10px;
      border-top: 1px solid var(--line);
    }
    .album-comment-edit-button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 7px;
      width: 100%;
      min-height: 40px;
      padding: 8px 12px;
      border: 1px solid rgba(226, 189, 125, .35);
      border-radius: 11px;
      background: rgba(226, 189, 125, .08);
      color: var(--gold-soft);
      cursor: pointer;
      font-weight: 800;
    }
    .album-comment-edit-button:hover,
    .album-comment-edit-button:focus-visible {
      background: rgba(226, 189, 125, .15);
      border-color: rgba(226, 189, 125, .7);
      outline: none;
    }
    .album-comment-editor {
      display: grid;
      gap: 8px;
    }
    .album-comment-editor[hidden] { display: none; }
    .album-comment-editor label {
      color: var(--gold-soft);
      font-weight: 700;
    }
    .album-comment-editor textarea {
      min-height: 90px;
      resize: vertical;
      line-height: 1.55;
    }
    .album-comment-editor-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }
    .album-comment-editor-actions button {
      flex: 1 1 110px;
      min-height: 38px;
      padding: 7px 12px;
      border-radius: 10px;
      cursor: pointer;
      font-weight: 800;
    }
    .album-comment-save {
      border: 0;
      background: linear-gradient(135deg, var(--gold-soft), #bd8d52);
      color: #2a141a;
    }
    .album-comment-cancel {
      border: 1px solid var(--line);
      background: rgba(255,255,255,.025);
      color: var(--muted);
    }
    .album-comment-editor-actions button:disabled { opacity: .55; cursor: wait; }
    .album-comment-hint,
    .album-comment-status {
      color: var(--muted);
      font-size: .74rem;
      line-height: 1.5;
    }
    .album-comment-status.is-error { color: #ffadb3; }
    .album-comment-status.is-success { color: #a7e1c1; }
  `;
  document.head.appendChild(style);

  function identifyItem(item) {
    const image = item.querySelector('img');
    if (!image) return null;

    try {
      const url = new URL(image.getAttribute('src') || image.src, window.location.href);
      const source = url.searchParams.get('type');
      const id = Number.parseInt(url.searchParams.get('id') || '', 10);
      if (!['site', 'guest'].includes(source) || !Number.isFinite(id) || id <= 0) return null;
      return { source, id };
    } catch (_error) {
      return null;
    }
  }

  const itemMap = new Map();

  items.forEach((item) => {
    const identity = identifyItem(item);
    const body = Array.from(item.children).find((child) => child.tagName === 'DIV');
    const title = body?.querySelector('strong');
    if (!identity || !body || !title) return;

    const key = `${identity.source}-${identity.id}`;
    const tools = document.createElement('div');
    tools.className = 'album-comment-tools';

    const editButton = document.createElement('button');
    editButton.type = 'button';
    editButton.className = 'album-comment-edit-button';
    editButton.innerHTML = '<span aria-hidden="true">✎</span><span>تعديل تعليق الصورة</span>';

    const editor = document.createElement('div');
    editor.className = 'album-comment-editor';
    editor.hidden = true;

    const label = document.createElement('label');
    label.textContent = 'التعليق الظاهر أسفل الصورة';

    const textarea = document.createElement('textarea');
    textarea.maxLength = 180;
    textarea.placeholder = 'اكتب التعليق الذي سيظهر للزوار أسفل الصورة';
    textarea.setAttribute('aria-label', 'تعليق الصورة');
    label.appendChild(textarea);

    const hint = document.createElement('span');
    hint.className = 'album-comment-hint';
    hint.textContent = 'اتركه فارغًا لاستخدام التعليق الافتراضي.';

    const actions = document.createElement('div');
    actions.className = 'album-comment-editor-actions';

    const saveButton = document.createElement('button');
    saveButton.type = 'button';
    saveButton.className = 'album-comment-save';
    saveButton.textContent = 'حفظ التعليق';

    const cancelButton = document.createElement('button');
    cancelButton.type = 'button';
    cancelButton.className = 'album-comment-cancel';
    cancelButton.textContent = 'إلغاء';

    const status = document.createElement('span');
    status.className = 'album-comment-status';
    status.setAttribute('role', 'status');

    actions.append(saveButton, cancelButton);
    editor.append(label, hint, actions, status);
    tools.append(editButton, editor);

    const removeForm = body.querySelector('form');
    if (removeForm) body.insertBefore(tools, removeForm);
    else body.appendChild(tools);

    const state = {
      ...identity,
      key,
      title,
      editButton,
      editor,
      textarea,
      saveButton,
      cancelButton,
      status,
      caption: '',
      displayCaption: title.textContent?.trim() || '',
    };
    itemMap.set(key, state);

    editButton.addEventListener('click', () => {
      textarea.value = state.caption;
      status.textContent = '';
      status.className = 'album-comment-status';
      editor.hidden = false;
      editButton.hidden = true;
      textarea.focus();
      textarea.setSelectionRange(textarea.value.length, textarea.value.length);
    });

    cancelButton.addEventListener('click', () => {
      editor.hidden = true;
      editButton.hidden = false;
      status.textContent = '';
      textarea.value = state.caption;
    });

    saveButton.addEventListener('click', async () => {
      const caption = textarea.value.trim();
      saveButton.disabled = true;
      cancelButton.disabled = true;
      status.textContent = 'جارٍ الحفظ…';
      status.className = 'album-comment-status';

      try {
        const formData = new FormData();
        formData.set('csrf', csrf);
        formData.set('source', state.source);
        formData.set('id', String(state.id));
        formData.set('caption', caption);

        const response = await fetch(`album-comments.php?t=${Date.now()}`, {
          method: 'POST',
          body: formData,
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { Accept: 'application/json' },
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || !result.ok) throw new Error(result.message || 'تعذر تحديث التعليق.');

        state.caption = result.caption || '';
        state.displayCaption = result.displayCaption || state.displayCaption;
        state.title.textContent = state.displayCaption;
        status.textContent = 'تم حفظ التعليق.';
        status.className = 'album-comment-status is-success';
        window.setTimeout(() => {
          editor.hidden = true;
          editButton.hidden = false;
          status.textContent = '';
        }, 650);
      } catch (error) {
        status.textContent = error instanceof Error ? error.message : 'تعذر تحديث التعليق.';
        status.className = 'album-comment-status is-error';
      } finally {
        saveButton.disabled = false;
        cancelButton.disabled = false;
      }
    });
  });

  if (!itemMap.size) return;

  fetch(`album-comments.php?t=${Date.now()}`, {
    method: 'GET',
    credentials: 'same-origin',
    cache: 'no-store',
    headers: { Accept: 'application/json' },
  })
    .then((response) => response.json().then((body) => ({ response, body })))
    .then(({ response, body }) => {
      if (!response.ok || !body.ok || !body.comments) return;
      Object.entries(body.comments).forEach(([key, comment]) => {
        const state = itemMap.get(key);
        if (!state) return;
        state.caption = String(comment.caption || '');
        state.displayCaption = String(comment.displayCaption || state.displayCaption);
        state.title.textContent = state.displayCaption;
      });
    })
    .catch((error) => console.warn('Could not load album comments:', error));
})();
