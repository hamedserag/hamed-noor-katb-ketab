const arabicDigits = new Intl.NumberFormat('ar-EG', { useGrouping: false });
const invitation = document.getElementById('invitation');
const openButton = document.getElementById('openInvitation');
const musicToggle = document.getElementById('musicToggle');
const musicFallback = document.getElementById('musicFallback');
const mapLink = document.getElementById('mapLink');
const rsvpForm = document.getElementById('rsvpForm');
const formStatus = document.getElementById('formStatus');
const calendarLink = document.getElementById('calendarLink');
const gallery = document.getElementById('gallery');
const photoUploadForm = document.getElementById('photoUploadForm');
const photoGuestName = document.getElementById('photoGuestName');
const guestPhotos = document.getElementById('guestPhotos');
const photoSelection = document.getElementById('photoSelection');
const photoUploadButton = document.getElementById('photoUploadButton');
const photoUploadStatus = document.getElementById('photoUploadStatus');
const photoUploadProgress = document.getElementById('photoUploadProgress');
const photoUploadProgressBar = photoUploadProgress?.querySelector('span');

const MAP_URL = 'https://maps.app.goo.gl/U77XKBWKZwy4YmFH8?g_st=ic';
const VIDEO_ID = '2c0UFobfOiM';
const eventDate = new Date('2026-10-03T18:00:00+03:00');
const MAX_GUEST_PHOTOS = 8;
const MAX_GUEST_PHOTO_BYTES = 10 * 1024 * 1024;

let player = null;
let playerReady = false;
let requestedMusic = false;
let musicPlaying = false;
let invitationOpened = false;
let touchStartY = null;
let selectedGuestPhotos = [];

mapLink.href = MAP_URL;
mapLink.addEventListener('click', () => {
  mapLink.href = MAP_URL;
});

function updateCountdown() {
  const diff = Math.max(0, eventDate.getTime() - Date.now());
  const values = {
    days: Math.floor(diff / 86400000),
    hours: Math.floor((diff % 86400000) / 3600000),
    minutes: Math.floor((diff % 3600000) / 60000),
    seconds: Math.floor((diff % 60000) / 1000)
  };

  Object.entries(values).forEach(([id, value]) => {
    const element = document.getElementById(id);
    if (element) element.textContent = arabicDigits.format(value);
  });
}

function updateMusicButton(isPlaying) {
  musicPlaying = isPlaying;
  musicToggle.classList.toggle('playing', isPlaying);
  musicToggle.querySelector('span').textContent = isPlaying ? '❚❚' : '♫';
  musicToggle.setAttribute('aria-label', isPlaying ? 'إيقاف الموسيقى' : 'تشغيل الموسيقى');
}

function showMusicFallback() {
  musicFallback.hidden = false;
  musicFallback.classList.add('visible');
}

function playMusic() {
  requestedMusic = true;

  if (!playerReady || !player) {
    musicToggle.classList.add('loading');
    return;
  }

  try {
    player.unMute();
    player.setVolume(65);
    player.playVideo();
    updateMusicButton(true);
    musicToggle.classList.remove('loading');
  } catch (error) {
    console.error('Could not start YouTube music:', error);
    updateMusicButton(false);
    showMusicFallback();
  }
}

function pauseMusic() {
  requestedMusic = false;
  if (playerReady && player) player.pauseVideo();
  updateMusicButton(false);
}

window.onYouTubeIframeAPIReady = function () {
  player = new YT.Player('musicPlayer', {
    width: '1',
    height: '1',
    videoId: VIDEO_ID,
    playerVars: {
      autoplay: 0,
      controls: 0,
      disablekb: 1,
      fs: 0,
      loop: 1,
      playlist: VIDEO_ID,
      playsinline: 1,
      rel: 0
    },
    events: {
      onReady: (event) => {
        playerReady = true;
        event.target.setVolume(65);
        musicToggle.classList.remove('loading');
        if (requestedMusic) playMusic();
      },
      onStateChange: (event) => {
        if (event.data === YT.PlayerState.PLAYING) updateMusicButton(true);
        if (event.data === YT.PlayerState.PAUSED || event.data === YT.PlayerState.ENDED) updateMusicButton(false);
      },
      onError: (event) => {
        console.error('YouTube player error:', event.data);
        requestedMusic = false;
        updateMusicButton(false);
        showMusicFallback();
      }
    }
  });
};

function removeAutoOpenListeners() {
  window.removeEventListener('wheel', handleWheel);
  window.removeEventListener('touchstart', handleTouchStart);
  window.removeEventListener('touchmove', handleTouchMove);
  window.removeEventListener('keydown', handleKeyDown);
}

