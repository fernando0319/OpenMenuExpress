package main

import (
	"fmt"
	"os"
	"path/filepath"
	"time"
)

// fileOutputPrinter is the printer name shown in the panel in test mode (--save-to).
const fileOutputPrinter = "Arquivo de teste (OpenMenuExpress)"

var fileOutputDir string

// useFileOutput switches the agent to test mode: tickets become .bin files with the exact
// ESC/POS bytes, so the whole flow can be checked without a thermal printer.
func useFileOutput(dir string) {
	fileOutputDir = dir
}

func availablePrinters() ([]string, error) {
	if fileOutputDir != "" {
		return []string{fileOutputPrinter}, nil
	}

	return listPrinters()
}

func send(printerName string, data []byte) error {
	if fileOutputDir == "" {
		return printRaw(printerName, data)
	}

	if err := os.MkdirAll(fileOutputDir, 0o755); err != nil {
		return err
	}

	path := filepath.Join(fileOutputDir, fmt.Sprintf("cupom-%s.bin", time.Now().Format("20060102-150405.000")))

	return os.WriteFile(path, data, 0o644)
}
