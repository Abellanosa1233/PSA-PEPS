using Microsoft.Web.WebView2.WinForms;

namespace HorizonDesktop;

internal static class Program
{
    [STAThread]
    private static void Main()
    {
        ApplicationConfiguration.Initialize();

        var screen = Screen.FromPoint(Cursor.Position);
        var workArea = screen.WorkingArea;
        var windowedWidth = Math.Min(1280, workArea.Width);
        var windowedHeight = Math.Min(800, workArea.Height);
        var windowedBounds = new Rectangle(
            workArea.Left + (workArea.Width - windowedWidth) / 2,
            workArea.Top + (workArea.Height - windowedHeight) / 2,
            windowedWidth,
            windowedHeight);

        using var window = new Form
        {
            Text = "HRMS (Human Resource Management System)",
            StartPosition = FormStartPosition.Manual,
            FormBorderStyle = FormBorderStyle.None,
            Bounds = screen.Bounds
        };

        var profilePath = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "HorizonDesktop",
            "WebView2");
        var webView = new WebView2
        {
            Dock = DockStyle.Fill,
            CreationProperties = new CoreWebView2CreationProperties { UserDataFolder = profilePath }
        };
        window.Controls.Add(webView);

        var isFullScreen = true;

        void ToggleFullScreen()
        {
            if (isFullScreen)
            {
                window.FormBorderStyle = FormBorderStyle.Sizable;
                window.Bounds = windowedBounds;
            }
            else
            {
                var currentScreen = Screen.FromControl(window);
                windowedBounds = window.WindowState == FormWindowState.Normal ? window.Bounds : window.RestoreBounds;
                window.WindowState = FormWindowState.Normal;
                window.FormBorderStyle = FormBorderStyle.None;
                window.Bounds = currentScreen.Bounds;
            }

            isFullScreen = !isFullScreen;
            if (webView.Source is not null) ReportFullScreenState();
        }

        void ReportFullScreenState() =>
            webView.CoreWebView2.PostWebMessageAsString(isFullScreen ? "hrms-full-screen" : "hrms-windowed");

        void HandleFullScreenKey(object? sender, KeyEventArgs e)
        {
            if (e.KeyData != Keys.F11) return;
            e.Handled = true;
            window.BeginInvoke(ToggleFullScreen);
        }

        window.KeyDown += HandleFullScreenKey;
        webView.KeyDown += HandleFullScreenKey;
        window.Shown += async (_, _) =>
        {
            await webView.EnsureCoreWebView2Async();
            var appUrl = new Uri("http://localhost:8080/PSA-PEPS-main/overview");
            webView.CoreWebView2.WebMessageReceived += (_, message) =>
            {
                if (!Uri.TryCreate(message.Source, UriKind.Absolute, out var source)
                    || source.GetLeftPart(UriPartial.Authority) != appUrl.GetLeftPart(UriPartial.Authority)
                    || source.AbsolutePath != appUrl.AbsolutePath)
                {
                    return;
                }

                switch (message.WebMessageAsJson)
                {
                    case "\"hrms-toggle-full-screen\"":
                        ToggleFullScreen();
                        break;
                    case "\"hrms-full-screen-status\"":
                        ReportFullScreenState();
                        break;
                }
            };
            webView.Source = appUrl;
        };

        Application.Run(window);
    }
}