function openInvitation({ scrollIntoView = true, startMusic = false } = {}) {
  if (!invitationOpened) {
    invitationOpened = true;
    invitation.hidden = false;
    document.body.classList.add('opened');
    removeAutoOpenListeners();
  }

  if (startMusic) playMusic();

  if (scrollIntoView) {
    requestAnimationFrame(() => {
      invitation.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }
}

function handleWheel(event) {
  if (event.deltaY <= 0 || invitationOpened) return;
  event.preventDefault();
  openInvitation({ scrollIntoView: true, startMusic: false });
}

function handleTouchStart(event) {
  touchStartY = event.touches[0]?.clientY ?? null;
}

function handleTouchMove(event) {
  if (invitationOpened || touchStartY === null || !event.touches[0]) return;
  const currentY = event.touches[0].clientY;
  if (touchStartY - currentY < 16) return;
  event.preventDefault();
  openInvitation({ scrollIntoView: true, startMusic: false });
}

function handleKeyDown(event) {
  const openingKeys = ['ArrowDown', 'PageDown', ' ', 'Spacebar'];
  if (invitationOpened || !openingKeys.includes(event.key)) return;
  event.preventDefault();
  openInvitation({ scrollIntoView: true, startMusic: false });
}

openButton.addEventListener('click', () => {
  openInvitation({ scrollIntoView: true, startMusic: true });
});

window.addEventListener('wheel', handleWheel, { passive: false });
window.addEventListener('touchstart', handleTouchStart, { passive: true });
window.addEventListener('touchmove', handleTouchMove, { passive: false });
window.addEventListener('keydown', handleKeyDown);

musicToggle.addEventListener('click', () => {
  if (musicPlaying) pauseMusic();
  else playMusic();
});

function savePreviewRsvp(data) {
  const submissions = JSON.parse(localStorage.getItem('katbKetabRsvp') || '[]');
  submissions.push({ ...data, submittedAt: new Date().toISOString() });
  localStorage.setItem('katbKetabRsvp', JSON.stringify(submissions));
}

rsvpForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  const data = Object.fromEntries(new FormData(rsvpForm).entries());
  const submitButton = rsvpForm.querySelector('button[type="submit"]');
  submitButton.disabled = true;
  formStatus.textContent = 'جارٍ تسجيل تأكيد الحضور...';

  try {
    const response = await fetch('api/rsvp.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(data)
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.ok !== true) {
      throw new Error(payload.message || 'تعذر تسجيل الرد الآن.');
    }
    formStatus.textContent = payload.message || 'تم تسجيل تأكيد حضوركم.';
    rsvpForm.reset();
  } catch (error) {
    const isStaticPreview = location.hostname.endsWith('github.io') || location.protocol === 'file:';
    if (isStaticPreview) {
      savePreviewRsvp(data);
      formStatus.textContent = 'هذه نسخة GitHub التجريبية؛ تم حفظ الرد على هذا الجهاز فقط. النسخة المنشورة على Hostinger ستسجل الرد في قاعدة البيانات.';
      rsvpForm.reset();
    } else {
      console.error('RSVP submission failed:', error);
      formStatus.textContent = error.message || 'تعذر تسجيل الرد الآن. يرجى المحاولة مرة أخرى.';
    }
  } finally {
    submitButton.disabled = false;
  }
});

function formatFileSize(bytes) {
  return `${(bytes / 1024 / 1024).toFixed(2)} MB`;
}

function renderGuestPhotoSelection() {
  if (!photoSelection) return;
  photoSelection.innerHTML = '';

  if (selectedGuestPhotos.length === 0) {
    photoSelection.hidden = true;
    return;
  }

  selectedGuestPhotos.forEach((file) => {
    const row = document.createElement('div');
    row.className = 'selected-photo';

    const name = document.createElement('span');
    name.textContent = file.name;

    const size = document.createElement('small');
    size.textContent = formatFileSize(file.size);

    row.append(name, size);
    photoSelection.appendChild(row);
  });
  photoSelection.hidden = false;
}

function setPhotoUploadProgress(current, total) {
  if (!photoUploadProgress || !photoUploadProgressBar) return;
  if (total <= 0) {
    photoUploadProgress.hidden = true;
    photoUploadProgressBar.style.width = '0%';
    return;
  }
  photoUploadProgress.hidden = false;
  photoUploadProgressBar.style.width = `${Math.round((current / total) * 100)}%`;
}

guestPhotos?.addEventListener('change', () => {
  photoUploadStatus.textContent = '';
  photoUploadStatus.className = 'photo-upload-status';

  const files = Array.from(guestPhotos.files || []);
  const accepted = [];
  const rejected = [];

  files.slice(0, MAX_GUEST_PHOTOS).forEach((file) => {
    if (file.size <= 0 || file.size > MAX_GUEST_PHOTO_BYTES) {
      rejected.push(`${file.name} أكبر من ١٠MB`);
      return;
    }
    accepted.push(file);
  });

  if (files.length > MAX_GUEST_PHOTOS) {
    rejected.push(`يمكن رفع ${MAX_GUEST_PHOTOS} صور فقط في المرة الواحدة`);
  }

  selectedGuestPhotos = accepted;
  renderGuestPhotoSelection();

  if (rejected.length > 0) {
    photoUploadStatus.textContent = rejected.join(' — ');
    photoUploadStatus.classList.add('error');
  }
});

