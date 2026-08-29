(() => {
  const arabicDigits = new Intl.NumberFormat('ar-EG', { useGrouping: false });
  const showcase = document.getElementById('galleryShowcase');
  const preview = document.getElementById('gallery');
  const dialog = document.getElementById('galleryDialog');
  const wall = document.getElementById('galleryWall');
  const lightbox = document.getElementById('galleryLightbox');
  const lightboxImage = document.getElementById('galleryLightboxImage');
  const lightboxCaption = document.getElementById('galleryLightboxCaption');
  const lightboxPosition = document.getElementById('galleryLightboxPosition');
  const rail = document.getElementById('galleryThumbnailRail');
  const status = document.getElementById('galleryStatus');

  if (!showcase || !preview || !dialog || !wall || !lightbox || !lightboxImage) return;

  showcase.hidden = false;
  showcase.classList.add('gallery-loading');

  let items = [];
  let activeIndex = -1;

  function replaceControl(id, handler) {
    const old = document.getElementById(id);
    if (!old) return null;
    const fresh = old.cloneNode(true);
    old.replaceWith(fresh);
    fresh.addEventListener('click', handler);
    return fresh;
  }

  function setStatus(message = '', kind = '') {
    if (!status) return;
    status.textContent = message;
    status.className = `gallery-status${kind ? ` ${kind}` : ''}`;
    status.hidden = message === '';
  }

  function orientation(item) {
    const w = Number(item.width || 0);
    const h = Number(item.height || 0);
    if (!w || !h) return 'unknown';
    const r = w / h;
    if (r > 1.28) return 'landscape';
    if (r < .78) return 'portrait';
    return 'square';
  }

  function createTile(item, index, context) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `gallery-flow-item ${orientation(item)}`;
    button.dataset.galleryRuntime = '1';
    button.setAttribute('aria-label', item.caption ? `عرض الصورة: ${item.caption}` : `عرض الصورة ${index + 1}`);

    const img = document.createElement('img');
    img.src = item.url;
    img.alt = item.altText || item.caption || 'صورة من حكايتنا';
    img.loading = context === 'preview' && index < 2 ? 'eager' : 'lazy';
    img.decoding = 'async';
    if (Number(item.width) > 0) img.width = Number(item.width);
    if (Number(item.height) > 0) img.height = Number(item.height);
    button.appendChild(img);

    if (item.caption) {
      const caption = document.createElement('span');
      caption.className = 'gallery-flow-caption';
      caption.textContent = item.caption;
      button.appendChild(caption);
    }

    button.addEventListener('click', () => {
      openDialog();
      showLightbox(index);
    });
    return button;
  }

  function render() {
    preview.replaceChildren();
    wall.replaceChildren();

    if (!items.length) {
      showcase.classList.remove('gallery-loading');
      setStatus('لا توجد صور منشورة في الألبوم حتى الآن.', 'empty');
      const button = document.getElementById('openFullGallery');
      if (button) button.disabled = true;
      return;
    }

    setStatus('');
    showcase.classList.remove('gallery-loading');
    items.slice(0, 7).forEach((item, index) => preview.appendChild(createTile(item, index, 'preview')));
    items.forEach((item, index) => wall.appendChild(createTile(item, index, 'wall')));

    const button = document.getElementById('openFullGallery');
    if (button) {
      button.disabled = false;
      button.textContent = `عرض الألبوم كاملًا — ${arabicDigits.format(items.length)} صورة`;
    }
  }

  function openDialog() {
    if (!dialog.open) {
      if (typeof dialog.showModal === 'function') dialog.showModal();
      else dialog.setAttribute('open', '');
    }
    document.body.classList.add('gallery-is-open');
  }

  function closeDialog() {
    closeLightbox();
    if (dialog.open && typeof dialog.close === 'function') dialog.close();
    else dialog.removeAttribute('open');
    document.body.classList.remove('gallery-is-open');
  }

  function closeLightbox() {
    lightbox.hidden = true;
    activeIndex = -1;
    lightboxImage.removeAttribute('src');
    if (rail) rail.replaceChildren();
  }

  function renderRail() {
    if (!rail || activeIndex < 0 || !items.length) return;
    rail.replaceChildren();
    const start = Math.max(0, activeIndex - 2);
    const end = Math.min(items.length - 1, activeIndex + 2);

    for (let i = start; i <= end; i += 1) {
      const distance = Math.abs(i - activeIndex);
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `gallery-thumb ${distance === 0 ? 'current' : distance === 1 ? 'near' : 'far'}`;
      button.setAttribute('aria-label', `عرض الصورة ${i + 1}`);
      if (distance === 0) button.setAttribute('aria-current', 'true');
      const img = document.createElement('img');
      img.src = items[i].url;
      img.alt = '';
      button.appendChild(img);
      button.addEventListener('click', () => showLightbox(i));
      rail.appendChild(button);
    }
  }

  function showLightbox(index) {
    if (!items.length) return;
    activeIndex = (index + items.length) % items.length;
    const item = items[activeIndex];
    lightboxImage.src = item.url;
    lightboxImage.alt = item.altText || item.caption || 'صورة من حكايتنا';
    if (lightboxCaption) lightboxCaption.textContent = item.caption || '';
    if (lightboxPosition) lightboxPosition.textContent = `${arabicDigits.format(activeIndex + 1)} / ${arabicDigits.format(items.length)}`;
    lightbox.hidden = false;
    renderRail();
  }

  const openButton = replaceControl('openFullGallery', () => {
    if (!items.length) return;
    openDialog();
  });
  if (openButton) openButton.disabled = true;
  replaceControl('closeFullGallery', closeDialog);
  replaceControl('closeLightbox', closeLightbox);
  replaceControl('previousGalleryImage', () => activeIndex >= 0 && showLightbox(activeIndex - 1));
  replaceControl('nextGalleryImage', () => activeIndex >= 0 && showLightbox(activeIndex + 1));

  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) closeDialog();
  });
  dialog.addEventListener('close', () => {
    document.body.classList.remove('gallery-is-open');
    closeLightbox();
  });
  document.addEventListener('keydown', (event) => {
    if (!dialog.open || activeIndex < 0) return;
    if (event.key === 'ArrowLeft') showLightbox(activeIndex + 1);
    if (event.key === 'ArrowRight') showLightbox(activeIndex - 1);
    if (event.key === 'Escape') {
      event.preventDefault();
      closeLightbox();
    }
  });

  async function load() {
    setStatus('جارٍ تحميل صور الألبوم…');
    try {
      const response = await fetch(`api/images.php?gallery=${Date.now()}`, {
        headers: { Accept: 'application/json' },
        cache: 'no-store'
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload.ok !== true || !Array.isArray(payload.images)) {
        throw new Error('تعذر تحميل الألبوم.');
      }
      items = payload.images.filter((item) => item && item.url);
      render();
      // Reassert the runtime render after the legacy gallery request completes.
      window.setTimeout(render, 650);
    } catch (error) {
      console.error('Gallery runtime failed:', error);
      showcase.classList.remove('gallery-loading');
      setStatus('تعذر تحميل الألبوم الآن. حاول تحديث الصفحة بعد قليل.', 'error');
      if (openButton) openButton.disabled = true;
    }
  }

  load();
})();
