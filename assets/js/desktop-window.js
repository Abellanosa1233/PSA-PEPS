const desktopWindow = window.chrome?.webview;
const fullScreenButton = document.querySelector('.desktop-full-screen');

if (desktopWindow && fullScreenButton) {
  desktopWindow.addEventListener('message', (event) => {
    if (event.data !== 'hrms-full-screen' && event.data !== 'hrms-windowed') return;
    fullScreenButton.hidden = false;
    fullScreenButton.textContent = event.data === 'hrms-full-screen' ? 'Exit full screen' : 'Full screen';
  });
  fullScreenButton.addEventListener('click', () => desktopWindow.postMessage('hrms-toggle-full-screen'));
  desktopWindow.postMessage('hrms-full-screen-status');
}
