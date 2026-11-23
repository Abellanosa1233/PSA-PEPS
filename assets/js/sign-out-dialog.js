const signOutDialog = document.getElementById('sign-out-dialog');

if (signOutDialog && typeof signOutDialog.showModal === 'function') {
  const openSignOut = document.getElementById('open-sign-out');
  const cancelSignOut = document.getElementById('cancel-sign-out');
  const form = signOutDialog.querySelector('form');
  const openedFromLink = signOutDialog.open;
  let closing = false;

  async function closeSignOutDialog(afterClose) {
    if (closing) return;
    closing = true;
    signOutDialog.setAttribute('data-closing', '');
    if (typeof signOutDialog.getAnimations === 'function') {
      await Promise.allSettled(signOutDialog.getAnimations().map((animation) => animation.finished));
    }
    signOutDialog.close();
    signOutDialog.removeAttribute('data-closing');
    closing = false;
    afterClose();
  }

  function cancel(event) {
    event.preventDefault();
    closeSignOutDialog(() => {
      if (openedFromLink) window.location.assign(cancelSignOut.href);
    });
  }

  openSignOut.addEventListener('click', (event) => {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    signOutDialog.showModal();
  });

  if (openedFromLink) {
    signOutDialog.close();
    signOutDialog.showModal();
  }

  cancelSignOut.addEventListener('click', cancel);
  signOutDialog.addEventListener('cancel', cancel);
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    closeSignOutDialog(() => form.submit());
  });
}
