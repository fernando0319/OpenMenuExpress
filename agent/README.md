# OpenMenuExpress Impressão

Programa que roda no computador da loja e imprime os pedidos nas impressoras térmicas (ESC/POS).
Ele busca os cupons no OpenMenuExpress a cada 3 segundos, envia os bytes direto para a impressora e
confirma ao servidor. Um cupom só sai da fila quando é confirmado: se o computador ou a impressora
falhar, ele é enviado de novo.

## Instalação na loja

1. Instale a impressora térmica no Windows normalmente (driver do fabricante ou "Generic / Text Only").
2. Baixe o `openmenuexpress-impressao.exe` e coloque numa pasta fixa (ex.: `C:\MenuExpress`).
3. No painel, abra **Impressão → Vincular computador** e anote o código de 6 dígitos.
4. Abra o programa, informe o endereço do OpenMenuExpress e o código.
5. No painel, as impressoras do computador aparecem em alguns segundos. Escolha qual usar, a largura
   do papel (58 ou 80 mm) e clique em **Testar**.

Para iniciar junto com o Windows, coloque um atalho do programa em `shell:startup`
(tecla Windows + R, digite `shell:startup`).

## Opções

| Opção                  | Para quê                                                               |
| ---------------------- | ---------------------------------------------------------------------- |
| `--server https://...` | Define o endereço do OpenMenuExpress sem perguntar                     |
| `--reset`              | Esquece o vínculo e pede um novo código                                |
| `--save-to pasta`      | Modo de teste: grava cada cupom como arquivo `.bin` em vez de imprimir |

A configuração fica em `%APPDATA%\MenuExpressImpressao\config.json` (Windows) ou
`~/.config/MenuExpressImpressao/config.json` (Linux), legível só pelo usuário.

## Linux

Usa o CUPS: as impressoras vêm de `lpstat -e` e a impressão vai por `lp -o raw`.

## Desenvolvimento

```bash
cd agent
go vet ./...
go build -o build/openmenuexpress-impressao.exe .
```

Requer Go 1.27+. O CI do repositório compila as versões Windows e Linux a cada push.
