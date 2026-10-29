//! OpenMenuExpress Painel: the store panel in its own window.
//!
//! - Loads the panel from the server (MX_SERVER_URL at build time, local by default).
//! - Closing the window hides it next to the clock: the order alarm keeps ringing and Windows
//!   notifications still show up. "Sair" in the tray menu really quits.
//! - Optional start with Windows (minimized), single instance, automatic updates.

use tauri::menu::{CheckMenuItem, Menu, MenuItem, PredefinedMenuItem};
use tauri::tray::{MouseButton, MouseButtonState, TrayIconBuilder, TrayIconEvent};
use tauri::{AppHandle, Manager, UserAttentionType, WebviewUrl, WebviewWindowBuilder, WindowEvent};
use tauri_plugin_autostart::{MacosLauncher, ManagerExt};
use tauri_plugin_notification::NotificationExt;
use tauri_plugin_opener::OpenerExt;
use tauri_plugin_updater::UpdaterExt;

const DEFAULT_SERVER: &str = "http://localhost:8000";
const MINIMIZED_FLAG: &str = "--minimized";

/// Hosts that open inside the app: the panel itself and the Mercado Pago login used to
/// connect a store's account (it must come back to the same session). Anything else goes
/// to the default browser.
const IN_APP_HOSTS: &[&str] = &["mercadopago.com", "mercadopago.com.br", "mercadolivre.com", "mercadolibre.com"];

/// WebView2 flags: keep timers and audio running while the window is hidden, and let the
/// order alarm play without a click first. The first three features are Tauri's defaults.
const BROWSER_ARGS: &str = "--disable-features=msWebOOUI,msPdfOOUI,msSmartScreenProtection,IntensiveWakeUpThrottling,CalculateNativeWinOcclusion \
--disable-background-timer-throttling --disable-renderer-backgrounding --disable-backgrounding-occluded-windows \
--autoplay-policy=no-user-gesture-required";

fn server() -> String {
    option_env!("MX_SERVER_URL").unwrap_or(DEFAULT_SERVER).trim_end_matches('/').to_string()
}

fn server_host() -> String {
    url::Url::parse(&server())
        .ok()
        .and_then(|url| url.host_str().map(str::to_string))
        .unwrap_or_default()
}

fn opens_in_app(url: &url::Url) -> bool {
    let Some(host) = url.host_str() else {
        return true;
    };
    let panel = server_host();

    host == panel
        || host.ends_with(&format!(".{panel}"))
        || IN_APP_HOSTS.iter().any(|allowed| host == *allowed || host.ends_with(&format!(".{allowed}")))
}

fn show_panel_window(app: &AppHandle) {
    if let Some(window) = app.get_webview_window("main") {
        let _ = window.unminimize();
        let _ = window.show();
        let _ = window.set_focus();
    }
}

/// Called by the panel when orders are waiting to be accepted.
#[tauri::command]
fn new_orders(app: AppHandle, title: String, body: String) {
    let _ = app.notification().builder().title(title).body(body).show();

    if let Some(window) = app.get_webview_window("main") {
        // Flashes the taskbar button until the window gets attention.
        let _ = window.request_user_attention(Some(UserAttentionType::Critical));
    }
}

#[tauri::command]
fn show_panel(app: AppHandle) {
    show_panel_window(&app);
}

