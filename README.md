# OpenMenuExpress

Cardápio digital, pedidos, PDV e pagamentos para o comércio local, em código aberto. O OpenMenuExpress é a versão aberta do [Menu Express](https://menuexpress.app.br).

O objetivo é criar alternativas aos modelos tradicionais de negócio e pagamento. O lojista vende sem comissão por pedido e recebe direto na própria conta, em reais (Pix e dinheiro), Bitcoin ou stablecoins, sem intermediário segurando o dinheiro. O cliente compra sem criar conta, informando só nome e WhatsApp.

O projeto foi pensado para ser **operado localmente**: quem tem capacidade técnica pode hospedar o sistema na sua cidade, atender os lojistas da região e ganhar com isso, seja por mensalidade, percentual das vendas, rede de entregadores, implantação ou personalização.

> Em desenvolvimento. Contribuições são bem-vindas: veja [CONTRIBUTING.md](CONTRIBUTING.md).

## Princípios

- **Não custodial.** O sistema nunca guarda dinheiro nem chaves de lojistas. Cada pagamento cai direto na conta ou carteira de quem vendeu.
- **Proteção, não evasão.** Todo recebimento fica registrado com o valor em reais no momento, pronto para a contabilidade.
- **Escolha do lojista.** Nenhuma moeda é imposta; o lojista decide o que aceita.
- **O dinheiro precisa circular.** Entregadores, fornecedores e clientes entram no mesmo circuito.

## Funcionalidades

- Cardápio online com checkout sem cadastro
- Produtos com grupos de opções e pizzas (sabores, bordas, regras de preço)
- Pedidos em tempo real no painel, com mensagens automáticas no WhatsApp por status
- PDV com controle de caixa, gestão de mesas e app do garçom
- Rede de entregadores com rastreamento
- Impressão automática em impressoras térmicas via agente local ([agent/](agent/))
- App de balcão para Windows com alarme de pedidos ([desktop/](desktop/))
- Pagamento online direto na conta da loja: Mercado Pago, Pix Sicoob e BTCPay (Bitcoin)
- Agendamento de pedidos, cupons, banners, relatórios e módulo financeiro
- Frete por bairro ou por distância
- Planos, assinaturas e faturas para o operador cobrar os lojistas

## Stack

- PHP 8.3+ e [Laravel 13](https://laravel.com)
- [Inertia](https://inertiajs.com) + Vue 3 + TypeScript + Tailwind
- MySQL 8 / MariaDB em produção; SQLite no desenvolvimento e nos testes
- Laravel Reverb para tempo real e filas do Laravel para integrações
- Agente de impressão em Go; app de balcão em Tauri (Rust)

## Rodando localmente

Requisitos: PHP 8.3+ (extensões `mbstring`, `openssl`, `pdo_sqlite`, `intl`, `zip`, `curl`), Composer 2 e Node 22+.

```bash
git clone https://github.com/fernando0319/OpenMenuExpress.git
cd OpenMenuExpress
composer setup   # instala dependências, cria .env, gera chave, migra o banco e compila o front
composer dev     # sobe servidor, fila, logs e Vite
```

Acesse http://localhost:8000.

Para colocar no ar no seu domínio, siga o [DEPLOY.md](DEPLOY.md).

### Painel do operador

Quem hospeda o sistema tem um painel privado em `/admin`, com métricas de todas as lojas, suspensão e reativação de lojas e o **modo suporte**: abrir o painel de uma loja como o dono, em somente leitura ou com permissão para alterar, por até 60 minutos e com motivo obrigatório. Toda intervenção fica registrada, e o lojista vê os acessos do suporte na tela Equipe. Não existe tela para virar administrador: o acesso é dado pelo servidor.

```bash
php artisan app:super-admin seu@email.com            # dá acesso
php artisan app:super-admin seu@email.com --revoke   # remove
```

#### Planos e assinaturas

Com `BILLING_ENABLED=true`, cada loja nova ganha um teste grátis (`BILLING_TRIAL_DAYS`, padrão 14 dias). Em **/admin › Planos** você define quais módulos cada plano inclui (PDV e caixa, mesas, entregadores, relatórios, WhatsApp, cupons e banners). O cardápio, os pedidos e o catálogo estão sempre incluídos. O pagamento da assinatura acontece por fora do sistema: depois de receber, você **libera dias** no detalhe da loja ou gera **vouchers** em /admin › Vouchers, que o lojista ativa em **Assinatura**. Ao vencer, a loja tem `BILLING_GRACE_DAYS` de carência com aviso. Depois disso, o painel mostra só a Assinatura e o cardápio para de receber pedidos, sem apagar nenhum dado.

**Plano gratuito por faturamento:** um plano com "Gratuito até (R$)" preenchido não cobra enquanto a loja vender até esse valor no mês (pedidos concluídos, no fuso da loja), e renova sozinho todo mês. Lojas sem plano pago, ao fim do teste ou de um período pago, caem no primeiro plano gratuito ativo. Se o mês fechar acima do limite, a cobrança vale a partir do mês seguinte, com a mesma carência antes do bloqueio.

**Plano por uso (percentual):** marque "% das vendas online" no plano e defina o percentual, se ele incide só sobre o que passar do limite "Grátis até" (padrão) ou sobre o total do mês, e um mínimo e um teto opcionais. A base é o valor dos produtos dos pedidos concluídos do cardápio online (sem taxa de entrega; PDV e mesas não entram). Cada mês fecha numa fatura (no primeiro acesso do mês seguinte ou pelo comando agendado `app:close-invoices`), que vence no dia 1º com a mesma carência; em /admin › Faturas você marca como paga ou dispensa.

Para uso de uma loja só, deixe `BILLING_ENABLED=false`: nada é bloqueado e os planos não limitam recursos.

#### Pagamento online (direto na conta da loja)

Módulo **Pagamento online**: em Configurações › Pagamento online a loja conecta **Mercado Pago** (Pix com QR na tela e cartão pelo Checkout Pro; por OAuth se `MERCADOPAGO_CLIENT_ID/SECRET` estiverem configurados, ou colando o Access Token), **Sicoob** (API Pix padrão Bacen: Client ID, chave Pix e certificado, guardado criptografado) e **BTCPay Server** (Bitcoin on-chain/Lightning: endereço, chave de API e Store ID). O dinheiro vai direto para a conta da loja. O pedido pago online nasce "aguardando pagamento" e só entra para a loja (alerta, impressão, WhatsApp) quando o pagamento é confirmado; o status é sempre relido na API do provedor. Sem pagamento em `PAYMENTS_EXPIRATION_MINUTES`, o pedido é cancelado. Cancelar um pedido pago estorna no Mercado Pago e no Sicoob (no BTCPay o estorno é manual). Os avisos chegam em `/api/webhooks/payments/{token}` (o `APP_URL` precisa ser público em https) e o comando `app:sync-online-payments`, agendado a cada minuto, cobre avisos perdidos. Antes de usar em produção, homologue cada provedor com uma conta de teste.

#### Financeiro

Módulo **Financeiro** (dono e gerente): fornecedores (CPF/CNPJ validado), contas a pagar e a receber, à vista ou parceladas (semanal, quinzenal ou mensal, com cada parcela ajustável), baixa com data, valor pago (juros/desconto), forma de pagamento e opção de tirar/colocar o dinheiro no caixa aberto. Parcelas marcadas como "baixa automática" (débito automático, cartão) são baixadas no vencimento pelo comando `app:auto-settle-installments`, agendado de hora em hora, então o `schedule:run` precisa estar no cron. A visão geral mostra o resultado do mês (vendas do sistema + receitas − pagamentos), despesas por categoria e próximos vencimentos.

> **Windows:** se as integrações (WhatsApp, pagamentos) falharem com `cURL error 60: unable to get local issuer certificate`, o PHP está sem a lista de certificados. Baixe [cacert.pem](https://curl.se/ca/cacert.pem) e aponte `curl.cainfo` e `openssl.cafile` para ele no `php.ini`. Não desative a verificação SSL.

## Qualidade

```bash
composer test       # Pint (estilo) + Larastan (análise estática) + testes
composer lint       # corrige o estilo do código
npm run check:fix   # corrige o estilo do front
```

O GitHub Actions roda as mesmas verificações em cada pull request.

## Arquitetura

- **Núcleo**: catálogo, pedidos, clientes, mesas, caixa e horários. Preço, cupom, estoque e frete são sempre calculados no servidor (`app/Services`).
- **Eventos**: pedido criado, status alterado, pagamento confirmado e rodada de mesa disparam as integrações em fila.
- **Contratos de integração**: `PaymentGateway`, `MessagingProvider`, `PrinterDriver` e `ShippingCalculator`. Um novo meio de pagamento ou provedor de mensagens entra implementando o contrato.
- Valores em dinheiro são inteiros em centavos (`*_cents`).

## Nome e marca

"Menu Express" é a marca do serviço mantido pelo autor do projeto. Quem opera o OpenMenuExpress pode usar o código livremente, nos termos da licença, mas deve publicar o sistema com nome próprio (`APP_NAME`), sem se apresentar como Menu Express.

## Licença

[GNU Affero General Public License v3.0](LICENSE). Quem oferecer o sistema como serviço com modificações precisa disponibilizar o código dessas modificações aos usuários.
