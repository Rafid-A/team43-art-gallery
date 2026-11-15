// Applies a saved light/dark choice before the page paints (loaded without defer;
// the site's Content Security Policy does not allow inline scripts). app.js owns the toggle.
try {
  const theme = localStorage.getItem('theme');
  if (theme === 'dark' || theme === 'light') document.documentElement.dataset.theme = theme;
} catch (e) { /* storage unavailable: follow the system setting */ }
