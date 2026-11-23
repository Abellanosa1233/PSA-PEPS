using Microsoft.Web.WebView2.WinForms;

namespace HorizonDesktop;

internal static class Program
{
    [STAThread]
    private static void Main()
    {
        ApplicationConfiguration.Initialize();

        using var window = new Form
        {
            Text = "HRMS (Human Resource Management System)",
            Width = 1280,
            Height = 800
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

        window.Shown += async (_, _) =>
        {
            await webView.EnsureCoreWebView2Async();
            webView.Source = new Uri("http://localhost:8080/PSA-PEPS-main/index.php");
        };

        Application.Run(window);
    }
}
