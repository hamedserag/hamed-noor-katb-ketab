const arabicDigits = new Intl.NumberFormat('ar-EG', { useGrouping: false });
const invitation = document.getElementById('invitation');
const openButton = document.getElementById('openInvitation');
const musicToggle = document.getElementById('musicToggle');
const musicFallback = document.getElementById('musicFallback');
const mapLink = document.getElementById('mapLink');
const rsvpForm = document.getElementById('rsvpForm');
const formStatus = document.getElementById('formStatus');
const calendarLink = document.getElementById('calendarLink');

const MAP_URL = 'https://maps.app.goo.gl/U77XKBWKZwy4YmFH8?g_st=ic';
const VIDEO_ID = '2c0UFobfOiM';
const eventDate = new Date('2026-10-03T18:00:00+03:00');

let player = null;
let playerReady = false;
let requestedMusic = false;
let musicPlaying = false;

mapLink.href = MAP_URL;
mapLink.addEventListener('click', () => {
  // Reapply the exact shared Maps URL in case a browser extension rewrites the anchor.
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
    // The player is still loading; onPlayerReady will complete the request.
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

openButton.addEventListener('click', () => {
  invitation.hidden = false;
  document.body.classList.add('opened');
  playMusic();
  setTimeout(() => invitation.scrollIntoView({ behavior: 'smooth', block: 'start' }), 180);
});

musicToggle.addEventListener('click', () => {
  if (musicPlaying) pauseMusic();
  else playMusic();
});

rsvpForm.addEventListener('submit', (event) => {
  event.preventDefault();
  const data = Object.fromEntries(new FormData(rsvpForm).entries());
  const submissions = JSON.parse(localStorage.getItem('katbKetabRsvp') || '[]');
  submissions.push({ ...data, submittedAt: new Date().toISOString() });
  localStorage.setItem('katbKetabRsvp', JSON.stringify(submissions));
  formStatus.textContent = 'تم تسجيل ردكم على هذا الجهاز. شكرًا لمشاركتنا فرحتنا.';
  rsvpForm.reset();
});

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