photoUploadForm?.addEventListener('submit', async (event) => {
  event.preventDefault();

  if (selectedGuestPhotos.length === 0) {
    photoUploadStatus.textContent = 'اختر صورة واحدة على الأقل.';
    photoUploadStatus.className = 'photo-upload-status error';
    return;
  }

  const isStaticPreview = location.hostname.endsWith('github.io') || location.protocol === 'file:';
  if (isStaticPreview) {
    photoUploadStatus.textContent = 'رفع الصور يعمل من نسخة Hostinger فقط لأن GitHub Pages لا يشغّل PHP.';
    photoUploadStatus.className = 'photo-upload-status error';
    return;
  }

  photoUploadButton.disabled = true;
  guestPhotos.disabled = true;
  photoUploadStatus.className = 'photo-upload-status';
  setPhotoUploadProgress(0, selectedGuestPhotos.length);

  let uploaded = 0;
  const total = selectedGuestPhotos.length;

  try {
    for (let index = 0; index < total; index += 1) {
      const file = selectedGuestPhotos[index];
      photoUploadStatus.textContent = `جارٍ رفع الصورة ${arabicDigits.format(index + 1)} من ${arabicDigits.format(total)}...`;

      const formData = new FormData();
      formData.append('guest_name', photoGuestName?.value.trim() || '');
      formData.append('photo', file, file.name);

      const response = await fetch('api/upload-photo.php', {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: formData
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload.ok !== true) {
        throw new Error(payload.message || `تعذر رفع ${file.name}.`);
      }

      uploaded += 1;
      setPhotoUploadProgress(uploaded, total);
    }

    photoUploadStatus.textContent = `تم رفع ${arabicDigits.format(uploaded)} صورة بنجاح إلى ألبومنا. شكرًا لمشاركتنا اللحظة.`;
    photoUploadStatus.className = 'photo-upload-status success';
    selectedGuestPhotos = [];
    guestPhotos.value = '';
    renderGuestPhotoSelection();
    await loadUploadedImages();
  } catch (error) {
    console.error('Guest photo upload failed:', error);
    photoUploadStatus.textContent = `تم رفع ${arabicDigits.format(uploaded)} من ${arabicDigits.format(total)}. ${error.message || 'تعذر إكمال رفع الصور.'}`;
    photoUploadStatus.className = 'photo-upload-status error';
  } finally {
    photoUploadButton.disabled = false;
    guestPhotos.disabled = false;
    if (uploaded === total) {
      setTimeout(() => setPhotoUploadProgress(0, 0), 900);
    }
  }
});

async function loadUploadedImages() {
  if (!gallery) return;
  try {
    const response = await fetch('api/images.php', { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
    if (!response.ok) return;
    const payload = await response.json();
    if (!payload.ok || !Array.isArray(payload.images)) return;

    gallery.querySelectorAll('[data-uploaded-image]').forEach((element) => element.remove());

    payload.images.forEach((item) => {
      const figure = document.createElement('figure');
      figure.className = 'photo-card uploaded-card';
      figure.dataset.uploadedImage = 'true';

      const img = document.createElement('img');
      img.src = item.url;
      img.alt = item.altText || item.caption || 'صورة من حكايتنا';
      img.loading = 'lazy';
      figure.appendChild(img);

      if (item.caption) {
        const caption = document.createElement('figcaption');
        caption.textContent = item.caption;
        figure.appendChild(caption);
      }

      gallery.appendChild(figure);
    });
  } catch (error) {
    // GitHub Pages is a static preview and intentionally has no PHP API.
    if (!location.hostname.endsWith('github.io')) {
      console.error('Could not load uploaded gallery images:', error);
    }
  }
}

const calendarStart = '20261003T143000Z';
const calendarEnd = '20261003T180000Z';
const calendarTitle = 'كتب كتاب حامد ونور';
const calendarDetails = `استقبال الضيوف ٥:٣٠ مساءً، وبدء مراسم كتب الكتاب ٦:٠٠ مساءً. الموقع: ${MAP_URL}`;
const calendarLocation = 'مسجد قصر محمد علي بالمنيل';
calendarLink.href = `https://calendar.google.com/calendar/render?action=TEMPLATE&text=${encodeURIComponent(calendarTitle)}&dates=${calendarStart}/${calendarEnd}&details=${encodeURIComponent(calendarDetails)}&location=${encodeURIComponent(calendarLocation)}`;
calendarLink.target = '_blank';
calendarLink.rel = 'noopener noreferrer';

updateCountdown();
setInterval(updateCountdown, 1000);
loadUploadedImages();
