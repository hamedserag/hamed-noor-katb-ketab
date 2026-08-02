const arabicDigits = new Intl.NumberFormat('ar-EG', { useGrouping: false });
const invitation = document.getElementById('invitation');
const openButton = document.getElementById('openInvitation');
const musicToggle = document.getElementById('musicToggle');
const audio = document.getElementById('backgroundMusic');
const rsvpForm = document.getElementById('rsvpForm');
const formStatus = document.getElementById('formStatus');
const calendarLink = document.getElementById('calendarLink');

const eventDate = new Date('2026-10-03T18:00:00+03:00');

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

openButton.addEventListener('click', () => {
  invitation.hidden = false;
  document.body.classList.add('opened');
  invitation.scrollIntoView({ behavior: 'smooth', block: 'start' });
});

musicToggle.addEventListener('click', async () => {
  if (!audio.src) {
    formStatus.textContent = 'أضف ملف الموسيقى إلى assets/music.mp3 ثم حدّث مصدر الصوت.';
    return;
  }

  if (audio.paused) {
    await audio.play();
    musicToggle.textContent = '❚❚';
  } else {
    audio.pause();
    musicToggle.textContent = '♫';
  }
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

const calendarStart = '20261003T150000Z';
const calendarEnd = '20261003T180000Z';
calendarLink.href = `https://calendar.google.com/calendar/render?action=TEMPLATE&text=${encodeURIComponent('كتب كتاب حامد ونور')}&dates=${calendarStart}/${calendarEnd}&details=${encodeURIComponent('استقبال الضيوف ٥:٣٠ مساءً وبدء كتب الكتاب ٦:٠٠ مساءً')}&location=${encodeURIComponent('مسجد قصر محمد علي بالمنيل')}`;
calendarLink.target = '_blank';
calendarLink.rel = 'noreferrer';

updateCountdown();
setInterval(updateCountdown, 1000);
