fn main() {
    // The app's own commands, so capabilities can grant them to the panel page (a remote URL).
    tauri_build::try_build(
        tauri_build::Attributes::new()
            .app_manifest(tauri_build::AppManifest::new().commands(&["new_orders", "show_panel"])),
    )
    .expect("failed to run tauri-build");
}
