//go:build !windows

package main

import (
	"bytes"
	"fmt"
	"os/exec"
	"runtime"
	"strings"
)

func platformName() string {
	return runtime.GOOS
}

// listPrinters asks CUPS for the configured printer queues.
func listPrinters() ([]string, error) {
	output, err := exec.Command("lpstat", "-e").Output()
	if err != nil {
		return nil, fmt.Errorf("lpstat indisponível (instale o CUPS): %w", err)
	}

	var names []string

	for _, line := range strings.Split(string(output), "\n") {
		if name := strings.TrimSpace(line); name != "" {
			names = append(names, name)
		}
	}

	return names, nil
}

// printRaw sends the ESC/POS bytes through CUPS without filtering.
func printRaw(name string, data []byte) error {
	command := exec.Command("lp", "-d", name, "-o", "raw")
	command.Stdin = bytes.NewReader(data)

	if output, err := command.CombinedOutput(); err != nil {
		return fmt.Errorf("lp falhou: %v: %s", err, strings.TrimSpace(string(output)))
	}

	return nil
}