fn build_tray(app: &AppHandle) -> tauri::Result<()> {
    let open = MenuItem::with_id(app, "open", "Abrir o painel", true, None::<&str>)?;
    let autostart_on = app.autolaunch().is_enabled().unwrap_or(false);
    let autostart = CheckMenuItem::with_id(app, "autostart", "Iniciar com o Windows", true, autostart_on, None::<&str>)?;
    let quit = MenuItem::with_id(app, "quit", "Sair", true, None::<&str>)?;
    let menu = Menu::with_items(app, &[&open, &autostart, &PredefinedMenuItem::separator(app)?, &quit])?;

    let mut tray = TrayIconBuilder::with_id("main")
        .tooltip("OpenMenuExpress")
        .menu(&menu)
        .show_menu_on_left_click(false)
        .on_menu_event(move |app, event| match event.id.as_ref() {
            "open" => show_panel_window(app),
            "autostart" => {
                let launcher = app.autolaunch();
                let enabled = launcher.is_enabled().unwrap_or(false);
                let _ = if enabled { launcher.disable() } else { launcher.enable() };
                let _ = autostart.set_checked(launcher.is_enabled().unwrap_or(false));
            }
            "quit" => app.exit(0),
            _ => {}
        })
        .on_tray_icon_event(|tray, event| {
            if let TrayIconEvent::Click { button: MouseButton::Left, button_state: MouseButtonState::Up, .. } = event {
                show_panel_window(tray.app_handle());
            }
        });

    if let Some(icon) = app.default_window_icon() {
        tray = tray.icon(icon.clone());
    }

    tray.build(app)?;

    Ok(())
}

/// Checks for a new version when the app starts and installs it right away (a few seconds,
/// before the store gets busy). Versions are signed; the public key is in tauri.conf.json.
fn check_for_updates(app: AppHandle) {
    tauri::async_runtime::spawn(async move {
        let Ok(endpoint) = format!("{}/storage/desktop/latest.json", server()).parse() else {
            return;
        };
        let Ok(updater) = app.updater_builder().endpoints(vec![endpoint]).and_then(|builder| builder.build()) else {
            return;
        };

        if let Ok(Some(update)) = updater.check().await {
            let _ = app
                .notification()
                .builder()
                .title("Atualizando o OpenMenuExpress")
                .body(format!("Instalando a versão {}. O painel abre de novo em instantes.", update.version))
                .show();

            if update.download_and_install(|_, _| {}, || {}).await.is_ok() {
                app.restart();
            }
        }
    });
}

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    tauri::Builder::default()
        // Opening the app again just brings the existing window forward.
        .plugin(tauri_plugin_single_instance::init(|app, _args, _cwd| show_panel_window(app)))
        .plugin(tauri_plugin_notification::init())
        .plugin(tauri_plugin_autostart::init(MacosLauncher::LaunchAgent, Some(vec![MINIMIZED_FLAG])))
        .plugin(tauri_plugin_updater::Builder::new().build())
        .plugin(tauri_plugin_opener::init())
        .invoke_handler(tauri::generate_handler![new_orders, show_panel])
        .setup(|app| {
            let start_hidden = std::env::args().any(|arg| arg == MINIMIZED_FLAG);
            let url: url::Url = format!("{}/dashboard", server()).parse()?;
            let opener = app.handle().clone();
            let popup_opener = app.handle().clone();

            WebviewWindowBuilder::new(app, "main", WebviewUrl::External(url))
                .title("OpenMenuExpress")
                .inner_size(1280.0, 800.0)
                .min_inner_size(900.0, 600.0)
                .maximized(true)
                .visible(!start_hidden)
                .additional_browser_args(BROWSER_ARGS)
                .on_navigation(move |url| {
                    if opens_in_app(url) {
                        return true;
                    }

                    let _ = opener.opener().open_url(url.as_str(), None::<&str>);
                    false
                })
                // Links meant for a new tab (the menu, WhatsApp, maps) open in the browser.
                .on_new_window(move |url, _features| {
                    let _ = popup_opener.opener().open_url(url.as_str(), None::<&str>);
                    tauri::webview::NewWindowResponse::Deny
                })
                .build()?;

            build_tray(app.handle())?;
            check_for_updates(app.handle().clone());

            Ok(())
        })
        .on_window_event(|window, event| {
            // The X hides the panel next to the clock instead of quitting.
            if let WindowEvent::CloseRequested { api, .. } = event {
                api.prevent_close();
                let _ = window.hide();
            }
        })
        .run(tauri::generate_context!())
        .expect("erro ao iniciar o OpenMenuExpress");
}
