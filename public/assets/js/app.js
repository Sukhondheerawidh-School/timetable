document.addEventListener('DOMContentLoaded', () => {
  const b = document.getElementById('tt-side-btn');
  const m = document.getElementById('tt-side-menu');
  if (b && m) b.addEventListener('click', () => m.classList.toggle('hidden'));
});
