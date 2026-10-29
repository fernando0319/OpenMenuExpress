// OpenMenuExpress Impressão: runs on the store's computer, fetches print jobs from the
// OpenMenuExpress server and sends them, as raw ESC/POS bytes, to the thermal printers.
//
// First run asks for the server address and the 6-digit code shown in the panel
// (Impressão → Vincular computador). After that it runs by itself.
package main

import (
	"bufio"
	"encoding/base64"
	"errors"
	"flag"
	"fmt"
	"log"
	"os"
	"os/signal"
	"strings"
	"syscall"
	"time"
)

// defaultServer is filled at build time (-ldflags "-X main.defaultServer=https://…") by the
// deploy, so the copy downloaded from a server already knows where to connect.
var defaultServer = ""

const (
	version           = "0.1.0"
	pollInterval      = 3 * time.Second
	heartbeatInterval = 15 * time.Second
)

func main() {
	serverFlag := flag.String("server", "", "endereço do OpenMenuExpress, ex.: https://pedidos.seudominio.com.br")
	reset := flag.Bool("reset", false, "esquece o vínculo atual e pede um novo código")
	saveTo := flag.String("save-to", "", "modo de teste: grava cada cupom como arquivo nesta pasta em vez de imprimir")
	flag.Parse()

	if *saveTo != "" {
		useFileOutput(*saveTo)
	}

	log.SetFlags(log.Ldate | log.Ltime)
	fmt.Printf("OpenMenuExpress Impressão v%s\n\n", version)

	cfg, err := loadConfig()
	if err != nil {
		log.Printf("Configuração ilegível, começando do zero: %v", err)
		cfg = &Config{}
	}

	if *serverFlag != "" {
		cfg.ServerURL = normalizeURL(*serverFlag)
	} else if cfg.ServerURL == "" && defaultServer != "" {
		cfg.ServerURL = normalizeURL(defaultServer)
	}

	if *reset {
		cfg.Token = ""
	}

	stdin := bufio.NewReader(os.Stdin)

	for cfg.Token == "" {
		if err := pair(cfg, stdin); err != nil {
			fmt.Printf("\n%v\n\n", err)
		}
	}

	stop := make(chan os.Signal, 1)
	signal.Notify(stop, os.Interrupt, syscall.SIGTERM)

	run(cfg, stdin, stop)
}

// pair asks for the server and the code, then stores the agent token.
func pair(cfg *Config, stdin *bufio.Reader) error {
	if cfg.ServerURL == "" {
		cfg.ServerURL = normalizeURL(ask(stdin, "Endereço do OpenMenuExpress (ex.: https://pedidos.seudominio.com.br): "))
	}

	code := strings.TrimSpace(ask(stdin, "Código de 6 dígitos mostrado no painel (Impressão → Vincular computador): "))

	hostname, _ := os.Hostname()
	if hostname == "" {
		hostname = "Computador da loja"
	}

	client := newAPIClient(cfg.ServerURL, "")
	result, err := client.pair(code, hostname, platformName(), version)
	if err != nil {
		if errors.Is(err, errUnreachable) {
			cfg.ServerURL = ""
		}

		return err
	}

	cfg.Token = result.Token
	cfg.StoreName = result.Store

	if err := saveConfig(cfg); err != nil {
		return fmt.Errorf("vinculado, mas não consegui salvar a configuração: %w", err)
	}

	fmt.Printf("\nPronto! Este computador agora imprime os pedidos de %s.\n\n", result.Store)

	return nil
}

func run(cfg *Config, stdin *bufio.Reader, stop <-chan os.Signal) {
	client := newAPIClient(cfg.ServerURL, cfg.Token)
	poll := time.NewTicker(pollInterval)
	heartbeat := time.NewTicker(heartbeatInterval)
	defer poll.Stop()
	defer heartbeat.Stop()

	log.Printf("Conectado a %s. Aguardando pedidos… (feche esta janela para parar)", cfg.ServerURL)
	sendHeartbeat(client)

	offline := false

	for {
		select {
		case <-stop:
			log.Print("Encerrando.")
			return

		case <-heartbeat.C:
			sendHeartbeat(client)

		case <-poll.C:
			jobs, err := client.jobs()

			switch {
			case errors.Is(err, errUnauthorized):
				log.Print("O vínculo deste computador foi removido no painel.")
				cfg.Token = ""
				_ = saveConfig(cfg)

				for cfg.Token == "" {
					if err := pair(cfg, stdin); err != nil {
						fmt.Printf("\n%v\n\n", err)
					}
				}

				client = newAPIClient(cfg.ServerURL, cfg.Token)

			case err != nil:
				if !offline {
					log.Printf("Sem conexão com o servidor (%v). Tentando de novo…", err)
					offline = true
				}

			default:
				if offline {
					log.Print("Conexão restabelecida.")
					offline = false
				}

				for _, job := range jobs {
					printJob(client, job)
				}
			}
		}
	}
}

func sendHeartbeat(client *apiClient) {
	printers, err := availablePrinters()
	if err != nil {
		log.Printf("Não consegui listar as impressoras: %v", err)
	}

	if err := client.heartbeat(printers, version); err != nil && !errors.Is(err, errUnauthorized) {
		log.Printf("Falha ao avisar o servidor: %v", err)
	}
}

func printJob(client *apiClient, job printJobData) {
	data, err := base64.StdEncoding.DecodeString(job.Payload)
	if err == nil {
		err = send(job.Printer, data)
	}

	if err != nil {
		log.Printf("Trabalho %d em \"%s\": FALHOU (%v)", job.ID, job.Printer, err)
		_ = client.result(job.ID, false, err.Error())

		return
	}

	log.Printf("Trabalho %d impresso em \"%s\".", job.ID, job.Printer)

	if err := client.result(job.ID, true, ""); err != nil {
		// The server re-sends unconfirmed jobs after a while; better a duplicate than a lost order.
		log.Printf("Impresso, mas não consegui confirmar ao servidor: %v", err)
	}
}

func ask(stdin *bufio.Reader, prompt string) string {
	fmt.Print(prompt)
	line, _ := stdin.ReadString('\n')

	return strings.TrimSpace(line)
}

func normalizeURL(raw string) string {
	url := strings.TrimRight(strings.TrimSpace(raw), "/")

	if url != "" && !strings.HasPrefix(url, "http://") && !strings.HasPrefix(url, "https://") {
		url = "https://" + url
	}

	return url
}
