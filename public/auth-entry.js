(function () {
  var path = window.location.pathname.replace(/\/+$/, '') || '/';
  if (path === '/login' && !window.location.hash.includes('ieosuia_token=')) window.location.replace('/api/auth/ieosuia/start');
  if (path === '/register') window.location.replace('/api/auth/ieosuia/start?screen_hint=signup');
})();
