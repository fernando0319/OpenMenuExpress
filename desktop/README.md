# OpenMenuExpress Painel (Windows)

O painel da loja numa janela própria, para o computador do balcão:

- abre direto no painel (`/dashboard`), sem barra de navegador;
- o **X esconde** a janela perto do relógio: o alarme de pedido continua tocando e aparece a
  notificação do Windows com o botão da barra de tarefas piscando. Para fechar de verdade,
  clique com o botão direito no ícone perto do relógio → **Sair**;
- **Iniciar com o Windows** (no mesmo menu) abre o app minimizado quando o computador liga;
- abrir o app de novo só traz a janela existente para a frente;
- atualiza sozinho ao abrir quando há versão nova (assinada).

Links que abrem nova aba (cardápio, WhatsApp, mapas) vão para o navegador padrão. O login do
Mercado Pago, usado para conectar a conta da loja, abre dentro do app.

O programa de impressão automática continua separado (pasta `agent/`).

## Publicação

O workflow `desktop` compila o instalador num runner Windows e o envia para
`storage/desktop/` no servidor, junto com o `latest.json` que os apps instalados consultam.
Roda pela aba _Actions_ do seu fork.

Antes da primeira publicação, cada operador configura o app para o próprio domínio:

1. Gere o par de chaves das atualizações: `npx tauri signer generate -w ~/.tauri/painel.key`.
2. Em `src-tauri/tauri.conf.json`, troque `pubkey` pela chave pública gerada e
   `seudominio.com.br` no endpoint do `latest.json` pelo seu domínio.
3. Em `src-tauri/capabilities/panel.json`, troque `seudominio.com.br` pelo seu domínio.
4. No GitHub, cadastre os secrets `TAURI_SIGNING_PRIVATE_KEY` e
   `TAURI_SIGNING_PRIVATE_KEY_PASSWORD`, os `DEPLOY_*` do deploy e a variável
   `DEPLOY_APP_URL` (o endereço que o app abre).

Perder a chave privada impede as atualizações automáticas: guarde uma cópia em local seguro.

## Desenvolvimento

Requer Rust (stable) e as ferramentas de C++ do Visual Studio (Build Tools).

```bash
cd desktop
npm install
MX_SERVER_URL=http://127.0.0.1:8000 npx tauri dev   # abre o painel local
npx tauri build                                      # instalador em src-tauri/target/release/bundle/nsis
```
