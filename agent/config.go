package main

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
)

// Config is kept in the user's config folder, e.g. %APPDATA%\MenuExpressImpressao\config.json.
type Config struct {
	ServerURL string `json:"server_url"`
	Token     string `json:"token"`
	StoreName string `json:"store_name"`
}

func configPath() (string, error) {
	dir, err := os.UserConfigDir()
	if err != nil {
		return "", err
	}

	return filepath.Join(dir, "MenuExpressImpressao", "config.json"), nil
}

func loadConfig() (*Config, error) {
	path, err := configPath()
	if err != nil {
		return nil, err
	}

	raw, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return &Config{}, nil
	}

	if err != nil {
		return nil, err
	}

	var cfg Config
	if err := json.Unmarshal(raw, &cfg); err != nil {
		return nil, err
	}

	return &cfg, nil
}

func saveConfig(cfg *Config) error {
	path, err := configPath()
	if err != nil {
		return err
	}

	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return err
	}

	raw, err := json.MarshalIndent(cfg, "", "  ")
	if err != nil {
		return err
	}

	// Only the current user can read the token.
	return os.WriteFile(path, raw, 0o600)
}
