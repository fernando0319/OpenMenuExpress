//go:build windows

package main

import (
	"fmt"

	"github.com/alexbrainman/printer"
)

func platformName() string {
	return "windows"
}

func listPrinters() ([]string, error) {
	return printer.ReadNames()
}

// printRaw sends the bytes straight to the spooler as RAW, so the printer gets the ESC/POS
// commands untouched (no driver rendering).
func printRaw(name string, data []byte) error {
	if name == "" {
		return fmt.Errorf("impressora não definida")
	}

	p, err := printer.Open(name)
	if err != nil {
		return fmt.Errorf("não encontrei a impressora: %w", err)
	}
	defer p.Close()

	if err := p.StartRawDocument("OpenMenuExpress - pedido"); err != nil {
		return err
	}
	defer p.EndDocument()

	if err := p.StartPage(); err != nil {
		return err
	}

	if _, err := p.Write(data); err != nil {
		return err
	}

	return p.EndPage()
}
