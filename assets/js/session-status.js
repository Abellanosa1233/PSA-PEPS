(() => {
  const dialog = document.getElementById('session-replaced-dialog');
  let checking = false;
  let leaving = false;
  let poll;

  function returnToSignIn(replaced) {
    leaving = true;
    window.clearInterval(poll);
    const redirect = () => window.location.replace(dialog.dataset.signIn);
    if (!replaced) {
      redirect();
      return;
    }
    dialog.addEventListener('cancel', (event) => event.preventDefault());
    dialog.showModal();
    window.setTimeout(redirect, 3000);
  }

  async function checkSession() {
    if (checking || leaving || document.hidden) return;
    checking = true;
    try {
      const response = await fetch('api/index.php?action=session.status', { cache: 'no-store' });
      if (!response.ok) return;
      const { status } = await response.json();
      if (status === 'replaced' || status === 'ended') returnToSignIn(status === 'replaced');
    } catch {
      // Try again at the next check if the network is temporarily unavailable.
    } finally {
      checking = false;
    }
  }

  if (dialog.hasAttribute('data-replaced')) {
    returnToSignIn(true);
    return;
  }
  poll = window.setInterval(checkSession, 3000);
  document.addEventListener('visibilitychange', checkSession);
  window.addEventListener('pageshow', checkSession);
  checkSession();
})();
