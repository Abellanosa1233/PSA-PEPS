const actionNotifications = document.getElementById('action-notifications');

if (actionNotifications) {
  if (typeof actionNotifications.showPopover === 'function') {
    actionNotifications.setAttribute('popover', 'manual');
    actionNotifications.showPopover();
  }

  for (const toast of actionNotifications.querySelectorAll('.web-toast')) {
    const dismissButton = toast.querySelector('button');
    const timeout = window.setTimeout(dismiss, 3000);
    function dismiss() {
      window.clearTimeout(timeout);
      toast.remove();
      if (!actionNotifications.children.length) actionNotifications.remove();
    }
    dismissButton.hidden = false;
    dismissButton.addEventListener('click', dismiss);
  }
}
