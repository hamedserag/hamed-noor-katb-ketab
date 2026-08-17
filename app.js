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

const MAP_URL = 'https://maps.app.goo.gl/U77XKBWKZwy4YmFH8?g_st=ic';
const VIDEO_ID = '2c0UFobfOiM';
const eventDate = new Date('2026-10-03T18:00:00+03:00');

let player = null;
let playerReady = false;
let requestedMusic = false;
let musicPlaying = false;
let invitationOpened = false;
let touchStartY = null;

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

async function loadUploadedImages() {
  if (!gallery) return;
  try {
    const response = await fetch('api/images.php', { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
    if (!response.ok) return;
    const payload = await response.json();
    if (!payload.ok || !Array.isArray(payload.images)) return;

    payload.images.forEach((item) => {
      const figure = document.createElement('figure');
      figure.className = 'photo-card uploaded-card';

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
